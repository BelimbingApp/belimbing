<?php
namespace App\Modules\Commerce\Sales\Http\Controllers;

use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Inventory\Services\DefaultCurrencyResolver;
use App\Modules\Commerce\Sales\Models\Sale;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a sales-export CSV for the authenticated operator's company.
 *
 * Bookkeeper format: one row per `Sale`, with all accounting fields rendered
 * as decimal strings (not minor units) so the receiving spreadsheet doesn't
 * have to know about minor-units convention.
 */
class SalesCsvExportController
{
    public function __invoke(Request $request, DefaultCurrencyResolver $currencyResolver): StreamedResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $companyId = (int) $user->company_id;
        $currencyCode = $request->query('currency_code');
        if (! is_string($currencyCode) || ! preg_match('/^[A-Z]{3}$/', strtoupper($currencyCode))) {
            $currencyCode = $currencyResolver->forCompany($companyId);
        }
        $currencyCode = strtoupper($currencyCode);

        try {
            $from = Carbon::parse((string) $request->query('from', Carbon::today()->subDays(30)->toDateString()))->startOfDay();
            $to = Carbon::parse((string) $request->query('to', Carbon::today()->toDateString()))->endOfDay();
        } catch (\Throwable) {
            abort(422, 'Invalid date range.');
        }

        if ($from->greaterThan($to)) {
            abort(422, 'Start date must be on or before end date.');
        }

        $filename = sprintf('sales-export-%s-to-%s-%s.csv', $from->toDateString(), $to->toDateString(), strtolower($currencyCode));

        return new StreamedResponse(
            function () use ($companyId, $currencyCode, $from, $to): void {
                $handle = fopen('php://output', 'w');

                fputcsv($handle, [
                    'sold_at',
                    'channel',
                    'external_sale_id',
                    'item_id',
                    'sku',
                    'title',
                    'category',
                    'quantity',
                    'sale_amount',
                    'cost_basis_amount',
                    'fee_amount',
                    'gross_profit',
                    'currency_code',
                ]);

                Sale::query()
                    ->with(['item.category', 'orderLine'])
                    ->where('company_id', $companyId)
                    ->where('currency_code', $currencyCode)
                    ->whereBetween('sold_at', [$from, $to])
                    ->orderBy('sold_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->chunk(500, function ($sales) use ($handle, $currencyCode): void {
                        foreach ($sales as $sale) {
                            $revenue = $sale->sale_amount ?? 0;
                            $cost = $sale->cost_basis_amount ?? 0;
                            $fees = $sale->fee_amount ?? 0;

                            fputcsv($handle, [
                                $sale->sold_at?->toIso8601String(),
                                $sale->channel,
                                $sale->external_sale_id,
                                $sale->item_id,
                                $sale->item?->sku ?? $sale->orderLine?->external_sku,
                                $sale->item?->title
                                    ?? $sale->orderLine?->title
                                    ?? $sale->orderLine?->external_sku
                                    ?? '',
                                $sale->item?->category?->name,
                                $sale->quantity,
                                Money::formatInput($sale->sale_amount),
                                Money::formatInput($sale->cost_basis_amount),
                                Money::formatInput($sale->fee_amount),
                                Money::formatInput($revenue - $cost - $fees),
                                $currencyCode,
                            ]);
                        }
                    });

                fclose($handle);
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }
}
