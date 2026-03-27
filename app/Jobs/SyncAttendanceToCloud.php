<?php

namespace App\Jobs;

use App\Enums\SyncDirection;
use App\Models\AttendanceLog;
use App\Models\CloudServer;
use App\Models\Employee;
use App\Models\SyncLog;
use App\Services\AttendanceProcessorService;
use App\Services\CloudApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncAttendanceToCloud implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 120;

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
    public function __construct(public ?string $date = null) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-attendance-'.($this->date ?? 'all');
    }

    /**
     * Execute the job.
     */
    public function handle(AttendanceProcessorService $processor): void
    {
        $server = CloudServer::query()->active()->first();

        if (! $server || ! $server->branch_id) {
            Log::info('SyncAttendanceToCloud: No active cloud server with branch configured.');

            return;
        }

        $api = new CloudApiService($server);

        if (! $api->isReachable()) {
            Log::info('SyncAttendanceToCloud: Cloud not reachable, skipping.');

            return;
        }

        $startedAt = now();

        if ($this->date) {
            $records = $processor->processDate(Carbon::parse($this->date));
            $allRecords = [$this->date => $records];
        } else {
            $allRecords = $processor->processUnsynced();
        }

        $totalAccepted = 0;
        $totalRejected = 0;
        $allErrors = [];

        foreach ($allRecords as $date => $records) {
            if (empty($records)) {
                continue;
            }

            // Batch in chunks of 200 (lavloss accepts max 500)
            foreach (array_chunk($records, 200) as $batch) {
                $result = $api->uploadAttendance($batch);

                if (! $result['success']) {
                    Log::warning("SyncAttendanceToCloud: Upload failed for {$date}", [
                        'error' => $result['error'],
                    ]);
                    $allErrors[] = $result['error'];

                    continue;
                }

                $totalAccepted += $result['accepted'];
                $totalRejected += $result['rejected'];
                $allErrors = array_merge($allErrors, $result['errors']);

                // Mark synced punches for the accepted employee_codes + date
                $acceptedCodes = collect($batch)
                    ->pluck('employee_code')
                    ->diff(collect($result['errors'])->pluck('employee_code'));

                $this->markPunchesSynced($acceptedCodes->toArray(), $date);
            }
        }

        $totalRecords = $totalAccepted + $totalRejected;

        if ($totalRecords > 0) {
            SyncLog::logSuccess([
                'cloud_server_id' => $server->id,
                'direction' => SyncDirection::CloudUp,
                'entity_type' => 'attendance',
                'records_affected' => $totalAccepted,
                'started_at' => $startedAt,
            ]);
        }

        Log::info("SyncAttendanceToCloud: Done — {$totalAccepted} accepted, {$totalRejected} rejected.");
    }

    /**
     * Mark attendance punches as synced for given employee codes on a date.
     *
     * @param  array<int, string>  $employeeCodes
     */
    private function markPunchesSynced(array $employeeCodes, string $date): void
    {
        if (empty($employeeCodes)) {
            return;
        }

        $employeeIds = Employee::query()
            ->whereIn('employee_code', $employeeCodes)
            ->pluck('id');

        AttendanceLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('timestamp', $date)
            ->where('cloud_synced', false)
            ->update([
                'cloud_synced' => true,
                'cloud_synced_at' => now(),
                'last_sync_error' => null,
            ]);
    }
}
