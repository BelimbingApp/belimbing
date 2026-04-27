<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Database\Factories;

use App\Modules\Core\Company\Models\RelationshipType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<RelationshipType>
 */
class RelationshipTypeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = RelationshipType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('??????'),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'is_external' => fake()->boolean(),
            'is_active' => true,
            'metadata' => [
                'default_permissions' => fake()->randomElements(
                    ['view_all', 'edit_all', 'view_orders', 'submit_invoices', 'view_documents'],
                    fake()->numberBetween(1, 3)
                ),
            ],
        ];
    }

    /**
     * Indicate that the relationship type is external.
     */
    public function external(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_external' => true,
        ]);
    }

    /**
     * Indicate that the relationship type is internal.
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_external' => false,
        ]);
    }

    /**
     * Indicate that the relationship type is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate that the relationship type is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a customer relationship type.
     */
    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'customer',
            'name' => 'Customer',
            'description' => 'Customer relationship - company purchases from us',
            'is_external' => true,
            'metadata' => [
                'default_permissions' => ['view_orders', 'view_invoices', 'view_statements'],
            ],
        ]);
    }

    /**
     * Create a supplier relationship type.
     */
    public function supplier(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'supplier',
            'name' => 'Supplier',
            'description' => 'Supplier relationship - we purchase from this company',
            'is_external' => true,
            'metadata' => [
                'default_permissions' => ['view_purchase_orders', 'submit_invoices'],
            ],
        ]);
    }

    /**
     * Create an internal relationship type.
     */
    public function internalType(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'internal',
            'name' => 'Internal',
            'description' => 'Internal company relationship within the same group/organization',
            'is_external' => false,
            'metadata' => [
                'default_permissions' => ['view_all', 'edit_all'],
            ],
        ]);
    }

    /**
     * Create a partner relationship type.
     */
    public function partner(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'partner',
            'name' => 'Partner',
            'description' => 'Business partner relationship - collaborative business relationship',
            'is_external' => true,
            'metadata' => [
                'default_permissions' => ['view_shared_projects', 'view_shared_documents'],
            ],
        ]);
    }

    /**
     * Create an agency relationship type.
     */
    public function agency(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'agency',
            'name' => 'Agency',
            'description' => 'Agency relationship - company acts on our behalf',
            'is_external' => true,
            'metadata' => [
                'default_permissions' => ['view_shipments', 'submit_documents'],
            ],
        ]);
    }
}
