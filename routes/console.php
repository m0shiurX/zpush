<?php

use App\Jobs\CheckCloudConnectivity;
use App\Jobs\PollDeviceAttendance;
use App\Jobs\ProcessSyncQueue;
use App\Jobs\SyncAttendanceToCloud;
use App\Jobs\SyncEmployeesFromCloud;
use App\Jobs\SyncEmployeesToDevice;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PollDeviceAttendance)->everyThirtySeconds();
Schedule::job(new ProcessSyncQueue)->everyThirtySeconds();
Schedule::job(new SyncAttendanceToCloud)->everyMinute();
Schedule::job(new CheckCloudConnectivity)->everyMinute();
Schedule::job(new SyncEmployeesFromCloud)->everyFiveMinutes();
Schedule::job(new SyncEmployeesToDevice)->everyFiveMinutes();
