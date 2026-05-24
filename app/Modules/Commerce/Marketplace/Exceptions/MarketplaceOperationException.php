<?php

namespace App\Modules\Commerce\Marketplace\Exceptions;

use App\Base\Foundation\Exceptions\BlbIntegrationException;

class MarketplaceOperationException extends BlbIntegrationException
{
    public static function missingConfiguration(string $channel, string $key): self
    {
        return new self("Marketplace channel [{$channel}] is missing required setting [{$key}].");
    }

    public static function writePathNotEnabled(string $channel): self
    {
        return new self("Marketplace channel [{$channel}] write path is not enabled yet.");
    }

    public static function missingChannel(string $channel): self
    {
        return new self("Marketplace channel [{$channel}] is not registered.");
    }

    public static function invalidChannel(string $channel, string $resolvedClass): self
    {
        return new self("Marketplace channel [{$channel}] resolved to invalid class [{$resolvedClass}].");
    }

    public static function requestFailed(string $channel, string $operation, ?int $status = null, ?string $exchangeId = null): self
    {
        $suffix = $status !== null ? " (HTTP {$status})" : '';
        $exchange = $exchangeId !== null ? " Integration exchange [{$exchangeId}]." : '';

        return new self(
            "Marketplace channel [{$channel}] request [{$operation}] failed{$suffix}.{$exchange}",
            context: array_filter([
                'status' => $status,
                'exchange_id' => $exchangeId,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  list<array{key: string, label: string}>  $blockers
     */
    public static function draftNotReady(string $channel, int $draftId, array $blockers = []): self
    {
        $summary = collect($blockers)
            ->pluck('label')
            ->filter(fn (mixed $label): bool => is_string($label) && trim($label) !== '')
            ->take(3)
            ->implode(' ');

        $suffix = $summary !== '' ? ' '.$summary : '';

        return new self(
            "Marketplace channel [{$channel}] draft [{$draftId}] is not ready for publish or revise.{$suffix}",
            context: array_filter([
                'draft_id' => $draftId,
                'blockers' => $blockers,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    public static function listingNotWritable(string $channel, int $listingId, string $reason): self
    {
        return new self(
            "Marketplace channel [{$channel}] listing [{$listingId}] cannot be updated. {$reason}",
            context: [
                'listing_id' => $listingId,
                'reason' => $reason,
            ],
        );
    }
}
