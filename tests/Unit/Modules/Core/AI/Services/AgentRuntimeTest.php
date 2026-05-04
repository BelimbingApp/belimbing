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

it('returns the assistant response on a successful single-config call', function (): void {
    $result = runRuntimeConversation([
        $this->makeConfig('openai', 'gpt-4o'),
    ], fn (LlmClient $llmClient) => $llmClient->shouldReceive('chat')->once()->andReturn($this->makeSuccessResponse('Hello!')));

    expect($result['content'])->toBe('Hello!')
        ->and($result['meta']['model'])->toBe('gpt-4o')
        ->and($result['meta']['provider_name'])->toBe('openai')
        ->and($result['meta']['llm']['provider'])->toBe('openai')
        ->and($result['meta']['llm']['model'])->toBe('gpt-4o');
});

it('returns a no-configuration error when no config is available', function (): void {
    $result = runRuntimeConversation([], static fn (LlmClient $llmClient) => $llmClient);

    expect($result['content'])->toContain('⚠')
        ->and($result['meta']['error_type'])->toBe('config_error')
        ->and($result['meta']['provider_name'])->toBe('unknown');
});

it('surfaces transient provider failures honestly without retrying a different provider', function (): void {
    $result = runRuntimeConversation([
        $this->makeConfig('provider-a', 'model-a'),
    ], fn (LlmClient $llmClient) => $llmClient->shouldReceive('chat')->once()->andReturn(
        $this->makeErrorResponse(AiErrorType::ServerError, 'HTTP 500: Internal Server Error', 150)
    ));

    expect($result['meta']['error_type'])->toBe('server_error')
        ->and($result['meta']['provider_name'])->toBe('provider-a')
        ->and($result['meta']['model'])->toBe('model-a');
});

it('surfaces client errors directly without falling back', function (): void {
    $result = runRuntimeConversation([
        $this->makeConfig('openai', 'gpt-4o'),
    ], fn (LlmClient $llmClient) => $llmClient->shouldReceive('chat')->once()->andReturn(
        $this->makeErrorResponse(AiErrorType::AuthError, 'HTTP 401: Unauthorized', 30)
    ));

    expect($result['meta']['error_type'])->toBe('auth_error')
        ->and($result['content'])->toContain('openai/gpt-4o');
});

it('returns config_error when credential resolution fails', function (): void {
    $result = runRuntimeConversation([
        $this->makeConfig('broken', 'model-a', '', 'https://api.example.com/v1'),
    ], static fn (LlmClient $llmClient) => $llmClient);

    expect($result['meta']['error_type'])->toBe('config_error')
        ->and($result['meta']['provider_name'])->toBe('broken')
        ->and($result['meta']['llm']['provider'])->toBe('broken')
        ->and($result['meta']['llm']['model'])->toBe('model-a');
});
