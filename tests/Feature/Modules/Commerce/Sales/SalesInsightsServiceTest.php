<?php

use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Commerce\Sales\Models\OrderLine;
use App\Modules\Commerce\Sales\Models\Sale;
use App\Modules\Commerce\Sales\Services\SalesInsightsService;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Carbon;

const SALES_INSIGHTS_FROM = '2026-04-01';
const SALES_INSIGHTS_TO = '2026-04-30 23:59:59';
const SALES_INSIGHTS_CURRENCY_USD = 'USD';

it('aggregates revenue, cost, fees, and unit count for the requested company and period', function (): void {
    $company = Company::factory()->create();

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-10'),
        'quantity' => 2,
        'sale_amount' => 5000,
        'cost_basis_amount' => 1500,
        'fee_amount' => 400,
    ]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-20'),
        'quantity' => 1,
        'sale_amount' => 3000,
        'cost_basis_amount' => 800,
        'fee_amount' => 200,
    ]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-05-05'),
        'sale_amount' => 9999,
    ]);

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($summary->saleCount)->toBe(2)
        ->and($summary->unitCount)->toBe(3)
        ->and($summary->totalRevenueMinor)->toBe(8000)
        ->and($summary->totalCostMinor)->toBe(2300)
        ->and($summary->totalFeesMinor)->toBe(600)
        ->and($summary->grossProfitMinor())->toBe(5100)
        ->and($summary->currencyCode)->toBe('USD');
});

it('treats missing cost and fee values as zero contributions', function (): void {
    $company = Company::factory()->create();

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-10'),
        'sale_amount' => 4000,
        'cost_basis_amount' => null,
        'fee_amount' => null,
    ]);

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($summary->totalRevenueMinor)->toBe(4000)
        ->and($summary->totalCostMinor)->toBe(0)
        ->and($summary->totalFeesMinor)->toBe(0)
        ->and($summary->grossProfitMinor())->toBe(4000);
});

it('excludes sales from other companies', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    Sale::factory()->create([
        'company_id' => $companyA->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-10'),
        'sale_amount' => 1000,
    ]);

    Sale::factory()->create([
        'company_id' => $companyB->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-15'),
        'sale_amount' => 99999,
    ]);

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $companyA->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($summary->saleCount)->toBe(1)
        ->and($summary->totalRevenueMinor)->toBe(1000);
});

it('excludes sales in a different currency', function (): void {
    $company = Company::factory()->create();

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-10'),
        'sale_amount' => 1000,
    ]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => 'MYR',
        'sold_at' => Carbon::parse('2026-04-15'),
        'sale_amount' => 5000,
    ]);

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($summary->saleCount)->toBe(1)
        ->and($summary->totalRevenueMinor)->toBe(1000);
});

it('returns zeros when no sales fall in the window', function (): void {
    $company = Company::factory()->create();

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($summary->saleCount)->toBe(0)
        ->and($summary->unitCount)->toBe(0)
        ->and($summary->totalRevenueMinor)->toBe(0)
        ->and($summary->totalCostMinor)->toBe(0)
        ->and($summary->totalFeesMinor)->toBe(0)
        ->and($summary->grossProfitMinor())->toBe(0);
});

it('aggregates margin per item and orders rows by gross profit descending', function (): void {
    $company = Company::factory()->create();
    $headlight = Item::factory()->create(['company_id' => $company->id]);
    $bumper = Item::factory()->create(['company_id' => $company->id]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $headlight->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-05'),
        'quantity' => 1,
        'sale_amount' => 4000,
        'cost_basis_amount' => 1000,
        'fee_amount' => 300,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $headlight->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-15'),
        'quantity' => 2,
        'sale_amount' => 6000,
        'cost_basis_amount' => 2000,
        'fee_amount' => 400,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $bumper->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-20'),
        'quantity' => 1,
        'sale_amount' => 2500,
        'cost_basis_amount' => 800,
        'fee_amount' => 200,
    ]);

    $rows = app(SalesInsightsService::class)->marginPerItem(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($rows)->toHaveCount(2);

    $top = $rows->first();
    expect($top->itemId)->toBe($headlight->id)
        ->and($top->saleCount)->toBe(2)
        ->and($top->unitCount)->toBe(3)
        ->and($top->totalRevenueMinor)->toBe(10000)
        ->and($top->totalCostMinor)->toBe(3000)
        ->and($top->totalFeesMinor)->toBe(700)
        ->and($top->grossProfitMinor())->toBe(6300);

    $second = $rows->last();
    expect($second->itemId)->toBe($bumper->id)
        ->and($second->grossProfitMinor())->toBe(1500);
});

