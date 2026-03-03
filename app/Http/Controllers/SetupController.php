<?php

namespace App\Http\Controllers;

use App\Http\Requests\Setup\StoreCloudServerRequest;
use App\Http\Requests\Setup\StoreDeviceRequest;
use App\Http\Requests\Setup\TestDeviceConnectionRequest;
use App\Models\AppSetting;
use App\Models\CloudServer;
use App\Models\DeviceConfig;
use App\Services\CloudApiService;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    /**
     * Step 1 — Welcome / intro page.
     */
    public function welcome(): Response|RedirectResponse
    {
        if (AppSetting::isTrue('setup_completed')) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('setup/Welcome');
    }

    /**
     * Step 2 — Device configuration form.
     */
    public function device(): Response|RedirectResponse
    {
        if (AppSetting::isTrue('setup_completed')) {
            return redirect()->route('dashboard');
        }

        $existingDevice = DeviceConfig::query()->first();

        return Inertia::render('setup/DeviceConnect', [
            'device' => $existingDevice,
        ]);
    }

    /**
     * Step 2 — Test device connection (AJAX).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testDevice(TestDeviceConnectionRequest $request, DeviceService $service)
    {
        $device = new DeviceConfig([
            'name' => 'Test',
            'ip_address' => $request->validated('ip_address'),
            'port' => $request->validated('port'),
            'protocol' => $request->validated('protocol'),
        ]);

        $result = $service->testConnection($device);

        return response()->json($result);
    }

    /**
     * Step 2 — Store the device configuration.
     */
    public function storeDevice(StoreDeviceRequest $request): RedirectResponse
    {
        DeviceConfig::updateOrCreate(
            ['ip_address' => $request->validated('ip_address')],
            $request->validated(),
        );

        return redirect()->route('setup.cloud');
    }

    /**
     * Step 3 — Cloud server configuration form.
     */
    public function cloud(): Response|RedirectResponse
    {
        if (AppSetting::isTrue('setup_completed')) {
            return redirect()->route('dashboard');
        }

        $existingCloud = CloudServer::query()->first();

        return Inertia::render('setup/CloudConfig', [
            'cloudServer' => $existingCloud,
            'device' => DeviceConfig::query()->first(),
        ]);
    }

    /**
     * Step 3 — Store the cloud server configuration.
     */
    public function storeCloud(StoreCloudServerRequest $request): RedirectResponse
    {
        $server = CloudServer::updateOrCreate(
            ['api_base_url' => $request->validated('api_base_url')],
            $request->validated(),
        );

        // Store branch info in app_settings for quick access
        if ($request->filled('branch_id')) {
            AppSetting::set('cloud_branch_id', $request->validated('branch_id'));
            AppSetting::set('cloud_branch_name', $request->validated('branch_name'));
        }

        return redirect()->route('setup.complete');
    }

    /**
     * Step 3 — Test cloud connection (AJAX).
     */
    public function testCloud(Request $request): JsonResponse
    {
        $request->validate([
            'api_base_url' => ['required', 'url'],
            'api_key' => ['required', 'string'],
        ]);

        $server = new CloudServer([
            'api_base_url' => $request->input('api_base_url'),
            'api_key' => $request->input('api_key'),
        ]);

        $api = new CloudApiService($server);

        return response()->json($api->ping());
    }

    /**
     * Step 3 — Fetch branches from cloud (AJAX).
     */
    public function fetchBranches(Request $request): JsonResponse
    {
        $request->validate([
            'api_base_url' => ['required', 'url'],
            'api_key' => ['required', 'string'],
        ]);

        $server = new CloudServer([
            'api_base_url' => $request->input('api_base_url'),
            'api_key' => $request->input('api_key'),
        ]);

        $api = new CloudApiService($server);

        return response()->json($api->fetchBranches());
    }

    /**
     * Step 3 — Skip cloud configuration.
     */
    public function skipCloud(): RedirectResponse
    {
        return redirect()->route('setup.complete');
    }

    /**
     * Step 4 — Summary / completion page.
     */
    public function complete(): Response|RedirectResponse
    {
        if (AppSetting::isTrue('setup_completed')) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('setup/Complete', [
            'device' => DeviceConfig::query()->first(),
            'cloudServer' => CloudServer::query()->first(),
        ]);
    }

    /**
     * Step 4 — Finalize setup.
     */
    public function finalize(): RedirectResponse
    {
        AppSetting::set('setup_completed', true);

        return redirect()->route('dashboard');
    }
}
