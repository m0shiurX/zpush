<?php

namespace App\Services;

use App\Enums\PunchType;
use App\Exceptions\DeviceConnectionException;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MehediJaman\LaravelZkteco\LaravelZkteco;

class DeviceService
{
    private ?LaravelZkteco $zk = null;

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

        return $this;
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
     * Fetch users from the device and upsert into the employees table.
     *
     * @return Collection<int, Employee>
     *
     * @throws DeviceConnectionException
     */
    public function syncUsersFromDevice(DeviceConfig $device): Collection
    {
        $this->ensureConnected($device);

        $rawUsers = $this->zk->getUser();

        if (! is_array($rawUsers) || empty($rawUsers)) {
            Log::info("No users found on device [{$device->name}].");

            return collect();
        }

        $synced = collect();

        foreach ($rawUsers as $user) {
            $employee = Employee::updateOrCreate(
                ['device_uid' => (int) $user['uid']],
                [
                    'name' => $user['name'] ?: "User {$user['userid']}",
                    'employee_code' => $user['userid'],
                    'card_number' => ltrim($user['cardno'], '0') ?: null,
                ]
            );

            $synced->push($employee);
        }

        Log::info("Synced {$synced->count()} users from device [{$device->name}].");

        return $synced;
    }

    /**
     * Fetch attendance logs from the device and store new records.
     *
     * @return array{total: int, new: int, duplicates: int}
     *
     * @throws DeviceConnectionException
     */
    public function pollAttendance(DeviceConfig $device): array
    {
        $this->ensureConnected($device);

        $rawLogs = $this->zk->getAttendance();

        if (! is_array($rawLogs) || empty($rawLogs)) {
            $device->update(['last_poll_at' => now()]);

            return ['total' => 0, 'new' => 0, 'duplicates' => 0];
        }

        $newCount = 0;
        $duplicateCount = 0;

        foreach ($rawLogs as $log) {
            $timestamp = Carbon::parse($log['timestamp']);
            $punchType = PunchType::tryFrom((int) $log['type']) ?? PunchType::CheckIn;

            $existed = AttendanceLog::where('device_id', $device->id)
                ->where('device_uid', (int) $log['uid'])
                ->where('timestamp', $timestamp)
                ->exists();

            if ($existed) {
                $duplicateCount++;

                continue;
            }

            AttendanceLog::create([
                'employee_id' => Employee::where('device_uid', (int) $log['uid'])->value('id'),
                'device_id' => $device->id,
                'device_uid' => (int) $log['uid'],
                'timestamp' => $timestamp,
                'punch_type' => $punchType,
            ]);

            $newCount++;
        }

        $device->update(['last_poll_at' => now()]);

        Log::info("Polled device [{$device->name}]: {$newCount} new, {$duplicateCount} duplicates out of " . count($rawLogs) . ' total.');

        return [
            'total' => count($rawLogs),
            'new' => $newCount,
            'duplicates' => $duplicateCount,
        ];
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
            uid: $employee->device_uid,
            userid: (string) $employee->device_uid,
            name: $employee->name,
            password: '',
            cardno: (int) ($employee->card_number ?? 0),
        );

        return (bool) $result;
    }

    /**
     * Remove a user from the device.
     *
     * @throws DeviceConnectionException
     */
    public function removeUserFromDevice(DeviceConfig $device, int $uid): bool
    {
        $this->ensureConnected($device);

        return (bool) $this->zk->removeUser($uid);
    }

    /**
     * Clear all attendance logs on the device.
     *
     * @throws DeviceConnectionException
     */
    public function clearDeviceAttendance(DeviceConfig $device): bool
    {
        $this->ensureConnected($device);

        return (bool) $this->zk->clearAttendance();
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

        $users = $this->zk->getUser();

        return [
            'serial_number' => $this->zk->serialNumber() ?: null,
            'device_name' => $this->zk->deviceName() ?: null,
            'firmware' => $this->zk->version() ?: null,
            'platform' => $this->zk->platform() ?: null,
            'user_count' => is_array($users) ? count($users) : 0,
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
    private function createZkInstance(DeviceConfig $device): LaravelZkteco
    {
        if ($device->isTcp()) {
            return new ZktecoTcp($device->ip_address, $device->port);
        }

        return new LaravelZkteco($device->ip_address, $device->port);
    }
}
