<?php

namespace Database\Factories;

use App\Models\CloudServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudServer>
 */
class CloudServerFactory extends Factory
{
    protected $model = CloudServer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Production', 'Staging', 'HR Portal', 'ERP']).' Server',
            'api_base_url' => fake()->url(),
            'api_key' => fake()->sha256(),
            'is_active' => true,
            'is_connected' => false,
            'last_successful_sync' => null,
            'last_failed_sync' => null,
            'sync_failure_count' => 0,
        ];
    }

    /**
     * Indicate the server is connected and recently synced.
     */
    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_connected' => true,
            'last_successful_sync' => now(),
            'sync_failure_count' => 0,
        ]);
    }

    /**
     * Indicate the server has sync failures.
     */
    public function failing(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_connected' => false,
            'last_failed_sync' => now()->subMinutes(10),
            'sync_failure_count' => fake()->numberBetween(1, 5),
        ]);
    }

    /**
     * Indicate the server is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
