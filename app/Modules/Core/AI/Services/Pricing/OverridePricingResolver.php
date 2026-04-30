<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Pricing;

use App\Modules\Core\AI\Models\AiPricingOverride;
use App\Modules\Core\AI\Values\ResolvedRate;

class OverridePricingResolver implements PricingResolver
{
    public function resolve(?string $provider, string $model): ?ResolvedRate
    {
        $override = AiPricingOverride::query()
            ->where('model', $model)
            ->where(function ($query) use ($provider): void {
                $query->whereNull('provider');

                if ($provider !== null && $provider !== '') {
                    $query->orWhere('provider', $provider);
                }
            })
            ->orderByRaw('case when provider = ? then 0 when provider is null then 1 else 2 end', [$provider ?? ''])
            ->latest('updated_at')
            ->latest('id')
            ->first();

        if ($override === null) {
            return null;
        }

        return new ResolvedRate(
            provider: $override->provider,
            model: $override->model,
            source: 'override',
            version: 'override:'.$override->id,
            inputCentsPerToken: (string) $override->input_cents_per_token,
            cachedInputCentsPerToken: $override->cached_input_cents_per_token !== null
                ? (string) $override->cached_input_cents_per_token
                : null,
            outputCentsPerToken: (string) $override->output_cents_per_token,
        );
    }
}
