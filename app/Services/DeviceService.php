<?php

namespace App\Services;

use App\Enums\PunchType;
use App\Exceptions\DeviceConnectionException;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\Zk\AttendanceRecord;
use App\Services\Zk\ZkClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DeviceService
{
    /**
     * How far the device clock may drift before we reset it.
     */
    private const CLOCK_TOLERANCE_SECONDS = 60;

    private ?ZkClient $zk = null;

    private ?DeviceConfig $device = null;

    /**
     * Connect to a ZKTeco device.
     *
     * @throws DeviceConnectionException
     */
    public function connect(DeviceConfig $device): self
    {
        $this->device = $device;
        $this->zk = $this->createZkInstance($device);

        try {
            $connected = $this->zk->connect();
        } catch (\Throwable $e) {
            $device->recordFailure();

            throw new DeviceConnectionException($device, previous: $e);
        }

        if (! $connected) {
            $device->recordFailure();

            throw new DeviceConnectionException($device);
        }

        $device->recordSuccess();

        $this->syncDeviceClock($device);

        return $this;
    }

    /**
     * Bring the device clock in line with this host.
     *
     * A device that loses power restarts its clock at 2000-01-01 and stamps
     * every punch made before someone notices with a meaningless time. Setting
     * it on connect keeps that window as small as the poll interval.
     */
    private function syncDeviceClock(DeviceConfig $device): void
    {
        try {
            $deviceTime = Carbon::parse($this->zk->getTime());
            $drift = abs($deviceTime->diffInSeconds(now()));

            if ($drift <= self::CLOCK_TOLERANCE_SECONDS) {
                return;
            }

            $this->zk->setTime(now()->format('Y-m-d H:i:s'));

            Log::warning("Device [{$device->name}] clock was {$drift}s out ({$deviceTime}); reset to host time.");
        } catch (\Throwable $e) {
            // A device that will not tell us the time is still worth polling.
            Log::warning("Could not sync the clock on [{$device->name}]: {$e->getMessage()}");
        }
    }

    /**
     * Disconnect from the current device.
     */
    public function disconnect(): void
    {
        if ($this->zk) {
            try {
                $this->zk->disconnect();
            } catch (\Throwable) {
                // Ignore disconnection errors
            }

            $this->zk = null;
        }
    }

    /**
     * Test connection to a device without persisting the connection.
     *
     * @return array{success: bool, serial_number: string|null, device_name: string|null, firmware: string|null, error: string|null}
     */
    public function testConnection(DeviceConfig $device): array
    {
        try {
            $this->connect($device);

            $info = [
                'success' => true,
                'serial_number' => $this->zk->serialNumber() ?: null,
                'device_name' => $this->zk->deviceName() ?: null,
                'firmware' => $this->zk->version() ?: null,
                'error' => null,
            ];

            $this->disconnect();

            return $info;
        } catch (\Throwable $e) {
            $this->disconnect();

            return [
                'success' => false,
                'serial_number' => null,
                'device_name' => null,
                'firmware' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch users from the device and match with existing employees.
     *
     * Only updates card_number for known employees (matched by device_user_id).
     * Does NOT create new employees from unknown device users — employees
     * should come from the cloud, not from old device memory.
     *
     * @return Collection<int, Employee>
     *
     * @throws DeviceConnectionException
     */
    public function syncUsersFromDevice(DeviceConfig $device): Collection
    {
        $this->ensureConnected($device);

        $rawUsers = $this->zk->getUsers();

        if (! is_array($rawUsers) || empty($rawUsers)) {
            Log::info("No users found on device [{$device->name}].");

            return collect();
        }

        $synced = collect();

        foreach ($rawUsers as $user) {
            // Match on the operator-facing id, not the device's internal slot:
            // a user enrolled at the keypad can hold slot 1 and id 101.
            $employee = Employee::where('device_user_id', (string) ($user['user_id'] ?? ''))->first();

            if (! $employee) {
                continue;
            }

            $changes = [];

            // Remember the slot so commands that address one can find it later.
            $slotUid = (int) ($user['uid'] ?? 0);
            if ($slotUid > 0 && $employee->device_slot_uid !== $slotUid) {
                $changes['device_slot_uid'] = $slotUid;
            }

            $cardNo = ltrim($user['card_no'] ?? '', '0') ?: null;
            if ($cardNo && $employee->card_number !== $cardNo) {
                $changes['card_number'] = $cardNo;
            }

            if ($changes !== []) {
                $employee->update($changes);
            }

            $synced->push($employee);
        }

        Log::info("Matched {$synced->count()} employees on device [{$device->name}] (of ".count($rawUsers).' device users).');

        return $synced;
    }

    /**
     * Fetch attendance logs from the device and store new records.
     *
     * @return array{total: int, new: int, duplicates: int, skipped: int, quarantined: int}
     *
     * @throws DeviceConnectionException
     */
    public function pollAttendance(DeviceConfig $device): array
    {
        $this->ensureConnected($device);

        $records = $this->zk->readAttendance();
        $total = count($records);

        if ($total === 0) {
            // An empty log means the device was cleared (or never used). Either
            // way its positions restart, so the watermark has to restart too —
            // leaving it stale would skip the whole of the next log.
            $device->update([
                'last_poll_at' => now(),
                'last_synced_ordinal' => 0,
                'last_record_count' => 0,
            ]);

            return ['total' => 0, 'new' => 0, 'duplicates' => 0, 'skipped' => 0, 'quarantined' => 0];
        }

        // The device log is append-only, so anything below the watermark has
        // already been seen. A log that shrank was cleared on the device, which
        // resets positions — start again from the beginning.
        $wasCleared = $total < $device->last_record_count;
        $floor = $wasCleared ? 0 : $device->last_synced_ordinal;

        if ($wasCleared) {
            Log::warning("Device [{$device->name}] reports {$total} records, down from {$device->last_record_count}. Its log was cleared; re-reading from the start.");
        }

        $newCount = 0;
        $duplicateCount = 0;
        $skippedCount = 0;
        $quarantinedCount = 0;
        $firstUnresolved = null;

        foreach ($records as $record) {
            if ($record->ordinal < $floor) {
                continue;
            }

            $employeeId = Employee::where('device_user_id', $record->userId)->value('id');

            // Don't import orphaned records — employees come from the cloud,
            // not from whoever happens to be enrolled on the device.
            if (! $employeeId) {
                $skippedCount++;

                // Hold the watermark here. The employee may yet arrive from the
                // cloud, and advancing past this record would strand it.
                $firstUnresolved ??= $record->ordinal;

                continue;
            }

            $log = $this->recordPunch($device, $record, $employeeId);

            if (! $log->wasRecentlyCreated) {
                $duplicateCount++;

                continue;
            }

            $newCount++;

            if ($log->is_quarantined) {
                $quarantinedCount++;
            }
        }

        $device->update([
            'last_poll_at' => now(),
            'last_synced_ordinal' => $firstUnresolved ?? $total,
            'last_record_count' => $total,
        ]);

        if ($skippedCount > 0) {
            Log::info("Polled device [{$device->name}]: skipped {$skippedCount} records from unknown device user ids.");
        }

        if ($quarantinedCount > 0) {
            Log::warning("Polled device [{$device->name}]: quarantined {$quarantinedCount} records stamped before {$device->trustedEpoch()}. Check the device clock.");
        }

        Log::info("Polled device [{$device->name}]: {$newCount} new, {$duplicateCount} duplicates out of {$total} total.");

        return [
            'total' => $total,
            'new' => $newCount,
            'duplicates' => $duplicateCount,
            'skipped' => $skippedCount,
            'quarantined' => $quarantinedCount,
        ];
    }

    /**
     * Store one punch, or return the record that already held that slot.
     *
     * Both read paths — bulk polling and the real-time listener — land here, so
     * a punch seen by each is stored once, under one key.
     */
    private function recordPunch(DeviceConfig $device, AttendanceRecord $record, int $employeeId): AttendanceLog
    {
        return AttendanceLog::firstOrCreate(
            [
                'device_id' => $device->id,
                'device_user_id' => $record->userId,
                'timestamp' => $record->timestamp,
            ],
            [
                'employee_id' => $employeeId,
                'punch_type' => PunchType::tryFrom($record->punch) ?? PunchType::CheckIn,
                // A device that lost power restarts its clock in the year 2000,
                // so a punch stamped before then cannot be believed.
                'is_quarantined' => $record->timestamp->lt($device->trustedEpoch()),
            ],
        );
    }

    /**
     * Listen for real-time attendance events from a device.
     *
     * Some ZKTeco firmware (e.g. K40 Ver 6.60 / JZ4725) returns corrupt data
     * for bulk attendance reads (CMD_ATT_LOG_RRQ). Real-time event monitoring
     * bypasses this by capturing punch events as they happen.
     *
     * @param  callable(array{new: int, skipped: int}): void  $onEvent  Optional callback after each event is processed.
     *
     * @throws DeviceConnectionException
     */
    public function listenForAttendance(DeviceConfig $device, int $timeout = 0, ?callable $onEvent = null): void
    {
        $this->ensureConnected($device);

        try {
            $this->zk->getRealTimeLogs(function (array $event) use ($device, $onEvent) {
                $result = $this->handleRealtimeEvent($event, $device);

                if ($onEvent) {
                    $onEvent($result);
                }
            }, $timeout);
        } finally {
            // Always disconnect after a listen cycle. ZKTeco devices (especially
            // K40 firmware) may close the TCP connection during event monitoring,
            // leaving a stale socket. Starting each cycle with a fresh connection
            // prevents "Broken pipe" errors on CMD_REG_EVENT re-registration.
            $this->disconnect();
        }
    }

    /**
     * Process a single real-time attendance event from a device.
     *
     * @param  array{user_id: string, record_time: string, state: int, type: int, device_ip: string}  $event
     * @return array{new: int, skipped: int}
     */
    public function handleRealtimeEvent(array $event, DeviceConfig $device): array
    {
        $deviceUserId = (string) ($event['user_id'] ?? '');

        $employee = Employee::where('device_user_id', $deviceUserId)->first();

        if (! $employee) {
            Log::debug("Realtime event from unknown device user id {$deviceUserId} on [{$device->name}].");

            return ['new' => 0, 'skipped' => 1];
        }

        $timestamp = Carbon::parse($event['record_time']);
        $punchType = PunchType::tryFrom((int) ($event['type'] ?? 0)) ?? PunchType::CheckIn;

        $log = $this->recordPunch($device, new AttendanceRecord(
            ordinal: 0,
            uid: 0,
            userId: $deviceUserId,
            timestamp: $timestamp->toImmutable(),
            status: (int) ($event['state'] ?? 0),
            punch: (int) ($event['type'] ?? 0),
        ), $employee->id);

        if (! $log->wasRecentlyCreated) {
            return ['new' => 0, 'skipped' => 1];
        }

        $device->update(['last_poll_at' => now()]);

        Log::info("Realtime: {$employee->name} (device user id {$deviceUserId}) {$punchType->label()} at {$timestamp} on [{$device->name}].");

        return ['new' => 1, 'skipped' => 0];
    }

    /**
     * Add a user to the device.
     *
     * @throws DeviceConnectionException
     */
    public function addUserToDevice(DeviceConfig $device, Employee $employee): bool
    {
        $this->ensureConnected($device);

        $result = $this->zk->setUser(
            uid: $employee->deviceSlot(),
            userid: (string) $employee->device_user_id,
            name: $employee->name,
            password: '',
            cardno: (int) ($employee->card_number ?? 0),
        );

        return $result !== false;
    }

    /**
     * Remove a user from the device.
     *
     * @throws DeviceConnectionException
     */
    public function removeUserFromDevice(DeviceConfig $device, int $uid): bool
    {
        $this->ensureConnected($device);

        return $this->zk->removeUser($uid) !== false;
    }

    /**
     * Clear all attendance logs on the device.
     *
     * @throws DeviceConnectionException
     */
    public function clearDeviceAttendance(DeviceConfig $device): bool
    {
        $this->ensureConnected($device);

        return $this->zk->clearAttendance() !== false;
    }

    /**
     * Remove all users from the device.
     *
     * @return int Number of users removed.
     *
     * @throws DeviceConnectionException
     */
    public function removeAllUsersFromDevice(DeviceConfig $device): int
    {
        $this->ensureConnected($device);

        $users = $this->zk->getUsers();

        if (! is_array($users) || empty($users)) {
            return 0;
        }

        $removed = 0;
        foreach ($users as $user) {
            if ($this->zk->removeUser((int) $user['uid']) !== false) {
                $removed++;
            }
        }

        Log::info("Removed {$removed} users from device [{$device->name}].");

        return $removed;
    }

    /**
     * Get device info (serial number, name, firmware, etc.).
     *
     * @return array{serial_number: string|null, device_name: string|null, firmware: string|null, platform: string|null, user_count: int}
     *
     * @throws DeviceConnectionException
     */
    public function getDeviceInfo(DeviceConfig $device): array
    {
        $this->ensureConnected($device);

        $users = $this->zk->getUsers();

        return [
            'serial_number' => $this->zk->serialNumber() ?: null,
            'device_name' => $this->zk->deviceName() ?: null,
            'firmware' => $this->zk->version() ?: null,
            'platform' => $this->zk->platform() ?: null,
            'user_count' => is_array($users) ? count($users) : 0,
        ];
    }

    /**
     * Sync the server's current date/time to the device.
     *
     * @return array{success: bool, device_time: string}
     *
     * @throws DeviceConnectionException
     */
    public function syncTime(DeviceConfig $device): array
    {
        $this->ensureConnected($device);

        $serverTime = now()->format('Y-m-d H:i:s');

        $this->zk->setTime($serverTime);

        $deviceTime = $this->zk->getTime();

        Log::info("Synced time on device [{$device->name}] to {$serverTime}. Device reports: {$deviceTime}.");

        return [
            'success' => true,
            'device_time' => $deviceTime ?: $serverTime,
        ];
    }

    /**
     * Ensure we have a live connection, connecting if necessary.
     *
     * @throws DeviceConnectionException
     */
    private function ensureConnected(DeviceConfig $device): void
    {
        if ($this->zk && $this->device?->id === $device->id) {
            return;
        }

        $this->disconnect();
        $this->connect($device);
    }

    /**
     * Create the appropriate ZKTeco instance based on device protocol.
     */
    protected function createZkInstance(DeviceConfig $device, int $timeout = 10): ZkClient
    {
        return new ZkClient(
            host: $device->ip_address,
            port: $device->port,
            timeout: $timeout,
            protocol: $device->isTcp() ? 'tcp' : 'udp',
        );
    }
}
