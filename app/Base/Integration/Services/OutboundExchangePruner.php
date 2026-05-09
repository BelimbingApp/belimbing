<?php
namespace App\Base\Integration\Services;

use App\Base\Integration\Models\OutboundExchange;
use Illuminate\Support\Carbon;

class OutboundExchangePruner
{
    /**
     * Remove retained payload previews whose operation-class TTL has elapsed.
     */
    public function prunePayloads(): int
    {
        $pruned = 0;

        OutboundExchange::query()
            ->where(function ($query): void {
                $query->whereNotNull('request_body')
                    ->orWhereNotNull('response_body')
                    ->orWhereNotNull('request_headers')
                    ->orWhereNotNull('response_headers');
            })
            ->orderBy('occurred_at')
            ->chunk(200, function ($rows) use (&$pruned): void {
                foreach ($rows as $exchange) {
                    if (! $exchange instanceof OutboundExchange) {
                        continue;
                    }

                    $cutoff = now()->subDays($this->retentionDays($exchange));
                    if (! $exchange->occurred_at instanceof Carbon || $exchange->occurred_at->greaterThanOrEqualTo($cutoff)) {
                        continue;
                    }

                    $exchange->forceFill([
                        'request_headers' => null,
                        'request_body' => null,
                        'request_body_truncated' => false,
                        'request_body_original_bytes' => null,
                        'response_headers' => null,
                        'response_body' => null,
                        'response_body_truncated' => false,
                        'response_body_original_bytes' => null,
                    ])->save();

                    $pruned++;
                }
            });

        return $pruned;
    }

    public function retentionDays(OutboundExchange $exchange): int
    {
        if ($exchange->protocol === 'oauth2' || str_contains($exchange->operation, 'oauth')) {
            return 7;
        }

        if ($exchange->system === 'ai_catalog' || str_contains($exchange->operation, 'catalog')) {
            return 90;
        }

        if ($exchange->system === 'ebay' || str_contains($exchange->operation, 'marketplace')) {
            return 30;
        }

        return 30;
    }
}
