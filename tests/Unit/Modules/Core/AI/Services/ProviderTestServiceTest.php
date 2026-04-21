<?php

use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\ProviderTestService;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const PROVIDER_TEST_SERVICE_PROVIDER_ID = 101;
const PROVIDER_TEST_SERVICE_MODEL_ID = 'gpt-5.4-nano';
const PROVIDER_TEST_SERVICE_BASE_URL = 'https://api.example.test/v1';

function makeProviderTestService(array $config): ProviderTestService
{
    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver
        ->shouldReceive('resolveForProvider')
        ->once()
        ->with(PROVIDER_TEST_SERVICE_PROVIDER_ID, PROVIDER_TEST_SERVICE_MODEL_ID)
        ->andReturn($config);

    $credentialResolver = Mockery::mock(RuntimeCredentialResolver::class);
    $credentialResolver
        ->shouldReceive('resolve')
        ->once()
        ->with($config)
        ->andReturn([
            'api_key' => 'test-key',
            'base_url' => PROVIDER_TEST_SERVICE_BASE_URL,
        ]);

    return new ProviderTestService(
        $configResolver,
        $credentialResolver,
        new LlmClient,
    );
}

it('tests provider connectivity successfully across supported protocol clients', function (
    AiApiType $apiType,
    string $providerName,
    string $urlPattern,
    array $responseBody,
): void {
    Http::fake([
        $urlPattern => Http::response($responseBody),
    ]);

    $service = makeProviderTestService([
        'provider_name' => $providerName,
        'model' => PROVIDER_TEST_SERVICE_MODEL_ID,
        'timeout' => 30,
        'api_type' => $apiType,
    ]);

    $result = $service->testSelection(
        PROVIDER_TEST_SERVICE_PROVIDER_ID,
        PROVIDER_TEST_SERVICE_MODEL_ID,
    );

    expect($result->connected)->toBeTrue()
        ->and($result->providerName)->toBe($providerName)
        ->and($result->model)->toBe(PROVIDER_TEST_SERVICE_MODEL_ID)
        ->and($result->latencyMs)->toBeInt()
        ->and($result->error)->toBeNull();
})->with([
    'chat completions' => [
        AiApiType::OpenAiChatCompletions,
        'openai',
        '*/chat/completions',
        [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'OK',
                ],
            ]],
            'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1],
        ],
    ],
    'responses' => [
        AiApiType::OpenAiResponses,
        'openai',
        '*/responses',
        [
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'OK',
                ]],
            ]],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 1],
        ],
    ],
    'anthropic messages' => [
        AiApiType::AnthropicMessages,
        'anthropic',
        '*/messages',
        [
            'content' => [[
                'type' => 'text',
                'text' => 'OK',
            ]],
            'usage' => ['input_tokens' => 2, 'output_tokens' => 1],
        ],
    ],
]);

it('surfaces structured provider errors across supported protocol clients', function (
    AiApiType $apiType,
    string $providerName,
    string $urlPattern,
): void {
    Http::fake([
        $urlPattern => Http::response([
            'error' => [
                'message' => 'Invalid API key.',
            ],
        ], 401),
    ]);

    $service = makeProviderTestService([
        'provider_name' => $providerName,
        'model' => PROVIDER_TEST_SERVICE_MODEL_ID,
        'timeout' => 30,
        'api_type' => $apiType,
    ]);

    $result = $service->testSelection(
        PROVIDER_TEST_SERVICE_PROVIDER_ID,
        PROVIDER_TEST_SERVICE_MODEL_ID,
    );

    expect($result->connected)->toBeFalse()
        ->and($result->providerName)->toBe($providerName)
        ->and($result->model)->toBe(PROVIDER_TEST_SERVICE_MODEL_ID)
        ->and($result->error)->not->toBeNull()
        ->and($result->error->errorType)->toBe(AiErrorType::AuthError)
        ->and($result->error->diagnostic)->toContain('HTTP 401')
        ->and($result->error->diagnostic)->toContain('Invalid API key.');
})->with([
    'chat completions' => [
        AiApiType::OpenAiChatCompletions,
        'openai',
        '*/chat/completions',
    ],
    'responses' => [
        AiApiType::OpenAiResponses,
        'openai',
        '*/responses',
    ],
    'anthropic messages' => [
        AiApiType::AnthropicMessages,
        'anthropic',
        '*/messages',
    ],
]);
