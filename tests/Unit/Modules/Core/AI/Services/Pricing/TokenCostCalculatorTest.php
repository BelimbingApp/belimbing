<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\Pricing\TokenCostCalculator;
use App\Modules\Core\AI\Values\CallUsage;
use App\Modules\Core\AI\Values\ResolvedRate;

it('costs regular input, cached input, and output tokens separately', function (): void {
    $cost = (new TokenCostCalculator)->costFor(
        new CallUsage(
            promptTokens: 100,
            cachedInputTokens: 25,
            completionTokens: 10,
            totalTokens: 110,
            raw: [],
        ),
        new ResolvedRate(
            provider: 'openai',
            model: 'gpt-5.4',
            source: 'override',
            version: 'override:1',
            inputCentsPerToken: '1.000000000000',
            cachedInputCentsPerToken: '0.100000000000',
            outputCentsPerToken: '2.000000000000',
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
            promptTokens: 100,
            cachedInputTokens: 25,
            completionTokens: 10,
            totalTokens: 110,
            raw: [],
        ),
        new ResolvedRate(
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
            source: 'litellm',
            version: '2026-04-30',
            inputCentsPerToken: '1.000000000000',
            cachedInputCentsPerToken: null,
            outputCentsPerToken: '2.000000000000',
        ),
    );

    expect($cost->inputCents)->toBe(100)
        ->and($cost->cachedInputCents)->toBeNull()
        ->and($cost->outputCents)->toBe(20)
        ->and($cost->totalCents)->toBe(120);
});
