<?php

use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\DTO\Message;
use Tests\Support\MakesRuntimeResponses;
use Tests\TestCase;

uses(TestCase::class, MakesRuntimeResponses::class);

/**
 * @param  list<array<string, mixed>>  $configs
 * @param  callable(LlmClient): void  $configureClient
 * @return array{content: string, run_id: string, meta: array<string, mixed>}
 */
function runRuntimeConversation(array $configs, callable $configureClient): array
{
    $llmClient = Mockery::mock(LlmClient::class);
    $configureClient($llmClient);

    return test()
        ->makeAgentRuntime(test()->mockResolvedConfigResolver($configs), $llmClient)
        ->run([
            new Message(
                role: 'user',
                content: 'Hi',
                timestamp: new DateTimeImmutable,
            ),
        ], 1);
}

it('returns empty fallback_attempts on first model success', function (): void {
    $result = runRuntimeConversation([
        $this->makeConfig('openai', 'gpt-4o'),
    ], fn (LlmClient $llmClient) => $llmClient->shouldReceive('chat')->once()->andReturn($this->makeSuccessResponse('Hello!')));

    expect($result['content'])->toBe('Hello!')
        ->and($result['meta']['fallback_attempts'])->toBeArray()->toBeEmpty()
        ->and($result['meta']['model'])->toBe('gpt-4o')
        ->and($result['meta']['provider_name'])->toBe('openai')
        ->and($result['meta']['llm']['provider'])->toBe('openai')
        ->and($result['meta']['llm']['model'])->toBe('gpt-4o');
});

it('returns no-configuration error when no workspace and default config are available', function (): void {
    $result = runRuntimeConversation([], static fn (LlmClient $llmClient) => $llmClient);

    expect($result['content'])->toContain('⚠')
        ->and($result['meta']['error_type'])->toBe('config_error')
        ->and($result['meta']['provider_name'])->toBe('unknown')
        ->and($result['meta']['fallback_attempts'])->toBeEmpty();
});

it('collects fallback attempt entries on transient failures before success', function (): void {
    $result = runRuntimeConversation([
        $this->makeConfig('provider-a', 'model-a'),
        $this->makeConfig('provider-b', 'model-b'),
        $this->makeConfig('provider-c', 'model-c'),
    ], function (LlmClient $llmClient): void {
        $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
            $this->makeErrorResponse(AiErrorType::ServerError, 'HTTP 500: Internal Server Error', 150)
        );
        $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
            $this->makeErrorResponse(AiErrorType::RateLimit, 'HTTP 429: Too Many Requests', 50)
        );
        $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
            $this->makeSuccessResponse('Finally worked!', 300)
        );
    });

    expect($result['content'])->toBe('Finally worked!')
        ->and($result['meta']['model'])->toBe('model-c')
        ->and($result['meta']['fallback_attempts'])->toHaveCount(2);

    $this->assertFallbackAttempt($result['meta']['fallback_attempts'][0], 'provider-a', 'model-a', 'server_error', 150);
    $this->assertFallbackAttempt($result['meta']['fallback_attempts'][1], 'provider-b', 'model-b', 'rate_limit', 50);
});

it('includes fallback attempts when all models fail', function (): void {
    $result = runRuntimeConversation([
        $this->makeConfig('prov-a', 'model-a'),
        $this->makeConfig('prov-b', 'model-b'),
    ], function (LlmClient $llmClient): void {
        $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
            $this->makeErrorResponse(AiErrorType::ServerError, 'HTTP 500: Server Error', 100)
        );
        $llmClient->shouldReceive('chat')->once()->ordered()->andReturn(
            $this->makeErrorResponse(AiErrorType::ConnectionError, 'Connection refused', 50)
        );
    });

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
    $result = runRuntimeConversation([
        $this->makeConfig('openai', 'gpt-4o'),
        $this->makeConfig('anthropic', 'claude-3'),
    ], fn (LlmClient $llmClient) => $llmClient->shouldReceive('chat')->once()->andReturn(
        $this->makeErrorResponse(AiErrorType::AuthError, 'HTTP 401: Unauthorized', 30)
    ));

    // Should stop at first model, no fallback — user sees safe message
    expect($result['meta']['error_type'])->toBe('auth_error')
        ->and($result['meta']['fallback_attempts'])->toBeEmpty();
});

it('records config_error in result without fallback since not transient', function (): void {
    $result = runRuntimeConversation([
        $this->makeConfig('broken', 'model-a', '', 'https://api.example.com/v1'),
        $this->makeConfig('working', 'model-b'),
    ], static fn (LlmClient $llmClient) => $llmClient);

    // config_error is NOT in the shouldFallback transient list, so no fallback
    expect($result['meta']['error_type'])->toBe('config_error')
        ->and($result['meta']['provider_name'])->toBe('broken')
        ->and($result['meta']['llm']['provider'])->toBe('broken')
        ->and($result['meta']['llm']['model'])->toBe('model-a')
        ->and($result['meta']['fallback_attempts'])->toBeEmpty();
});
