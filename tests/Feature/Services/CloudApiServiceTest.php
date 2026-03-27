<?php

use App\Models\CloudServer;
use App\Services\CloudApiService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->server = CloudServer::factory()->withBranch(1, 'Main Factory')->create([
        'api_base_url' => 'https://lavloss.test',
        'api_key' => 'test-token-123',
    ]);
});

test('ping returns success when cloud is reachable', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/ping' => Http::response([
            'success' => true,
            'server_time' => '2026-03-04T10:30:00Z',
        ]),
    ]);

    $api = new CloudApiService($this->server);
    $result = $api->ping();

    expect($result['success'])->toBeTrue()
        ->and($result['server_time'])->toBe('2026-03-04T10:30:00Z')
        ->and($result['error'])->toBeNull();

    $this->server->refresh();
    expect($this->server->is_connected)->toBeTrue();
});

test('ping returns failure on connection error', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/ping' => Http::response(null, 500),
    ]);

    $api = new CloudApiService($this->server);
    $result = $api->ping();

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->not->toBeNull();

    $this->server->refresh();
    expect($this->server->is_connected)->toBeFalse();
});

test('fetchBranches returns list of branches', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/branches' => Http::response([
            'branches' => [
                ['id' => 1, 'name' => 'Main Factory', 'code' => 'MF', 'department_count' => 3, 'employee_count' => 120],
                ['id' => 2, 'name' => 'Branch Office', 'code' => 'BO', 'department_count' => 2, 'employee_count' => 25],
            ],
        ]),
    ]);

    $api = new CloudApiService($this->server);
    $result = $api->fetchBranches();

    expect($result['success'])->toBeTrue()
        ->and($result['branches'])->toHaveCount(2)
        ->and($result['branches'][0]['name'])->toBe('Main Factory');
});

test('fetchBranches returns failure on error', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/branches' => Http::response(null, 401),
    ]);

    $api = new CloudApiService($this->server);
    $result = $api->fetchBranches();

    expect($result['success'])->toBeFalse()
        ->and($result['branches'])->toBeEmpty();
});

test('fetchEmployees returns employees for configured branch', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/employees*' => Http::response([
            'employees' => [
                [
                    'id' => 42,
                    'employee_code' => 'EMP-042',
                    'name' => 'Rahim Ahmed',
                    'department' => 'Production',
                    'designation' => 'Machine Operator',
                    'shift' => ['name' => 'Morning', 'start_time' => '08:00', 'end_time' => '17:00'],
                    'is_active' => true,
                    'updated_at' => '2026-03-01T12:00:00Z',
                ],
            ],
            'total' => 1,
            'branch' => ['id' => 1, 'name' => 'Main Factory'],
        ]),
    ]);

    $api = new CloudApiService($this->server);
    $result = $api->fetchEmployees();

    expect($result['success'])->toBeTrue()
        ->and($result['employees'])->toHaveCount(1)
        ->and($result['employees'][0]['employee_code'])->toBe('EMP-042')
        ->and($result['total'])->toBe(1);
});

test('fetchEmployees supports updated_since parameter', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/employees*' => Http::response([
            'employees' => [],
            'total' => 0,
            'branch' => ['id' => 1, 'name' => 'Main Factory'],
        ]),
    ]);

    $api = new CloudApiService($this->server);
    $api->fetchEmployees('2026-03-01T00:00:00Z');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'updated_since=');
    });
});

test('fetchEmployees fails when no branch is configured', function () {
    $noBranchServer = CloudServer::factory()->create([
        'api_base_url' => 'https://lavloss.test',
        'api_key' => 'test-token',
        'branch_id' => null,
    ]);

    $api = new CloudApiService($noBranchServer);
    $result = $api->fetchEmployees();

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('No branch configured');
});

test('uploadAttendance sends records and returns result', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/attendance/bulk' => Http::response([
            'accepted' => 2,
            'rejected' => 0,
            'errors' => [],
        ]),
    ]);

    $api = new CloudApiService($this->server);
    $result = $api->uploadAttendance([
        ['employee_code' => 'EMP-001', 'date' => '2026-03-04', 'check_in' => '08:00', 'check_out' => '17:00', 'source' => 'zpush'],
        ['employee_code' => 'EMP-002', 'date' => '2026-03-04', 'check_in' => '08:15', 'check_out' => '17:20', 'source' => 'zpush'],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['accepted'])->toBe(2)
        ->and($result['rejected'])->toBe(0)
        ->and($result['errors'])->toBeEmpty();

    $this->server->refresh();
    expect($this->server->sync_failure_count)->toBe(0);
});

test('uploadAttendance handles partial rejection', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/attendance/bulk' => Http::response([
            'accepted' => 1,
            'rejected' => 1,
            'errors' => [
                ['employee_code' => 'EMP-999', 'date' => '2026-03-04', 'error' => 'Employee not found'],
            ],
        ]),
    ]);

    $api = new CloudApiService($this->server);
    $result = $api->uploadAttendance([
        ['employee_code' => 'EMP-001', 'date' => '2026-03-04', 'check_in' => '08:00', 'check_out' => '17:00', 'source' => 'zpush'],
        ['employee_code' => 'EMP-999', 'date' => '2026-03-04', 'check_in' => '08:00', 'check_out' => '17:00', 'source' => 'zpush'],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['accepted'])->toBe(1)
        ->and($result['rejected'])->toBe(1)
        ->and($result['errors'])->toHaveCount(1);
});

test('uploadAttendance handles connection failure', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/attendance/bulk' => Http::response(null, 500),
    ]);

    $api = new CloudApiService($this->server);
    $result = $api->uploadAttendance([
        ['employee_code' => 'EMP-001', 'date' => '2026-03-04', 'check_in' => '08:00', 'check_out' => '17:00', 'source' => 'zpush'],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->not->toBeNull();

    $this->server->refresh();
    expect($this->server->sync_failure_count)->toBe(1);
});

test('uploadAttendance returns success for empty records', function () {
    $api = new CloudApiService($this->server);
    $result = $api->uploadAttendance([]);

    expect($result['success'])->toBeTrue()
        ->and($result['accepted'])->toBe(0);
});

test('isReachable returns true when ping succeeds', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/ping' => Http::response([
            'success' => true,
            'server_time' => now()->toIso8601String(),
        ]),
    ]);

    $api = new CloudApiService($this->server);

    expect($api->isReachable())->toBeTrue();
});

test('isReachable returns false when ping fails', function () {
    Http::fake([
        'lavloss.test/api/v1/zpush/ping' => Http::response(null, 500),
    ]);

    $api = new CloudApiService($this->server);

    expect($api->isReachable())->toBeFalse();
});

test('forActiveServer creates instance from active server', function () {
    $api = CloudApiService::forActiveServer();

    expect($api->getServer()->id)->toBe($this->server->id);
});

test('forActiveServer throws when no active server exists', function () {
    $this->server->update(['is_active' => false]);

    CloudApiService::forActiveServer();
})->throws(RuntimeException::class, 'No active cloud server configured.');

test('sends authorization header with api key', function () {
    Http::fake([
        'lavloss.test/*' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
    ]);

    $api = new CloudApiService($this->server);
    $api->ping();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization')
            && str_contains($request->header('Authorization')[0], 'Bearer')
            && $request->hasHeader('X-ZPush-Version');
    });
});
