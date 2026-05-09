<?php
namespace App\Modules\Commerce\Marketplace\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Commerce\Inventory\Database\Seeders\Dev\DevInventoryItemSeeder;
use App\Modules\Commerce\Marketplace\Models\Listing;
use Illuminate\Support\Carbon;

class DevListingSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevInventoryItemSeeder::class,
    ];

    protected function seed(): void
    {
        $company = $this->licenseeCompany();

        if ($company === null) {
            return;
        }

        $now = Carbon::now();

        foreach ($this->rows($now) as $row) {
            Listing::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'channel' => 'ebay',
                    'external_listing_id' => $row['external_listing_id'],
                ],
                [
                    'item_id' => null,
                    'external_offer_id' => null,
                    'external_sku' => $row['external_sku'],
                    'marketplace_id' => 'EBAY_US',
                    'title' => $row['title'],
                    'status' => 'ACTIVE',
                    'price_amount' => $row['price_amount'],
                    'currency_code' => 'MYR',
                    'listing_url' => null,
                    'listed_at' => $row['listed_at'],
                    'ended_at' => null,
                    'last_synced_at' => $now,
                    'raw_payload' => null,
                ],
            );
        }
    }

    /**
     * Demo unsold listings spread across recent and aging windows so the
     * `daysListedWithoutSale` Insights query has rows to surface in the
     * licensee dev environment.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Carbon $now): array
    {
        return [
            [
                'external_listing_id' => 'DEMO-LISTING-2001',
                'external_sku' => 'DEMO-SKU-2001',
                'title' => '1998 Toyota Corolla side mirror — left',
                'price_amount' => 5500,
                'listed_at' => $now->copy()->subDays(245),
            ],
            [
                'external_listing_id' => 'DEMO-LISTING-2002',
                'external_sku' => 'DEMO-SKU-2002',
                'title' => 'Mazda RX-7 FD3S front grille',
                'price_amount' => 17500,
                'listed_at' => $now->copy()->subDays(210),
            ],
            [
                'external_listing_id' => 'DEMO-LISTING-2003',
                'external_sku' => 'DEMO-SKU-2003',
                'title' => 'Honda Civic EK9 OEM tail lamp',
                'price_amount' => 9800,
                'listed_at' => $now->copy()->subDays(186),
            ],
            [
                'external_listing_id' => 'DEMO-LISTING-2004',
                'external_sku' => 'DEMO-SKU-2004',
                'title' => 'Nissan Skyline R34 brake caliper',
                'price_amount' => 32000,
                'listed_at' => $now->copy()->subDays(60),
            ],
            [
                'external_listing_id' => 'DEMO-LISTING-2005',
                'external_sku' => 'DEMO-SKU-2005',
                'title' => 'Suzuki Swift radiator hose set',
                'price_amount' => 4200,
                'listed_at' => $now->copy()->subDays(14),
            ],
        ];
    }
}
