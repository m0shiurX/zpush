<?php

use App\Jobs\SyncEmployeesToDevice;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\DeviceService;
use App\Services\ListenerCoordinator;

beforeEach(function () {
    $this->device = DeviceConfig::factory()->connected()->create();

    // Mock ListenerCoordinator to just execute the callback directly (no NativePHP runtime in tests)
    $coordinatorMock = Mockery::mock(ListenerCoordinator::class);
    $coordinatorMock->shouldReceive('withPausedListener')
        ->andReturnUsing(fn($device, $callback) => $callback());

    app()->instance(ListenerCoordinator::class, $coordinatorMock);
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
            Mockery::on(fn($d) => $d->id === $this->device->id),
            Mockery::on(fn($e) => $e->id === $employee->id),
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

test('removes deactivated employees from device', function () {
    $inactiveEmployee = Employee::factory()->synced()->inactive()->create([
        'device_uid' => 5,
        'device_synced_at' => now()->subDay(),
    ]);

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('removeUserFromDevice')
        ->once()
        ->with(
            Mockery::on(fn($d) => $d->id === $this->device->id),
            5,
        )
        ->andReturn(true);
    $mock->shouldNotReceive('addUserToDevice');
    $mock->shouldReceive('disconnect')->once();

    app()->instance(DeviceService::class, $mock);

    $job = new SyncEmployeesToDevice;
    app()->call([$job, 'handle']);

    $inactiveEmployee->refresh();
    expect($inactiveEmployee->device_synced_at)->toBeNull();
});

test('removes deactivated and syncs new employees in one pass', function () {
    $inactiveEmployee = Employee::factory()->synced()->inactive()->create([
        'device_uid' => 3,
        'device_synced_at' => now()->subDay(),
    ]);

    $newEmployee = Employee::factory()->synced()->create([
        'device_uid' => 10,
        'device_synced_at' => null,
    ]);

    $mock = Mockery::mock(DeviceService::class);
    $mock->shouldReceive('removeUserFromDevice')->once()->andReturn(true);
    $mock->shouldReceive('addUserToDevice')->once()->andReturn(true);
    $mock->shouldReceive('disconnect')->once();

    app()->instance(DeviceService::class, $mock);

    $job = new SyncEmployeesToDevice;
    app()->call([$job, 'handle']);

    $inactiveEmployee->refresh();
    $newEmployee->refresh();

    expect($inactiveEmployee->device_synced_at)->toBeNull()
        ->and($newEmployee->device_synced_at)->not->toBeNull();
});

test('pauses listener for realtime devices during sync', function () {
    $employee = Employee::factory()->synced()->create([
        'device_uid' => 1,
        'device_synced_at' => null,
    ]);

    // Override the coordinator mock to verify it's called
    $coordinatorMock = Mockery::mock(ListenerCoordinator::class);
    $coordinatorMock->shouldReceive('withPausedListener')
        ->once()
        ->with(
            Mockery::on(fn($d) => $d->id === $this->device->id),
            Mockery::type('callable'),
        )
        ->andReturnUsing(fn($device, $callback) => $callback());

    app()->instance(ListenerCoordinator::class, $coordinatorMock);

    $serviceMock = Mockery::mock(DeviceService::class);
    $serviceMock->shouldReceive('addUserToDevice')->once()->andReturn(true);
    $serviceMock->shouldReceive('disconnect')->once();

    app()->instance(DeviceService::class, $serviceMock);

    $job = new SyncEmployeesToDevice;
    app()->call([$job, 'handle']);
});
