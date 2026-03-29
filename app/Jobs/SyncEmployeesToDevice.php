<?php

namespace App\Jobs;

use App\Exceptions\DeviceConnectionException;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\DeviceService;
use App\Services\ListenerCoordinator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyncEmployeesToDevice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 120;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(public ?int $deviceId = null) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-employees-to-device-'.($this->deviceId ?? 'all');
    }

    /**
     * Execute the job.
     */
    public function handle(DeviceService $service, ListenerCoordinator $coordinator): void
    {
        $devices = $this->deviceId
            ? DeviceConfig::where('id', $this->deviceId)->active()->get()
            : DeviceConfig::active()->get();

        if ($devices->isEmpty()) {
            Log::info('SyncEmployeesToDevice: No active devices found.');

            return;
        }

        // Employees to add or update on the device
        $employeesToSync = Employee::query()
            ->active()
            ->where(function ($query) {
                $query->whereNull('device_synced_at')
                    ->orWhereColumn('device_synced_at', '<', 'cloud_synced_at');
            })
            ->get();

        // Inactive employees that still have a device_uid (need removal from device)
        $employeesToRemove = Employee::query()
            ->where('is_active', false)
            ->whereNotNull('device_uid')
            ->whereNotNull('device_synced_at')
            ->get();

        if ($employeesToSync->isEmpty() && $employeesToRemove->isEmpty()) {
            Log::info('SyncEmployeesToDevice: No employees need syncing or removal.');

            return;
        }

        foreach ($devices as $device) {
            $coordinator->withPausedListener($device, function () use ($device, $service, $employeesToSync, $employeesToRemove) {
                $this->syncDevice($device, $service, $employeesToSync, $employeesToRemove);
            });
        }
    }

    /**
     * Perform the actual sync for a single device: remove inactive, add/update active.
     *
     * @param  Collection<int, Employee>  $employeesToSync
     * @param  Collection<int, Employee>  $employeesToRemove
     */
    private function syncDevice(
        DeviceConfig $device,
        DeviceService $service,
        $employeesToSync,
        $employeesToRemove,
    ): void {
        $synced = 0;
        $removed = 0;
        $failed = 0;

        try {
            // Phase 1: Remove deactivated employees from the device
            foreach ($employeesToRemove as $employee) {
                try {
                    $service->removeUserFromDevice($device, $employee->device_uid);
                    $employee->update(['device_synced_at' => null]);
                    $removed++;
                } catch (\Throwable $e) {
                    Log::warning("SyncEmployeesToDevice: Failed to remove [{$employee->name}] from [{$device->name}]", [
                        'error' => $e->getMessage(),
                    ]);
                    $failed++;
                }
            }

            // Phase 2: Add or update active employees on the device
            foreach ($employeesToSync as $employee) {
                try {
                    if (! $employee->device_uid) {
                        $employee->device_uid = $this->allocateDeviceUid();
                        $employee->save();
                    }

                    $success = $service->addUserToDevice($device, $employee);

                    if ($success) {
                        $employee->update(['device_synced_at' => now()]);
                        $synced++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("SyncEmployeesToDevice: Failed to sync [{$employee->name}] to [{$device->name}]", [
                        'error' => $e->getMessage(),
                    ]);
                    $failed++;
                }
            }

            Log::info("SyncEmployeesToDevice [{$device->name}]: {$synced} synced, {$removed} removed, {$failed} failed.");
        } catch (DeviceConnectionException $e) {
            Log::warning("SyncEmployeesToDevice [{$device->name}]: Connection failed — {$e->getMessage()}");
        } finally {
            $service->disconnect();
        }
    }

    /**
     * Allocate the next available device UID.
     *
     * ZKTeco K40 supports UIDs 1–65535.
     */
    private function allocateDeviceUid(): int
    {
        $maxUid = Employee::query()->max('device_uid') ?? 0;

        return $maxUid + 1;
    }
}
