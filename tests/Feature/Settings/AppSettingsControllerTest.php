<?php

use App\Models\AppSetting;
use App\Models\User;

beforeEach(function () {
    AppSetting::set('setup_completed', true);
});

// ==========================================
// Index
// ==========================================

test('guests cannot access app settings', function () {
    $this->get(route('settings.app'))->assertRedirect(route('login'));
});

test('authenticated users can view app settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.app'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('settings/AppSettings')
                ->has('settings')
                ->has('timezones')
        );
});

test('app settings page shows default values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.app'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('settings.sync_interval', 30)
                ->where('settings.timezone', 'Asia/Dhaka')
                ->where('settings.log_retention_days', 90)
                ->where('settings.auto_sync_enabled', true)
                ->where('settings.poll_interval', 5)
        );
});

test('app settings page shows saved values', function () {
    $user = User::factory()->create();

    AppSetting::set('sync_interval', 15);
    AppSetting::set('timezone', 'America/New_York');
    AppSetting::set('log_retention_days', 60);
    AppSetting::set('auto_sync_enabled', false);
    AppSetting::set('poll_interval', 10);

    $this->actingAs($user)
        ->get(route('settings.app'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('settings.sync_interval', 15)
                ->where('settings.timezone', 'America/New_York')
                ->where('settings.log_retention_days', 60)
                ->where('settings.poll_interval', 10)
        );
});

// ==========================================
// Update
// ==========================================

test('guests cannot update app settings', function () {
    $this->put(route('settings.app.update'))->assertRedirect(route('login'));
});

test('authenticated users can update app settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.app.update'), [
            'sync_interval' => 15,
            'timezone' => 'America/New_York',
            'log_retention_days' => 60,
            'auto_sync_enabled' => false,
            'poll_interval' => 10,
        ])
        ->assertRedirect(route('settings.app'));

    expect(AppSetting::get('sync_interval'))->toBe(15);
    expect(AppSetting::get('timezone'))->toBe('America/New_York');
    expect(AppSetting::get('log_retention_days'))->toBe(60);
    expect(AppSetting::get('poll_interval'))->toBe(10);
});

test('update validates sync interval minimum', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.app.update'), [
            'sync_interval' => 0,
            'timezone' => 'Asia/Dhaka',
            'log_retention_days' => 90,
            'auto_sync_enabled' => true,
            'poll_interval' => 5,
        ])
        ->assertSessionHasErrors('sync_interval');
});

test('update validates sync interval maximum', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.app.update'), [
            'sync_interval' => 9999,
            'timezone' => 'Asia/Dhaka',
            'log_retention_days' => 90,
            'auto_sync_enabled' => true,
            'poll_interval' => 5,
        ])
        ->assertSessionHasErrors('sync_interval');
});

test('update validates timezone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.app.update'), [
            'sync_interval' => 30,
            'timezone' => 'Invalid/Zone',
            'log_retention_days' => 90,
            'auto_sync_enabled' => true,
            'poll_interval' => 5,
        ])
        ->assertSessionHasErrors('timezone');
});

test('update validates log retention range', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.app.update'), [
            'sync_interval' => 30,
            'timezone' => 'Asia/Dhaka',
            'log_retention_days' => 500,
            'auto_sync_enabled' => true,
            'poll_interval' => 5,
        ])
        ->assertSessionHasErrors('log_retention_days');
});

test('update validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.app.update'), [])
        ->assertSessionHasErrors(['sync_interval', 'timezone', 'log_retention_days', 'auto_sync_enabled', 'poll_interval']);
});
