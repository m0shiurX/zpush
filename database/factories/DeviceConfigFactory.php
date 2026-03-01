<?php

namespace Database\Factories;

use App\Models\DeviceConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceConfig>
 */
class DeviceConfigFactory extends Factory
{
    protected $model = DeviceConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Main Entrance', 'Back Door', 'Floor 2', 'Server Room', 'Lobby']).' '.fake()->numberBetween(1, 9),
            'ip_address' => fake()->localIpv4(),
            'port' => 4370,
            'is_active' => true,
            'last_connected_at' => null,
            'last_poll_at' => null,
            'connection_failures' => 0,
        ];
    }

    /**
     * Indicate the device is connected and recently polled.
     */
    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_connected_at' => now(),
            'last_poll_at' => now(),
            'connection_failures' => 0,
        ]);
    }

    /**
     * Indicate the device has connection failures.
     */
    public function failing(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_connected_at' => now()->subMinutes(10),
            'connection_failures' => fake()->numberBetween(1, 5),
        ]);
    }

    /**
     * Indicate the device is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
