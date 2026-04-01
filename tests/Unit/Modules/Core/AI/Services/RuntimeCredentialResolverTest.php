<?php

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ProviderDefinitionRegistry;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

const RCR_PROXY_BASE_URL = 'http://localhost:1337/v1';
const RCR_PROXY_PROVIDER = 'copilot-proxy';

function makeResolver(): RuntimeCredentialResolver
{
    return app(RuntimeCredentialResolver::class);
}

function createRcrProvider(string $name, string $baseUrl, string $apiKey = ''): AiProvider
{
    $company = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    return AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => $name,
        'display_name' => $name,
        'base_url' => $baseUrl,
        'auth_type' => 'api_key',
        'credentials' => $apiKey !== '' ? ['api_key' => $apiKey] : [],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);
}

test('copilot-proxy returns connection error when server is unreachable', function (): void {
    createRcrProvider(RCR_PROXY_PROVIDER, RCR_PROXY_BASE_URL);

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
    createRcrProvider(RCR_PROXY_PROVIDER, RCR_PROXY_BASE_URL);

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
    createRcrProvider(RCR_PROXY_PROVIDER, RCR_PROXY_BASE_URL);

    Http::fake([
        'localhost:1337/v1/models' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
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
