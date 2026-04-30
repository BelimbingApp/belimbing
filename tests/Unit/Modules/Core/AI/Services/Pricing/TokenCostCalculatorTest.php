<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\Pricing\TokenCostCalculator;
use App\Modules\Core\AI\Values\CallUsage;
use App\Modules\Core\AI\Values\ResolvedRate;

it('costs regular input, cached input, and output tokens separately', function (): void {
    $cost = (new TokenCostCalculator)->costFor(
        new CallUsage(
            promptTokens: 1_000_000,
            cachedInputTokens: 250_000,
            completionTokens: 100_000,
            totalTokens: 1_100_000,
            raw: [],
        ),
        new ResolvedRate(
            provider: 'openai',
            model: 'gpt-5.4',
            source: 'override',
            version: 'override:1',
            inputUsdPerMillionTokens: '1.000000000000',
            cachedInputUsdPerMillionTokens: '0.100000000000',
            outputUsdPerMillionTokens: '2.000000000000',
        ),
    );

    expect($cost->inputCents)->toBe(75)
        ->and($cost->cachedInputCents)->toBe(3)
        ->and($cost->outputCents)->toBe(20)
        ->and($cost->totalCents)->toBe(98);
});

it('charges cached tokens as regular input when no cached rate is known', function (): void {
    $cost = (new TokenCostCalculator)->costFor(
        new CallUsage(
            promptTokens: 1_000_000,
            cachedInputTokens: 250_000,
            completionTokens: 100_000,
            totalTokens: 1_100_000,
            raw: [],
        ),
        new ResolvedRate(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            source: 'litellm',
            version: '2026-04-30',
            inputUsdPerMillionTokens: '1.000000000000',
            cachedInputUsdPerMillionTokens: null,
            outputUsdPerMillionTokens: '2.000000000000',
        ),
    );

    expect($cost->inputCents)->toBe(100)
        ->and($cost->cachedInputCents)->toBeNull()
        ->and($cost->outputCents)->toBe(20)
        ->and($cost->totalCents)->toBe(120);
});
