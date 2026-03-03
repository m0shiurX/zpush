<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCloudServerRequest;
use App\Jobs\SyncAttendanceToCloud;
use App\Jobs\SyncEmployeesFromCloud;
use App\Jobs\SyncEmployeesToDevice;
use App\Models\AppSetting;
use App\Models\CloudServer;
use App\Models\SyncLog;
use App\Services\CloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CloudServerController extends Controller
{
    /**
     * Show cloud server management page.
     */
    public function index(): Response
    {
        return Inertia::render('cloud-servers/Index', [
            'cloudServer' => CloudServer::query()->first(),
            'recentSyncLogs' => SyncLog::query()
                ->whereNotNull('cloud_server_id')
                ->latest('started_at')
                ->take(20)
                ->get(),
        ]);
    }

    /**
     * Store or update the cloud server configuration.
     */
    public function store(StoreCloudServerRequest $request): RedirectResponse
    {
        $server = CloudServer::updateOrCreate(
            ['api_base_url' => $request->validated('api_base_url')],
            $request->validated(),
        );

        if ($request->filled('branch_id')) {
            AppSetting::set('cloud_branch_id', $request->validated('branch_id'));
            AppSetting::set('cloud_branch_name', $request->validated('branch_name'));
        }

        return redirect()->route('cloud-servers.index')
            ->with('success', 'Cloud server configuration saved.');
    }

    /**
     * Test the cloud server connection.
     */
    public function test(CloudServer $cloudServer): JsonResponse
    {
        $api = new CloudApiService($cloudServer);

        return response()->json($api->ping());
    }

    /**
     * Fetch available branches from the cloud.
     */
    public function branches(CloudServer $cloudServer): JsonResponse
    {
        $api = new CloudApiService($cloudServer);

        return response()->json($api->fetchBranches());
    }

    /**
     * Manually trigger attendance sync to cloud.
     */
    public function syncAttendance(CloudServer $cloudServer): JsonResponse
    {
        SyncAttendanceToCloud::dispatch();

        return response()->json([
            'success' => true,
            'message' => 'Attendance sync job dispatched.',
        ]);
    }

    /**
     * Manually trigger employee sync from cloud.
     */
    public function syncEmployees(CloudServer $cloudServer): JsonResponse
    {
        SyncEmployeesFromCloud::dispatch(fullSync: true);

        return response()->json([
            'success' => true,
            'message' => 'Employee sync job dispatched.',
        ]);
    }

    /**
     * Manually trigger employee push to devices.
     */
    public function syncToDevice(CloudServer $cloudServer): JsonResponse
    {
        SyncEmployeesToDevice::dispatch();

        return response()->json([
            'success' => true,
            'message' => 'Device sync job dispatched.',
        ]);
    }

    /**
     * Remove the cloud server configuration.
     */
    public function destroy(CloudServer $cloudServer): RedirectResponse
    {
        $cloudServer->delete();

        AppSetting::set('cloud_branch_id', null);
        AppSetting::set('cloud_branch_name', null);

        return redirect()->route('cloud-servers.index')
            ->with('success', 'Cloud server removed.');
    }
}
