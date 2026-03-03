<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSyncQueue;
use App\Jobs\SyncAttendanceToCloud;
use App\Jobs\SyncEmployeesFromCloud;
use App\Models\AttendanceLog;
use App\Models\CloudServer;
use App\Models\SyncLog;
use App\Models\SyncQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SyncController extends Controller
{
    /**
     * Show sync monitoring dashboard with optional filtering.
     */
    public function index(Request $request): Response
    {
        $logsQuery = SyncLog::query()
            ->with(['cloudServer:id,name', 'device:id,name'])
            ->latest('started_at');

        if ($request->filled('direction')) {
            $logsQuery->where('direction', $request->input('direction'));
        }

        if ($request->filled('entity_type')) {
            $logsQuery->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('status')) {
            $logsQuery->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $logsQuery->whereDate('started_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $logsQuery->whereDate('started_at', '<=', $request->input('date_to'));
        }

        return Inertia::render('sync/Monitor', [
            'cloudServer' => CloudServer::query()->first(),
            'stats' => [
                'pending_attendance' => AttendanceLog::query()->where('cloud_synced', false)->count(),
                'synced_attendance' => AttendanceLog::query()->where('cloud_synced', true)->count(),
                'queue_pending' => SyncQueue::query()->where('status', 'pending')->count(),
                'queue_failed' => SyncQueue::query()->where('status', 'failed')->count(),
            ],
            'recentLogs' => $logsQuery->take(50)->get(),
            'filters' => $request->only(['direction', 'entity_type', 'status', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Manually trigger a full sync cycle.
     */
    public function triggerSync(): JsonResponse
    {
        SyncAttendanceToCloud::dispatch();
        SyncEmployeesFromCloud::dispatch(fullSync: false);
        ProcessSyncQueue::dispatch();

        return response()->json([
            'success' => true,
            'message' => 'Sync jobs dispatched.',
        ]);
    }
}
