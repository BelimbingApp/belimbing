<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Database\Factories;

use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Commerce\Sales\Models\OrderLine;
use App\Modules\Commerce\Sales\Models\Sale;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = Sale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'order_id' => Order::factory(),
            'order_line_id' => OrderLine::factory(),
            'item_id' => null,
            'listing_id' => null,
            'channel' => 'ebay',
            'external_sale_id' => 'SALE-'.fake()->unique()->numerify('########'),
            'sold_at' => now(),
            'quantity' => 1,
            'sale_amount' => fake()->numberBetween(1000, 50000),
            'currency_code' => 'USD',
            'cost_basis_amount' => null,
            'fee_amount' => null,
            'raw_payload' => null,
        ];
    }
}
