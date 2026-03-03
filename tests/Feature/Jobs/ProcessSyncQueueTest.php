<?php

use App\Enums\SyncStatus;
use App\Jobs\ProcessSyncQueue;
use App\Models\CloudServer;
use App\Models\SyncQueue;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->server = CloudServer::factory()
        ->connected()
        ->withBranch()
        ->create();
});

test('processes pending attendance uploads from the queue', function () {
    SyncQueue::factory()->cloudUp()->create([
        'entity_type' => 'attendance',
        'payload' => ['employee_code' => 'EMP-100', 'date' => '2026-03-04', 'check_in' => '08:00', 'check_out' => '17:00', 'source' => 'zpush'],
        'scheduled_at' => now()->subMinute(),
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

    $job = new ProcessSyncQueue;
    app()->call([$job, 'handle']);

    expect(SyncQueue::first()->status)->toBe(SyncStatus::Completed);
});

test('marks items as failed when upload fails', function () {
    SyncQueue::factory()->cloudUp()->create([
        'entity_type' => 'attendance',
        'payload' => ['employee_code' => 'EMP-100', 'date' => '2026-03-04', 'check_in' => '08:00', 'check_out' => '17:00', 'source' => 'zpush'],
        'scheduled_at' => now()->subMinute(),
    ]);

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['success' => true, 'server_time' => now()->toIso8601String()]),
        '*/api/v1/zpush/attendance/bulk' => Http::response([
            'success' => false,
            'error' => 'Server error',
        ], 500),
    ]);

    $job = new ProcessSyncQueue;
    app()->call([$job, 'handle']);

    $item = SyncQueue::first();
    expect($item->last_error)->not->toBeNull()
        ->and($item->attempts)->toBeGreaterThan(0);
});

test('skips when queue is empty', function () {
    Http::fake();

    $job = new ProcessSyncQueue;
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
});

test('skips when cloud is not reachable', function () {
    SyncQueue::factory()->cloudUp()->create([
        'entity_type' => 'attendance',
        'payload' => ['employee_code' => 'EMP-100'],
        'scheduled_at' => now()->subMinute(),
    ]);

    Http::fake([
        '*/api/v1/zpush/ping' => Http::response([], 500),
    ]);

    $job = new ProcessSyncQueue;
    app()->call([$job, 'handle']);

    expect(SyncQueue::first()->status)->toBe(SyncStatus::Pending);
});

test('does not process future scheduled items', function () {
    SyncQueue::factory()->cloudUp()->create([
        'entity_type' => 'attendance',
        'payload' => ['employee_code' => 'EMP-100'],
        'scheduled_at' => now()->addHour(),
    ]);

    Http::fake();

    $job = new ProcessSyncQueue;
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
});
