<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Database\Factories;

use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Commerce\Sales\Models\OrderLine;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = OrderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(1000, 50000);

        return [
            'company_id' => Company::factory(),
            'order_id' => Order::factory(),
            'item_id' => null,
            'listing_id' => null,
            'external_line_item_id' => 'LINE-'.fake()->unique()->numerify('######'),
            'external_listing_id' => fake()->numerify('##########'),
            'external_sku' => fake()->bothify('SKU-####'),
            'title' => fake()->words(4, true),
            'quantity' => 1,
            'unit_price_amount' => $unitPrice,
            'line_total_amount' => $unitPrice,
            'currency_code' => 'USD',
            'raw_payload' => null,
        ];
    }
}