it('groups unmatched sales under a null item id row', function (): void {
    $company = Company::factory()->create();
    $linked = Item::factory()->create(['company_id' => $company->id]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $linked->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-05'),
        'sale_amount' => 5000,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => null,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-10'),
        'sale_amount' => 1500,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => null,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-12'),
        'sale_amount' => 1500,
    ]);

    $rows = app(SalesInsightsService::class)->marginPerItem(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($rows)->toHaveCount(2);

    $unmatched = $rows->firstWhere(fn ($row) => $row->itemId === null);
    expect($unmatched)->not->toBeNull()
        ->and($unmatched->saleCount)->toBe(2)
        ->and($unmatched->totalRevenueMinor)->toBe(3000);
});

it('honors the limit when ranking top items by gross profit', function (): void {
    $company = Company::factory()->create();
    $a = Item::factory()->create(['company_id' => $company->id]);
    $b = Item::factory()->create(['company_id' => $company->id]);
    $c = Item::factory()->create(['company_id' => $company->id]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $a->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-05'),
        'sale_amount' => 5000,
        'cost_basis_amount' => 1000,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $b->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-06'),
        'sale_amount' => 8000,
        'cost_basis_amount' => 1000,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $c->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-07'),
        'sale_amount' => 3000,
        'cost_basis_amount' => 1000,
    ]);

    $rows = app(SalesInsightsService::class)->marginPerItem(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        limit: 2,
    );

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('itemId')->all())->toBe([$b->id, $a->id]);
});

it('returns active listings without sales ordered oldest first', function (): void {
    $company = Company::factory()->create();
    $asOf = Carbon::parse('2026-05-01 12:00:00');

    $oldest = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => 'USD',
        'listed_at' => $asOf->copy()->subDays(45),
    ]);
    $middle = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => 'USD',
        'listed_at' => $asOf->copy()->subDays(20),
    ]);
    $newest = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => 'USD',
        'listed_at' => $asOf->copy()->subDays(3),
    ]);

    $rows = app(SalesInsightsService::class)->daysListedWithoutSale(
        companyId: $company->id,
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        asOf: $asOf,
    );

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('listingId')->all())->toBe([$oldest->id, $middle->id, $newest->id])
        ->and($rows->pluck('daysListed')->all())->toBe([45, 20, 3]);
});

it('excludes listings that already produced a sale', function (): void {
    $company = Company::factory()->create();
    $asOf = Carbon::parse('2026-05-01 12:00:00');

    $sold = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(30),
    ]);
    $unsold = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(10),
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'listing_id' => $sold->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => $asOf->copy()->subDays(2),
    ]);

    $rows = app(SalesInsightsService::class)->daysListedWithoutSale(
        companyId: $company->id,
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        asOf: $asOf,
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->listingId)->toBe($unsold->id);
});

it('excludes ended listings and listings missing listed_at', function (): void {
    $company = Company::factory()->create();
    $asOf = Carbon::parse('2026-05-01 12:00:00');

    Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(30),
        'ended_at' => $asOf->copy()->subDays(1),
    ]);
    Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => null,
    ]);
    $kept = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(15),
    ]);

    $rows = app(SalesInsightsService::class)->daysListedWithoutSale(
        companyId: $company->id,
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        asOf: $asOf,
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->listingId)->toBe($kept->id);
});

it('scopes listings by company and currency', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $asOf = Carbon::parse('2026-05-01 12:00:00');

    $kept = Listing::factory()->create([
        'company_id' => $companyA->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(20),
    ]);
    Listing::factory()->create([
        'company_id' => $companyB->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(20),
    ]);
    Listing::factory()->create([
        'company_id' => $companyA->id,
        'currency_code' => 'MYR',
        'listed_at' => $asOf->copy()->subDays(20),
    ]);

    $rows = app(SalesInsightsService::class)->daysListedWithoutSale(
        companyId: $companyA->id,
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        asOf: $asOf,
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->listingId)->toBe($kept->id);
});

