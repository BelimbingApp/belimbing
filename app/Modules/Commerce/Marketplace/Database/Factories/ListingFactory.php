<?php

namespace App\Modules\Commerce\Marketplace\Database\Factories;

use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = Listing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'item_id' => null,
            'channel' => 'ebay',
            'external_listing_id' => 'LISTING-'.fake()->unique()->numerify('########'),
            'external_offer_id' => null,
            'external_sku' => null,
            'marketplace_id' => 'EBAY_US',
            'title' => fake()->sentence(4),
            'status' => 'ACTIVE',
            'management_state' => 'imported',
            'drift_status' => 'unknown',
            'drift_summary' => null,
            'price_amount' => fake()->numberBetween(1000, 50000),
            'currency_code' => 'USD',
            'listing_url' => fake()->url(),
            'listed_at' => now()->subDays(7),
            'ended_at' => null,
            'last_synced_at' => now(),
            'raw_payload' => null,
        ];
    }
}
