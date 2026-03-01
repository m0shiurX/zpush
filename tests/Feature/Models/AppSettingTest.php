<?php

use App\Models\AppSetting;

test('set and get a string value', function () {
    AppSetting::set('app_name', 'ZPush');

    expect(AppSetting::get('app_name'))->toBe('ZPush');
});

test('get returns default when key missing', function () {
    expect(AppSetting::get('nonexistent', 'default'))->toBe('default');
});

test('set overwrites existing value', function () {
    AppSetting::set('poll_interval', '30');
    AppSetting::set('poll_interval', '60');

    expect(AppSetting::get('poll_interval'))->toBe(60);
});

test('isTrue returns boolean correctly', function () {
    AppSetting::set('feature_enabled', 'true');
    AppSetting::set('feature_disabled', 'false');
    AppSetting::set('feature_one', '1');

    expect(AppSetting::isTrue('feature_enabled'))->toBeTrue()
        ->and(AppSetting::isTrue('feature_disabled'))->toBeFalse()
        ->and(AppSetting::isTrue('feature_one'))->toBeTrue()
        ->and(AppSetting::isTrue('nonexistent'))->toBeFalse();
});

test('set and get JSON value', function () {
    AppSetting::set('config', ['key' => 'value']);

    $value = AppSetting::get('config');

    expect($value)->toBe(['key' => 'value']);
});

test('allSettings returns all settings', function () {
    AppSetting::set('key1', 'value1');
    AppSetting::set('key2', 'value2');

    $all = AppSetting::allSettings();

    expect($all)->toHaveKey('key1', 'value1')
        ->toHaveKey('key2', 'value2');
});