it('honors minDaysListed and limit when ranking aged listings', function (): void {
    $company = Company::factory()->create();
    $asOf = Carbon::parse('2026-05-01 12:00:00');

    Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(2),
    ]);
    $aged30 = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(30),
    ]);
    $aged60 = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(60),
    ]);
    $aged90 = Listing::factory()->create([
        'company_id' => $company->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'listed_at' => $asOf->copy()->subDays(90),
    ]);

    $rows = app(SalesInsightsService::class)->daysListedWithoutSale(
        companyId: $company->id,
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        asOf: $asOf,
        minDaysListed: 14,
        limit: 2,
    );

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('listingId')->all())->toBe([$aged90->id, $aged60->id]);

    $unfiltered = app(SalesInsightsService::class)->daysListedWithoutSale(
        companyId: $company->id,
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        asOf: $asOf,
        minDaysListed: 14,
    );

    expect($unfiltered->pluck('listingId')->all())->toBe([$aged90->id, $aged60->id, $aged30->id]);
});

it('lists sales newest first with linked item title and category', function (): void {
    $company = Company::factory()->create();
    $lighting = Category::factory()->create(['company_id' => $company->id, 'name' => 'Lighting']);
    $headlight = Item::factory()->create([
        'company_id' => $company->id,
        'title' => '2008 Civic headlight',
        'category_id' => $lighting->id,
    ]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $headlight->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-10 10:00:00'),
        'sale_amount' => 5000,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $headlight->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-20 10:00:00'),
        'sale_amount' => 6000,
    ]);

    $rows = app(SalesInsightsService::class)->salesInPeriod(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($rows)->toHaveCount(2)
        ->and($rows->first()->soldAt->toDateString())->toBe('2026-04-20')
        ->and($rows->first()->title)->toBe('2008 Civic headlight')
        ->and($rows->first()->categoryName)->toBe('Lighting')
        ->and($rows->last()->soldAt->toDateString())->toBe('2026-04-10');
});

it('falls back to order line title then sku when no item is linked', function (): void {
    $company = Company::factory()->create();

    $orderA = Order::factory()->create(['company_id' => $company->id]);
    $lineA = OrderLine::factory()->create([
        'company_id' => $company->id,
        'order_id' => $orderA->id,
        'title' => 'Salvaged Toyota mirror',
        'external_sku' => 'EBAY-MIR-1',
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'order_id' => $orderA->id,
        'order_line_id' => $lineA->id,
        'item_id' => null,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-15 10:00:00'),
    ]);

    $orderB = Order::factory()->create(['company_id' => $company->id]);
    $lineB = OrderLine::factory()->create([
        'company_id' => $company->id,
        'order_id' => $orderB->id,
        'title' => null,
        'external_sku' => 'EBAY-BUMPER-2',
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'order_id' => $orderB->id,
        'order_line_id' => $lineB->id,
        'item_id' => null,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-10 10:00:00'),
    ]);

    $rows = app(SalesInsightsService::class)->salesInPeriod(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($rows->pluck('title')->all())->toBe(['Salvaged Toyota mirror', 'EBAY-BUMPER-2'])
        ->and($rows->pluck('categoryName')->all())->toBe([null, null]);
});

it('honors the limit when listing recent sales', function (): void {
    $company = Company::factory()->create();

    $sales = [];
    foreach (range(1, 5) as $i) {
        $sales[] = Sale::factory()->create([
            'company_id' => $company->id,
            'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
            'sold_at' => Carbon::parse('2026-04-10')->addDays($i),
        ]);
    }

    $rows = app(SalesInsightsService::class)->salesInPeriod(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        limit: 3,
    );

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('saleId')->all())->toBe([
            $sales[4]->id,
            $sales[3]->id,
            $sales[2]->id,
        ])
        ->and($rows->pluck('soldAt')->map(fn (Carbon $c) => $c->toDateString())->all())->toBe([
            '2026-04-15',
            '2026-04-14',
            '2026-04-13',
        ]);
});

