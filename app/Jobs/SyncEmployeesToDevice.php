<?php

namespace App\Jobs;

use App\Exceptions\DeviceConnectionException;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\DeviceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
    public function handle(DeviceService $service): void
    {
        $devices = $this->deviceId
            ? DeviceConfig::where('id', $this->deviceId)->active()->get()
            : DeviceConfig::active()->get();

        if ($devices->isEmpty()) {
            Log::info('SyncEmployeesToDevice: No active devices found.');

            return;
        }

        // Get employees that need to be synced to devices
        // (have cloud_id but no device_uid, or device_synced_at < cloud_synced_at)
        $employees = Employee::query()
            ->active()
            ->where(function ($query) {
                $query->whereNull('device_synced_at')
                    ->orWhereColumn('device_synced_at', '<', 'cloud_synced_at');
            })
            ->get();

        if ($employees->isEmpty()) {
            Log::info('SyncEmployeesToDevice: No employees need syncing.');

            return;
        }

        foreach ($devices as $device) {
            try {
                $synced = 0;
                $failed = 0;

                foreach ($employees as $employee) {
                    try {
                        // Assign device_uid if not set
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
                        Log::warning("SyncEmployeesToDevice: Failed to sync employee [{$employee->name}] to [{$device->name}]", [
                            'error' => $e->getMessage(),
                        ]);
                        $failed++;
                    }
                }

                Log::info("SyncEmployeesToDevice [{$device->name}]: {$synced} synced, {$failed} failed.");
            } catch (DeviceConnectionException $e) {
                Log::warning("SyncEmployeesToDevice [{$device->name}]: Connection failed — {$e->getMessage()}");
            } finally {
                $service->disconnect();
            }
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
