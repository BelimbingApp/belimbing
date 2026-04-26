<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Database\Factories;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->randomElement([
            'Driver side headlight assembly',
            'Mirrorless camera body',
            'Vintage denim jacket',
            'OEM alternator bracket',
            'Used compact bookshelf speaker',
        ]);

        return [
            'company_id' => Company::factory(),
            'sku' => 'ITEM-'.fake()->unique()->bothify('####??'),
            'status' => fake()->randomElement(Item::statuses()),
            'title' => $title,
            'description' => fake()->optional()->paragraph(),
            'unit_cost_amount' => fake()->optional()->numberBetween(1000, 15000),
            'target_price_amount' => fake()->optional()->numberBetween(2500, 35000),
            'currency_code' => 'MYR',
            'attributes' => null,
        ];
    }
}
