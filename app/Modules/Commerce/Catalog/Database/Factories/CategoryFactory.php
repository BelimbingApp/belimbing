<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Database\Factories;

use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'company_id' => Company::factory(),
            'parent_id' => null,
            'code' => Str::slug($name),
            'name' => Str::headline($name),
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }
}
