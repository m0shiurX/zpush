<?php

use App\Models\AppSetting;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Models\User;
use App\Services\DeviceService;

beforeEach(function () {
    AppSetting::set('setup_completed', true);
});

// ==========================================
// Index
// ==========================================

test('guests cannot access device index', function () {
    $this->get(route('devices.index'))->assertRedirect(route('login'));
});

test('device index renders with devices', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->connected()->create();

    $this->actingAs($user)
        ->get(route('devices.index'))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->component('devices/Index')
                ->has('devices', 1)
                ->where('devices.0.name', $device->name)
        );
});

test('device index shows attendance log counts', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    AttendanceLog::factory()->count(5)->create(['device_id' => $device->id]);

    $this->actingAs($user)
        ->get(route('devices.index'))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->where('devices.0.attendance_logs_count', 5)
        );
});

// ==========================================
// Show
// ==========================================

test('device show renders with device details', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->connected()->create();

    $this->actingAs($user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->component('devices/Show')
                ->has('device')
                ->where('device.name', $device->name)
                ->where('device.is_connected', true)
        );
});

test('device show includes recent attendance logs', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    $employee = Employee::factory()->create();

    AttendanceLog::factory()->count(3)->create([
        'device_id' => $device->id,
        'employee_id' => $employee->id,
    ]);

    $this->actingAs($user)
        ->get(route('devices.show', $device))
        ->assertOk()
        ->assertInertia(
            fn($page) => $page
                ->has('recentLogs', 3)
        );
});

// ==========================================
// Test Connection
// ==========================================

test('test connection returns success result', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('testConnection')
        ->once()
        ->andReturn([
            'success' => true,
            'serial_number' => 'ABC123',
            'device_name' => 'K40',
            'firmware' => '1.0',
            'error' => null,
        ]);

    $this->actingAs($user)
        ->postJson(route('devices.test', $device))
        ->assertSuccessful()
        ->assertJson(['success' => true, 'serial_number' => 'ABC123']);
});

test('test connection returns failure result', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('testConnection')
        ->once()
        ->andReturn([
            'success' => false,
            'serial_number' => null,
            'device_name' => null,
            'firmware' => null,
            'error' => 'Connection refused',
        ]);

    $this->actingAs($user)
        ->postJson(route('devices.test', $device))
        ->assertSuccessful()
        ->assertJson(['success' => false, 'error' => 'Connection refused']);
});

// ==========================================
// Poll
// ==========================================

test('poll device returns attendance results', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('syncUsersFromDevice')
        ->once()
        ->andReturn(collect([Employee::factory()->make()]));
    $mock->shouldReceive('pollAttendance')
        ->once()
        ->andReturn(['total' => 10, 'new' => 5, 'duplicates' => 5]);
    $mock->shouldReceive('disconnect')->once();

    $this->actingAs($user)
        ->postJson(route('devices.poll', $device))
        ->assertSuccessful()
        ->assertJson([
            'success' => true,
            'new' => 5,
            'duplicates' => 5,
            'users_synced' => 1,
        ]);
});

// ==========================================
// Update (toggle active)
// ==========================================

test('device can be toggled inactive', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create(['is_active' => true]);

    $this->actingAs($user)
        ->patch(route('devices.update', $device), ['is_active' => false])
        ->assertRedirect();

    expect($device->fresh()->is_active)->toBeFalse();
});

test('device can be toggled active', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create(['is_active' => false]);

    $this->actingAs($user)
        ->patch(route('devices.update', $device), ['is_active' => true])
        ->assertRedirect();

    expect($device->fresh()->is_active)->toBeTrue();
});

// ==========================================
// Setup incomplete redirect
// ==========================================

test('device index redirects when setup not complete', function () {
    AppSetting::set('setup_completed', false);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('devices.index'))
        ->assertRedirect(route('setup.welcome'));
});
