<?php

namespace App\Console\Commands;

use App\Models\DeviceConfig;
use App\Services\DeviceService;
use Illuminate\Console\Command;

class DebugAttendance extends Command
{
    protected $signature = 'app:debug-attendance';

    protected $description = 'Debug attendance data from ZKTeco device using 0mithun/php-zkteco';

    public function handle(): int
    {
        $device = DeviceConfig::first();

        if (! $device) {
            $this->error('No device configured.');

            return 1;
        }

        $this->info("Connecting to {$device->name} ({$device->ip_address}:{$device->port}, protocol: {$device->protocol})...");

        $service = new DeviceService;

        try {
            $service->connect($device);
        } catch (\Throwable $e) {
            $this->error("Connection failed: {$e->getMessage()}");

            return 1;
        }

        $this->info('Connected successfully.');

        // Fetch parsed attendance via the new package
        $this->newLine();
        $this->info('=== Parsed Attendance Records ===');

        $ref = new \ReflectionClass($service);
        $zkProp = $ref->getProperty('zk');
        $zkProp->setAccessible(true);
        $zkInstance = $zkProp->getValue($service);

        $records = $zkInstance->getAttendances();

        if (empty($records)) {
            $this->warn('No attendance records found. Make a punch on the device first!');
            $service->disconnect();

            return 0;
        }

        $this->info('Found '.count($records).' record(s):');
        $this->newLine();

        $this->table(
            ['#', 'UID', 'User ID', 'State', 'Timestamp', 'Type'],
            collect($records)->map(fn ($r, $i) => [
                $i,
                $r['uid'] ?? '-',
                $r['user_id'] ?? '-',
                $r['state'] ?? '-',
                $r['record_time'] ?? '-',
                $r['type'] ?? '-',
            ])->toArray(),
        );

        // Also fetch users for reference
        $this->newLine();
        $this->info('=== Device Users ===');

        $users = $zkInstance->getUsers();

        if (empty($users)) {
            $this->warn('No users found on device.');
        } else {
            $this->info('Found '.count($users).' user(s):');
            $this->table(
                ['UID', 'User ID', 'Name', 'Role', 'Card No'],
                collect($users)->map(fn ($u) => [
                    $u['uid'] ?? '-',
                    $u['user_id'] ?? '-',
                    $u['name'] ?? '-',
                    $u['role'] ?? '-',
                    $u['card_no'] ?? '-',
                ])->toArray(),
            );
        }

        $service->disconnect();
        $this->info('Done.');

        return 0;
    }
}
