<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Exceptions\PricingSnapshotRefreshException;
use App\Modules\Core\AI\Models\AiPricingSnapshot;
use App\Modules\Core\AI\Services\Pricing\RefreshPricingSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('imports LiteLLM token pricing snapshots idempotently', function (): void {
    Http::fake([
        'https://pricing.test/litellm.json' => Http::sequence()
            ->push([
                'sample_spec' => ['input_cost_per_token' => 0, 'output_cost_per_token' => 0],
                'gpt-5.4' => [
                    'litellm_provider' => 'openai',
                    'mode' => 'chat',
                    'input_cost_per_token' => 0.000001,
                    'cache_read_input_token_cost' => 0.0000001,
                    'output_cost_per_token' => 0.000002,
                    'supports_prompt_caching' => true,
                ],
                'text-embedding-test' => [
                    'litellm_provider' => 'openai',
                    'mode' => 'embedding',
                    'input_cost_per_token' => 0.00000002,
                ],
                'claude-sonnet-4-6' => [
                    'litellm_provider' => 'anthropic',
                    'mode' => 'chat',
                    'input_cost_per_token' => 0.000003,
                    'output_cost_per_token' => 0.000015,
                ],
            ])
            ->push([
                'gpt-5.4' => [
                    'litellm_provider' => 'openai',
                    'input_cost_per_token' => 0.000001,
                    'cache_read_input_token_cost' => 0.0000001,
                    'output_cost_per_token' => 0.000004,
                ],
                'claude-sonnet-4-6' => [
                    'litellm_provider' => 'anthropic',
                    'input_cost_per_token' => 0.000003,
                    'output_cost_per_token' => 0.000015,
                ],
            ]),
    ]);

    $service = app(RefreshPricingSnapshot::class);
    $date = Carbon::parse('2026-04-30');

    $first = $service->refresh('https://pricing.test/litellm.json', $date);
    $second = $service->refresh('https://pricing.test/litellm.json', $date);

    $gpt = AiPricingSnapshot::query()
        ->where('provider', 'openai')
        ->where('model', 'gpt-5.4')
        ->firstOrFail();

    expect($first['imported_count'])->toBe(2)
        ->and($first['skipped_count'])->toBe(2)
        ->and($second['imported_count'])->toBe(2)
        ->and(AiPricingSnapshot::query()->count())->toBe(2)
        ->and($gpt->input_cents_per_token)->toBe('0.000100000000')
        ->and($gpt->cached_input_cents_per_token)->toBe('0.000010000000')
        ->and($gpt->output_cents_per_token)->toBe('0.000400000000')
        ->and($gpt->source)->toBe('litellm')
        ->and($gpt->source_version)->toBe('2026-04-30');
});

it('falls back to the previous snapshot when the source cannot refresh', function (): void {
    AiPricingSnapshot::query()->create([
        'provider' => 'openai',
        'model' => 'gpt-5.4',
        'input_cents_per_token' => '0.000100000000',
        'cached_input_cents_per_token' => '0.000010000000',
        'output_cents_per_token' => '0.000200000000',
        'source' => 'litellm',
        'source_version' => '2026-04-29',
        'snapshot_date' => '2026-04-29',
    ]);

    Http::fake([
        'https://pricing.test/litellm.json' => Http::response('unavailable', 503),
    ]);

    $result = app(RefreshPricingSnapshot::class)->refresh(
        'https://pricing.test/litellm.json',
        Carbon::parse('2026-04-30'),
    );

    expect($result['refreshed'])->toBeFalse()
        ->and($result['used_fallback'])->toBeTrue()
        ->and($result['model_count'])->toBe(1)
        ->and($result['snapshot_date'])->toBe('2026-04-29')
        ->and($result['error'])->toContain('HTTP 503');
});

it('throws when refresh fails before any fallback snapshot exists', function (): void {
    Http::fake([
        'https://pricing.test/litellm.json' => Http::response('unavailable', 503),
    ]);

    app(RefreshPricingSnapshot::class)->refresh(
        'https://pricing.test/litellm.json',
        Carbon::parse('2026-04-30'),
    );
})->throws(PricingSnapshotRefreshException::class);
