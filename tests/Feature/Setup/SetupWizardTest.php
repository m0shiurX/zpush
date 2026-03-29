<?php

use App\Models\AppSetting;
use App\Models\CloudServer;
use App\Models\DeviceConfig;
use App\Models\User;
use App\Services\DeviceService;
use Illuminate\Support\Facades\Http;

// ==========================================
// Welcome Page
// ==========================================

test('welcome page renders when setup is not complete', function () {
    $response = $this->get(route('setup.wizard'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('setup/Wizard'));
});

test('welcome page redirects to dashboard when setup is complete', function () {
    AppSetting::set('setup_completed', true);

    $response = $this->get(route('setup.wizard'));

    $response->assertRedirect(route('dashboard'));
});

// ==========================================
// Device Connect Page
// ==========================================

test('device page renders when setup is not complete', function () {
    $response = $this->get(route('setup.device'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('setup/DeviceConnect'));
});

test('device page passes existing device config', function () {
    $device = DeviceConfig::factory()->create();

    $response = $this->get(route('setup.device'));

    $response->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('setup/DeviceConnect')
                ->has('device')
                ->where('device.ip_address', $device->ip_address)
        );
});

test('device page redirects to dashboard when setup is complete', function () {
    AppSetting::set('setup_completed', true);

    $response = $this->get(route('setup.device'));

    $response->assertRedirect(route('dashboard'));
});

// ==========================================
// Store Device
// ==========================================

test('storing device creates a new device config', function () {
    $response = $this->post(route('setup.device.store'), [
        'name' => 'Office K40',
        'ip_address' => '192.168.1.100',
        'port' => 4370,
        'protocol' => 'tcp',
    ]);

    $response->assertRedirect(route('setup.cloud'));

    expect(DeviceConfig::count())->toBe(1)
        ->and(DeviceConfig::first())
        ->name->toBe('Office K40')
        ->ip_address->toBe('192.168.1.100')
        ->port->toBe(4370)
        ->protocol->toBe('tcp');
});

test('storing device with same IP updates existing record', function () {
    DeviceConfig::factory()->create([
        'name' => 'Old Name',
        'ip_address' => '192.168.1.100',
        'port' => 4370,
        'protocol' => 'udp',
    ]);

    $response = $this->post(route('setup.device.store'), [
        'name' => 'New Name',
        'ip_address' => '192.168.1.100',
        'port' => 4370,
        'protocol' => 'tcp',
    ]);

    $response->assertRedirect(route('setup.cloud'));

    expect(DeviceConfig::count())->toBe(1)
        ->and(DeviceConfig::first())
        ->name->toBe('New Name')
        ->protocol->toBe('tcp');
});

test('storing device validates required fields', function () {
    $response = $this->post(route('setup.device.store'), []);

    $response->assertSessionHasErrors(['name', 'ip_address', 'port', 'protocol']);
});

test('storing device validates ip address format', function () {
    $response = $this->post(route('setup.device.store'), [
        'name' => 'K40',
        'ip_address' => 'not-an-ip',
        'port' => 4370,
        'protocol' => 'tcp',
    ]);

    $response->assertSessionHasErrors('ip_address');
});

test('storing device validates protocol is tcp or udp', function () {
    $response = $this->post(route('setup.device.store'), [
        'name' => 'K40',
        'ip_address' => '192.168.1.100',
        'port' => 4370,
        'protocol' => 'invalid',
    ]);

    $response->assertSessionHasErrors('protocol');
});

// ==========================================
// Test Device Connection
// ==========================================

test('test device connection returns success on valid device', function () {
    $this->mock(DeviceService::class, function ($mock) {
        $mock->shouldReceive('testConnection')
            ->once()
            ->andReturn([
                'success' => true,
                'serial_number' => 'ABC123',
                'device_name' => 'K40',
                'firmware' => 'Ver 6.60',
                'error' => null,
            ]);
    });

    $response = $this->postJson(route('setup.device.test'), [
        'ip_address' => '192.168.1.100',
        'port' => 4370,
        'protocol' => 'tcp',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'serial_number' => 'ABC123',
        ]);
});

test('test device connection validates input', function () {
    $response = $this->postJson(route('setup.device.test'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['ip_address', 'port', 'protocol']);
});

// ==========================================
// Cloud Config Page
// ==========================================

test('cloud page renders when setup is not complete', function () {
    $response = $this->get(route('setup.cloud'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->component('setup/CloudConfig'));
});

test('cloud page passes existing cloud server config', function () {
    $cloud = CloudServer::factory()->create([
        'api_base_url' => 'https://api.example.com',
        'api_key' => 'test-key-123',
    ]);

    $response = $this->get(route('setup.cloud'));

    $response->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('setup/CloudConfig')
                ->has('cloudServer')
                ->where('cloudServer.api_base_url', 'https://api.example.com')
        );
});

test('cloud page redirects to dashboard when setup is complete', function () {
    AppSetting::set('setup_completed', true);

    $response = $this->get(route('setup.cloud'));

    $response->assertRedirect(route('dashboard'));
});

// ==========================================
// Store Cloud Server
// ==========================================

test('storing cloud server creates a new record', function () {
    $response = $this->post(route('setup.cloud.store'), [
        'api_base_url' => 'https://api.example.com',
        'api_key' => 'my-secret-key',
    ]);

    $response->assertRedirect(route('setup.complete'));

    expect(CloudServer::count())->toBe(1)
        ->and(CloudServer::first())
        ->api_base_url->toBe('https://api.example.com');
});

test('storing cloud server validates required fields', function () {
    $response = $this->post(route('setup.cloud.store'), []);

    $response->assertSessionHasErrors(['api_base_url', 'api_key']);
});

test('storing cloud server validates url format', function () {
    $response = $this->post(route('setup.cloud.store'), [
        'api_base_url' => 'not-a-url',
        'api_key' => 'key',
    ]);

    $response->assertSessionHasErrors('api_base_url');
});

test('storing cloud server saves branch info', function () {
    $response = $this->post(route('setup.cloud.store'), [
        'api_base_url' => 'https://api.example.com',
        'api_key' => 'my-secret-key',
        'branch_id' => 5,
        'branch_name' => 'Main Branch',
    ]);

    $response->assertRedirect(route('setup.complete'));

    $server = CloudServer::first();
    expect($server->branch_id)->toBe(5)
        ->and($server->branch_name)->toBe('Main Branch');

    expect(AppSetting::get('cloud_branch_id'))->toBe(5)
        ->and(AppSetting::get('cloud_branch_name'))->toBe('Main Branch');
});

// ==========================================
// Test Cloud Connection
// ==========================================

test('test cloud connection returns success when API is reachable', function () {
    Http::fake([
        '*/api/v1/zpush/ping' => Http::response([
            'success' => true,
            'server_time' => now()->toIso8601String(),
        ]),
    ]);

    $response = $this->postJson(route('setup.cloud.test'), [
        'api_base_url' => 'https://api.example.com',
        'api_key' => 'test-key',
    ]);

    $response->assertOk()
        ->assertJson(['success' => true]);
});

test('test cloud connection returns failure when API is unreachable', function () {
    Http::fake([
        '*/api/v1/zpush/ping' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $response = $this->postJson(route('setup.cloud.test'), [
        'api_base_url' => 'https://api.example.com',
        'api_key' => 'bad-key',
    ]);

    $response->assertOk()
        ->assertJson(['success' => false]);
});

test('test cloud connection validates input', function () {
    $response = $this->postJson(route('setup.cloud.test'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['api_base_url', 'api_key']);
});

// ==========================================
// Fetch Branches
// ==========================================

test('fetch branches returns list from cloud server', function () {
    Http::fake([
        '*/api/v1/zpush/branches' => Http::response([
            'success' => true,
            'branches' => [
                ['id' => 1, 'name' => 'Main', 'department_count' => 5, 'employee_count' => 50],
                ['id' => 2, 'name' => 'Branch 2', 'department_count' => 3, 'employee_count' => 20],
            ],
        ]),
    ]);

    $response = $this->postJson(route('setup.cloud.branches'), [
        'api_base_url' => 'https://api.example.com',
        'api_key' => 'test-key',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'branches' => [
                ['id' => 1, 'name' => 'Main'],
            ],
        ]);
});

test('fetch branches returns error on failure', function () {
    Http::fake([
        '*/api/v1/zpush/branches' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $response = $this->postJson(route('setup.cloud.branches'), [
        'api_base_url' => 'https://api.example.com',
        'api_key' => 'bad-key',
    ]);

    $response->assertOk()
        ->assertJson(['success' => false]);
});

// ==========================================
// Skip Cloud
// ==========================================

test('skipping cloud redirects to complete', function () {
    $response = $this->post(route('setup.cloud.skip'));

    $response->assertRedirect(route('setup.complete'));
});

// ==========================================
// Complete Page
// ==========================================

test('complete page renders with device and cloud summary', function () {
    $device = DeviceConfig::factory()->create();
    $cloud = CloudServer::factory()->create([
        'api_base_url' => 'https://api.example.com',
        'api_key' => 'key',
    ]);

    $response = $this->get(route('setup.complete'));

    $response->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('setup/Complete')
                ->has('device')
                ->has('cloudServer')
        );
});

test('complete page renders without cloud server when skipped', function () {
    DeviceConfig::factory()->create();

    $response = $this->get(route('setup.complete'));

    $response->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('setup/Complete')
                ->has('device')
                ->where('cloudServer', null)
        );
});

test('complete page redirects to dashboard when setup is already complete', function () {
    AppSetting::set('setup_completed', true);

    $response = $this->get(route('setup.complete'));

    $response->assertRedirect(route('dashboard'));
});

// ==========================================
// Finalize Setup
// ==========================================

test('finalizing setup sets app setting and redirects to dashboard', function () {
    $response = $this->post(route('setup.finalize'));

    $response->assertRedirect(route('dashboard'));

    expect(AppSetting::isTrue('setup_completed'))->toBeTrue();
});

// ==========================================
// EnsureSetupComplete Middleware
// ==========================================

test('dashboard redirects to setup when setup is not complete', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('setup.wizard'));
});

test('dashboard is accessible when setup is complete', function () {
    AppSetting::set('setup_completed', true);

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
});
