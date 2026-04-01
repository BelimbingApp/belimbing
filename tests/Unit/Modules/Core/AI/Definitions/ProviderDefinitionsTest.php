<?php

use App\Modules\Core\AI\Definitions\CloudflareGatewayDefinition;
use App\Modules\Core\AI\Definitions\CopilotProxyDefinition;
use App\Modules\Core\AI\Definitions\GenericApiKeyDefinition;
use App\Modules\Core\AI\Definitions\GenericLocalDefinition;
use App\Modules\Core\AI\Definitions\GithubCopilotDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

// ── GenericApiKeyDefinition ──

test('GenericApiKeyDefinition returns correct key and auth type', function (): void {
    $def = new GenericApiKeyDefinition('openai', 'https://api.openai.com/v1');

    expect($def->key())->toBe('openai')
        ->and($def->authType())->toBe(AuthType::ApiKey)
        ->and($def->defaultBaseUrl())->toBe('https://api.openai.com/v1');
});

test('GenericApiKeyDefinition editorFields includes base_url and api_key', function (): void {
    $def = new GenericApiKeyDefinition('openai', 'https://api.openai.com/v1');
    $fields = $def->editorFields(ProviderOperation::Create);

    expect($fields)->toHaveCount(2)
        ->and($fields[0]->key)->toBe('base_url')
        ->and($fields[0]->isSecret)->toBeFalse()
        ->and($fields[1]->key)->toBe('api_key')
        ->and($fields[1]->isSecret)->toBeTrue();
});

test('GenericApiKeyDefinition validates and normalizes create input', function (): void {
    $def = new GenericApiKeyDefinition('openai');

    $result = $def->validateAndNormalize([
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => 'sk-test-key',
    ], ProviderOperation::Create);

    expect($result)
        ->toHaveKey('base_url', 'https://api.openai.com/v1')
        ->toHaveKey('auth_type', AuthType::ApiKey)
        ->toHaveKey('credentials', ['api_key' => 'sk-test-key'])
        ->toHaveKey('connection_config', []);
});

test('GenericApiKeyDefinition omits credentials when api_key is blank on edit', function (): void {
    $def = new GenericApiKeyDefinition('openai');

    $result = $def->validateAndNormalize([
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => '',
    ], ProviderOperation::Edit);

    expect($result)
        ->toHaveKey('base_url')
        ->not->toHaveKey('credentials');
});

test('GenericApiKeyDefinition rejects missing api_key on create', function (): void {
    $def = new GenericApiKeyDefinition('openai');

    $def->validateAndNormalize([
        'base_url' => 'https://api.openai.com/v1',
        'api_key' => '',
    ], ProviderOperation::Create);
})->throws(ValidationException::class);

test('GenericApiKeyDefinition resolveRuntime returns config from provider', function (): void {
    $provider = new AiProvider;
    $provider->base_url = 'https://api.openai.com/v1';
    $provider->credentials = ['api_key' => 'sk-test'];

    $def = new GenericApiKeyDefinition('openai');
    $resolved = $def->resolveRuntime($provider);

    expect($resolved)->toBeInstanceOf(ResolvedProviderConfig::class)
        ->and($resolved->baseUrl)->toBe('https://api.openai.com/v1')
        ->and($resolved->apiKey)->toBe('sk-test');
});

// ── GenericLocalDefinition ──

test('GenericLocalDefinition has Local auth type and optional api_key', function (): void {
    $def = new GenericLocalDefinition('ollama', 'http://localhost:11434');

    expect($def->authType())->toBe(AuthType::Local);

    $result = $def->validateAndNormalize([
        'base_url' => 'http://localhost:11434',
        'api_key' => '',
    ], ProviderOperation::Create);

    expect($result)
        ->toHaveKey('auth_type', AuthType::Local)
        ->not->toHaveKey('credentials');
});

test('GenericLocalDefinition accepts optional api_key', function (): void {
    $def = new GenericLocalDefinition('vllm');

    $result = $def->validateAndNormalize([
        'base_url' => 'http://localhost:8000',
        'api_key' => 'optional-key',
    ], ProviderOperation::Create);

    expect($result)
        ->toHaveKey('credentials', ['api_key' => 'optional-key']);
});

