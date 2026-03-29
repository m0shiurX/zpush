<?php

use App\Enums\SyncDirection;
use App\Jobs\SyncAttendanceToCloud;
use App\Models\AttendanceLog;
use App\Models\CloudServer;
use App\Models\DeviceConfig;
use App\Models\Employee;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->server = CloudServer::factory()
        ->connected()
        ->withBranch()
        ->create();

    $this->device = DeviceConfig::factory()->create();
});

test('uploads unsynced attendance to cloud and marks punches synced', function () {
    $employee = Employee::factory()->create(['employee_code' => 'EMP-100']);

    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => '2026-03-04 08:00:00',
        'cloud_synced' => false,
    ]);

    AttendanceLog::factory()->checkOut()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => '2026-03-04 17:00:00',
        'cloud_synced' => false,
    ]);

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/attendance/bulk' => Http::response([
            'success' => true,
            'accepted' => 1,
            'rejected' => 0,
            'errors' => [],
        ]),
    ]);

    $job = new SyncAttendanceToCloud('2026-03-04');
    app()->call([$job, 'handle']);

    expect(AttendanceLog::where('employee_id', $employee->id)->where('cloud_synced', true)->count())->toBe(2);

    expect(SyncLog::where('direction', SyncDirection::CloudUp)->where('entity_type', 'attendance')->exists())->toBeTrue();
});

test('skips when no active cloud server exists', function () {
    $this->server->update(['is_active' => false]);

    Http::fake();

    $job = new SyncAttendanceToCloud;
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
});

test('skips when cloud is not reachable', function () {
    Http::fake([
        '*/api/v1/zpush/ping' => Http::response([], 500),
    ]);

    $job = new SyncAttendanceToCloud;
    app()->call([$job, 'handle']);

    Http::assertSentCount(1); // Only the ping
});

test('handles partial rejection from cloud', function () {
    $emp1 = Employee::factory()->create(['employee_code' => 'EMP-200']);
    $emp2 = Employee::factory()->create(['employee_code' => 'EMP-201']);

    foreach ([$emp1, $emp2] as $emp) {
        AttendanceLog::factory()->checkIn()->create([
            'employee_id' => $emp->id,
            'device_id' => $this->device->id,
            'device_uid' => $emp->device_uid,
            'timestamp' => '2026-03-04 08:00:00',
            'cloud_synced' => false,
        ]);
    }

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/attendance/bulk' => Http::response([
            'success' => true,
            'accepted' => 1,
            'rejected' => 1,
            'errors' => [
                ['employee_code' => 'EMP-201', 'error' => 'Employee not found'],
            ],
        ]),
    ]);

    $job = new SyncAttendanceToCloud('2026-03-04');
    app()->call([$job, 'handle']);

    // EMP-200 punches should be synced, EMP-201 should not
    expect(AttendanceLog::where('employee_id', $emp1->id)->where('cloud_synced', true)->count())->toBe(1);
    expect(AttendanceLog::where('employee_id', $emp2->id)->where('cloud_synced', false)->count())->toBe(1);
});

test('processes all unsynced dates when no specific date provided', function () {
    $employee = Employee::factory()->create(['employee_code' => 'EMP-300']);

    // Day 1 punch
    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => '2026-03-03 08:00:00',
        'cloud_synced' => false,
    ]);

    // Day 2 punch
    AttendanceLog::factory()->checkIn()->create([
        'employee_id' => $employee->id,
        'device_id' => $this->device->id,
        'device_uid' => $employee->device_uid,
        'timestamp' => '2026-03-04 08:00:00',
        'cloud_synced' => false,
    ]);

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/attendance/bulk' => Http::response([
            'success' => true,
            'accepted' => 1,
            'rejected' => 0,
            'errors' => [],
        ]),
    ]);

    $job = new SyncAttendanceToCloud;
    app()->call([$job, 'handle']);

    // Both dates should be synced
    expect(AttendanceLog::where('cloud_synced', true)->count())->toBe(2);
});
