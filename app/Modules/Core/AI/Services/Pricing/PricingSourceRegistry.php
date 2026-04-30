<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Pricing;

use App\Modules\Core\AI\Values\ResolvedRate;

class PricingSourceRegistry
{
    /**
     * @param  iterable<int, PricingResolver>|null  $resolvers
     */
    public function __construct(
        private readonly ?iterable $resolvers = null,
    ) {}

    public function resolve(?string $provider, ?string $model): ?ResolvedRate
    {
        if ($model === null || trim($model) === '') {
            return null;
        }

        foreach ($this->resolverChain() as $resolver) {
            $rate = $resolver->resolve($provider, $model);

            if ($rate !== null) {
                return $rate;
            }
        }

        return null;
    }

    /**
     * @return iterable<int, PricingResolver>
     */
    private function resolverChain(): iterable
    {
        return $this->resolvers ?? [
            app(OverridePricingResolver::class),
            app(OpenRouterApiPricingResolver::class),
            app(LiteLlmSnapshotPricingResolver::class),
        ];
    }
}
