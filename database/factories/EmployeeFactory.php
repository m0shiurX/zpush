<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_uid' => fake()->unique()->numberBetween(1, 9999),
            'name' => fake()->name(),
            'employee_code' => fake()->unique()->numerify('EMP-####'),
            'card_number' => (string) fake()->optional(0.5)->numberBetween(1000000, 9999999),
            'department' => fake()->optional(0.5)->randomElement(['HR', 'IT', 'Finance', 'Sales']),
            'is_active' => true,
            'cloud_synced_at' => null,
            'cloud_id' => null,
        ];
    }

    /**
     * Indicate the employee has been synced to cloud.
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'cloud_synced_at' => now(),
            'cloud_id' => fake()->numberBetween(1, 9999),
        ]);
    }

    /**
     * Indicate the employee is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
