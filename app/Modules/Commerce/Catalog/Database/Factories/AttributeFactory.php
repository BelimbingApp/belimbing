<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Database\Factories;

use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = Attribute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Condition Grade',
            'OEM Number',
            'Interchange Number',
            'Fitment Year',
            'Color',
        ]);

        return [
            'company_id' => Company::factory(),
            'category_id' => null,
            'product_template_id' => null,
            'code' => Str::slug($name, '_'),
            'name' => $name,
            'type' => Attribute::TYPE_TEXT,
            'is_required' => false,
            'options' => null,
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }

    public function forCategory(Category $category): self
    {
        return $this->state(fn (): array => [
            'company_id' => $category->company_id,
            'category_id' => $category->id,
        ]);
    }

    public function forProductTemplate(ProductTemplate $productTemplate): self
    {
        return $this->state(fn (): array => [
            'company_id' => $productTemplate->company_id,
            'product_template_id' => $productTemplate->id,
        ]);
    }
}
