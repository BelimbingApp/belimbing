<?php
namespace App\Modules\Commerce\Sales\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Commerce\Inventory\Database\Seeders\Dev\DevInventoryItemSeeder;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Commerce\Sales\Models\OrderLine;
use App\Modules\Commerce\Sales\Models\Sale;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Carbon;

class DevSaleSeeder extends DevSeeder
{
    private const TITLE_HEADLIGHT = '2008 Honda Civic driver side headlight';

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
        $thisMonth = $now->copy()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonthNoOverflow();
        $twoMonthsAgo = $thisMonth->copy()->subMonthsNoOverflow(2);

        foreach ($this->rows($thisMonth, $lastMonth, $twoMonthsAgo) as $row) {
            $this->upsertSale($company, $row);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertSale(Company $company, array $row): void
    {
        $item = Item::query()
            ->where('company_id', $company->id)
            ->where('sku', $row['sku'])
            ->first();

        $order = Order::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'channel' => 'ebay',
                'external_order_id' => $row['external_order_id'],
            ],
            [
                'marketplace_id' => 'EBAY_US',
                'buyer_username' => $row['buyer'],
                'buyer_email' => $row['buyer'].'@example.test',
                'status' => 'PAID',
                'ordered_at' => $row['sold_at'],
                'paid_at' => $row['sold_at'],
                'total_amount' => $row['sale_amount'],
                'currency_code' => $row['currency_code'],
                'last_synced_at' => $row['sold_at'],
                'raw_payload' => null,
            ],
        );

        $line = OrderLine::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'external_line_item_id' => $row['external_order_id'].'-L1',
            ],
            [
                'company_id' => $company->id,
                'item_id' => $item?->id,
                'listing_id' => null,
                'external_listing_id' => null,
                'external_sku' => $row['sku'],
                'title' => $row['title'],
                'quantity' => $row['quantity'],
                'unit_price_amount' => intdiv($row['sale_amount'], $row['quantity']),
                'line_total_amount' => $row['sale_amount'],
                'currency_code' => $row['currency_code'],
                'raw_payload' => null,
            ],
        );

        Sale::query()->updateOrCreate(
            ['order_line_id' => $line->id],
            [
                'company_id' => $company->id,
                'order_id' => $order->id,
                'item_id' => $item?->id,
                'listing_id' => null,
                'channel' => 'ebay',
                'external_sale_id' => $row['external_order_id'].'-S1',
                'sold_at' => $row['sold_at'],
                'quantity' => $row['quantity'],
                'sale_amount' => $row['sale_amount'],
                'currency_code' => $row['currency_code'],
                'cost_basis_amount' => $row['cost_basis_amount'],
                'fee_amount' => $row['fee_amount'],
                'raw_payload' => null,
            ],
        );
    }

    /**
     * Demo sales scattered across the current and prior two months so the
     * Insights surfaces ("Sold this month," upcoming "Top earners last 90
     * days") have something to render under the licensee company.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Carbon $thisMonth, Carbon $lastMonth, Carbon $twoMonthsAgo): array
    {
        return [
            [
                'external_order_id' => 'DEMO-ORDER-1001',
                'buyer' => 'demo-buyer-aiden',
                'sku' => 'ITEM-DEMO-HEADLIGHT',
                'title' => self::TITLE_HEADLIGHT,
                'quantity' => 1,
                'sale_amount' => 52000,
                'cost_basis_amount' => 18000,
                'fee_amount' => 4200,
                'currency_code' => 'MYR',
                'sold_at' => $thisMonth->copy()->addDays(2)->setTime(10, 15),
            ],
            [
                'external_order_id' => 'DEMO-ORDER-1002',
                'buyer' => 'demo-buyer-bria',
                'sku' => 'ITEM-DEMO-CAMERA',
                'title' => 'Mirrorless camera body with battery',
                'quantity' => 1,
                'sale_amount' => 145000,
                'cost_basis_amount' => 95000,
                'fee_amount' => 11600,
                'currency_code' => 'MYR',
                'sold_at' => $thisMonth->copy()->addDays(5)->setTime(13, 30),
            ],
            [
                'external_order_id' => 'DEMO-ORDER-1003',
                'buyer' => 'demo-buyer-cole',
                'sku' => 'ITEM-DEMO-JACKET',
                'title' => 'Vintage denim jacket, medium',
                'quantity' => 2,
                'sale_amount' => 25800,
                'cost_basis_amount' => 9000,
                'fee_amount' => 2100,
                'currency_code' => 'MYR',
                'sold_at' => $thisMonth->copy()->addDays(9)->setTime(9, 0),
            ],
            [
                'external_order_id' => 'DEMO-ORDER-1004',
                'buyer' => 'demo-buyer-dani',
                'sku' => 'ITEM-DEMO-HEADLIGHT',
                'title' => self::TITLE_HEADLIGHT,
                'quantity' => 1,
                'sale_amount' => 50000,
                'cost_basis_amount' => 18000,
                'fee_amount' => 4000,
                'currency_code' => 'MYR',
                'sold_at' => $lastMonth->copy()->addDays(4)->setTime(11, 45),
            ],
            [
                'external_order_id' => 'DEMO-ORDER-1005',
                'buyer' => 'demo-buyer-evan',
                'sku' => 'ITEM-DEMO-CAMERA',
                'title' => 'Mirrorless camera body with battery',
                'quantity' => 1,
                'sale_amount' => 142000,
                'cost_basis_amount' => 95000,
                'fee_amount' => 11400,
                'currency_code' => 'MYR',
                'sold_at' => $lastMonth->copy()->addDays(18)->setTime(15, 5),
            ],
            [
                'external_order_id' => 'DEMO-ORDER-1006',
                'buyer' => 'demo-buyer-faye',
                'sku' => 'ITEM-DEMO-JACKET',
                'title' => 'Vintage denim jacket, medium',
                'quantity' => 1,
                'sale_amount' => 12500,
                'cost_basis_amount' => 4500,
                'fee_amount' => 1000,
                'currency_code' => 'MYR',
                'sold_at' => $twoMonthsAgo->copy()->addDays(7)->setTime(16, 20),
            ],
            [
                'external_order_id' => 'DEMO-ORDER-1007',
                'buyer' => 'demo-buyer-gabe',
                'sku' => 'ITEM-DEMO-HEADLIGHT',
                'title' => self::TITLE_HEADLIGHT,
                'quantity' => 1,
                'sale_amount' => 49500,
                'cost_basis_amount' => 18000,
                'fee_amount' => 3960,
                'currency_code' => 'MYR',
                'sold_at' => $twoMonthsAgo->copy()->addDays(22)->setTime(14, 0),
            ],
        ];
    }
}
