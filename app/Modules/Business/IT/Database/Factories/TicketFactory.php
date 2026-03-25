<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Business\IT\Database\Factories;

use App\Modules\Business\IT\Models\Ticket;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = Ticket::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'reporter_id' => Employee::factory(),
            'assignee_id' => null,
            'status' => 'open',
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'category' => fake()->randomElement(['hardware', 'software', 'network', 'access', 'other']),
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'location' => fake()->optional()->randomElement([
                'Floor 1 - Room 101',
                'Floor 2 - Room 205',
                'Floor 3 - Server Room',
                'Floor 4 - Open Office',
                'Building B - Lab',
            ]),
            'metadata' => null,
        ];
    }

    /**
     * Indicate that the ticket has high priority.
     */
    public function highPriority(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'priority' => 'high',
            ],
        );
    }

    /**
     * Indicate that the ticket has critical priority.
     */
    public function critical(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'priority' => 'critical',
            ],
        );
    }

    /**
     * Indicate that the ticket has been assigned to an employee.
     */
    public function assigned(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'assignee_id' => Employee::factory(),
                'status' => 'assigned',
            ],
        );
    }
}
