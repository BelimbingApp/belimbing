<?php

use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Services\ConfigResolver;
use Tests\Support\MakesRuntimeResponses;
use Tests\TestCase;

uses(TestCase::class, MakesRuntimeResponses::class);

function makeRuntime(
    ConfigResolver $configResolver,
    LlmClient $llmClient,
): \App\Modules\Core\AI\Services\AgentRuntime {
    return test()->makeAgentRuntime($configResolver, $llmClient);
}

function stubResolvedConfigs(ConfigResolver $configResolver, array $configs): void
{
    $configResolver->shouldReceive('resolve')->with(1)->andReturn($configs);
    $configResolver->shouldReceive('resolveWithDefaultFallback')->with(1)->andReturn($configs);
}

function runRuntimeConversation(
    ConfigResolver $configResolver,
    LlmClient $llmClient,
): array {
    return makeRuntime($configResolver, $llmClient)
        ->run([
            new Message(
                role: 'user',
                content: 'Hi',
                timestamp: new \DateTimeImmutable,
            ),
        ], 1);
}

it('returns empty fallback_attempts on first model success', function (): void {
    $configResolver = Mockery::mock(ConfigResolver::class);
    stubResolvedConfigs($configResolver, [
        $this->makeConfig('openai', 'gpt-4o'),
    ]);

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chat')->once()->andReturn($this->makeSuccessResponse('Hello!'));

    $result = runRuntimeConversation($configResolver, $llmClient);

    expect($result['content'])->toBe('Hello!')
        ->and($result['meta']['fallback_attempts'])->toBeArray()->toBeEmpty()
        ->and($result['meta']['model'])->toBe('gpt-4o')
        ->and($result['meta']['provider_name'])->toBe('openai')
        ->and($result['meta']['llm']['provider'])->toBe('openai')
        ->and($result['meta']['llm']['model'])->toBe('gpt-4o');
});

it('returns no-configuration error when no workspace and default config are available', function (): void {
    $configResolver = Mockery::mock(ConfigResolver::class);
    stubResolvedConfigs($configResolver, []);

    $llmClient = Mockery::mock(LlmClient::class);

    $result = runRuntimeConversation($configResolver, $llmClient);

    expect($result['content'])->toContain('⚠')
        ->and($result['meta']['error_type'])->toBe('config_error')
        ->and($result['meta']['provider_name'])->toBe('unknown')
        ->and($result['meta']['fallback_attempts'])->toBeEmpty();
});

it('collects fallback attempt entries on transient failures before success', function (): void {
    $configResolver = Mockery::mock(ConfigResolver::class);
    stubResolvedConfigs($configResolver, [
        $this->makeConfig('provider-a', 'model-a'),
        $this->makeConfig('provider-b', 'model-b'),
        $this->makeConfig('provider-c', 'model-c'),
    ]);

    $llmClient = Mockery::mock(LlmClient::class);
    // First call: server error (fallback-worthy)
    $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
        $this->makeErrorResponse(AiErrorType::ServerError, 'HTTP 500: Internal Server Error', 150)
    );
    // Second call: rate limit (fallback-worthy)
    $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
        $this->makeErrorResponse(AiErrorType::RateLimit, 'HTTP 429: Too Many Requests', 50)
    );
    // Third call: success
    $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
        $this->makeSuccessResponse('Finally worked!', 300)
    );

    $result = runRuntimeConversation($configResolver, $llmClient);

    expect($result['content'])->toBe('Finally worked!')
        ->and($result['meta']['model'])->toBe('model-c')
        ->and($result['meta']['fallback_attempts'])->toHaveCount(2);

    $this->assertFallbackAttempt($result['meta']['fallback_attempts'][0], 'provider-a', 'model-a', 'server_error', 150);
    $this->assertFallbackAttempt($result['meta']['fallback_attempts'][1], 'provider-b', 'model-b', 'rate_limit', 50);
});

it('includes fallback attempts when all models fail', function (): void {
    $configResolver = Mockery::mock(ConfigResolver::class);
    stubResolvedConfigs($configResolver, [
        $this->makeConfig('prov-a', 'model-a'),
        $this->makeConfig('prov-b', 'model-b'),
    ]);

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
        $this->makeErrorResponse(AiErrorType::ServerError, 'HTTP 500: Server Error', 100)
    );
    $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
        $this->makeErrorResponse(AiErrorType::ConnectionError, 'Connection refused', 50)
    );

    $result = runRuntimeConversation($configResolver, $llmClient);

    // Last failure is returned as the result — user sees safe message, not raw diagnostic
    expect($result['meta']['error_type'])->toBe('connection_error')
        ->and($result['meta']['fallback_attempts'])->toHaveCount(2);

    // Both attempts recorded
    expect($result['meta']['fallback_attempts'][0]['provider'])->toBe('prov-a')
        ->and($result['meta']['fallback_attempts'][0]['error_type'])->toBe('server_error')
        ->and($result['meta']['fallback_attempts'][1]['provider'])->toBe('prov-b')
        ->and($result['meta']['fallback_attempts'][1]['error_type'])->toBe('connection_error');
});

it('does not fall back on client errors and still records empty attempts', function (): void {
    $configResolver = Mockery::mock(ConfigResolver::class);
    stubResolvedConfigs($configResolver, [
        $this->makeConfig('openai', 'gpt-4o'),
        $this->makeConfig('anthropic', 'claude-3'),
    ]);

    $llmClient = Mockery::mock(LlmClient::class);
    // Client error (401) — should NOT trigger fallback
    $llmClient->shouldReceive('chat')->once()->andReturn(
        $this->makeErrorResponse(AiErrorType::AuthError, 'HTTP 401: Unauthorized', 30)
    );

    $result = runRuntimeConversation($configResolver, $llmClient);

    // Should stop at first model, no fallback — user sees safe message
    expect($result['meta']['error_type'])->toBe('auth_error')
        ->and($result['meta']['fallback_attempts'])->toBeEmpty();
});

it('records config_error in result without fallback since not transient', function (): void {
    $configResolver = Mockery::mock(ConfigResolver::class);
    stubResolvedConfigs($configResolver, [
        $this->makeConfig('broken', 'model-a', '', 'https://api.example.com/v1'),
        $this->makeConfig('working', 'model-b'),
    ]);

    $llmClient = Mockery::mock(LlmClient::class);

    $result = runRuntimeConversation($configResolver, $llmClient);

    // config_error is NOT in the shouldFallback transient list, so no fallback
    expect($result['meta']['error_type'])->toBe('config_error')
        ->and($result['meta']['provider_name'])->toBe('broken')
        ->and($result['meta']['llm']['provider'])->toBe('broken')
        ->and($result['meta']['llm']['model'])->toBe('model-a')
        ->and($result['meta']['fallback_attempts'])->toBeEmpty();
});
