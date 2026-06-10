<?php

namespace App\Modules\Commerce\Inventory\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Core\Company\Database\Seeders\Dev\DevCompanyAddressSeeder;

class DevInventoryItemSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevCompanyAddressSeeder::class,
    ];

    protected function seed(): void
    {
        $company = $this->licenseeCompany();

        if ($company === null) {
            return;
        }

        foreach ($this->items() as $item) {
            Item::query()->updateOrCreate(
                [
                    'sku' => $item['sku'],
                ],
                array_merge($item, [
                    'company_id' => $company->id,
                    'currency_code' => 'MYR',
                ]),
            );
        }
    }

    /**
     * @return array<int, array{sku: string, status: string, title: string, notes: string, unit_cost_amount: int, target_price_amount: int}>
     */
    private function items(): array
    {
        return [
            [
                'sku' => 'ITEM-DEMO-HEADLIGHT',
                'status' => Item::STATUS_DRAFT,
                'title' => '2008 Honda Civic driver side headlight',
                'notes' => 'Generic commerce item seeded from Ham-shaped workflow: condition notes, identifiers, and pricing are captured before photos and AI drafts.',
                'unit_cost_amount' => 18000,
                'target_price_amount' => 52000,
            ],
            [
                'sku' => 'ITEM-DEMO-CAMERA',
                'status' => Item::STATUS_READY,
                'title' => 'Mirrorless camera body with battery',
                'notes' => 'Used electronics example showing the same Inventory can represent non-auto goods.',
                'unit_cost_amount' => 95000,
                'target_price_amount' => 145000,
            ],
            [
                'sku' => 'ITEM-DEMO-JACKET',
                'status' => Item::STATUS_LISTED,
                'title' => 'Vintage denim jacket, medium',
                'notes' => 'Apparel example for catalog attributes such as size, condition, and measurements.',
                'unit_cost_amount' => 4500,
                'target_price_amount' => 12900,
            ],
            // Used auto-parts examples (relocated from the old listing seed). They are
            // inventory items ready to push; real eBay listings appear only after a push
            // or a pull, so the eBay Listings page mirrors the actual eBay store.
            [
                'sku' => 'DEMO-SKU-2001',
                'status' => Item::STATUS_READY,
                'title' => '1998 Toyota Corolla side mirror — left',
                'notes' => 'Used OEM left side mirror. Tested, intact glass, minor housing scuffs.',
                'unit_cost_amount' => 2500,
                'target_price_amount' => 5500,
            ],
            [
                'sku' => 'DEMO-SKU-2002',
                'status' => Item::STATUS_READY,
                'title' => 'Mazda RX-7 FD3S front grille',
                'notes' => 'Used front grille for FD3S. Some age marks; mounting tabs intact.',
                'unit_cost_amount' => 9000,
                'target_price_amount' => 17500,
            ],
            [
                'sku' => 'DEMO-SKU-2003',
                'status' => Item::STATUS_READY,
                'title' => 'Honda Civic EK9 OEM tail lamp',
                'notes' => 'Genuine EK9 tail lamp, right side. No cracks, seals good.',
                'unit_cost_amount' => 5000,
                'target_price_amount' => 9800,
            ],
            [
                'sku' => 'DEMO-SKU-2004',
                'status' => Item::STATUS_READY,
                'title' => 'Nissan Skyline R34 brake caliper',
                'notes' => 'Front brake caliper, rebuilt. Bench-tested, no leaks.',
                'unit_cost_amount' => 18000,
                'target_price_amount' => 32000,
            ],
            [
                'sku' => 'DEMO-SKU-2005',
                'status' => Item::STATUS_DRAFT,
                'title' => 'Suzuki Swift radiator hose set',
                'notes' => 'Upper and lower hoses. New old stock.',
                'unit_cost_amount' => 1800,
                'target_price_amount' => 4200,
            ],
        ];
    }
}
