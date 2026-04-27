<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Database\Factories;

use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductTemplate>
 */
class ProductTemplateFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = ProductTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'company_id' => Company::factory(),
            'category_id' => null,
            'code' => Str::slug($name),
            'name' => Str::headline($name),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'metadata' => null,
        ];
    }

    public function forCategory(Category $category): self
    {
        return $this->state(fn (): array => [
            'company_id' => $category->company_id,
            'category_id' => $category->id,
        ]);
    }
}
