<?php

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\GithubCopilotAuthService;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

const RCR_PROXY_BASE_URL = 'http://localhost:1337/v1';
const RCR_PROXY_PROVIDER = 'copilot-proxy';

function makeResolver(): RuntimeCredentialResolver
{
    return new RuntimeCredentialResolver(
        Mockery::mock(GithubCopilotAuthService::class),
    );
}

test('copilot-proxy returns connection error when server is unreachable', function (): void {
    Http::fake([
        'localhost:1337/v1/models' => Http::response(status: 500),
    ]);

    // ConnectionException isn't easily faked via Http::fake, so test the HTTP error path
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
    Http::fake([
        'localhost:1337/v1/models' => Http::response(['data' => []], 200),
    ]);

    $result = makeResolver()->resolve([
        'api_key' => 'not-required',
        'base_url' => RCR_PROXY_BASE_URL,
        'provider_name' => RCR_PROXY_PROVIDER,
    ]);

    expect($result)
        ->toHaveKey('api_key', 'not-required')
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
        ->and($result['runtime_error']->hint)->toContain('VS Code');
});
