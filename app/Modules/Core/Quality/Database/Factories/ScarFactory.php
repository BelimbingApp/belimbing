<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Database\Factories;

use App\Modules\Core\Quality\Models\Ncr;
use App\Modules\Core\Quality\Models\Scar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Scar>
 */
class ScarFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = Scar::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ncr_id' => Ncr::factory(),
            'scar_no' => 'SCAR-'.fake()->unique()->numerify('######'),
            'status' => 'draft',
            'supplier_name' => fake()->company(),
            'supplier_contact_name' => fake()->name(),
            'supplier_contact_email' => fake()->safeEmail(),
            'product_name' => fake()->words(2, true),
            'product_code' => fake()->bothify('??-####'),
            'problem_description' => fake()->paragraph(),
        ];
    }
}
