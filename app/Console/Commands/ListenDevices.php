<?php

namespace App\Console\Commands;

use App\Exceptions\DeviceConnectionException;
use App\Models\DeviceConfig;
use App\Services\DeviceService;
use Illuminate\Console\Command;

class ListenDevices extends Command
{
    protected $signature = 'devices:listen
        {--device= : Listen to a specific device by ID}
        {--timeout=0 : Timeout in seconds (0 = infinite)}';

    protected $description = 'Listen for real-time attendance events from ZKTeco devices';

    public function handle(DeviceService $service): int
    {
        $devices = $this->option('device')
            ? DeviceConfig::where('id', $this->option('device'))->active()->get()
            : DeviceConfig::active()->get();

        if ($devices->isEmpty()) {
            $this->warn('No active devices found.');

            return self::SUCCESS;
        }

        $timeout = (int) $this->option('timeout');
        $device = $devices->first();

        $this->info("Listening for attendance on [{$device->name}] ({$device->ip_address}:{$device->port})...");
        $this->info('Press Ctrl+C to stop.');
        $this->newLine();

        $newCount = 0;
        $skippedCount = 0;

        try {
            $service->listenForAttendance($device, $timeout, function (array $result) use (&$newCount, &$skippedCount) {
                $newCount += $result['new'];
                $skippedCount += $result['skipped'];

                if ($result['new'] > 0) {
                    $this->info("  Attendance recorded. Total new: {$newCount}");
                }
            });
        } catch (DeviceConnectionException $e) {
            $this->error("Connection failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            $service->disconnect();
        }

        $this->newLine();
        $this->info("Finished. {$newCount} new records, {$skippedCount} skipped.");

        return self::SUCCESS;
    }
}
