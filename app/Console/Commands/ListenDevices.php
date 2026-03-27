<?php

namespace App\Console\Commands;

use App\Exceptions\DeviceConnectionException;
use App\Models\DeviceConfig;
use App\Services\DeviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ListenDevices extends Command
{
    protected $signature = 'devices:listen
        {--device= : Listen to a specific device by ID}
        {--timeout=0 : Total runtime in seconds (0 = run forever)}
        {--max-retries=0 : Max consecutive reconnect attempts (0 = unlimited)}';

    protected $description = 'Listen for real-time attendance events with auto-reconnect';

    private const LISTEN_CYCLE_SECONDS = 30;

    private const BASE_BACKOFF_SECONDS = 2;

    private const MAX_BACKOFF_SECONDS = 60;

    private bool $shouldStop = false;

    private int $newCount = 0;

    private int $skippedCount = 0;

    public function handle(DeviceService $service): int
    {
        $device = $this->resolveDevice();

        if (! $device) {
            return self::FAILURE;
        }

        $this->registerSignalHandlers();

        $timeout = (int) $this->option('timeout');
        $maxRetries = (int) $this->option('max-retries');
        $startTime = time();
        $consecutiveFailures = 0;

        $this->info("Listening on [{$device->name}] ({$device->ip_address}:{$device->port} via {$device->protocol})");
        $this->info('Auto-reconnect enabled. Press Ctrl+C to stop.');
        $this->newLine();

        while (! $this->shouldStop) {
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                $this->components->info('Timeout reached.');

                break;
            }

            if ($maxRetries > 0 && $consecutiveFailures >= $maxRetries) {
                $this->components->error("Max retries ({$maxRetries}) reached. Giving up.");

                break;
            }

            try {
                $device->refresh();

                if (! $device->is_active) {
                    $this->components->warn('Device deactivated. Stopping.');

                    break;
                }

                $cycleTimeout = $this->calculateCycleTimeout($timeout, $startTime);

                $service->listenForAttendance($device, $cycleTimeout, function (array $result) {
                    $this->newCount += $result['new'];
                    $this->skippedCount += $result['skipped'];

                    if ($result['new'] > 0) {
                        $this->components->twoColumnDetail(
                            '<fg=green>Punch recorded</>',
                            "new: {$this->newCount} | skipped: {$this->skippedCount}"
                        );
                    }
                });

                // Cycle completed normally — connection is healthy
                $consecutiveFailures = 0;
                $device->recordSuccess();
                Cache::put("device:{$device->id}:listening", true, 90);
            } catch (DeviceConnectionException $e) {
                $consecutiveFailures++;
                $service->disconnect();

                $backoff = $this->calculateBackoff($consecutiveFailures);
                $this->components->warn("Connection lost (attempt {$consecutiveFailures}): {$e->getMessage()}");
                $this->components->info("Reconnecting in {$backoff}s...");

                Log::warning("Listener [{$device->name}]: connection lost (attempt {$consecutiveFailures}), retrying in {$backoff}s.", [
                    'error' => $e->getMessage(),
                ]);

                $this->interruptibleSleep($backoff);
            } catch (\Throwable $e) {
                $consecutiveFailures++;
                $service->disconnect();

                $backoff = $this->calculateBackoff($consecutiveFailures);
                $this->components->error("Unexpected error (attempt {$consecutiveFailures}): {$e->getMessage()}");
                $this->components->info("Reconnecting in {$backoff}s...");

                Log::error("Listener [{$device->name}]: unexpected error (attempt {$consecutiveFailures}).", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->interruptibleSleep($backoff);
            }
        }

        $service->disconnect();
        Cache::forget("device:{$device->id}:listening");

        $this->newLine();
        $this->components->info("Stopped. {$this->newCount} new records, {$this->skippedCount} skipped.");

        return self::SUCCESS;
    }

    private function resolveDevice(): ?DeviceConfig
    {
        $deviceId = $this->option('device');

        if ($deviceId) {
            $device = DeviceConfig::where('id', $deviceId)->active()->first();

            if (! $device) {
                $this->components->error("Device #{$deviceId} not found or inactive.");

                return null;
            }

            if ($device->isBulk()) {
                $this->components->warn("Device [{$device->name}] is configured for bulk polling, not real-time listening. Change its poll method to 'realtime' first.");

                return null;
            }

            return $device;
        }

        $device = DeviceConfig::active()->where('poll_method', 'realtime')->first();

        if (! $device) {
            $this->components->error('No active real-time devices found.');

            return null;
        }

        return $device;
    }

    private function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function () {
            $this->newLine();
            $this->components->info('Shutdown signal received. Finishing current cycle...');
            $this->shouldStop = true;
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    /**
     * Calculate the listen cycle timeout, respecting the overall runtime limit.
     */
    private function calculateCycleTimeout(int $totalTimeout, int $startTime): int
    {
        if ($totalTimeout <= 0) {
            return self::LISTEN_CYCLE_SECONDS;
        }

        $remaining = $totalTimeout - (time() - $startTime);

        return max(1, min(self::LISTEN_CYCLE_SECONDS, $remaining));
    }

    /**
     * Exponential backoff with jitter, capped at MAX_BACKOFF_SECONDS.
     */
    private function calculateBackoff(int $failures): int
    {
        $backoff = self::BASE_BACKOFF_SECONDS * (2 ** min($failures - 1, 5));

        return min($backoff, self::MAX_BACKOFF_SECONDS);
    }

    /**
     * Sleep that can be interrupted by a shutdown signal.
     */
    private function interruptibleSleep(int $seconds): void
    {
        $end = time() + $seconds;

        while (time() < $end && ! $this->shouldStop) {
            sleep(1);
        }
    }
}
