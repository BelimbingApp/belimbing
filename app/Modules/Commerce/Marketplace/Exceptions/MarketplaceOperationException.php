<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
}
