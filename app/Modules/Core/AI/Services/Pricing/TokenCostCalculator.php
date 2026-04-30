<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Pricing;

use App\Modules\Core\AI\Values\CallCost;
use App\Modules\Core\AI\Values\CallUsage;
use App\Modules\Core\AI\Values\ResolvedRate;

class TokenCostCalculator
{
    public function costFor(CallUsage $usage, ResolvedRate $rate): CallCost
    {
        $cachedTokens = $usage->cachedInputTokens;
        $hasCachedRate = $cachedTokens !== null && $rate->cachedInputUsdPerMillionTokens !== null;
        $regularInputTokens = $usage->promptTokens;

        if ($regularInputTokens !== null && $hasCachedRate) {
            $regularInputTokens = max(0, $regularInputTokens - $cachedTokens);
        }

        $inputCents = $this->cost($regularInputTokens, $rate->inputUsdPerMillionTokens);
        $cachedInputCents = $hasCachedRate
            ? $this->cost($cachedTokens, $rate->cachedInputUsdPerMillionTokens)
            : null;
        $outputCents = $this->cost($usage->completionTokens, $rate->outputUsdPerMillionTokens);

        return new CallCost(
            inputCents: $inputCents,
            cachedInputCents: $cachedInputCents,
            outputCents: $outputCents,
            totalCents: $this->total($inputCents, $cachedInputCents, $outputCents),
        );
    }

    private function cost(?int $tokens, ?string $usdPerMillionTokens): ?int
    {
        if ($tokens === null || $usdPerMillionTokens === null) {
            return null;
        }

        return (int) round($tokens * (float) $usdPerMillionTokens * 100 / 1_000_000);
    }

    private function total(?int ...$parts): ?int
    {
        $reported = array_filter($parts, static fn (?int $part): bool => $part !== null);

        if ($reported === []) {
            return null;
        }

        return array_sum($reported);
    }
}
