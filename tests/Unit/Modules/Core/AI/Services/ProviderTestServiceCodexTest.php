<?php

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\ProviderTestService;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

test('ProviderTestService rewrites Codex transport rejections into reconnect guidance and structured logs', function (): void {
    $config = [
        'provider_name' => 'openai-codex',
        'model' => 'gpt-5.4-nano',
        'timeout' => 30,
        'api_type' => AiApiType::OpenAiCodexResponses,
    ];

    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver
        ->shouldReceive('resolveForProvider')
        ->once()
        ->with(101, 'gpt-5.4-nano')
        ->andReturn($config);

    $credentialResolver = Mockery::mock(RuntimeCredentialResolver::class);
    $credentialResolver
        ->shouldReceive('resolve')
        ->once()
        ->with($config)
        ->andReturn([
            'api_key' => 'codex-token',
            'base_url' => 'https://chatgpt.com/backend-api',
            'headers' => ['chatgpt-account-id' => 'acct_test'],
        ]);

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient
        ->shouldReceive('chat')
        ->once()
        ->with(Mockery::type(ChatRequest::class))
        ->andReturn([
            'runtime_error' => AiRuntimeError::fromType(
                AiErrorType::BadRequest,
                'HTTP 400: missing chatgpt-account-id',
            ),
        ]);

    Log::spy();

    $service = new ProviderTestService($configResolver, $credentialResolver, $llmClient);
    $result = $service->testSelection(101, 'gpt-5.4-nano');

    expect($result->connected)->toBeFalse()
        ->and($result->error)->not->toBeNull()
        ->and($result->error->userMessage)->toBe('OpenAI Codex rejected the ChatGPT backend session.')
        ->and($result->error->hint)->toBe('Reconnect OpenAI Codex. If the failure persists, disable this provider because the external ChatGPT backend contract may have changed.');

    Log::shouldHaveReceived('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context): bool {
            return $level === 'warning'
                && $message === 'AI provider test completed'
                && ($context['provider_name'] ?? null) === 'openai-codex'
                && ($context['compatibility_contract'] ?? null) === 'undocumented_chatgpt_backend'
                && ($context['operator_action'] ?? null) === 'reconnect_or_disable'
                && ($context['contract_change_suspected'] ?? null) === true;
        });
});
