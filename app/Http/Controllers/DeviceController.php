<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    /**
     * Display the list of all devices.
     */
    public function index(): Response
    {
        $devices = DeviceConfig::query()
            ->withCount('attendanceLogs')
            ->get()
            ->map(fn (DeviceConfig $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'ip_address' => $device->ip_address,
                'port' => $device->port,
                'protocol' => $device->protocol,
                'is_active' => $device->is_active,
                'is_connected' => $device->isConnected(),
                'last_connected_at' => $device->last_connected_at?->toISOString(),
                'last_poll_at' => $device->last_poll_at?->toISOString(),
                'connection_failures' => $device->connection_failures,
                'attendance_logs_count' => $device->attendance_logs_count,
            ]);

        return Inertia::render('devices/Index', [
            'devices' => $devices,
        ]);
    }

    /**
     * Display a single device with recent attendance.
     */
    public function show(DeviceConfig $device): Response
    {
        $recentLogs = AttendanceLog::with('employee')
            ->where('device_id', $device->id)
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

        return Inertia::render('devices/Show', [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'ip_address' => $device->ip_address,
                'port' => $device->port,
                'protocol' => $device->protocol,
                'is_active' => $device->is_active,
                'is_connected' => $device->isConnected(),
                'last_connected_at' => $device->last_connected_at?->toISOString(),
                'last_poll_at' => $device->last_poll_at?->toISOString(),
                'connection_failures' => $device->connection_failures,
                'total_logs' => $device->attendanceLogs()->count(),
            ],
            'recentLogs' => $recentLogs,
        ]);
    }

    /**
     * Test connection to a device (AJAX).
     */
    public function test(DeviceConfig $device, DeviceService $service): JsonResponse
    {
        $result = $service->testConnection($device);

        return response()->json($result);
    }

    /**
     * Poll a device for new attendance data (AJAX).
     */
    public function poll(DeviceConfig $device, DeviceService $service): JsonResponse
    {
        try {
            $users = $service->syncUsersFromDevice($device);
            $result = $service->pollAttendance($device);

            return response()->json([
                'success' => true,
                'users_synced' => $users->count(),
                ...$result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            $service->disconnect();
        }
    }

    /**
     * Toggle device active/inactive status.
     */
    public function update(Request $request, DeviceConfig $device): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $device->update($validated);

        return back();
    }
}
