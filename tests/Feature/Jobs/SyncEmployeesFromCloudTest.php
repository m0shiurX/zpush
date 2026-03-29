<?php

use App\Enums\SyncDirection;
use App\Jobs\SyncEmployeesFromCloud;
use App\Jobs\SyncEmployeesToDevice;
use App\Models\CloudServer;
use App\Models\Employee;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->server = CloudServer::factory()
        ->connected()
        ->withBranch()
        ->create();
});

test('creates new employees from cloud response', function () {
    Queue::fake();

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/employees*' => Http::response([
            'success' => true,
            'employees' => [
                [
                    'id' => 10,
                    'employee_code' => 'CLOUD-001',
                    'name' => 'Jane Doe',
                    'department' => 'Engineering',
                    'designation' => 'Developer',
                    'shift' => null,
                    'is_active' => true,
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'total' => 1,
        ]),
    ]);

    $job = new SyncEmployeesFromCloud;
    app()->call([$job, 'handle']);

    $employee = Employee::where('employee_code', 'CLOUD-001')->first();
    expect($employee)->not->toBeNull()
        ->and($employee->cloud_id)->toBe(10)
        ->and($employee->name)->toBe('Jane Doe')
        ->and($employee->department)->toBe('Engineering')
        ->and($employee->cloud_synced_at)->not->toBeNull();

    expect(SyncLog::where('direction', SyncDirection::CloudDown)->where('entity_type', 'employee')->exists())->toBeTrue();

    Queue::assertPushed(SyncEmployeesToDevice::class);
});

test('updates existing employee when data changes', function () {
    Queue::fake();

    $employee = Employee::factory()->create([
        'cloud_id' => 10,
        'employee_code' => 'CLOUD-002',
        'name' => 'Old Name',
        'department' => 'HR',
    ]);

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/employees*' => Http::response([
            'success' => true,
            'employees' => [
                [
                    'id' => 10,
                    'employee_code' => 'CLOUD-002',
                    'name' => 'New Name',
                    'department' => 'Engineering',
                    'designation' => null,
                    'shift' => null,
                    'is_active' => true,
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'total' => 1,
        ]),
    ]);

    $job = new SyncEmployeesFromCloud;
    app()->call([$job, 'handle']);

    $employee->refresh();
    expect($employee->name)->toBe('New Name')
        ->and($employee->department)->toBe('Engineering');

    Queue::assertPushed(SyncEmployeesToDevice::class);
});

test('does not update employee when hash matches', function () {
    $employee = Employee::factory()->create([
        'cloud_id' => 10,
        'employee_code' => 'CLOUD-003',
        'name' => 'Same Name',
        'department' => 'Sales',
        'is_active' => true,
    ]);
    $originalHash = $employee->sync_hash;

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/employees*' => Http::response([
            'success' => true,
            'employees' => [
                [
                    'id' => 10,
                    'employee_code' => 'CLOUD-003',
                    'name' => 'Same Name',
                    'department' => 'Sales',
                    'designation' => null,
                    'shift' => null,
                    'is_active' => true,
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'total' => 1,
        ]),
    ]);

    $job = new SyncEmployeesFromCloud;
    app()->call([$job, 'handle']);

    $employee->refresh();
    expect($employee->sync_hash)->toBe($originalHash)
        ->and($employee->cloud_synced_at)->not->toBeNull();
});

test('full sync deactivates employees not in cloud response', function () {
    $keepEmployee = Employee::factory()->create([
        'cloud_id' => 1,
        'employee_code' => 'KEEP-001',
        'is_active' => true,
    ]);

    $removeEmployee = Employee::factory()->create([
        'cloud_id' => 2,
        'employee_code' => 'REMOVE-001',
        'is_active' => true,
    ]);

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/employees*' => Http::response([
            'success' => true,
            'employees' => [
                [
                    'id' => 1,
                    'employee_code' => 'KEEP-001',
                    'name' => $keepEmployee->name,
                    'department' => $keepEmployee->department,
                    'designation' => null,
                    'shift' => null,
                    'is_active' => true,
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'total' => 1,
        ]),
    ]);

    $job = new SyncEmployeesFromCloud(fullSync: true);
    app()->call([$job, 'handle']);

    $keepEmployee->refresh();
    $removeEmployee->refresh();

    expect($keepEmployee->is_active)->toBeTrue()
        ->and($removeEmployee->is_active)->toBeFalse();
});

test('skips when no active cloud server with branch', function () {
    $this->server->update(['is_active' => false]);

    Http::fake();

    $job = new SyncEmployeesFromCloud;
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
});

test('logs failure when employees fetch fails', function () {
    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/employees*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $job = new SyncEmployeesFromCloud;
    app()->call([$job, 'handle']);

    expect(SyncLog::where('direction', SyncDirection::CloudDown)->where('status', 'failed')->exists())->toBeTrue();
});

test('sends updated_since for incremental sync', function () {
    Employee::factory()->create([
        'cloud_synced_at' => '2026-03-01 12:00:00',
    ]);

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/employees*' => Http::response([
            'success' => true,
            'employees' => [],
            'total' => 0,
        ]),
    ]);

    $job = new SyncEmployeesFromCloud;
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/employees')) {
            return false;
        }

        return str_contains($request->url(), 'updated_since=');
    });
});
