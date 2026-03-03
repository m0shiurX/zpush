<?php

namespace App\Services;

use App\Models\CloudServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConnectivityService
{
    /**
     * Check if the internet is reachable.
     */
    public function hasInternet(): bool
    {
        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->get('https://httpbin.org/status/200');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if the active cloud server is reachable and authenticated.
     *
     * @return array{online: bool, cloud_reachable: bool, cloud_authenticated: bool, error: string|null}
     */
    public function checkAll(): array
    {
        $online = $this->hasInternet();

        if (! $online) {
            return [
                'online' => false,
                'cloud_reachable' => false,
                'cloud_authenticated' => false,
                'error' => 'No internet connection.',
            ];
        }

        $server = CloudServer::query()->active()->first();

        if (! $server) {
            return [
                'online' => true,
                'cloud_reachable' => false,
                'cloud_authenticated' => false,
                'error' => 'No cloud server configured.',
            ];
        }

        try {
            $api = new CloudApiService($server);
            $ping = $api->ping();

            return [
                'online' => true,
                'cloud_reachable' => true,
                'cloud_authenticated' => $ping['success'],
                'error' => $ping['error'],
            ];
        } catch (\Throwable $e) {
            Log::warning('ConnectivityService: Cloud check failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'online' => true,
                'cloud_reachable' => false,
                'cloud_authenticated' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Quick check — is the cloud available for syncing?
     */
    public function isCloudAvailable(): bool
    {
        $status = $this->checkAll();

        return $status['cloud_authenticated'];
    }
}
