<?php

use App\Models\AttendanceLog;
use App\Models\Employee;

test('it can be created via factory', function () {
    $employee = Employee::factory()->create();

    expect($employee)->toBeInstanceOf(Employee::class)
        ->and($employee->device_uid)->toBeInt()
        ->and($employee->name)->not->toBeEmpty()
        ->and($employee->is_active)->toBeTrue();
});

test('sync_hash is auto-computed on create', function () {
    $employee = Employee::factory()->create([
        'name' => 'John Doe',
        'device_uid' => 42,
        'employee_code' => 'EMP-0042',
        'department' => 'IT',
    ]);

    expect($employee->sync_hash)->not->toBeNull()
        ->and($employee->sync_hash)->toBe(md5(json_encode(['John Doe', 'IT', 'EMP-0042', true])));
});

test('sync_hash updates when name changes', function () {
    $employee = Employee::factory()->create(['name' => 'Old Name']);
    $originalHash = $employee->sync_hash;

    $employee->update(['name' => 'New Name']);
    $employee->refresh();

    expect($employee->sync_hash)->not->toBe($originalHash);
});

test('synced state sets cloud fields', function () {
    $employee = Employee::factory()->synced()->create();

    expect($employee->cloud_synced_at)->not->toBeNull()
        ->and($employee->cloud_id)->not->toBeNull();
});

test('active scope filters active employees', function () {
    Employee::factory()->count(3)->create(['is_active' => true]);
    Employee::factory()->inactive()->create();

    expect(Employee::active()->count())->toBe(3);
});

test('unsyncedToCloud scope filters unsynced employees', function () {
    Employee::factory()->count(2)->create(['cloud_synced_at' => null]);
    Employee::factory()->synced()->create();

    expect(Employee::unsyncedToCloud()->count())->toBe(2);
});

test('has many attendance logs', function () {
    $employee = Employee::factory()->create();
    AttendanceLog::factory()->count(2)->create(['employee_id' => $employee->id]);

    expect($employee->attendanceLogs)->toHaveCount(2);
});
