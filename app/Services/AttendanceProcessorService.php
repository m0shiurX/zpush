<?php

namespace App\Services;

use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AttendanceProcessorService
{
    /**
     * Process raw punches for a given date into daily check-in/check-out pairs.
     *
     * Returns an array of processed attendance records ready for cloud upload.
     *
     * @return array<int, array{employee_code: string, date: string, check_in: string, check_out: string|null, source: string}>
     */
    public function processDate(Carbon $date): array
    {
        $records = [];

        $employeeIds = AttendanceLog::query()
            ->whereDate('timestamp', $date)
            ->select('employee_id')
            ->distinct()
            ->whereNotNull('employee_id')
            ->pluck('employee_id');

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->active()
            ->get()
            ->keyBy('id');

        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);

            if (! $employee) {
                continue;
            }

            $pair = $this->pairPunchesForEmployee($employee, $date);

            if ($pair) {
                $records[] = $pair;
            }
        }

        Log::info("AttendanceProcessor: Processed {$date->toDateString()} — ".count($records).' records.');

        return $records;
    }

    /**
     * Process punches for a specific employee on a given date.
     *
     * @return array{employee_code: string, date: string, check_in: string, check_out: string|null, source: string}|null
     */
    public function pairPunchesForEmployee(Employee $employee, Carbon $date): ?array
    {
        $punches = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('timestamp', $date)
            ->orderBy('timestamp')
            ->get();

        if ($punches->isEmpty()) {
            return null;
        }

        $checkIn = $this->findFirstCheckIn($punches);
        $checkOut = $this->findLastCheckOut($punches);

        if (! $checkIn) {
            return null;
        }

        return [
            'employee_code' => $employee->employee_code,
            'date' => $date->toDateString(),
            'check_in' => $checkIn->timestamp->format('H:i'),
            'check_out' => $checkOut?->timestamp->format('H:i'),
            'source' => 'zpush',
        ];
    }

    /**
     * Process punches for all dates that have unsynced attendance.
     *
     * Returns records grouped by date for batching.
     *
     * @return array<string, array<int, array{employee_code: string, date: string, check_in: string, check_out: string|null, source: string}>>
     */
    public function processUnsynced(): array
    {
        $dates = AttendanceLog::query()
            ->where('cloud_synced', false)
            ->whereNotNull('employee_id')
            ->selectRaw('DATE(timestamp) as punch_date')
            ->distinct()
            ->pluck('punch_date');

        $allRecords = [];

        foreach ($dates as $dateString) {
            $date = Carbon::parse($dateString);
            $records = $this->processDate($date);

            if (! empty($records)) {
                $allRecords[$dateString] = $records;
            }
        }

        return $allRecords;
    }

    /**
     * Get all completed pairs (have both check-in and check-out) for a date.
     *
     * @return array<int, array{employee_code: string, date: string, check_in: string, check_out: string, source: string}>
     */
    public function getCompletedPairs(Carbon $date): array
    {
        $records = $this->processDate($date);

        return array_values(array_filter($records, fn (array $r) => $r['check_out'] !== null));
    }

    /**
     * Get incomplete records (check-in only, no check-out yet) for a date.
     *
     * @return array<int, array{employee_code: string, date: string, check_in: string, check_out: null, source: string}>
     */
    public function getIncompletePairs(Carbon $date): array
    {
        $records = $this->processDate($date);

        return array_values(array_filter($records, fn (array $r) => $r['check_out'] === null));
    }

    /**
     * Find the first check-in punch from a collection of punches.
     */
    private function findFirstCheckIn(Collection $punches): ?AttendanceLog
    {
        $checkIn = $punches->first(fn (AttendanceLog $p) => $p->punch_type === PunchType::CheckIn);

        if (! $checkIn) {
            $checkIn = $punches->first();
        }

        return $checkIn;
    }

    /**
     * Find the last check-out punch from a collection of punches.
     */
    private function findLastCheckOut(Collection $punches): ?AttendanceLog
    {
        return $punches->last(fn (AttendanceLog $p) => $p->punch_type === PunchType::CheckOut);
    }
}
