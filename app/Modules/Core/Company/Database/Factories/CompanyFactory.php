<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Database\Factories;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\LegalEntityType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyName = fake()->company();

        return [
            'name' => $companyName,
            'code' => null, // Will be auto-generated from name
            'status' => 'active',
            'legal_name' => $companyName.' '.fake()->companySuffix(),
            'registration_number' => fake()->numerify('##########'),
            'tax_id' => fake()->numerify('##-#######'),
            'legal_entity_type_id' => LegalEntityType::query()->inRandomOrder()->value('id'),
            'jurisdiction' => fake()->country(),
            'email' => fake()->companyEmail(),
            'website' => fake()->domainName(),
            'scope_activities' => fake()->randomElements([
                'manufacturing',
                'technology',
                'retail',
                'services',
                'healthcare',
                'finance',
                'wholesale',
                'export',
                'import',
                'logistics',
                'consulting',
            ], fake()->numberBetween(2, 5)),
            'metadata' => [
                'employee_count' => fake()->optional()->numberBetween(1, 10000),
                'founded_year' => fake()->optional()->numberBetween(1950, 2026),
            ],
        ];
    }

    /**
     * Indicate that the company is a parent company.
     */
    public function parent(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'parent_id' => null,
            ],
        );
    }

    /**
     * Indicate that the company is a subsidiary.
     */
    public function subsidiary(?string $parentId = null): static
    {
        return $this->state(
            fn (array $attributes) => [
                'parent_id' => $parentId ?? Company::factory()->parent(),
            ],
        );
    }

    /**
     * Indicate that the company is active.
     */
    public function active(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'status' => 'active',
            ],
        );
    }

    /**
     * Indicate that the company is suspended.
     */
    public function suspended(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'status' => 'suspended',
            ],
        );
    }

    /**
     * Indicate that the company is pending.
     */
    public function pending(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'status' => 'pending',
            ],
        );
    }

    /**
     * Indicate that the company is archived.
     */
    public function archived(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'status' => 'archived',
            ],
        );
    }

    /**
     * Create a company with minimal data.
     */
    public function minimal(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'legal_name' => null,
                'registration_number' => null,
                'tax_id' => null,
                'legal_entity_type_id' => null,
                'jurisdiction' => null,
                'email' => null,
                'website' => null,
                'scope_activities' => null,
                'metadata' => null,
            ],
        );
    }
}