test('GenericLocalDefinition resolveRuntime returns config without api_key when none set', function (): void {
    $provider = new AiProvider;
    $provider->base_url = 'http://localhost:11434';
    $provider->credentials = [];

    $def = new GenericLocalDefinition('ollama');
    $resolved = $def->resolveRuntime($provider);

    expect($resolved->baseUrl)->toBe('http://localhost:11434')
        ->and($resolved->apiKey)->toBeNull();
});

// ── CloudflareGatewayDefinition ──

test('CloudflareGatewayDefinition derives base URL from account and gateway IDs', function (): void {
    $def = new CloudflareGatewayDefinition;

    $result = $def->validateAndNormalize([
        'account_id' => 'abc123',
        'gateway_id' => 'my-gateway',
        'api_key' => 'cf-token',
    ], ProviderOperation::Create);

    expect($result)
        ->toHaveKey('base_url', 'https://gateway.ai.cloudflare.com/v1/abc123/my-gateway/openai')
        ->toHaveKey('auth_type', AuthType::Custom)
        ->toHaveKey('credentials', ['api_key' => 'cf-token'])
        ->toHaveKey('connection_config', ['account_id' => 'abc123', 'gateway_id' => 'my-gateway']);
});

test('CloudflareGatewayDefinition trims whitespace from account and gateway IDs', function (): void {
    $def = new CloudflareGatewayDefinition;

    $result = $def->validateAndNormalize([
        'account_id' => '  abc123  ',
        'gateway_id' => '  my-gateway  ',
        'api_key' => 'cf-token',
    ], ProviderOperation::Create);

    expect($result['connection_config'])->toBe(['account_id' => 'abc123', 'gateway_id' => 'my-gateway'])
        ->and($result['base_url'])->toContain('abc123/my-gateway');
});

test('CloudflareGatewayDefinition resolveRuntime reads from provider', function (): void {
    $provider = new AiProvider;
    $provider->base_url = 'https://gateway.ai.cloudflare.com/v1/abc/gw/openai';
    $provider->credentials = ['api_key' => 'cf-token'];

    $def = new CloudflareGatewayDefinition;
    $resolved = $def->resolveRuntime($provider);

    expect($resolved->baseUrl)->toBe('https://gateway.ai.cloudflare.com/v1/abc/gw/openai')
        ->and($resolved->apiKey)->toBe('cf-token');
});

// ── GithubCopilotDefinition ──

test('GithubCopilotDefinition has DeviceFlow auth type', function (): void {
    $def = app(GithubCopilotDefinition::class);

    expect($def->key())->toBe('github-copilot')
        ->and($def->authType())->toBe(AuthType::DeviceFlow);
});

test('GithubCopilotDefinition validates api_key required on create', function (): void {
    $def = app(GithubCopilotDefinition::class);

    $result = $def->validateAndNormalize([
        'base_url' => 'https://api.individual.githubcopilot.com',
        'api_key' => 'ghu_token',
    ], ProviderOperation::Create);

    expect($result)
        ->toHaveKey('auth_type', AuthType::DeviceFlow)
        ->toHaveKey('credentials', ['api_key' => 'ghu_token']);
});

// ── CopilotProxyDefinition ──

test('CopilotProxyDefinition has correct defaults', function (): void {
    $def = new CopilotProxyDefinition;

    expect($def->key())->toBe('copilot-proxy')
        ->and($def->authType())->toBe(AuthType::Local)
        ->and($def->defaultBaseUrl())->toBe('http://localhost:1337/v1');
});

test('CopilotProxyDefinition resolveRuntime probes server before returning', function (): void {
    Http::fake([
        'localhost:1337/v1/models' => Http::response(['data' => []], 200),
    ]);

    $provider = new AiProvider;
    $provider->base_url = 'http://localhost:1337/v1';
    $provider->credentials = [];

    $def = new CopilotProxyDefinition;
    $resolved = $def->resolveRuntime($provider);

    expect($resolved->baseUrl)->toBe('http://localhost:1337/v1');
    Http::assertSentCount(1);
});

test('CopilotProxyDefinition resolveRuntime throws when server unreachable', function (): void {
    Http::fake([
        'localhost:1337/v1/models' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'),
    ]);

    $provider = new AiProvider;
    $provider->base_url = 'http://localhost:1337/v1';
    $provider->credentials = [];

    $def = new CopilotProxyDefinition;
    $def->resolveRuntime($provider);
})->throws(RuntimeException::class, 'Could not connect');
