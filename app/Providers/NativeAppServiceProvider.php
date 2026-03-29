<?php

namespace App\Providers;

use App\Models\DeviceConfig;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Window::open();

        $this->startDeviceListeners();
    }

    /**
     * Start a devices:listen child process for each active realtime device.
     */
    protected function startDeviceListeners(): void
    {
        $devices = DeviceConfig::query()
            ->active()
            ->where('poll_method', 'realtime')
            ->get();

        foreach ($devices as $device) {
            ChildProcess::artisan(
                "devices:listen --device={$device->id}",
                "device-listener-{$device->id}",
                persistent: true,
            );

            Cache::put("device:{$device->id}:listening", true, 90);
        }
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        $caPath = match (true) {
            is_file($herd = $_SERVER['HOME'] . '/Library/Application Support/Herd/config/php/cacert.pem') => $herd,
            is_file('/etc/ssl/cert.pem') => '/etc/ssl/cert.pem',
            default => '',
        };

        return array_filter([
            'curl.cainfo' => $caPath,
            'openssl.cafile' => $caPath,
        ]);
    }
}
