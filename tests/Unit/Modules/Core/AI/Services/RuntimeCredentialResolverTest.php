<?php

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Exceptions\GithubCopilotAuthException;
use App\Base\AI\Services\GithubCopilotAuthService;
use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RCR_PROXY_BASE_URL = 'http://localhost:1337/v1';
const RCR_PROXY_PROVIDER = 'copilot-proxy';

function makeResolver(): RuntimeCredentialResolver
{
    return app(RuntimeCredentialResolver::class);
}

function createRcrProvider(string $name, string $baseUrl, string $apiKey = '', AuthType $authType = AuthType::ApiKey): AiProvider
{
    $company = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    return AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => $name,
        'display_name' => $name,
        'base_url' => $baseUrl,
        'auth_type' => $authType,
        'credentials' => $apiKey !== '' ? ['api_key' => $apiKey] : [],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);
}

test('copilot-proxy returns connection error when server is unreachable', function (): void {
    createRcrProvider(RCR_PROXY_PROVIDER, RCR_PROXY_BASE_URL, authType: AuthType::Local);

    Http::fake([
        'localhost:1337/v1/models' => Http::response(status: 500),
    ]);

    $result = makeResolver()->resolve([
        'api_key' => 'not-required',
        'base_url' => RCR_PROXY_BASE_URL,
        'provider_name' => RCR_PROXY_PROVIDER,
    ]);

    expect($result)
        ->toHaveKey('runtime_error')
        ->and($result['runtime_error'])->toBeInstanceOf(AiRuntimeError::class)
        ->and($result['runtime_error']->errorType)->toBe(AiErrorType::ConnectionError)
        ->and($result['runtime_error']->diagnostic)->toContain('Copilot Proxy')
        ->and($result['runtime_error']->diagnostic)->toContain('500');
});

test('copilot-proxy passes when server is reachable', function (): void {
    createRcrProvider(RCR_PROXY_PROVIDER, RCR_PROXY_BASE_URL, authType: AuthType::Local);

    Http::fake([
        'localhost:1337/v1/models' => Http::response(['data' => []], 200),
    ]);

    $result = makeResolver()->resolve([
        'api_key' => 'not-required',
        'base_url' => RCR_PROXY_BASE_URL,
        'provider_name' => RCR_PROXY_PROVIDER,
    ]);

    expect($result)
        ->toHaveKey('api_key')
        ->toHaveKey('base_url', RCR_PROXY_BASE_URL)
        ->not->toHaveKey('runtime_error');
});

test('non-proxy providers skip connectivity check', function (): void {
    Http::fake();

    $result = makeResolver()->resolve([
        'api_key' => 'sk-test',
        'base_url' => 'https://api.openai.com/v1',
        'provider_name' => 'openai',
    ]);

    expect($result)
        ->toHaveKey('api_key', 'sk-test')
        ->toHaveKey('base_url', 'https://api.openai.com/v1')
        ->not->toHaveKey('runtime_error');

    Http::assertNothingSent();
});

test('copilot-proxy connection error message mentions VS Code extension', function (): void {
    createRcrProvider(RCR_PROXY_PROVIDER, RCR_PROXY_BASE_URL, authType: AuthType::Local);

    Http::fake([
        'localhost:1337/v1/models' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    $result = makeResolver()->resolve([
        'api_key' => 'not-required',
        'base_url' => RCR_PROXY_BASE_URL,
        'provider_name' => RCR_PROXY_PROVIDER,
    ]);

    expect($result)
        ->toHaveKey('runtime_error')
        ->and($result['runtime_error'])->toBeInstanceOf(AiRuntimeError::class)
        ->and($result['runtime_error']->errorType)->toBe(AiErrorType::ConnectionError)
        ->and($result['runtime_error']->diagnostic)->toContain('Could not connect')
        ->and($result['runtime_error']->diagnostic)->toContain('VS Code');
});

test('github copilot auth failures are classified as auth errors', function (): void {
    app()->instance(GithubCopilotAuthService::class, new class extends GithubCopilotAuthService
    {
        public function exchangeForCopilotToken(string $githubToken): array
        {
            throw GithubCopilotAuthException::tokenExchangeFailed(401);
        }
    });

    $result = makeResolver()->resolve([
        'api_key' => 'ghu_test',
        'base_url' => 'https://api.individual.githubcopilot.com',
        'provider_name' => 'github-copilot',
    ]);

    expect($result)
        ->toHaveKey('runtime_error')
        ->and($result['runtime_error'])->toBeInstanceOf(AiRuntimeError::class)
        ->and($result['runtime_error']->errorType)->toBe(AiErrorType::AuthError)
        ->and($result['runtime_error']->diagnostic)->toContain('Copilot token exchange failed: HTTP 401')
        ->and($result['runtime_error']->hint)->toContain('GitHub Copilot device flow');
});

test('provider resolution uses runtime config instead of persisted provider credentials', function (): void {
    createRcrProvider('openai', 'https://persisted.example/v1', 'persisted-secret');

    $result = makeResolver()->resolve([
        'api_key' => 'runtime-secret',
        'base_url' => 'https://runtime.example/v1',
        'provider_name' => 'openai',
    ]);

    expect($result)
        ->toHaveKey('api_key', 'runtime-secret')
        ->toHaveKey('base_url', 'https://runtime.example/v1')
        ->not->toHaveKey('runtime_error');
});

test('runtime resolution uses persisted codex provider credentials when provider_id is supplied', function (): void {
    $provider = createRcrProvider(
        OpenAiCodexDefinition::KEY,
        'https://chatgpt.com/backend-api',
        authType: AuthType::OAuth,
    );

    $provider->update([
        'credentials' => [
            OpenAiCodexDefinition::CRED_ACCESS_TOKEN => 'aaa.bbb.ccc',
            OpenAiCodexDefinition::CRED_REFRESH_TOKEN => 'refresh-token',
            OpenAiCodexDefinition::CRED_EXPIRES_AT => now()->addHour()->toIso8601String(),
            OpenAiCodexDefinition::CRED_ACCOUNT_ID => 'acct_test',
        ],
        'connection_config' => [
            OpenAiCodexDefinition::AUTH_STATE_KEY => [
                'status' => 'connected',
                'mode' => 'browser_pkce',
            ],
        ],
    ]);

    $result = makeResolver()->resolve([
        'api_key' => '',
        'base_url' => 'https://runtime.example.invalid',
        'provider_name' => OpenAiCodexDefinition::KEY,
        'provider_id' => $provider->id,
    ]);

    expect($result)
        ->toHaveKey('api_key', 'aaa.bbb.ccc')
        ->toHaveKey('base_url', 'https://chatgpt.com/backend-api')
        ->not->toHaveKey('runtime_error');
});
