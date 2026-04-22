<?php

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use Tests\TestCase;

uses(TestCase::class);

test('ModelDiscoveryService returns curated models for openai-codex', function (): void {
    config()->set('ai.provider_overlay.openai-codex.curated_models', [
        'gpt-5.4',
        'gpt-5.4-mini',
        'gpt-5.2',
    ]);

    $provider = new AiProvider(['name' => 'openai-codex']);

    $service = app(ModelDiscoveryService::class);
    $models = $service->discoverModels($provider);

    expect($models)->toBe([
        ['model_id' => 'gpt-5.4', 'display_name' => 'gpt-5.4'],
        ['model_id' => 'gpt-5.4-mini', 'display_name' => 'gpt-5.4-mini'],
        ['model_id' => 'gpt-5.2', 'display_name' => 'gpt-5.2'],
    ]);
});
