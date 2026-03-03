<?php

namespace App\Jobs;

use App\Enums\SyncDirection;
use App\Models\CloudServer;
use App\Models\SyncQueue;
use App\Services\CloudApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessSyncQueue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 60;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'process-sync-queue';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $pendingCount = SyncQueue::ready()->count();

        if ($pendingCount === 0) {
            return;
        }

        $server = CloudServer::query()->active()->first();

        if (! $server) {
            Log::debug('ProcessSyncQueue: No active cloud server, skipping.');

            return;
        }

        $api = new CloudApiService($server);

        if (! $api->isReachable()) {
            Log::debug('ProcessSyncQueue: Cloud not reachable, will retry later.');

            return;
        }

        // Process cloud-up items (attendance uploads)
        $this->processCloudUpItems($api);

        Log::info("ProcessSyncQueue: Processed queue — started with {$pendingCount} pending items.");
    }

    /**
     * Process queued attendance uploads.
     */
    private function processCloudUpItems(CloudApiService $api): void
    {
        $items = SyncQueue::ready()
            ->direction(SyncDirection::CloudUp)
            ->where('entity_type', 'attendance')
            ->take(200)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        // Batch the payloads
        $records = $items->map(fn (SyncQueue $item) => $item->payload)->toArray();

        $result = $api->uploadAttendance($records);

        if ($result['success']) {
            // Mark successful items
            $errorCodes = collect($result['errors'])->pluck('employee_code')->toArray();

            foreach ($items as $item) {
                $code = $item->payload['employee_code'] ?? null;

                if (in_array($code, $errorCodes)) {
                    $item->recordFailure('Rejected by cloud: '.collect($result['errors'])
                        ->firstWhere('employee_code', $code)['error'] ?? 'Unknown');
                } else {
                    $item->markCompleted();
                }
            }
        } else {
            // Mark all as failed with backoff
            foreach ($items as $item) {
                $item->recordFailure($result['error'] ?? 'Upload failed');
            }
        }
    }
}
