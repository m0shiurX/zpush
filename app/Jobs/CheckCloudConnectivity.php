<?php

namespace App\Jobs;

use App\Services\ConnectivityService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckCloudConnectivity implements ShouldBeUnique, ShouldQueue
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
        return 'check-cloud-connectivity';
    }

    /**
     * Execute the job.
     */
    public function handle(ConnectivityService $connectivity): void
    {
        $status = $connectivity->checkAll();

        if (! $status['online']) {
            Log::debug('CheckCloudConnectivity: No internet connection.');

            return;
        }

        if (! $status['cloud_authenticated']) {
            Log::warning('CheckCloudConnectivity: Cloud not authenticated.', [
                'error' => $status['error'],
            ]);

            return;
        }

        Log::debug('CheckCloudConnectivity: Cloud is reachable and authenticated.');
    }
}
