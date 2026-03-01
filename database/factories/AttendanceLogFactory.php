<?php

namespace Database\Factories;

use App\Enums\PunchType;
use App\Models\AttendanceLog;
use App\Models\DeviceConfig;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceLog>
 */
class AttendanceLogFactory extends Factory
{
    protected $model = AttendanceLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'device_id' => DeviceConfig::factory(),
            'device_uid' => fn (array $attributes) => Employee::find($attributes['employee_id'])?->device_uid ?? fake()->numberBetween(1, 9999),
            'timestamp' => fake()->dateTimeBetween('-7 days', 'now'),
            'punch_type' => fake()->randomElement(PunchType::cases()),
            'cloud_synced' => false,
            'cloud_synced_at' => null,
            'cloud_sync_attempts' => 0,
        ];
    }

    /**
     * A check-in punch.
     */
    public function checkIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'punch_type' => PunchType::CheckIn,
        ]);
    }

    /**
     * A check-out punch.
     */
    public function checkOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'punch_type' => PunchType::CheckOut,
        ]);
    }

    /**
     * Mark as synced to cloud.
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'cloud_synced' => true,
            'cloud_synced_at' => now(),
        ]);
    }

    /**
     * A log from today.
     */
    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'timestamp' => fake()->dateTimeBetween('today', 'now'),
        ]);
    }
}
