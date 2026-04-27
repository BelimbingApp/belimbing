<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Database\Factories;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_number' => fake()->unique()->numerify('EMP-#####'),
            'full_name' => fake()->name(),
            'short_name' => fake()->optional()->firstName(),
            'status' => 'active', // active, inactive, terminated, pending
            'employment_start' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'employment_end' => null,
            'email' => fake()->safeEmail(),
            'mobile_number' => fake()->phoneNumber(),
            'metadata' => [],
        ];
    }

    /**
     * Indicate that the employee is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'employment_end' => null,
        ]);
    }

    /**
     * Indicate that the employee is terminated.
     */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terminated',
            'employment_end' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
        ]);
    }
}
