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
        $hasCachedRate = $cachedTokens !== null && $rate->cachedInputCentsPerToken !== null;
        $regularInputTokens = $usage->promptTokens;

        if ($regularInputTokens !== null && $hasCachedRate) {
            $regularInputTokens = max(0, $regularInputTokens - $cachedTokens);
        }

        $inputCents = $this->cost($regularInputTokens, $rate->inputCentsPerToken);
        $cachedInputCents = $hasCachedRate
            ? $this->cost($cachedTokens, $rate->cachedInputCentsPerToken)
            : null;
        $outputCents = $this->cost($usage->completionTokens, $rate->outputCentsPerToken);

        return new CallCost(
            inputCents: $inputCents,
            cachedInputCents: $cachedInputCents,
            outputCents: $outputCents,
            totalCents: $this->total($inputCents, $cachedInputCents, $outputCents),
        );
    }

    private function cost(?int $tokens, ?string $centsPerToken): ?int
    {
        if ($tokens === null || $centsPerToken === null) {
            return null;
        }

        return (int) round($tokens * (float) $centsPerToken);
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
