<?php

namespace Database\Factories;

use App\Enums\SyncDirection;
use App\Enums\SyncStatus;
use App\Models\CloudServer;
use App\Models\DeviceConfig;
use App\Models\SyncLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncLog>
 */
class SyncLogFactory extends Factory
{
    protected $model = SyncLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-7 days', 'now');

        return [
            'cloud_server_id' => CloudServer::factory(),
            'device_id' => DeviceConfig::factory(),
            'direction' => fake()->randomElement(SyncDirection::cases()),
            'status' => SyncStatus::Completed,
            'records_synced' => fake()->numberBetween(1, 200),
            'records_failed' => 0,
            'started_at' => $startedAt,
            'completed_at' => (clone $startedAt)->modify('+'.fake()->numberBetween(1, 30).' seconds'),
            'error_message' => null,
        ];
    }

    /**
     * A successful sync log.
     */
    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncStatus::Completed,
            'records_failed' => 0,
            'error_message' => null,
        ]);
    }

    /**
     * A failed sync log.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncStatus::Failed,
            'records_synced' => 0,
            'records_failed' => fake()->numberBetween(1, 50),
            'error_message' => fake()->sentence(),
        ]);
    }
}
