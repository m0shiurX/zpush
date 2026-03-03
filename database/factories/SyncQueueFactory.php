<?php

namespace Database\Factories;

use App\Enums\SyncDirection;
use App\Enums\SyncStatus;
use App\Models\SyncQueue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncQueue>
 */
class SyncQueueFactory extends Factory
{
    protected $model = SyncQueue::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction' => fake()->randomElement(SyncDirection::cases()),
            'entity_type' => fake()->randomElement(['attendance', 'employee']),
            'status' => SyncStatus::Pending,
            'payload' => ['employee_id' => fake()->numberBetween(1, 100)],
            'priority' => 10,
            'attempts' => 0,
            'max_attempts' => 5,
            'scheduled_at' => now(),
            'last_error' => null,
        ];
    }

    /**
     * A cloud upload sync item.
     */
    public function cloudUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => SyncDirection::CloudUp,
        ]);
    }

    /**
     * A device upload sync item.
     */
    public function deviceUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => SyncDirection::DeviceUp,
        ]);
    }

    /**
     * Mark as completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncStatus::Failed,
            'attempts' => 5,
            'max_attempts' => 5,
            'last_error' => 'Connection timeout',
        ]);
    }

    /**
     * High priority item.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 1,
        ]);
    }
}
