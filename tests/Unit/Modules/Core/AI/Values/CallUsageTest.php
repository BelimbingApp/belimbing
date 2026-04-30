<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Values\CallUsage;

it('returns null for empty or missing usage payloads', function (): void {
    expect(CallUsage::fromProviderArray(null))->toBeNull()
        ->and(CallUsage::fromProviderArray([]))->toBeNull();
});

it('parses an OpenAI Chat Completions usage with cached and reasoning splits', function (): void {
    $usage = CallUsage::fromProviderArray([
        'prompt_tokens' => 1754,
        'completion_tokens' => 128,
        'total_tokens' => 1882,
        'prompt_tokens_details' => ['cached_tokens' => 1024],
        'completion_tokens_details' => ['reasoning_tokens' => 64],
    ]);

    expect($usage)->not->toBeNull()
        ->and($usage->promptTokens)->toBe(1754)
        ->and($usage->cachedInputTokens)->toBe(1024)
        ->and($usage->completionTokens)->toBe(128)
        ->and($usage->reasoningTokens)->toBe(64)
        ->and($usage->totalTokens)->toBe(1882);
});

it('parses an OpenAI Responses usage with input/output keys and details', function (): void {
    $usage = CallUsage::fromProviderArray([
        'input_tokens' => 500,
        'output_tokens' => 200,
        'input_tokens_details' => ['cached_tokens' => 100],
        'output_tokens_details' => ['reasoning_tokens' => 32],
    ]);

    expect($usage)->not->toBeNull()
        ->and($usage->promptTokens)->toBe(500)
        ->and($usage->cachedInputTokens)->toBe(100)
        ->and($usage->completionTokens)->toBe(200)
        ->and($usage->reasoningTokens)->toBe(32)
        ->and($usage->totalTokens)->toBe(700);
});

it('parses an Anthropic Messages usage with cache_read_input_tokens', function (): void {
    $usage = CallUsage::fromProviderArray([
        'input_tokens' => 800,
        'output_tokens' => 150,
        'cache_read_input_tokens' => 600,
    ]);

    expect($usage)->not->toBeNull()
        ->and($usage->promptTokens)->toBe(800)
        ->and($usage->cachedInputTokens)->toBe(600)
        ->and($usage->completionTokens)->toBe(150)
        ->and($usage->totalTokens)->toBe(950);
});

it('preserves the raw provider payload for forensics', function (): void {
    $raw = ['prompt_tokens' => 10, 'completion_tokens' => 5, 'extra_provider_field' => 'value'];
    $usage = CallUsage::fromProviderArray($raw);

    expect($usage->raw)->toBe($raw);
});
