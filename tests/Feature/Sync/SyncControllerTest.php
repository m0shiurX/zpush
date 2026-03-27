<?php

use App\Models\AppSetting;
use App\Models\AttendanceLog;
use App\Models\CloudServer;
use App\Models\SyncLog;
use App\Models\SyncQueue;
use App\Models\User;

beforeEach(function () {
    AppSetting::set('setup_completed', true);
});

// ==========================================
// Index
// ==========================================

test('guests cannot access sync monitor', function () {
    $this->get(route('sync.index'))->assertRedirect(route('login'));
});

test('sync monitor renders without cloud server', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('sync.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('sync/Monitor')
                ->where('cloudServer', null)
                ->has('stats')
                ->has('recentLogs', 0)
        );
});

test('sync monitor renders with stats and logs', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->connected()->create();

    AttendanceLog::factory()->count(3)->create(['cloud_synced' => false]);
    AttendanceLog::factory()->count(7)->create(['cloud_synced' => true]);
    SyncQueue::factory()->count(2)->create(['status' => 'pending']);
    SyncQueue::factory()->count(1)->create(['status' => 'failed']);

    SyncLog::factory()->count(5)->create(['cloud_server_id' => $server->id]);

    $this->actingAs($user)
        ->get(route('sync.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('sync/Monitor')
                ->where('cloudServer.id', $server->id)
                ->where('stats.pending_attendance', 3)
                ->where('stats.synced_attendance', 7)
                ->where('stats.queue_pending', 2)
                ->where('stats.queue_failed', 1)
                ->has('recentLogs', 5)
        );
});

test('sync monitor logs include relations', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();
    SyncLog::factory()->create(['cloud_server_id' => $server->id]);

    $this->actingAs($user)
        ->get(route('sync.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('sync/Monitor')
                ->has('recentLogs', 1)
                ->has('recentLogs.0.cloud_server')
        );
});

// ==========================================
// Trigger Sync
// ==========================================

test('guests cannot trigger sync', function () {
    $this->postJson(route('sync.trigger'))->assertUnauthorized();
});

test('trigger sync dispatches jobs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('sync.trigger'))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Sync completed.',
        ]);
});

// ==========================================
// Filtering
// ==========================================

test('sync monitor filters by direction', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();

    SyncLog::factory()->create([
        'cloud_server_id' => $server->id,
        'direction' => 'cloud_up',
    ]);
    SyncLog::factory()->create([
        'cloud_server_id' => $server->id,
        'direction' => 'cloud_down',
    ]);

    $this->actingAs($user)
        ->get(route('sync.index', ['direction' => 'cloud_up']))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('recentLogs', 1)
                ->where('recentLogs.0.direction', 'cloud_up')
                ->has('filters')
                ->where('filters.direction', 'cloud_up')
        );
});

test('sync monitor filters by status', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();

    SyncLog::factory()->create([
        'cloud_server_id' => $server->id,
        'status' => 'completed',
    ]);
    SyncLog::factory()->count(2)->create([
        'cloud_server_id' => $server->id,
        'status' => 'failed',
    ]);

    $this->actingAs($user)
        ->get(route('sync.index', ['status' => 'failed']))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('recentLogs', 2)
                ->where('filters.status', 'failed')
        );
});

test('sync monitor filters by entity type', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();

    SyncLog::factory()->create([
        'cloud_server_id' => $server->id,
        'entity_type' => 'attendance',
    ]);
    SyncLog::factory()->create([
        'cloud_server_id' => $server->id,
        'entity_type' => 'employee',
    ]);

    $this->actingAs($user)
        ->get(route('sync.index', ['entity_type' => 'attendance']))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('recentLogs', 1)
                ->where('recentLogs.0.entity_type', 'attendance')
        );
});

test('sync monitor filters by date range', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();

    SyncLog::factory()->create([
        'cloud_server_id' => $server->id,
        'started_at' => now()->subDays(10),
    ]);
    SyncLog::factory()->create([
        'cloud_server_id' => $server->id,
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('sync.index', ['date_from' => now()->toDateString()]))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('recentLogs', 1)
        );
});
