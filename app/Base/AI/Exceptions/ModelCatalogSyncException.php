<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Exceptions;

use App\Base\Foundation\Exceptions\BlbIntegrationException;
use Throwable;

final class ModelCatalogSyncException extends BlbIntegrationException
{
    public static function httpFailure(int $status): self
    {
        return new self('Catalog sync failed: HTTP '.$status, context: ['status' => $status]);
    }

    public static function lockTimeout(int $waitSeconds, ?Throwable $previous = null): self
    {
        return new self(
            'Catalog sync lock timed out after '.$waitSeconds.' seconds',
            context: ['wait_seconds' => $waitSeconds],
            previous: $previous,
        );
    }

    public static function invalidPayload(): self
    {
        return new self('Catalog sync returned empty or invalid data');
    }
}
