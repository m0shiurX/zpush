<?php

use App\Models\DeviceConfig;

test('it fails when no target options are provided', function () {
    $this->artisan('devices:test')
        ->expectsOutput('Provide either --device=ID or --ip=ADDRESS.')
        ->assertFailed();
});

test('it fails when device id does not exist', function () {
    $this->artisan('devices:test --device=99999')
        ->expectsOutput('Device with ID 99999 was not found in device_configs.')
        ->assertFailed();
});

test('it accepts an existing device option and attempts handshake', function () {
    $device = DeviceConfig::factory()->create([
        'ip_address' => '127.0.0.1',
        'port' => 4370,
    ]);

    $this->artisan("devices:test --device={$device->id} --timeout=1")
        ->expectsOutputToContain("Testing device [{$device->name}] at {$device->ip_address}:{$device->port}")
        ->assertFailed();
});
