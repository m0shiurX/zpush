<?php

namespace App\Console\Commands;

use App\Exceptions\DeviceConnectionException;
use App\Models\DeviceConfig;
use App\Services\DeviceService;
use Illuminate\Console\Command;

class PollDevices extends Command
{
    protected $signature = 'devices:poll
        {--device= : Poll a specific device by ID}
        {--sync-users : Also sync users from devices}';

    protected $description = 'Poll active ZKTeco devices for new attendance logs';

    public function handle(DeviceService $service): int
    {
        $devices = $this->option('device')
            ? DeviceConfig::where('id', $this->option('device'))->where('is_active', true)->get()
            : DeviceConfig::active()->where('poll_method', 'bulk')->get();

        if ($devices->isEmpty()) {
            $this->warn('No active bulk-polling devices found.');

            return self::SUCCESS;
        }

        $this->info("Polling {$devices->count()} device(s)...");
        $totalNew = 0;
        $failures = 0;

        foreach ($devices as $device) {
            try {
                if ($this->option('sync-users')) {
                    $users = $service->syncUsersFromDevice($device);
                    $this->line("  [{$device->name}] Synced {$users->count()} users.");
                }

                $result = $service->pollAttendance($device);
                $totalNew += $result['new'];

                $this->line("  [{$device->name}] {$result['new']} new / {$result['duplicates']} duplicates / {$result['total']} total");
            } catch (DeviceConnectionException $e) {
                $failures++;
                $this->error("  [{$device->name}] Connection failed: {$e->getMessage()}");
            } finally {
                $service->disconnect();
            }
        }

        $this->newLine();
        $this->info("Done. {$totalNew} new attendance records. {$failures} device(s) failed.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
