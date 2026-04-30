<?php

use App\Modules\Commerce\Sales\Models\Sale;
use App\Modules\Commerce\Sales\Services\SalesInsightsService;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Carbon;

it('aggregates revenue, cost, fees, and unit count for the requested company and period', function (): void {
    $company = Company::factory()->create();

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => 'USD',
        'sold_at' => Carbon::parse('2026-04-10'),
        'quantity' => 2,
        'sale_amount' => 5000,
        'cost_basis_amount' => 1500,
        'fee_amount' => 400,
    ]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => 'USD',
        'sold_at' => Carbon::parse('2026-04-20'),
        'quantity' => 1,
        'sale_amount' => 3000,
        'cost_basis_amount' => 800,
        'fee_amount' => 200,
    ]);

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => 'USD',
        'sold_at' => Carbon::parse('2026-05-05'),
        'sale_amount' => 9999,
    ]);

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $company->id,
        from: Carbon::parse('2026-04-01'),
        to: Carbon::parse('2026-04-30 23:59:59'),
        currencyCode: 'USD',
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
        'currency_code' => 'USD',
        'sold_at' => Carbon::parse('2026-04-10'),
        'sale_amount' => 4000,
        'cost_basis_amount' => null,
        'fee_amount' => null,
    ]);

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $company->id,
        from: Carbon::parse('2026-04-01'),
        to: Carbon::parse('2026-04-30 23:59:59'),
        currencyCode: 'USD',
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
        'currency_code' => 'USD',
        'sold_at' => Carbon::parse('2026-04-10'),
        'sale_amount' => 1000,
    ]);

    Sale::factory()->create([
        'company_id' => $companyB->id,
        'currency_code' => 'USD',
        'sold_at' => Carbon::parse('2026-04-15'),
        'sale_amount' => 99999,
    ]);

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $companyA->id,
        from: Carbon::parse('2026-04-01'),
        to: Carbon::parse('2026-04-30 23:59:59'),
        currencyCode: 'USD',
    );

    expect($summary->saleCount)->toBe(1)
        ->and($summary->totalRevenueMinor)->toBe(1000);
});

it('excludes sales in a different currency', function (): void {
    $company = Company::factory()->create();

    Sale::factory()->create([
        'company_id' => $company->id,
        'currency_code' => 'USD',
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
        from: Carbon::parse('2026-04-01'),
        to: Carbon::parse('2026-04-30 23:59:59'),
        currencyCode: 'USD',
    );

    expect($summary->saleCount)->toBe(1)
        ->and($summary->totalRevenueMinor)->toBe(1000);
});

it('returns zeros when no sales fall in the window', function (): void {
    $company = Company::factory()->create();

    $summary = app(SalesInsightsService::class)->soldInPeriod(
        companyId: $company->id,
        from: Carbon::parse('2026-04-01'),
        to: Carbon::parse('2026-04-30 23:59:59'),
        currencyCode: 'USD',
    );

    expect($summary->saleCount)->toBe(0)
        ->and($summary->unitCount)->toBe(0)
        ->and($summary->totalRevenueMinor)->toBe(0)
        ->and($summary->totalCostMinor)->toBe(0)
        ->and($summary->totalFeesMinor)->toBe(0)
        ->and($summary->grossProfitMinor())->toBe(0);
});
