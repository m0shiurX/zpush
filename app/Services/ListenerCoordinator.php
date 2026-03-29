<?php

namespace App\Services;

use App\Models\DeviceConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\ChildProcess;

class ListenerCoordinator
{
    /**
     * Get the NativePHP child process alias for a device listener.
     */
    public function listenerAlias(DeviceConfig $device): string
    {
        return "device-listener-{$device->id}";
    }

    /**
     * Stop the listener for a device so the socket is freed.
     */
    public function stopListener(DeviceConfig $device): void
    {
        $alias = $this->listenerAlias($device);

        try {
            ChildProcess::stop($alias);
        } catch (\Throwable $e) {
            Log::debug("ListenerCoordinator: Could not stop [{$alias}]: {$e->getMessage()}");
        }

        Cache::forget("device:{$device->id}:listening");

        // Allow time for the TCP socket to fully release
        sleep(2);
    }

    /**
     * Start (or restart) the listener for a device.
     */
    public function startListener(DeviceConfig $device): void
    {
        if (! $device->is_active || ! $device->isRealtime()) {
            return;
        }

        $alias = $this->listenerAlias($device);

        try {
            ChildProcess::artisan(
                "devices:listen --device={$device->id}",
                $alias,
                persistent: true,
            );

            // Set cache immediately so isListening() returns true before the first listen cycle
            Cache::put("device:{$device->id}:listening", true, 90);
        } catch (\Throwable $e) {
            Log::warning("ListenerCoordinator: Could not start [{$alias}]: {$e->getMessage()}");
        }
    }

    /**
     * Pause the listener, execute a callback, then resume the listener.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withPausedListener(DeviceConfig $device, callable $callback): mixed
    {
        $wasListening = $device->isRealtime() && $device->isListening();

        if ($wasListening) {
            Log::info("ListenerCoordinator: Pausing listener for [{$device->name}] to perform sync.");
            $this->stopListener($device);
        }

        try {
            return $callback();
        } finally {
            if ($wasListening && $device->is_active && $device->isRealtime()) {
                Log::info("ListenerCoordinator: Resuming listener for [{$device->name}].");
                $this->startListener($device);
            }
        }
    }
}
