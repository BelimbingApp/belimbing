<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Database\Factories;

use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'channel' => 'ebay',
            'external_order_id' => 'ORDER-'.fake()->unique()->numerify('########'),
            'marketplace_id' => 'EBAY_US',
            'buyer_username' => fake()->userName(),
            'buyer_email' => fake()->safeEmail(),
            'status' => 'PAID',
            'ordered_at' => now(),
            'paid_at' => now(),
            'fulfilled_at' => null,
            'total_amount' => fake()->numberBetween(1000, 50000),
            'currency_code' => 'USD',
            'last_synced_at' => now(),
            'raw_payload' => null,
        ];
    }
}
