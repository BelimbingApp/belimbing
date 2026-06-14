<?php

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Base\AI\Services\Tracing\NullLlmTraceContextFactory;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\ProviderTestService;
use App\Modules\Core\AI\Services\Runtime\RuntimeCredentialResolver;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

const PROVIDER_TEST_SERVICE_CODEX_PROVIDER_ID = 101;
const PROVIDER_TEST_SERVICE_CODEX_PROVIDER_NAME = 'openai-codex';
const PROVIDER_TEST_SERVICE_CODEX_MODEL_ID = 'gpt-5.4-nano';
const PROVIDER_TEST_SERVICE_CODEX_UNSUPPORTED_MODEL_ID = 'gpt-5.1-codex-mini';
const PROVIDER_TEST_SERVICE_CODEX_BASE_URL = 'https://chatgpt.com/backend-api';
const PROVIDER_TEST_SERVICE_CODEX_ACCOUNT_ID = 'acct_test';

function makeCodexProviderTestService(string $model, string $providerMessage): ProviderTestService
{
    $config = [
        'provider_name' => PROVIDER_TEST_SERVICE_CODEX_PROVIDER_NAME,
        'model' => $model,
        'timeout' => 30,
        'api_type' => AiApiType::OpenAiCodexResponses,
    ];

    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver
        ->shouldReceive('resolveForProvider')
        ->once()
        ->with(PROVIDER_TEST_SERVICE_CODEX_PROVIDER_ID, $model)
        ->andReturn($config);

    $credentialResolver = Mockery::mock(RuntimeCredentialResolver::class);
    $credentialResolver
        ->shouldReceive('resolve')
        ->once()
        ->with($config)
        ->andReturn([
            'api_key' => 'codex-token',
            'base_url' => PROVIDER_TEST_SERVICE_CODEX_BASE_URL,
            'headers' => ['chatgpt-account-id' => PROVIDER_TEST_SERVICE_CODEX_ACCOUNT_ID],
        ]);

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient
        ->shouldReceive('chat')
        ->once()
        ->with(Mockery::type(ChatRequest::class))
        ->andReturn([
            'runtime_error' => AiRuntimeError::fromProviderFailure(
                AiErrorType::BadRequest,
                $providerMessage,
            ),
        ]);

    return new ProviderTestService($configResolver, $credentialResolver, $llmClient, new NullLlmTraceContextFactory);
}

test('ProviderTestService preserves Codex transport provider messages and adds structured logs', function (): void {
    Log::spy();

    $service = makeCodexProviderTestService(
        PROVIDER_TEST_SERVICE_CODEX_MODEL_ID,
        'missing chatgpt-account-id',
    );
    $result = $service->testSelection(
        PROVIDER_TEST_SERVICE_CODEX_PROVIDER_ID,
        PROVIDER_TEST_SERVICE_CODEX_MODEL_ID,
    );

    expect($result->connected)->toBeFalse()
        ->and($result->error)->not->toBeNull()
        ->and($result->error->userMessage)->toBe('missing chatgpt-account-id')
        ->and($result->error->hint)->toBeNull();

    Log::shouldHaveReceived('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context): bool {
            return $level === 'warning'
                && $message === 'AI provider test completed'
                && ($context['provider_name'] ?? null) === PROVIDER_TEST_SERVICE_CODEX_PROVIDER_NAME
                && ($context['compatibility_contract'] ?? null) === 'undocumented_chatgpt_backend'
                && ($context['operator_action'] ?? null) === 'reconnect_or_disable'
                && ($context['contract_change_suspected'] ?? null) === true;
        });
});

test('ProviderTestService preserves unsupported Codex model provider messages', function (): void {
    $unsupportedModelMessage = '{"detail":"The \'gpt-5.1-codex-mini\' model is not supported when using Codex with a ChatGPT account."}';

    Log::spy();

    $service = makeCodexProviderTestService(
        PROVIDER_TEST_SERVICE_CODEX_UNSUPPORTED_MODEL_ID,
        $unsupportedModelMessage,
    );
    $result = $service->testSelection(
        PROVIDER_TEST_SERVICE_CODEX_PROVIDER_ID,
        PROVIDER_TEST_SERVICE_CODEX_UNSUPPORTED_MODEL_ID,
    );

    expect($result->connected)->toBeFalse()
        ->and($result->error)->not->toBeNull()
        ->and($result->error->userMessage)->toBe($unsupportedModelMessage)
        ->and($result->error->hint)->toBeNull();

    Log::shouldHaveReceived('log')
        ->once()
        ->withArgs(function (string $level, string $message, array $context): bool {
            return $level === 'warning'
                && $message === 'AI provider test completed'
                && ($context['provider_name'] ?? null) === PROVIDER_TEST_SERVICE_CODEX_PROVIDER_NAME
                && ! array_key_exists('compatibility_contract', $context);
        });
});
