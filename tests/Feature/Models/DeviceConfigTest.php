<?php

use App\Models\AttendanceLog;
use App\Models\DeviceConfig;

test('it can be created via factory', function () {
    $device = DeviceConfig::factory()->create();

    expect($device)->toBeInstanceOf(DeviceConfig::class)
        ->and($device->ip_address)->not->toBeEmpty()
        ->and($device->port)->toBe(4370)
        ->and($device->is_active)->toBeTrue();
});

test('connected state sets timestamps', function () {
    $device = DeviceConfig::factory()->connected()->create();

    expect($device->last_connected_at)->not->toBeNull()
        ->and($device->last_poll_at)->not->toBeNull()
        ->and($device->connection_failures)->toBe(0);
});

test('failing state increments failures', function () {
    $device = DeviceConfig::factory()->failing()->create();

    expect($device->connection_failures)->toBeGreaterThan(0);
});

test('inactive state sets is_active to false', function () {
    $device = DeviceConfig::factory()->inactive()->create();

    expect($device->is_active)->toBeFalse();
});

test('isConnected returns true when recently connected with no failures', function () {
    $device = DeviceConfig::factory()->create([
        'last_connected_at' => now(),
        'connection_failures' => 0,
    ]);

    expect($device->isConnected())->toBeTrue();
});

test('isConnected returns false when connection is stale', function () {
    $device = DeviceConfig::factory()->create([
        'last_connected_at' => now()->subMinutes(5),
        'connection_failures' => 0,
    ]);

    expect($device->isConnected())->toBeFalse();
});

test('isConnected returns false with failures', function () {
    $device = DeviceConfig::factory()->create([
        'last_connected_at' => now(),
        'connection_failures' => 1,
    ]);

    expect($device->isConnected())->toBeFalse();
});

test('recordSuccess resets failures and updates timestamp', function () {
    $device = DeviceConfig::factory()->failing()->create();

    $device->recordSuccess();
    $device->refresh();

    expect($device->connection_failures)->toBe(0)
        ->and($device->last_connected_at)->not->toBeNull();
});

test('recordFailure increments failure count', function () {
    $device = DeviceConfig::factory()->create(['connection_failures' => 0]);

    $device->recordFailure();
    $device->refresh();

    expect($device->connection_failures)->toBe(1);
});

test('active scope filters active devices', function () {
    DeviceConfig::factory()->count(2)->create(['is_active' => true]);
    DeviceConfig::factory()->inactive()->create();

    expect(DeviceConfig::active()->count())->toBe(2);
});

test('has many attendance logs', function () {
    $device = DeviceConfig::factory()->create();
    AttendanceLog::factory()->count(3)->create(['device_id' => $device->id]);

    expect($device->attendanceLogs)->toHaveCount(3);
});
