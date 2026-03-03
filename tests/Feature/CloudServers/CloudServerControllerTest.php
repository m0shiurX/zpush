<?php

use App\Models\AppSetting;
use App\Models\CloudServer;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    AppSetting::set('setup_completed', true);
});

// ==========================================
// Index
// ==========================================

test('guests cannot access cloud servers index', function () {
    $this->get(route('cloud-servers.index'))->assertRedirect(route('login'));
});

test('cloud servers index renders without server', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cloud-servers.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('cloud-servers/Index')
                ->where('cloudServer', null)
                ->has('recentSyncLogs', 0)
        );
});

test('cloud servers index renders with server and logs', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->connected()->create();
    SyncLog::factory()->count(5)->create(['cloud_server_id' => $server->id]);

    $this->actingAs($user)
        ->get(route('cloud-servers.index'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('cloud-servers/Index')
                ->where('cloudServer.id', $server->id)
                ->has('recentSyncLogs', 5)
        );
});

// ==========================================
// Store
// ==========================================

test('cloud server can be created', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cloud-servers.store'), [
            'api_base_url' => 'https://api.example.com',
            'api_key' => 'test-api-key-123',
        ])
        ->assertRedirect(route('cloud-servers.index'));

    $this->assertDatabaseHas('cloud_servers', [
        'api_base_url' => 'https://api.example.com',
    ]);
});

test('cloud server store validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cloud-servers.store'), [])
        ->assertSessionHasErrors(['api_base_url', 'api_key']);
});

test('cloud server store saves branch info to app settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cloud-servers.store'), [
            'api_base_url' => 'https://api.example.com',
            'api_key' => 'test-key',
            'branch_id' => 5,
            'branch_name' => 'Main Branch',
        ])
        ->assertRedirect(route('cloud-servers.index'));

    expect(AppSetting::get('cloud_branch_id'))->toBe(5);
    expect(AppSetting::get('cloud_branch_name'))->toBe('Main Branch');
});

// ==========================================
// Test Connection
// ==========================================

test('test connection returns success', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();

    Http::fake([
        '*zpush/ping' => Http::response([
            'success' => true,
            'server_time' => '2024-01-01T00:00:00Z',
        ]),
    ]);

    $this->actingAs($user)
        ->postJson(route('cloud-servers.test', $server))
        ->assertOk()
        ->assertJson(['success' => true]);
});

// ==========================================
// Sync Actions
// ==========================================

test('sync attendance dispatches job', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();

    $this->actingAs($user)
        ->postJson(route('cloud-servers.sync-attendance', $server))
        ->assertOk()
        ->assertJson(['success' => true]);
});

test('sync employees dispatches job', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();

    $this->actingAs($user)
        ->postJson(route('cloud-servers.sync-employees', $server))
        ->assertOk()
        ->assertJson(['success' => true]);
});

test('sync to device dispatches job', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->create();

    $this->actingAs($user)
        ->postJson(route('cloud-servers.sync-to-device', $server))
        ->assertOk()
        ->assertJson(['success' => true]);
});

// ==========================================
// Destroy
// ==========================================

test('cloud server can be deleted', function () {
    $user = User::factory()->create();
    $server = CloudServer::factory()->withBranch()->create();
    AppSetting::set('cloud_branch_id', 5);
    AppSetting::set('cloud_branch_name', 'Test Branch');

    $this->actingAs($user)
        ->delete(route('cloud-servers.destroy', $server))
        ->assertRedirect(route('cloud-servers.index'));

    $this->assertDatabaseMissing('cloud_servers', ['id' => $server->id]);
    expect(AppSetting::get('cloud_branch_id'))->toBeNull();
    expect(AppSetting::get('cloud_branch_name'))->toBeNull();
});
