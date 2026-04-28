<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
                'notes' => 'Used electronics example showing the same Inventory Workbench can represent non-auto goods.',
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
        ];
    }
}
