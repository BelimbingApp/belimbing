<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Pricing;

use App\Modules\Core\AI\Models\AiPricingSnapshot;
use App\Modules\Core\AI\Values\ResolvedRate;

class LiteLlmSnapshotPricingResolver implements PricingResolver
{
    public function resolve(?string $provider, string $model): ?ResolvedRate
    {
        $snapshot = AiPricingSnapshot::query()
            ->where('model', $model)
            ->where(function ($query) use ($provider): void {
                $query->whereNull('provider');

                if ($provider !== null && $provider !== '') {
                    $query->orWhere('provider', $provider);
                }
            })
            ->orderByRaw('case when provider = ? then 0 when provider is null then 1 else 2 end', [$provider ?? ''])
            ->latest('snapshot_date')
            ->latest('id')
            ->first();

        if ($snapshot === null) {
            return null;
        }

        $version = $snapshot->source_version
            ?? $snapshot->snapshot_date?->toDateString();

        return new ResolvedRate(
            provider: $snapshot->provider,
            model: $snapshot->model,
            source: $snapshot->source,
            version: $version,
            inputUsdPerMillionTokens: (string) $snapshot->input_usd_per_million_tokens,
            cachedInputUsdPerMillionTokens: $snapshot->cached_input_usd_per_million_tokens !== null
                ? (string) $snapshot->cached_input_usd_per_million_tokens
                : null,
            outputUsdPerMillionTokens: (string) $snapshot->output_usd_per_million_tokens,
        );
    }
}
