<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with device stats and today's attendance.
     */
    public function index(): Response
    {
        $devices = DeviceConfig::active()->get();

        $deviceSummaries = $devices->map(fn (DeviceConfig $device) => [
            'id' => $device->id,
            'name' => $device->name,
            'ip_address' => $device->ip_address,
            'is_connected' => $device->isConnected(),
            'last_poll_at' => $device->last_poll_at?->toISOString(),
            'connection_failures' => $device->connection_failures,
        ]);

        $todayLogs = AttendanceLog::with('employee')
            ->today()
            ->latest('timestamp')
            ->limit(50)
            ->get()
            ->map(fn (AttendanceLog $log) => [
                'id' => $log->id,
                'employee_name' => $log->employee?->name ?? "UID {$log->device_uid}",
                'employee_code' => $log->employee?->employee_code,
                'timestamp' => $log->timestamp->toISOString(),
                'punch_type' => $log->punch_type->value,
                'punch_label' => $log->punch_type->label(),
                'punch_color' => $log->punch_type->color(),
            ]);

        return Inertia::render('Dashboard', [
            'devices' => $deviceSummaries,
            'todayPunchCount' => AttendanceLog::today()->count(),
            'todayLogs' => $todayLogs,
            'employeeCount' => Employee::active()->count(),
            'unsyncedCount' => AttendanceLog::unsynced()->count(),
        ]);
    }
}
