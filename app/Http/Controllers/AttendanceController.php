<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    /**
     * Display attendance logs with filtering and pagination.
     */
    public function index(Request $request): Response
    {
        $query = AttendanceLog::with(['employee', 'device'])
            ->latest('timestamp');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('employee', function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('timestamp', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('timestamp', '<=', $request->input('date_to'));
        }

        if ($request->filled('punch_type') && $request->input('punch_type') !== '') {
            $query->where('punch_type', $request->input('punch_type'));
        }

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->input('device_id'));
        }

        $logs = $query->paginate(25)->through(fn (AttendanceLog $log) => [
            'id' => $log->id,
            'employee_name' => $log->employee?->name ?? "UID {$log->device_uid}",
            'employee_code' => $log->employee?->employee_code,
            'device_name' => $log->device?->name ?? 'Unknown',
            'timestamp' => $log->timestamp->toISOString(),
            'punch_type' => $log->punch_type->value,
            'punch_label' => $log->punch_type->label(),
            'punch_color' => $log->punch_type->color(),
            'cloud_synced' => $log->cloud_synced,
        ]);

        return Inertia::render('attendance/Index', [
            'logs' => $logs,
            'filters' => $request->only(['search', 'date_from', 'date_to', 'punch_type', 'device_id']),
        ]);
    }
}
