<?php

use App\Exceptions\DeviceConnectionException;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\DeviceService;

test('listen command fails when no active devices exist', function () {
    $this->artisan('devices:listen')
        ->expectsOutputToContain('No active real-time devices found')
        ->assertExitCode(1);
});

test('listen command fails when specified device does not exist', function () {
    $this->artisan('devices:listen', ['--device' => 999])
        ->expectsOutputToContain('not found or inactive')
        ->assertExitCode(1);
});

test('listen command fails when specified device is inactive', function () {
    $device = DeviceConfig::factory()->inactive()->create();

    $this->artisan('devices:listen', ['--device' => $device->id])
        ->expectsOutputToContain('not found or inactive')
        ->assertExitCode(1);
});

test('listen command fails when specified device is bulk polling', function () {
    $device = DeviceConfig::factory()->bulk()->create();

    $this->artisan('devices:listen', ['--device' => $device->id])
        ->expectsOutputToContain('configured for bulk polling')
        ->assertExitCode(1);
});

test('listen command stops after timeout', function () {
    $device = DeviceConfig::factory()->connected()->create();

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('listenForAttendance')
        ->andReturnUsing(function ($device, $timeout, $callback) {
            // Simulate a normal cycle that returns after timeout
        });
    $mock->shouldReceive('disconnect')->andReturnNull();

    $this->app->instance(DeviceService::class, $mock);

    $this->artisan('devices:listen', ['--device' => $device->id, '--timeout' => 1])
        ->expectsOutputToContain('Stopped')
        ->assertExitCode(0);
});

test('listen command sends heartbeat after each successful cycle', function () {
    $device = DeviceConfig::factory()->create([
        'is_active' => true,
        'last_connected_at' => now()->subMinutes(10),
        'connection_failures' => 3,
    ]);

    $cycleCount = 0;
    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('listenForAttendance')
        ->andReturnUsing(function () use (&$cycleCount) {
            $cycleCount++;
        });
    $mock->shouldReceive('disconnect')->andReturnNull();

    $this->app->instance(DeviceService::class, $mock);

    $this->artisan('devices:listen', ['--device' => $device->id, '--timeout' => 1])
        ->assertExitCode(0);

    $device->refresh();
    expect($device->connection_failures)->toBe(0)
        ->and($device->last_connected_at)->not->toBeNull()
        ->and($device->last_connected_at->gt(now()->subMinutes(1)))->toBeTrue();
});

test('listen command reconnects after connection failure', function () {
    $device = DeviceConfig::factory()->connected()->create();

    $callCount = 0;
    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('listenForAttendance')
        ->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new DeviceConnectionException(DeviceConfig::first());
            }
            // Second call succeeds — simulate normal return
        });
    $mock->shouldReceive('disconnect')->andReturnNull();

    $this->app->instance(DeviceService::class, $mock);

    $this->artisan('devices:listen', ['--device' => $device->id, '--timeout' => 5])
        ->expectsOutputToContain('Connection lost')
        ->expectsOutputToContain('Reconnecting')
        ->assertExitCode(0);
});

test('listen command stops after max retries', function () {
    $device = DeviceConfig::factory()->connected()->create();

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('listenForAttendance')
        ->andThrow(new DeviceConnectionException($device));
    $mock->shouldReceive('disconnect')->andReturnNull();

    $this->app->instance(DeviceService::class, $mock);

    $this->artisan('devices:listen', ['--device' => $device->id, '--max-retries' => 2, '--timeout' => 10])
        ->expectsOutputToContain('Max retries')
        ->assertExitCode(0);
});

test('listen command stops when device is deactivated', function () {
    $device = DeviceConfig::factory()->connected()->create();

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('listenForAttendance')
        ->andReturnUsing(function () use ($device) {
            // Deactivate the device during the cycle
            $device->update(['is_active' => false]);
        });
    $mock->shouldReceive('disconnect')->andReturnNull();

    $this->app->instance(DeviceService::class, $mock);

    $this->artisan('devices:listen', ['--device' => $device->id, '--timeout' => 10])
        ->expectsOutputToContain('Device deactivated')
        ->assertExitCode(0);
});

test('handleRealtimeEvent creates attendance log for known employee', function () {
    $device = DeviceConfig::factory()->connected()->create();
    $employee = Employee::factory()->create(['device_uid' => 42]);

    $service = new DeviceService;
    $result = $service->handleRealtimeEvent([
        'user_id' => '42',
        'record_time' => '2026-03-27 10:30:00',
        'state' => 0,
        'device_ip' => $device->ip_address,
    ], $device);

    expect($result)->toBe(['new' => 1, 'skipped' => 0]);
    expect(AttendanceLog::count())->toBe(1);

    $log = AttendanceLog::first();
    expect($log->employee_id)->toBe($employee->id)
        ->and($log->device_uid)->toBe(42)
        ->and($log->device_id)->toBe($device->id);
});

test('handleRealtimeEvent skips unknown uid', function () {
    $device = DeviceConfig::factory()->connected()->create();

    $service = new DeviceService;
    $result = $service->handleRealtimeEvent([
        'user_id' => '999',
        'record_time' => '2026-03-27 10:30:00',
        'state' => 0,
        'device_ip' => $device->ip_address,
    ], $device);

    expect($result)->toBe(['new' => 0, 'skipped' => 1]);
    expect(AttendanceLog::count())->toBe(0);
});

test('handleRealtimeEvent skips duplicate timestamps', function () {
    $device = DeviceConfig::factory()->connected()->create();
    $employee = Employee::factory()->create(['device_uid' => 7]);

    AttendanceLog::factory()->create([
        'employee_id' => $employee->id,
        'device_id' => $device->id,
        'device_uid' => 7,
        'timestamp' => '2026-03-27 10:30:00',
    ]);

    $service = new DeviceService;
    $result = $service->handleRealtimeEvent([
        'user_id' => '7',
        'record_time' => '2026-03-27 10:30:00',
        'state' => 0,
        'device_ip' => $device->ip_address,
    ], $device);

    expect($result)->toBe(['new' => 0, 'skipped' => 1]);
    expect(AttendanceLog::count())->toBe(1);
});
