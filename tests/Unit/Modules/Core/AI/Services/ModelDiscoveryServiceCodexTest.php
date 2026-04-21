<?php

uses(TestCase::class);

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use Tests\TestCase;

test('ModelDiscoveryService returns curated models for openai-codex', function (): void {
    config()->set('ai.provider_overlay.openai-codex.curated_models', ['gpt-5.1-codex-mini', 'gpt-5.1-codex']);

    $provider = new AiProvider(['name' => 'openai-codex']);

    $service = app(ModelDiscoveryService::class);
    $models = $service->discoverModels($provider);

    expect($models)->toBe([
        ['model_id' => 'gpt-5.1-codex-mini', 'display_name' => 'gpt-5.1-codex-mini'],
        ['model_id' => 'gpt-5.1-codex', 'display_name' => 'gpt-5.1-codex'],
    ]);
});
