<?php

use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;

test('it can be created via factory', function () {
    $log = AttendanceLog::factory()->create();

    expect($log)->toBeInstanceOf(AttendanceLog::class)
        ->and($log->punch_type)->toBeInstanceOf(PunchType::class)
        ->and($log->cloud_synced)->toBeFalse();
});

test('checkIn state sets correct punch type', function () {
    $log = AttendanceLog::factory()->checkIn()->create();

    expect($log->punch_type)->toBe(PunchType::CheckIn);
});

test('checkOut state sets correct punch type', function () {
    $log = AttendanceLog::factory()->checkOut()->create();

    expect($log->punch_type)->toBe(PunchType::CheckOut);
});

test('synced state marks as cloud synced', function () {
    $log = AttendanceLog::factory()->synced()->create();

    expect($log->cloud_synced)->toBeTrue()
        ->and($log->cloud_synced_at)->not->toBeNull();
});

test('markAsSynced updates cloud sync fields', function () {
    $log = AttendanceLog::factory()->create();

    $log->markAsSynced();
    $log->refresh();

    expect($log->cloud_synced)->toBeTrue()
        ->and($log->cloud_synced_at)->not->toBeNull();
});

test('recordSyncFailure increments attempts', function () {
    $log = AttendanceLog::factory()->create(['cloud_sync_attempts' => 0]);

    $log->recordSyncFailure('Connection timeout');
    $log->refresh();

    expect($log->cloud_sync_attempts)->toBe(1);
});

test('toSyncPayload returns correct structure', function () {
    $employee = Employee::factory()->create(['device_uid' => 100]);
    $log = AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_uid' => 100,
    ]);

    $payload = $log->toSyncPayload();

    expect($payload)->toBeArray()
        ->toHaveKeys(['employee_code', 'device_uid', 'timestamp', 'punch_type', 'device_name']);
});

test('unsynced scope filters unsynced logs', function () {
    AttendanceLog::factory()->count(2)->create(['cloud_synced' => false]);
    AttendanceLog::factory()->synced()->create();

    expect(AttendanceLog::unsynced()->count())->toBe(2);
});

test('today scope filters logs from today', function () {
    AttendanceLog::factory()->today()->count(2)->create();
    AttendanceLog::factory()->create(['timestamp' => now()->subDays(3)]);

    expect(AttendanceLog::today()->count())->toBe(2);
});

test('belongs to employee', function () {
    $employee = Employee::factory()->create();
    $log = AttendanceLog::factory()->create(['employee_id' => $employee->id]);

    expect($log->employee->id)->toBe($employee->id);
});

test('belongs to device config', function () {
    $device = DeviceConfig::factory()->create();
    $log = AttendanceLog::factory()->create(['device_id' => $device->id]);

    expect($log->device->id)->toBe($device->id);
});
