<?php
namespace App\Base\AI\Exceptions;

use App\Base\Foundation\Exceptions\BlbIntegrationException;
use Throwable;

final class ModelCatalogSyncException extends BlbIntegrationException
{
    public static function httpFailure(int $status, ?string $exchangeId = null): self
    {
        return new self('Catalog sync failed: HTTP '.$status, context: array_filter([
            'status' => $status,
            'exchange_id' => $exchangeId,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public static function lockTimeout(int $waitSeconds, ?Throwable $previous = null): self
    {
        return new self(
            'Catalog sync lock timed out after '.$waitSeconds.' seconds',
            context: ['wait_seconds' => $waitSeconds],
            previous: $previous,
        );
    }

    public static function invalidPayload(?string $exchangeId = null): self
    {
        return new self('Catalog sync returned empty or invalid data', context: array_filter([
            'exchange_id' => $exchangeId,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
