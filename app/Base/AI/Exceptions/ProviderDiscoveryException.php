<?php
namespace App\Base\AI\Exceptions;

use App\Base\Foundation\Exceptions\BlbIntegrationException;

final class ProviderDiscoveryException extends BlbIntegrationException
{
    public static function httpFailure(int $status, ?string $exchangeId = null): self
    {
        return new self(
            'Model discovery failed: HTTP '.$status,
            context: array_filter([
                'status' => $status,
                'exchange_id' => $exchangeId,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}
