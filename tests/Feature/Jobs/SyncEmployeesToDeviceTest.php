<?php

use App\Jobs\SyncEmployeesToDevice;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\DeviceService;

beforeEach(function () {
    $this->device = DeviceConfig::factory()->connected()->create();
});

test('syncs employees that need device sync', function () {
    $employee = Employee::factory()->synced()->create([
        'device_uid' => 1,
        'device_synced_at' => null,
    ]);

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('addUserToDevice')
        ->once()
        ->with(
            Mockery::on(fn ($d) => $d->id === $this->device->id),
            Mockery::on(fn ($e) => $e->id === $employee->id),
        )
        ->andReturn(true);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(DeviceService::class, $mock);

    $job = new SyncEmployeesToDevice;
    app()->call([$job, 'handle']);

    $employee->refresh();
    expect($employee->device_synced_at)->not->toBeNull();
});

test('skips employees already synced to device', function () {
    Employee::factory()->synced()->create([
        'device_uid' => 1,
        'cloud_synced_at' => now()->subHour(),
        'device_synced_at' => now(),
    ]);

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldNotReceive('addUserToDevice');
    $mock->shouldNotReceive('disconnect');

    app()->instance(DeviceService::class, $mock);

    $job = new SyncEmployeesToDevice;
    app()->call([$job, 'handle']);
});

test('skips when no active devices found', function () {
    $this->device->update(['is_active' => false]);

    Employee::factory()->synced()->create([
        'device_synced_at' => null,
    ]);

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldNotReceive('addUserToDevice');
    $mock->shouldNotReceive('disconnect');

    app()->instance(DeviceService::class, $mock);

    $job = new SyncEmployeesToDevice;
    app()->call([$job, 'handle']);
});

test('allocates device_uid when not set', function () {
    $employee = Employee::factory()->synced()->create([
        'device_uid' => null,
        'device_synced_at' => null,
    ]);

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('addUserToDevice')->once()->andReturn(true);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(DeviceService::class, $mock);

    $job = new SyncEmployeesToDevice;
    app()->call([$job, 'handle']);

    $employee->refresh();
    expect($employee->device_uid)->not->toBeNull()
        ->and($employee->device_uid)->toBeGreaterThan(0);
});

test('does not update device_synced_at when addUserToDevice fails', function () {
    $employee = Employee::factory()->synced()->create([
        'device_uid' => 1,
        'device_synced_at' => null,
    ]);

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('addUserToDevice')->once()->andReturn(false);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(DeviceService::class, $mock);

    $job = new SyncEmployeesToDevice;
    app()->call([$job, 'handle']);

    $employee->refresh();
    expect($employee->device_synced_at)->toBeNull();
});
