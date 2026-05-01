<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Services;

use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Sales\DTO\AgedListingRow;
use App\Modules\Commerce\Sales\DTO\ItemMarginRow;
use App\Modules\Commerce\Sales\DTO\SalesPeriodSummary;
use App\Modules\Commerce\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side query surface for sales insights.
 *
 * Answers "what sold for company X between two dates in currency Y?" — both as
 * a single aggregate ({@see soldInPeriod()}) and broken out per inventory item
 * ({@see marginPerItem()}). Days-listed-without-sale comes on a later slice.
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

    /**
     * Per-item gross margin for the given window, sorted by gross profit DESC
     * (computed in SQL so the limit is applied to the right rows).
     *
     * Sales not linked to an item are grouped together under a null itemId
     * row — they are typically pre-link or unmatched listings; surfacing them
     * lets the operator notice the gap rather than silently dropping revenue.
     *
     * @return Collection<int, ItemMarginRow>
     */
    public function marginPerItem(int $companyId, Carbon $from, Carbon $to, string $currencyCode, ?int $limit = null): Collection
    {
        $query = Sale::query()
            ->where('company_id', $companyId)
            ->where('currency_code', $currencyCode)
            ->whereBetween('sold_at', [$from, $to])
            ->groupBy('item_id')
            ->select('item_id')
            ->selectRaw('COUNT(*) as sale_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as unit_count')
            ->selectRaw('COALESCE(SUM(sale_amount), 0) as total_revenue')
            ->selectRaw('COALESCE(SUM(cost_basis_amount), 0) as total_cost')
            ->selectRaw('COALESCE(SUM(fee_amount), 0) as total_fees')
            ->orderByRaw('COALESCE(SUM(sale_amount), 0) - COALESCE(SUM(cost_basis_amount), 0) - COALESCE(SUM(fee_amount), 0) DESC');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($row): ItemMarginRow => new ItemMarginRow(
            itemId: $row->item_id !== null ? (int) $row->item_id : null,
            saleCount: (int) $row->sale_count,
            unitCount: (int) $row->unit_count,
            totalRevenueMinor: (int) $row->total_revenue,
            totalCostMinor: (int) $row->total_cost,
            totalFeesMinor: (int) $row->total_fees,
        ));
    }

    /**
     * Active listings that have aged without producing a sale.
     *
     * "Active" means `ended_at IS NULL`; "without sale" means no row exists in
     * `commerce_sales_sales` linked back via `listing_id`. Listings missing a
     * `listed_at` are excluded since their age is undefined. Ordered oldest
     * first so the operator's eye lands on the worst-stuck stock.
     *
     * Day count is computed in PHP (not SQL) to keep the query
     * driver-agnostic; `minDaysListed` is applied as an upper-bound on
     * `listed_at` so the filter still happens at the database.
     *
     * @return Collection<int, AgedListingRow>
     */
    public function daysListedWithoutSale(
        int $companyId,
        string $currencyCode,
        ?Carbon $asOf = null,
        ?int $minDaysListed = null,
        ?int $limit = null,
    ): Collection {
        $asOf ??= Carbon::now();

        $query = Listing::query()
            ->where('company_id', $companyId)
            ->where('currency_code', $currencyCode)
            ->whereNotNull('listed_at')
            ->whereNull('ended_at')
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw('1'))
                    ->from('commerce_sales_sales')
                    ->whereColumn('commerce_sales_sales.listing_id', 'commerce_marketplace_listings.id');
            })
            ->orderBy('listed_at', 'asc');

        if ($minDaysListed !== null) {
            $query->where('listed_at', '<=', $asOf->copy()->subDays($minDaysListed));
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn (Listing $listing): AgedListingRow => new AgedListingRow(
            listingId: $listing->id,
            itemId: $listing->item_id,
            channel: $listing->channel,
            marketplaceId: $listing->marketplace_id,
            title: $listing->title,
            priceAmountMinor: $listing->price_amount,
            listedAt: $listing->listed_at,
            daysListed: (int) $listing->listed_at->diffInDays($asOf, true),
        ));
    }
}