it('aggregates sales by category ordered by frequency', function (): void {
    $company = Company::factory()->create();
    $lighting = Category::factory()->create(['company_id' => $company->id, 'name' => 'Lighting']);
    $bumpers = Category::factory()->create(['company_id' => $company->id, 'name' => 'Bumpers']);

    $headlight = Item::factory()->create(['company_id' => $company->id, 'category_id' => $lighting->id]);
    $taillight = Item::factory()->create(['company_id' => $company->id, 'category_id' => $lighting->id]);
    $bumper = Item::factory()->create(['company_id' => $company->id, 'category_id' => $bumpers->id]);

    foreach ([$headlight, $headlight, $taillight] as $i => $item) {
        Sale::factory()->create([
            'company_id' => $company->id,
            'item_id' => $item->id,
            'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
            'sold_at' => Carbon::parse('2026-04-10')->addDays($i),
            'quantity' => 1,
            'sale_amount' => 5000,
            'cost_basis_amount' => 1000,
            'fee_amount' => 200,
        ]);
    }
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $bumper->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-15'),
        'quantity' => 1,
        'sale_amount' => 7000,
        'cost_basis_amount' => 2000,
        'fee_amount' => 300,
    ]);

    $rows = app(SalesInsightsService::class)->salesByCategory(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($rows)->toHaveCount(2)
        ->and($rows->first()->categoryName)->toBe('Lighting')
        ->and($rows->first()->saleCount)->toBe(3)
        ->and($rows->first()->totalRevenueMinor)->toBe(15000)
        ->and($rows->first()->grossProfitMinor())->toBe(15000 - 3000 - 600)
        ->and($rows->last()->categoryName)->toBe('Bumpers')
        ->and($rows->last()->saleCount)->toBe(1);
});

it('buckets uncategorized sales under a null category row', function (): void {
    $company = Company::factory()->create();
    $lighting = Category::factory()->create(['company_id' => $company->id, 'name' => 'Lighting']);
    $headlight = Item::factory()->create(['company_id' => $company->id, 'category_id' => $lighting->id]);
    $loose = Item::factory()->create(['company_id' => $company->id, 'category_id' => null]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $headlight->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-05'),
        'sale_amount' => 5000,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => $loose->id,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-10'),
        'sale_amount' => 3000,
    ]);
    Sale::factory()->create([
        'company_id' => $company->id,
        'item_id' => null,
        'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
        'sold_at' => Carbon::parse('2026-04-12'),
        'sale_amount' => 1500,
    ]);

    $rows = app(SalesInsightsService::class)->salesByCategory(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
    );

    expect($rows)->toHaveCount(2);

    $uncategorized = $rows->firstWhere(fn ($row) => $row->categoryId === null);
    expect($uncategorized)->not->toBeNull()
        ->and($uncategorized->categoryName)->toBeNull()
        ->and($uncategorized->saleCount)->toBe(2)
        ->and($uncategorized->totalRevenueMinor)->toBe(4500);
});

it('honors the limit when ranking categories by frequency', function (): void {
    $company = Company::factory()->create();
    $a = Category::factory()->create(['company_id' => $company->id, 'name' => 'A']);
    $b = Category::factory()->create(['company_id' => $company->id, 'name' => 'B']);
    $c = Category::factory()->create(['company_id' => $company->id, 'name' => 'C']);

    foreach ([[$a, 3], [$b, 2], [$c, 1]] as [$cat, $count]) {
        $item = Item::factory()->create(['company_id' => $company->id, 'category_id' => $cat->id]);
        for ($i = 0; $i < $count; $i++) {
            Sale::factory()->create([
                'company_id' => $company->id,
                'item_id' => $item->id,
                'currency_code' => SALES_INSIGHTS_CURRENCY_USD,
                'sold_at' => Carbon::parse('2026-04-10')->addHours($i),
                'sale_amount' => 1000,
            ]);
        }
    }

    $rows = app(SalesInsightsService::class)->salesByCategory(
        companyId: $company->id,
        from: Carbon::parse(SALES_INSIGHTS_FROM),
        to: Carbon::parse(SALES_INSIGHTS_TO),
        currencyCode: SALES_INSIGHTS_CURRENCY_USD,
        limit: 2,
    );

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('categoryName')->all())->toBe(['A', 'B']);
});
