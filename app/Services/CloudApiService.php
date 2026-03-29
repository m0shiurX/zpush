<?php

namespace App\Services;

use App\Models\CloudServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudApiService
{
    private CloudServer $server;

    private PendingRequest $http;

    /**
     * Create a new CloudApiService for the given server.
     */
    public function __construct(CloudServer $server)
    {
        $this->server = $server;
        $this->http = $this->buildClient();
    }

    /**
     * Create a CloudApiService for the active cloud server.
     *
     * @throws \RuntimeException
     */
    public static function forActiveServer(): static
    {
        $server = CloudServer::query()->active()->first();

        if (! $server) {
            throw new \RuntimeException('No active cloud server configured.');
        }

        return new static($server);
    }

    /**
     * Validate the API token and return server info.
     *
     * @return array{success: bool, server_time: string|null, error: string|null}
     */
    public function ping(): array
    {
        try {
            $response = $this->http->post('/api/v1/zpush/ping');
            $response->throw();

            $data = $response->json();

            $this->server->update(['is_connected' => true]);

            return [
                'success' => $data['success'] ?? true,
                'server_time' => $data['server_time'] ?? null,
                'error' => null,
            ];
        } catch (ConnectionException $e) {
            $this->server->update(['is_connected' => false]);

            return [
                'success' => false,
                'server_time' => null,
                'error' => 'Connection failed: '.$e->getMessage(),
            ];
        } catch (RequestException $e) {
            $this->server->update(['is_connected' => false]);

            return [
                'success' => false,
                'server_time' => null,
                'error' => 'HTTP '.$e->response->status().': '.($e->response->json('message') ?? $e->getMessage()),
            ];
        }
    }

    /**
     * Fetch available branches for setup wizard selection.
     *
     * @return array{success: bool, branches: array<int, array{id: int, name: string, code: string, department_count: int, employee_count: int}>, error: string|null}
     */
    public function fetchBranches(): array
    {
        try {
            $response = $this->http->get('/api/v1/zpush/branches');
            $response->throw();

            return [
                'success' => true,
                'branches' => $response->json('branches', []),
                'error' => null,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'branches' => [],
                'error' => 'Connection failed: '.$e->getMessage(),
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'branches' => [],
                'error' => 'HTTP '.$e->response->status().': '.($e->response->json('message') ?? $e->getMessage()),
            ];
        }
    }

    /**
     * Fetch employees for the configured branch.
     *
     * @return array{success: bool, employees: array, total: int, error: string|null}
     */
    public function fetchEmployees(?string $updatedSince = null): array
    {
        try {
            $params = [];

            if ($this->server->branch_id) {
                $params['branch_id'] = $this->server->branch_id;
            }

            if ($updatedSince) {
                $params['updated_since'] = $updatedSince;
            }

            $response = $this->http->get('/api/v1/zpush/employees', $params);
            $response->throw();

            return [
                'success' => true,
                'employees' => $response->json('employees', []),
                'total' => $response->json('total', 0),
                'error' => null,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'employees' => [],
                'total' => 0,
                'error' => 'Connection failed: '.$e->getMessage(),
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'employees' => [],
                'total' => 0,
                'error' => 'HTTP '.$e->response->status().': '.($e->response->json('message') ?? $e->getMessage()),
            ];
        }
    }

    /**
     * Upload processed daily attendance records to the cloud.
     *
     * @param  array<int, array{employee_code: string, date: string, check_in: string, check_out: string|null, source?: string}>  $records
     * @return array{success: bool, accepted: int, rejected: int, errors: array, error: string|null}
     */
    public function uploadAttendance(array $records): array
    {
        if (empty($records)) {
            return [
                'success' => true,
                'accepted' => 0,
                'rejected' => 0,
                'errors' => [],
                'error' => null,
            ];
        }

        try {
            $response = $this->http->post('/api/v1/zpush/attendance/bulk', [
                'records' => $records,
            ]);
            $response->throw();

            $data = $response->json();

            $this->server->recordSyncSuccess();

            Log::info('CloudApiService: Attendance uploaded', [
                'accepted' => $data['accepted'] ?? 0,
                'rejected' => $data['rejected'] ?? 0,
            ]);

            return [
                'success' => true,
                'accepted' => $data['accepted'] ?? 0,
                'rejected' => $data['rejected'] ?? 0,
                'errors' => $data['errors'] ?? [],
                'error' => null,
            ];
        } catch (ConnectionException $e) {
            $this->server->recordSyncFailure();

            return [
                'success' => false,
                'accepted' => 0,
                'rejected' => 0,
                'errors' => [],
                'error' => 'Connection failed: '.$e->getMessage(),
            ];
        } catch (RequestException $e) {
            $this->server->recordSyncFailure();

            $errorMsg = 'HTTP '.$e->response->status().': '.($e->response->json('message') ?? $e->getMessage());

            return [
                'success' => false,
                'accepted' => 0,
                'rejected' => 0,
                'errors' => $e->response->json('errors', []),
                'error' => $errorMsg,
            ];
        }
    }

    /**
     * Check if the cloud server is reachable.
     */
    public function isReachable(): bool
    {
        $result = $this->ping();

        return $result['success'];
    }

    /**
     * Get the underlying cloud server model.
     */
    public function getServer(): CloudServer
    {
        return $this->server;
    }

    /**
     * Build the HTTP client with proper auth and timeouts.
     */
    private function buildClient(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->server->api_base_url, '/'))
            ->withToken($this->server->api_key)
            ->acceptJson()
            ->withHeaders([
                'X-ZPush-Version' => '1.0.0',
            ])
            ->timeout(30)
            ->connectTimeout(10);
    }
}
