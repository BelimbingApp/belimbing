<?php

use App\Modules\Core\AI\Contracts\ProviderDefinition;
use App\Modules\Core\AI\Definitions\CloudflareGatewayDefinition;
use App\Modules\Core\AI\Definitions\CopilotProxyDefinition;
use App\Modules\Core\AI\Definitions\GenericApiKeyDefinition;
use App\Modules\Core\AI\Definitions\GenericLocalDefinition;
use App\Modules\Core\AI\Definitions\GithubCopilotDefinition;
use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Services\ProviderDefinitionRegistry;

uses(Tests\TestCase::class);

test('registry resolves dedicated definitions for outlier providers', function (): void {
    $registry = app(ProviderDefinitionRegistry::class);

    expect($registry->for('cloudflare-ai-gateway'))->toBeInstanceOf(CloudflareGatewayDefinition::class)
        ->and($registry->for('copilot-proxy'))->toBeInstanceOf(CopilotProxyDefinition::class)
        ->and($registry->for('github-copilot'))->toBeInstanceOf(GithubCopilotDefinition::class)
        ->and($registry->for('openai-codex'))->toBeInstanceOf(OpenAiCodexDefinition::class);
});

test('registry resolves GenericLocalDefinition for local auth_type providers', function (): void {
    $registry = app(ProviderDefinitionRegistry::class);

    $definition = $registry->for('ollama');

    expect($definition)->toBeInstanceOf(GenericLocalDefinition::class)
        ->and($definition->authType())->toBe(AuthType::Local);
});

test('registry resolves GenericApiKeyDefinition for standard providers', function (): void {
    $registry = app(ProviderDefinitionRegistry::class);

    $definition = $registry->for('openai');

    expect($definition)->toBeInstanceOf(GenericApiKeyDefinition::class)
        ->and($definition->authType())->toBe(AuthType::ApiKey);
});

test('registry falls back to GenericApiKeyDefinition for unknown providers', function (): void {
    $registry = app(ProviderDefinitionRegistry::class);

    $definition = $registry->for('unknown-provider-xyz');

    expect($definition)->toBeInstanceOf(GenericApiKeyDefinition::class)
        ->and($definition->key())->toBe('unknown-provider-xyz');
});

test('registry all() returns definitions for all catalog providers plus outliers', function (): void {
    $registry = app(ProviderDefinitionRegistry::class);

    $all = $registry->all();

    expect($all)->toBeArray()
        ->and($all)->toHaveKey('cloudflare-ai-gateway')
        ->and($all)->toHaveKey('copilot-proxy')
        ->and($all)->toHaveKey('github-copilot')
        ->and($all)->toHaveKey('openai');

    // All values implement ProviderDefinition
    foreach ($all as $definition) {
        expect($definition)->toBeInstanceOf(ProviderDefinition::class);
    }
});
