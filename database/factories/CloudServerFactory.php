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
            'base_url' => fake()->url(),
            'api_key' => fake()->sha256(),
            'is_active' => true,
            'last_sync_at' => null,
            'last_sync_status' => null,
            'sync_failures' => 0,
        ];
    }

    /**
     * Indicate the server is connected and recently synced.
     */
    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_sync_at' => now(),
            'last_sync_status' => 'success',
            'sync_failures' => 0,
        ]);
    }

    /**
     * Indicate the server has sync failures.
     */
    public function failing(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_sync_at' => now()->subMinutes(10),
            'last_sync_status' => 'failed',
            'sync_failures' => fake()->numberBetween(1, 5),
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
