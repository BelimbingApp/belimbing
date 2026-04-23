<?php

use App\Modules\Core\AI\Definitions\CloudflareGatewayDefinition;
use App\Modules\Core\AI\Definitions\CopilotProxyDefinition;
use App\Modules\Core\AI\Definitions\GenericApiKeyDefinition;
use App\Modules\Core\AI\Definitions\GenericLocalDefinition;
use App\Modules\Core\AI\Definitions\GenericOAuthDefinition;
use App\Modules\Core\AI\Definitions\GithubCopilotDefinition;
use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Exceptions\CopilotProxyRuntimeException;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

const PDT_OPENAI_BASE_URL = 'https://api.openai.com/v1';
const PDT_OLLAMA_BASE_URL = 'http://localhost:11434';
const PDT_COPILOT_PROXY_BASE_URL = 'http://localhost:1337/v1';
const PDT_CODEX_BASE_URL = 'https://chatgpt.com/backend-api';
const PDT_QWEN_BASE_URL = 'https://portal.qwen.ai/v1';

// ── GenericApiKeyDefinition ──

test('GenericApiKeyDefinition returns correct key and auth type', function (): void {
    $def = new GenericApiKeyDefinition('openai', PDT_OPENAI_BASE_URL);

    expect($def->key())->toBe('openai')
        ->and($def->authType())->toBe(AuthType::ApiKey)
        ->and($def->defaultBaseUrl())->toBe(PDT_OPENAI_BASE_URL);
});

test('GenericApiKeyDefinition editorFields includes base_url and api_key', function (): void {
    $def = new GenericApiKeyDefinition('openai', PDT_OPENAI_BASE_URL);
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
        'base_url' => PDT_OPENAI_BASE_URL,
        'api_key' => 'sk-test-key',
    ], ProviderOperation::Create);

    expect($result)
        ->toHaveKey('base_url', PDT_OPENAI_BASE_URL)
        ->toHaveKey('auth_type', AuthType::ApiKey)
        ->toHaveKey('credentials', ['api_key' => 'sk-test-key'])
        ->toHaveKey('connection_config', []);
});

test('GenericApiKeyDefinition omits credentials when api_key is blank on edit', function (): void {
    $def = new GenericApiKeyDefinition('openai');

    $result = $def->validateAndNormalize([
        'base_url' => PDT_OPENAI_BASE_URL,
        'api_key' => '',
    ], ProviderOperation::Edit);

    expect($result)
        ->toHaveKey('base_url')
        ->not->toHaveKey('credentials');
});

test('GenericApiKeyDefinition rejects missing api_key on create', function (): void {
    $def = new GenericApiKeyDefinition('openai');

    $def->validateAndNormalize([
        'base_url' => PDT_OPENAI_BASE_URL,
        'api_key' => '',
    ], ProviderOperation::Create);
})->throws(ValidationException::class);

test('GenericApiKeyDefinition resolveRuntime returns config from provider', function (): void {
    $provider = new AiProvider;
    $provider->base_url = PDT_OPENAI_BASE_URL;
    $provider->credentials = ['api_key' => 'sk-test'];

    $def = new GenericApiKeyDefinition('openai');
    $resolved = $def->resolveRuntime($provider);

    expect($resolved)->toBeInstanceOf(ResolvedProviderConfig::class)
        ->and($resolved->baseUrl)->toBe(PDT_OPENAI_BASE_URL)
        ->and($resolved->apiKey)->toBe('sk-test');
});

test('GenericApiKeyDefinition delegates model discovery to shared provider discovery', function (): void {
    $provider = new AiProvider;

    $def = new GenericApiKeyDefinition('openai');

    expect($def->discoverModels($provider))->toBeNull();
});

// ── GenericLocalDefinition ──

