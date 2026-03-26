<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Database\Factories;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Quality\Models\Ncr;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Ncr>
 */
class NcrFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = Ncr::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'ncr_no' => 'NCR-'.fake()->unique()->numerify('######'),
            'ncr_kind' => fake()->randomElement(['internal', 'customer']),
            'source' => 'manual',
            'status' => 'open',
            'severity' => fake()->randomElement(['critical', 'major', 'minor', 'observation']),
            'title' => fake()->sentence(),
            'summary' => fake()->paragraph(),
            'product_name' => fake()->words(2, true),
            'product_code' => fake()->bothify('??-####'),
            'quantity_affected' => fake()->randomFloat(2, 1, 1000),
            'uom' => fake()->randomElement(['pcs', 'kg', 'mtrs', 'sets']),
            'reported_at' => now(),
            'reported_by_name' => fake()->name(),
            'reported_by_email' => fake()->safeEmail(),
        ];
    }
}
