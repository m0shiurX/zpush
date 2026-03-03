<?php

namespace App\Jobs;

use App\Enums\SyncDirection;
use App\Models\CloudServer;
use App\Models\Employee;
use App\Models\SyncLog;
use App\Services\CloudApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncEmployeesFromCloud implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 300;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(public bool $fullSync = false) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-employees-from-cloud';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $server = CloudServer::query()->active()->first();

        if (! $server || ! $server->branch_id) {
            Log::info('SyncEmployeesFromCloud: No active cloud server with branch configured.');

            return;
        }

        $api = new CloudApiService($server);

        if (! $api->isReachable()) {
            Log::info('SyncEmployeesFromCloud: Cloud not reachable, skipping.');

            return;
        }

        $startedAt = now();

        // For incremental sync, use the last sync timestamp
        $updatedSince = null;
        if (! $this->fullSync) {
            $lastSync = Employee::query()->max('cloud_synced_at');
            $updatedSince = $lastSync;
        }

        $result = $api->fetchEmployees($updatedSince);

        if (! $result['success']) {
            Log::warning('SyncEmployeesFromCloud: Failed to fetch employees', [
                'error' => $result['error'],
            ]);

            SyncLog::logFailure([
                'cloud_server_id' => $server->id,
                'direction' => SyncDirection::CloudDown,
                'entity_type' => 'employee',
                'error_message' => $result['error'],
                'started_at' => $startedAt,
            ]);

            return;
        }

        $created = 0;
        $updated = 0;
        $deactivated = 0;

        foreach ($result['employees'] as $cloudEmployee) {
            $syncResult = $this->upsertEmployee($cloudEmployee);

            if ($syncResult === 'created') {
                $created++;
            } elseif ($syncResult === 'updated') {
                $updated++;
            }
        }

        // On full sync, deactivate employees not in the cloud response
        if ($this->fullSync && ! empty($result['employees'])) {
            $cloudIds = collect($result['employees'])->pluck('id')->toArray();
            $deactivated = Employee::query()
                ->whereNotNull('cloud_id')
                ->whereNotIn('cloud_id', $cloudIds)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        SyncLog::logSuccess([
            'cloud_server_id' => $server->id,
            'direction' => SyncDirection::CloudDown,
            'entity_type' => 'employee',
            'records_affected' => $created + $updated + $deactivated,
            'started_at' => $startedAt,
        ]);

        Log::info("SyncEmployeesFromCloud: Done — {$created} created, {$updated} updated, {$deactivated} deactivated.");
    }

    /**
     * Upsert a single employee from cloud data.
     *
     * @param  array{id: int, employee_code: string, name: string, department: string|null, designation: string|null, shift: array|null, is_active: bool, updated_at: string}  $cloudData
     */
    private function upsertEmployee(array $cloudData): string
    {
        $existing = Employee::query()
            ->where('cloud_id', $cloudData['id'])
            ->orWhere('employee_code', $cloudData['employee_code'])
            ->first();

        $attributes = [
            'cloud_id' => $cloudData['id'],
            'name' => $cloudData['name'],
            'employee_code' => $cloudData['employee_code'],
            'department' => $cloudData['department'],
            'is_active' => $cloudData['is_active'],
            'cloud_synced_at' => now(),
        ];

        if ($existing) {
            // Check if anything actually changed using sync_hash
            $newHash = md5(json_encode([
                $attributes['name'],
                $attributes['department'],
                $attributes['employee_code'],
                $attributes['is_active'],
            ]));

            if ($existing->sync_hash === $newHash) {
                // Just update the sync timestamp
                $existing->update(['cloud_synced_at' => now()]);

                return 'unchanged';
            }

            $existing->update($attributes);

            return 'updated';
        }

        Employee::create($attributes);

        return 'created';
    }
}
