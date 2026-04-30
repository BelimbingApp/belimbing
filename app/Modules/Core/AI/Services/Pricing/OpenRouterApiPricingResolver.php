<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Pricing;

use App\Modules\Core\AI\Values\ResolvedRate;

/**
 * Placeholder for OpenRouter's live `/api/v1/models` resolver.
 *
 * The registry includes this seam now so call sites have the final resolver
 * order. The network-backed refresh/probe lands with the lifecycle action.
 */
class OpenRouterApiPricingResolver implements PricingResolver
{
    public function resolve(?string $provider, string $model): ?ResolvedRate
    {
        return null;
    }
}
