<?php

namespace App\Jobs;

use App\Exceptions\DeviceConnectionException;
use App\Models\DeviceConfig;
use App\Services\DeviceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PollDeviceAttendance implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 60;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public ?int $deviceId = null) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'poll-attendance-'.($this->deviceId ?? 'all');
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
            Log::info('PollDeviceAttendance: No active devices found.');

            return;
        }

        foreach ($devices as $device) {
            try {
                $service->syncUsersFromDevice($device);
                $result = $service->pollAttendance($device);

                Log::info("PollDeviceAttendance [{$device->name}]: {$result['new']} new, {$result['duplicates']} duplicates.");
            } catch (DeviceConnectionException $e) {
                Log::warning("PollDeviceAttendance [{$device->name}]: Connection failed — {$e->getMessage()}");
            } finally {
                $service->disconnect();
            }
        }
    }
}
