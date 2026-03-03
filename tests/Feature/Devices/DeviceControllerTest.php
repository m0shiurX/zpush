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
            fn ($page) => $page
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
            fn ($page) => $page
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
            fn ($page) => $page
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
            fn ($page) => $page
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
// Clear Device Attendance
// ==========================================

test('clear attendance clears device and local records', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    AttendanceLog::factory()->count(10)->create(['device_id' => $device->id]);

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('clearDeviceAttendance')
        ->once()
        ->with(\Mockery::on(fn ($d) => $d->id === $device->id))
        ->andReturn(true);
    $mock->shouldReceive('disconnect')->once();

    $this->actingAs($user)
        ->deleteJson(route('devices.clear-attendance', $device))
        ->assertSuccessful()
        ->assertJson([
            'success' => true,
            'deleted' => 10,
        ]);

    expect(AttendanceLog::where('device_id', $device->id)->count())->toBe(0);
});

test('clear attendance returns error when device fails', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('clearDeviceAttendance')
        ->once()
        ->andThrow(new \App\Exceptions\DeviceConnectionException($device, 'Connection refused'));
    $mock->shouldReceive('disconnect')->once();

    $this->actingAs($user)
        ->deleteJson(route('devices.clear-attendance', $device))
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

test('clear attendance only removes records for that device', function () {
    $user = User::factory()->create();
    $device1 = DeviceConfig::factory()->create();
    $device2 = DeviceConfig::factory()->create();
    AttendanceLog::factory()->count(5)->create(['device_id' => $device1->id]);
    AttendanceLog::factory()->count(3)->create(['device_id' => $device2->id]);

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('clearDeviceAttendance')->once()->andReturn(true);
    $mock->shouldReceive('disconnect')->once();

    $this->actingAs($user)
        ->deleteJson(route('devices.clear-attendance', $device1))
        ->assertSuccessful()
        ->assertJson(['deleted' => 5]);

    expect(AttendanceLog::where('device_id', $device2->id)->count())->toBe(3);
});

// ==========================================
// Clear Local Attendance
// ==========================================

test('clear local attendance removes only local records', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();
    AttendanceLog::factory()->count(7)->create(['device_id' => $device->id]);

    $this->actingAs($user)
        ->deleteJson(route('devices.clear-local-attendance', $device))
        ->assertSuccessful()
        ->assertJson([
            'success' => true,
            'deleted' => 7,
        ]);

    expect(AttendanceLog::where('device_id', $device->id)->count())->toBe(0);
});

test('clear local attendance does not affect other devices', function () {
    $user = User::factory()->create();
    $device1 = DeviceConfig::factory()->create();
    $device2 = DeviceConfig::factory()->create();
    AttendanceLog::factory()->count(5)->create(['device_id' => $device1->id]);
    AttendanceLog::factory()->count(4)->create(['device_id' => $device2->id]);

    $this->actingAs($user)
        ->deleteJson(route('devices.clear-local-attendance', $device1))
        ->assertSuccessful()
        ->assertJson(['deleted' => 5]);

    expect(AttendanceLog::where('device_id', $device2->id)->count())->toBe(4);
});

test('clear local attendance requires authentication', function () {
    $device = DeviceConfig::factory()->create();

    $this->deleteJson(route('devices.clear-local-attendance', $device))
        ->assertUnauthorized();
});

// ==========================================
// Clear Device Users
// ==========================================

test('clear device users removes all users from device', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('removeAllUsersFromDevice')
        ->once()
        ->with(\Mockery::on(fn ($d) => $d->id === $device->id))
        ->andReturn(5);
    $mock->shouldReceive('disconnect')->once();

    $this->actingAs($user)
        ->deleteJson(route('devices.clear-device-users', $device))
        ->assertSuccessful()
        ->assertJson([
            'success' => true,
            'removed' => 5,
        ]);
});

test('clear device users handles connection error', function () {
    $user = User::factory()->create();
    $device = DeviceConfig::factory()->create();

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('removeAllUsersFromDevice')
        ->once()
        ->andThrow(new \App\Exceptions\DeviceConnectionException($device, 'Connection refused'));
    $mock->shouldReceive('disconnect')->once();

    $this->actingAs($user)
        ->deleteJson(route('devices.clear-device-users', $device))
        ->assertStatus(500)
        ->assertJson(['success' => false]);
});

test('clear device users requires authentication', function () {
    $device = DeviceConfig::factory()->create();

    $this->deleteJson(route('devices.clear-device-users', $device))
        ->assertUnauthorized();
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
