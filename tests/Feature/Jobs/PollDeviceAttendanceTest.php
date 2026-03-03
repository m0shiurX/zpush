<?php

use App\Exceptions\DeviceConnectionException;
use App\Jobs\PollDeviceAttendance;
use App\Models\DeviceConfig;
use App\Services\DeviceService;

test('poll job processes all active devices', function () {
    $device1 = DeviceConfig::factory()->create(['is_active' => true]);
    $device2 = DeviceConfig::factory()->create(['is_active' => true]);
    DeviceConfig::factory()->create(['is_active' => false]);

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('syncUsersFromDevice')->twice()->andReturn(collect());
    $mock->shouldReceive('pollAttendance')->twice()->andReturn(['total' => 0, 'new' => 0, 'duplicates' => 0]);
    $mock->shouldReceive('disconnect')->twice();

    (new PollDeviceAttendance)->handle($mock);
});

test('poll job processes specific device when id provided', function () {
    $device = DeviceConfig::factory()->create(['is_active' => true]);
    DeviceConfig::factory()->create(['is_active' => true]);

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('syncUsersFromDevice')->once()->andReturn(collect());
    $mock->shouldReceive('pollAttendance')->once()->andReturn(['total' => 5, 'new' => 3, 'duplicates' => 2]);
    $mock->shouldReceive('disconnect')->once();

    (new PollDeviceAttendance($device->id))->handle($mock);
});

test('poll job handles device connection failure gracefully', function () {
    $device = DeviceConfig::factory()->create(['is_active' => true]);

    $mock = $this->mock(DeviceService::class);
    $mock->shouldReceive('syncUsersFromDevice')
        ->once()
        ->andThrow(new DeviceConnectionException($device));
    $mock->shouldReceive('disconnect')->once();

    // Should not throw
    (new PollDeviceAttendance($device->id))->handle($mock);
});

test('poll job skips inactive devices', function () {
    DeviceConfig::factory()->create(['is_active' => false]);

    $mock = $this->mock(DeviceService::class);
    $mock->shouldNotReceive('syncUsersFromDevice');
    $mock->shouldNotReceive('pollAttendance');

    (new PollDeviceAttendance)->handle($mock);
});

test('poll job has unique id based on device', function () {
    $job = new PollDeviceAttendance(42);
    expect($job->uniqueId())->toBe('poll-attendance-42');

    $jobAll = new PollDeviceAttendance;
    expect($jobAll->uniqueId())->toBe('poll-attendance-all');
});

test('poll job implements ShouldBeUnique', function () {
    $job = new PollDeviceAttendance;
    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class);
});
