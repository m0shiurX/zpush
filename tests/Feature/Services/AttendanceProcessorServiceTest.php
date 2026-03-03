<?php

use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Services\AttendanceProcessorService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->processor = new AttendanceProcessorService;
    $this->device = DeviceConfig::factory()->create();
    $this->date = Carbon::parse('2026-03-04');
});

test('pairs check-in and check-out for a single employee', function () {
    $employee = Employee::factory()->create(['employee_code' => 'EMP-001']);

    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(8, 3),
    ]);

    AttendanceLog::factory()->checkOut()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(17, 32),
    ]);

    $records = $this->processor->processDate($this->date);

    expect($records)->toHaveCount(1)
        ->and($records[0]['employee_code'])->toBe('EMP-001')
        ->and($records[0]['date'])->toBe('2026-03-04')
        ->and($records[0]['check_in'])->toBe('08:03')
        ->and($records[0]['check_out'])->toBe('17:32')
        ->and($records[0]['source'])->toBe('zpush');
});

test('uses first check-in and last check-out when multiple punches exist', function () {
    $employee = Employee::factory()->create(['employee_code' => 'EMP-002']);

    // Multiple check-ins and check-outs (employee re-entered)
    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(8, 0),
    ]);

    AttendanceLog::factory()->checkOut()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(12, 0),
    ]);

    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(13, 0),
    ]);

    AttendanceLog::factory()->checkOut()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(18, 0),
    ]);

    $records = $this->processor->processDate($this->date);

    expect($records)->toHaveCount(1)
        ->and($records[0]['check_in'])->toBe('08:00')
        ->and($records[0]['check_out'])->toBe('18:00');
});

test('returns null check-out when employee has not checked out yet', function () {
    $employee = Employee::factory()->create(['employee_code' => 'EMP-003']);

    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(8, 15),
    ]);

    $records = $this->processor->processDate($this->date);

    expect($records)->toHaveCount(1)
        ->and($records[0]['check_in'])->toBe('08:15')
        ->and($records[0]['check_out'])->toBeNull();
});

test('processes multiple employees on the same date', function () {
    $emp1 = Employee::factory()->create(['employee_code' => 'EMP-010']);
    $emp2 = Employee::factory()->create(['employee_code' => 'EMP-020']);

    foreach ([$emp1, $emp2] as $employee) {
        AttendanceLog::factory()->checkIn()->create([
            'employee_id' => $employee->id,
            'device_id' => $this->device->id,
            'device_uid' => $employee->device_uid,
            'timestamp' => $this->date->copy()->setTime(8, 0),
        ]);

        AttendanceLog::factory()->checkOut()->create([
            'employee_id' => $employee->id,
            'device_id' => $this->device->id,
            'device_uid' => $employee->device_uid,
            'timestamp' => $this->date->copy()->setTime(17, 0),
        ]);
    }

    $records = $this->processor->processDate($this->date);

    expect($records)->toHaveCount(2);
    $codes = array_column($records, 'employee_code');
    expect($codes)->toContain('EMP-010')
        ->and($codes)->toContain('EMP-020');
});

test('skips inactive employees', function () {
    $active = Employee::factory()->create(['employee_code' => 'EMP-ACTIVE']);
    $inactive = Employee::factory()->inactive()->create(['employee_code' => 'EMP-INACTIVE']);

    foreach ([$active, $inactive] as $employee) {
        AttendanceLog::factory()->checkIn()->create([
            'employee_id' => $employee->id,
            'device_id' => $this->device->id,
            'device_uid' => $employee->device_uid,
            'timestamp' => $this->date->copy()->setTime(8, 0),
        ]);
    }

    $records = $this->processor->processDate($this->date);

    expect($records)->toHaveCount(1)
        ->and($records[0]['employee_code'])->toBe('EMP-ACTIVE');
});

test('returns empty array when no punches exist for date', function () {
    $records = $this->processor->processDate($this->date);

    expect($records)->toBeEmpty();
});

test('skips attendance logs without an employee', function () {
    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => null,
        'device_id' => $this->device->id,
        'device_uid' => 999,
        'timestamp' => $this->date->copy()->setTime(8, 0),
    ]);

    $records = $this->processor->processDate($this->date);

    expect($records)->toBeEmpty();
});

test('getCompletedPairs returns only records with check-out', function () {
    $emp1 = Employee::factory()->create();
    $emp2 = Employee::factory()->create();

    // Complete pair
    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $emp1->id,
        'device_id' => $this->device->id,
        'device_uid' => $emp1->device_uid,
        'timestamp' => $this->date->copy()->setTime(8, 0),
    ]);
    AttendanceLog::factory()->checkOut()->create([
        'employee_id' => $emp1->id,
        'device_id' => $this->device->id,
        'device_uid' => $emp1->device_uid,
        'timestamp' => $this->date->copy()->setTime(17, 0),
    ]);

    // Incomplete (no check-out)
    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $emp2->id,
        'device_id' => $this->device->id,
        'device_uid' => $emp2->device_uid,
        'timestamp' => $this->date->copy()->setTime(8, 0),
    ]);

    $completed = $this->processor->getCompletedPairs($this->date);
    $incomplete = $this->processor->getIncompletePairs($this->date);

    expect($completed)->toHaveCount(1)
        ->and($incomplete)->toHaveCount(1);
});

test('processUnsynced processes all dates with unsynced records', function () {
    $employee = Employee::factory()->create(['employee_code' => 'EMP-050']);

    // Day 1 — synced
    AttendanceLog::factory()->checkIn()->synced()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => Carbon::parse('2026-03-01')->setTime(8, 0),
    ]);

    // Day 2 — unsynced
    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => Carbon::parse('2026-03-02')->setTime(8, 0),
    ]);

    AttendanceLog::factory()->checkOut()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => Carbon::parse('2026-03-02')->setTime(17, 0),
    ]);

    $allRecords = $this->processor->processUnsynced();

    expect($allRecords)->toHaveKey('2026-03-02')
        ->and($allRecords)->not->toHaveKey('2026-03-01');
});

test('falls back to first punch if no explicit check-in', function () {
    $employee = Employee::factory()->create(['employee_code' => 'EMP-060']);

    // Only check-out punches (unusual but possible with device misconfiguration)
    AttendanceLog::factory()->checkOut()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(8, 0),
    ]);
    AttendanceLog::factory()->checkOut()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => $this->date->copy()->setTime(17, 0),
    ]);

    $records = $this->processor->processDate($this->date);

    // Should use first punch as check-in, last check-out as check-out
    expect($records)->toHaveCount(1)
        ->and($records[0]['check_in'])->toBe('08:00')
        ->and($records[0]['check_out'])->toBe('17:00');
});