test('GenericLocalDefinition has Local auth type and optional api_key', function (): void {
    $def = new GenericLocalDefinition('ollama', PDT_OLLAMA_BASE_URL);

    expect($def->authType())->toBe(AuthType::Local);

    $result = $def->validateAndNormalize([
        'base_url' => PDT_OLLAMA_BASE_URL,
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

// ── GenericOAuthDefinition ──

test('GenericOAuthDefinition has OAuth auth type and no generic runtime path', function (): void {
    $def = new GenericOAuthDefinition('qwen-portal', 'https://portal.qwen.ai/v1');

    expect($def->authType())->toBe(AuthType::OAuth)
        ->and($def->defaultBaseUrl())->toBe('https://portal.qwen.ai/v1');
});

test('GenericOAuthDefinition validates and normalizes create input without credentials', function (): void {
    $def = new GenericOAuthDefinition('qwen-portal');

    $result = $def->validateAndNormalize([
        'base_url' => 'https://portal.qwen.ai/v1',
    ], ProviderOperation::Create);

    expect($result)
        ->toHaveKey('base_url', 'https://portal.qwen.ai/v1')
        ->toHaveKey('auth_type', AuthType::OAuth)
        ->toHaveKey('connection_config')
        ->not->toHaveKey('credentials');
});

test('GenericOAuthDefinition refuses runtime resolution without a dedicated flow', function (): void {
    $provider = new AiProvider;
    $provider->base_url = 'https://portal.qwen.ai/v1';

    $def = new GenericOAuthDefinition('qwen-portal');

    $def->resolveRuntime($provider);
})->throws(RuntimeException::class, 'dedicated OAuth sign-in flow');

// ── OpenAiCodexDefinition ──

test('OpenAiCodexDefinition has OAuth auth type and default base URL', function (): void {
    $def = new OpenAiCodexDefinition(app(OpenAiCodexAuthManager::class));

    expect($def->key())->toBe('openai-codex')
        ->and($def->authType())->toBe(AuthType::OAuth)
        ->and($def->defaultBaseUrl())->toBe(PDT_CODEX_BASE_URL);
});

test('OpenAiCodexDefinition validates and normalizes create input without api key', function (): void {
    $def = new OpenAiCodexDefinition(app(OpenAiCodexAuthManager::class));

    $result = $def->validateAndNormalize([
        'base_url' => PDT_CODEX_BASE_URL,
    ], ProviderOperation::Create);

    expect($result)
        ->toHaveKey('base_url', PDT_CODEX_BASE_URL)
        ->toHaveKey('auth_type', AuthType::OAuth)
        ->toHaveKey('connection_config')
        ->and($result['connection_config'])->toHaveKey(OpenAiCodexDefinition::AUTH_STATE_KEY);
});

test('OpenAiCodexDefinition edit preserves durable auth state by omitting connection_config', function (): void {
    $def = new OpenAiCodexDefinition(app(OpenAiCodexAuthManager::class));

    $result = $def->validateAndNormalize([
        'base_url' => PDT_CODEX_BASE_URL,
    ], ProviderOperation::Edit);

    expect($result)
        ->toHaveKey('base_url', PDT_CODEX_BASE_URL)
        ->toHaveKey('auth_type', AuthType::OAuth)
        ->not->toHaveKey('connection_config');
});

test('OpenAiCodexDefinition owns curated model discovery', function (): void {
    config()->set('ai.provider_overlay.openai-codex.curated_models', [
        'gpt-5.4',
        'gpt-5.4-mini',
        'gpt-5.2',
    ]);

    $provider = new AiProvider;
    $def = new OpenAiCodexDefinition(app(OpenAiCodexAuthManager::class));

    expect($def->discoverModels($provider))->toBe([
        ['model_id' => 'gpt-5.4', 'display_name' => 'gpt-5.4'],
        ['model_id' => 'gpt-5.4-mini', 'display_name' => 'gpt-5.4-mini'],
        ['model_id' => 'gpt-5.2', 'display_name' => 'gpt-5.2'],
    ]);
});

test('GenericLocalDefinition resolveRuntime returns config without api_key when none set', function (): void {
    $provider = new AiProvider;
    $provider->base_url = PDT_OLLAMA_BASE_URL;
    $provider->credentials = [];

    $def = new GenericLocalDefinition('ollama');
    $resolved = $def->resolveRuntime($provider);

    expect($resolved->baseUrl)->toBe(PDT_OLLAMA_BASE_URL)
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
        ->and($def->defaultBaseUrl())->toBe(PDT_COPILOT_PROXY_BASE_URL);
});

test('CopilotProxyDefinition resolveRuntime probes server before returning', function (): void {
    Http::fake([
        'localhost:1337/v1/models' => Http::response(['data' => []], 200),
    ]);

    $provider = new AiProvider;
    $provider->base_url = PDT_COPILOT_PROXY_BASE_URL;
    $provider->credentials = [];

    $def = new CopilotProxyDefinition;
    $resolved = $def->resolveRuntime($provider);

    expect($resolved->baseUrl)->toBe(PDT_COPILOT_PROXY_BASE_URL);
    Http::assertSentCount(1);
});

test('CopilotProxyDefinition resolveRuntime throws when server unreachable', function (): void {
    Http::fake([
        'localhost:1337/v1/models' => fn () => throw new ConnectionException('refused'),
    ]);

    $provider = new AiProvider;
    $provider->base_url = PDT_COPILOT_PROXY_BASE_URL;
    $provider->credentials = [];

    $def = new CopilotProxyDefinition;
    $def->resolveRuntime($provider);
})->throws(CopilotProxyRuntimeException::class, 'Could not connect');
