<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAppSettingsRequest;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AppSettingsController extends Controller
{
    /**
     * Default values for application settings.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'sync_interval' => 30,
        'timezone' => 'Asia/Dhaka',
        'log_retention_days' => 90,
        'auto_sync_enabled' => true,
        'poll_interval' => 5,
    ];

    /**
     * Show the application settings page.
     */
    public function index(): Response
    {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = AppSetting::get($key, $default);
        }

        return Inertia::render('settings/AppSettings', [
            'settings' => $settings,
            'timezones' => $this->getTimezoneList(),
        ]);
    }

    /**
     * Update application settings.
     */
    public function update(UpdateAppSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        foreach ($validated as $key => $value) {
            AppSetting::set($key, $value);
        }

        return redirect()->route('settings.app')
            ->with('success', 'Application settings saved successfully.');
    }

    /**
     * Get a structured list of common timezones.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function getTimezoneList(): array
    {
        $timezones = [];
        $regions = [
            'Asia' => \DateTimeZone::ASIA,
            'America' => \DateTimeZone::AMERICA,
            'Europe' => \DateTimeZone::EUROPE,
            'Africa' => \DateTimeZone::AFRICA,
            'Pacific' => \DateTimeZone::PACIFIC,
            'Australia' => \DateTimeZone::AUSTRALIA,
        ];

        foreach ($regions as $region => $mask) {
            foreach (\DateTimeZone::listIdentifiers($mask) as $tz) {
                $timezones[] = [
                    'value' => $tz,
                    'label' => str_replace(['/', '_'], [' / ', ' '], $tz),
                ];
            }
        }

        return $timezones;
    }
}
