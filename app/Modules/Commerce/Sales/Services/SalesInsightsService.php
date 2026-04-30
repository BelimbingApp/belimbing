<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Services;

use App\Modules\Commerce\Sales\DTO\SalesPeriodSummary;
use App\Modules\Commerce\Sales\Models\Sale;
use Illuminate\Support\Carbon;

/**
 * Read-side query surface for sales insights.
 *
 * The first slice answers "what sold for company X between two dates in
 * currency Y?" — totals only, no per-item breakdown yet. Per-item margin and
 * days-listed-without-sale come on later slices.
 */
class SalesInsightsService
{
    public function soldInPeriod(int $companyId, Carbon $from, Carbon $to, string $currencyCode): SalesPeriodSummary
    {
        $row = Sale::query()
            ->where('company_id', $companyId)
            ->where('currency_code', $currencyCode)
            ->whereBetween('sold_at', [$from, $to])
            ->selectRaw('COUNT(*) as sale_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as unit_count')
            ->selectRaw('COALESCE(SUM(sale_amount), 0) as total_revenue')
            ->selectRaw('COALESCE(SUM(cost_basis_amount), 0) as total_cost')
            ->selectRaw('COALESCE(SUM(fee_amount), 0) as total_fees')
            ->first();

        return new SalesPeriodSummary(
            from: $from,
            to: $to,
            saleCount: (int) ($row->sale_count ?? 0),
            unitCount: (int) ($row->unit_count ?? 0),
            totalRevenueMinor: (int) ($row->total_revenue ?? 0),
            totalCostMinor: (int) ($row->total_cost ?? 0),
            totalFeesMinor: (int) ($row->total_fees ?? 0),
            currencyCode: $currencyCode,
        );
    }
}
