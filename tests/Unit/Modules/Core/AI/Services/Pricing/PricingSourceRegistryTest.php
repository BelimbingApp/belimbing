<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Models\AiPricingOverride;
use App\Modules\Core\AI\Models\AiPricingSnapshot;
use App\Modules\Core\AI\Services\Pricing\PricingSourceRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('resolves operator overrides before imported snapshots', function (): void {
    AiPricingSnapshot::query()->create([
        'provider' => 'openai',
        'model' => 'gpt-5.4',
        'input_cents_per_token' => '0.100000000000',
        'cached_input_cents_per_token' => '0.010000000000',
        'output_cents_per_token' => '0.200000000000',
        'source' => 'litellm',
        'source_version' => '2026-04-29',
        'snapshot_date' => '2026-04-29',
    ]);

    AiPricingOverride::query()->create([
        'provider' => 'openai',
        'model' => 'gpt-5.4',
        'input_cents_per_token' => '1.000000000000',
        'cached_input_cents_per_token' => '0.000000000000',
        'output_cents_per_token' => '2.000000000000',
        'reason' => 'enterprise contract',
    ]);

    $rate = app(PricingSourceRegistry::class)->resolve('openai', 'gpt-5.4');

    expect($rate)->not->toBeNull()
        ->and($rate->source)->toBe('override')
        ->and($rate->inputCentsPerToken)->toBe('1.000000000000')
        ->and($rate->cachedInputCentsPerToken)->toBe('0.000000000000')
        ->and($rate->outputCentsPerToken)->toBe('2.000000000000');
});

it('falls back to the newest matching snapshot when no override exists', function (): void {
    AiPricingSnapshot::query()->create([
        'provider' => null,
        'model' => 'claude-sonnet-4-6',
        'input_cents_per_token' => '0.100000000000',
        'cached_input_cents_per_token' => null,
        'output_cents_per_token' => '0.200000000000',
        'source' => 'litellm',
        'source_version' => '2026-04-28',
        'snapshot_date' => '2026-04-28',
    ]);
    AiPricingSnapshot::query()->create([
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'input_cents_per_token' => '0.300000000000',
        'cached_input_cents_per_token' => '0.030000000000',
        'output_cents_per_token' => '1.500000000000',
        'source' => 'litellm',
        'source_version' => '2026-04-30',
        'snapshot_date' => '2026-04-30',
    ]);

    $rate = app(PricingSourceRegistry::class)->resolve('anthropic', 'claude-sonnet-4-6');

    expect($rate)->not->toBeNull()
        ->and($rate->source)->toBe('litellm')
        ->and($rate->version)->toBe('2026-04-30')
        ->and($rate->provider)->toBe('anthropic')
        ->and($rate->inputCentsPerToken)->toBe('0.300000000000');
});

it('returns null for unknown models', function (): void {
    expect(app(PricingSourceRegistry::class)->resolve('openai', 'missing-model'))->toBeNull();
});
