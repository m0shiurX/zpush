<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeviceRequest;
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
            ->map(fn(DeviceConfig $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'ip_address' => $device->ip_address,
                'port' => $device->port,
                'protocol' => $device->protocol,
                'poll_method' => $device->poll_method ?? 'realtime',
                'is_active' => $device->is_active,
                'is_connected' => $device->isConnected(),
                'is_listening' => $device->isListening(),
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
            ->map(fn(AttendanceLog $log) => [
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
                'poll_method' => $device->poll_method ?? 'realtime',
                'is_active' => $device->is_active,
                'is_connected' => $device->isConnected(),
                'is_listening' => $device->isListening(),
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
        if ($device->isListening()) {
            return response()->json([
                'success' => true,
                'serial_number' => null,
                'device_name' => $device->name,
                'firmware' => null,
                'error' => null,
                'listening' => true,
            ]);
        }

        try {
            set_time_limit(15);

            $result = $service->testConnection($device);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'serial_number' => null,
                'device_name' => null,
                'firmware' => null,
                'error' => 'Connection timed out — the device may be busy or unreachable.',
            ]);
        }
    }

    /**
     * Poll a device for new attendance data (AJAX).
     */
    public function poll(DeviceConfig $device, DeviceService $service): JsonResponse
    {
        if ($device->isListening()) {
            return response()->json([
                'success' => true,
                'listening' => true,
                'message' => 'Attendance is being captured in real-time by the listener.',
            ]);
        }

        if ($device->isRealtime()) {
            return response()->json([
                'success' => true,
                'message' => 'This device uses real-time mode. Start the listener with: php artisan devices:listen --device=' . $device->id,
            ]);
        }

        try {
            set_time_limit(30);

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
     * Clear attendance on the physical device and in local DB for this device.
     */
    public function clearAttendance(DeviceConfig $device, DeviceService $service): JsonResponse
    {
        try {
            $service->clearDeviceAttendance($device);

            $deleted = AttendanceLog::where('device_id', $device->id)->delete();

            return response()->json([
                'success' => true,
                'message' => "Device attendance cleared. {$deleted} local records removed.",
                'deleted' => $deleted,
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
     * Clear only local attendance records for this device (keeps device untouched).
     */
    public function clearLocalAttendance(DeviceConfig $device): JsonResponse
    {
        $deleted = AttendanceLog::where('device_id', $device->id)->delete();

        return response()->json([
            'success' => true,
            'message' => "{$deleted} local attendance records removed.",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Clear all users from the physical device.
     */
    public function clearDeviceUsers(DeviceConfig $device, DeviceService $service): JsonResponse
    {
        try {
            $removed = $service->removeAllUsersFromDevice($device);

            return response()->json([
                'success' => true,
                'message' => "{$removed} users removed from device.",
                'removed' => $removed,
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
     * Sync the server's date/time to the device (AJAX).
     */
    public function syncTime(DeviceConfig $device, DeviceService $service): JsonResponse
    {
        if ($device->isListening()) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot sync time while the listener is connected. Stop the listener first.',
            ]);
        }

        try {
            set_time_limit(15);

            $result = $service->syncTime($device);

            return response()->json($result);
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
            'is_active' => ['sometimes', 'boolean'],
            'poll_method' => ['sometimes', 'string', 'in:realtime,bulk'],
        ]);

        $device->update($validated);

        return back();
    }

    /**
     * Store a new device.
     */
    public function store(StoreDeviceRequest $request): RedirectResponse
    {
        DeviceConfig::create($request->validated());

        return redirect()->route('devices.index');
    }

    /**
     * Delete a device and its local attendance records.
     */
    public function destroy(DeviceConfig $device): RedirectResponse
    {
        AttendanceLog::where('device_id', $device->id)->delete();
        $device->delete();

        return redirect()->route('devices.index');
    }
}
