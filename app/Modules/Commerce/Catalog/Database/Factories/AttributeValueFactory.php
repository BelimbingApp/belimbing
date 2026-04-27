<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Database\Factories;

use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AttributeValue>
 */
class AttributeValueFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = AttributeValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $displayValue = fake()->words(2, true);

        return [
            'item_id' => Item::factory(),
            'attribute_id' => Attribute::factory(),
            'value' => ['text' => $displayValue],
            'display_value' => $displayValue,
        ];
    }
}
