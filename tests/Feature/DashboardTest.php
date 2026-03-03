<?php

use App\Models\AppSetting;
use App\Models\AttendanceLog;
use App\Models\CloudServer;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Models\SyncLog;
use App\Models\User;

test('guests are redirected to the login page', function () {
    AppSetting::set('setup_completed', true);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    AppSetting::set('setup_completed', true);

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard shows device summaries', function () {
    AppSetting::set('setup_completed', true);
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->connected()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Dashboard')
                ->has('devices', 1)
                ->where('devices.0.name', $device->name)
                ->where('devices.0.is_connected', true)
        );
});

test('dashboard shows today punch count', function () {
    AppSetting::set('setup_completed', true);
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $employee = Employee::factory()->create();

    foreach (range(1, 3) as $i) {
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'device_uid' => $employee->device_uid,
            'timestamp' => now()->addSeconds($i),
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Dashboard')
                ->where('todayPunchCount', 3)
                ->has('todayLogs', 3)
        );
});

test('dashboard shows employee and unsynced counts', function () {
    AppSetting::set('setup_completed', true);
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();

    $employees = Employee::factory()->count(5)->create();
    foreach (range(0, 1) as $i) {
        AttendanceLog::factory()->create([
            'employee_id' => $employees[$i]->id,
            'device_id' => $device->id,
            'device_uid' => $employees[$i]->device_uid,
            'cloud_synced' => false,
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Dashboard')
                ->where('employeeCount', 5)
                ->where('unsyncedCount', 2)
        );
});

test('dashboard shows cloud server status and last sync', function () {
    AppSetting::set('setup_completed', true);
    $user = User::factory()->create();
    CloudServer::factory()->create();

    SyncLog::factory()->create([
        'status' => 'completed',
        'completed_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Dashboard')
                ->where('hasCloudServer', true)
                ->has('lastSyncAt')
        );
});

test('dashboard shows no cloud server when none configured', function () {
    AppSetting::set('setup_completed', true);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('Dashboard')
                ->where('hasCloudServer', false)
                ->where('lastSyncAt', null)
        );
});
