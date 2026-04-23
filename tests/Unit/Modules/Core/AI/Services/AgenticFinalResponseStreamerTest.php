<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\AgenticExecutionControlResolver;
use App\Modules\Core\AI\Services\AgenticFinalResponseStreamer;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorder;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\AI\Services\RuntimeResponseFactory;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

const AGENTIC_FINAL_STREAM_BASE_URL = 'https://api.example.test';

it('prepends client actions when the final stream ends without content deltas', function (): void {
    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chatStream')
        ->once()
        ->with(Mockery::on(function (ChatRequest $request): bool {
            return $request->apiType === AiApiType::OpenAiResponses
                && $request->executionControls->tools->choice === null
                && $request->executionControls->reasoning->visibility === ReasoningVisibility::Summary
                && $request->executionControls->tools->preserveReasoningContext === true;
        }))
        ->andReturn((function (): Generator {
            yield [
                'type' => 'done',
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 0],
                'latency_ms' => 42,
            ];
        })());

    $runRecorder = Mockery::mock(RunRecorder::class);
    $runRecorder->shouldReceive('complete')
        ->once()
        ->with('run_123', Mockery::on(function (array $meta): bool {
            return $meta['latency_ms'] === 42
                && $meta['tokens']['prompt'] === 11
                && $meta['tokens']['completion'] === 0;
        }));

    $streamer = new AgenticFinalResponseStreamer(
        $llmClient,
        $runRecorder,
        app(RuntimeResponseFactory::class),
        Mockery::mock(WireLogger::class)->shouldIgnoreMissing(),
        app(AgenticExecutionControlResolver::class),
    );

    $events = iterator_to_array($streamer->streamFinalResponse(
        'run_123',
        [
            'api_type' => AiApiType::OpenAiResponses,
            'model' => 'gpt-4.1',
            'execution_controls' => ExecutionControls::defaults(maxOutputTokens: 512, temperature: 0.3),
            'timeout' => 60,
            'provider_name' => 'test-provider',
        ],
        [
            'api_key' => 'test-key',
            'base_url' => AGENTIC_FINAL_STREAM_BASE_URL,
        ],
        [
            'api_messages' => [
                ['role' => 'user', 'content' => 'Open the dashboard'],
            ],
            'tools' => [],
            'tool_actions' => [],
            'client_actions' => ['<agent-action>Livewire.navigate(\'/dashboard\')</agent-action>'],
            'retry_attempts' => [],
            'fallback_attempts' => [],
            'hooks' => [],
        ],
    ), false);

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('done')
        ->and($events[0]['data']['content'])->toBe("<agent-action>Livewire.navigate('/dashboard')</agent-action>\n")
        ->and($events[0]['data']['meta']['latency_ms'])->toBe(42)
        ->and($events[0]['data']['meta']['tokens']['prompt'])->toBe(11)
        ->and($events[0]['data']['meta']['tokens']['completion'])->toBe(0);
});

it('emits an error event and records failure when the final stream returns a runtime error', function (): void {
    $runtimeError = AiRuntimeError::fromType(
        AiErrorType::RateLimit,
        'Too many requests from provider.',
        latencyMs: 51,
    );

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chatStream')
        ->once()
        ->with(Mockery::type(ChatRequest::class))
        ->andReturn((function () use ($runtimeError): Generator {
            yield [
                'type' => 'error',
                'runtime_error' => $runtimeError,
            ];
        })());

    $runRecorder = Mockery::mock(RunRecorder::class);
    $runRecorder->shouldReceive('fail')
        ->once()
        ->with('run_456', $runtimeError);

    $streamer = new AgenticFinalResponseStreamer(
        $llmClient,
        $runRecorder,
        app(RuntimeResponseFactory::class),
        Mockery::mock(WireLogger::class)->shouldIgnoreMissing(),
        app(AgenticExecutionControlResolver::class),
    );

    $events = iterator_to_array($streamer->streamFinalResponse(
        'run_456',
        [
            'api_type' => null,
            'model' => 'gpt-5.4',
            'execution_controls' => ExecutionControls::defaults(maxOutputTokens: 512, temperature: 0.3),
            'timeout' => 60,
            'provider_name' => 'openai',
        ],
        [
            'api_key' => 'test-key',
            'base_url' => AGENTIC_FINAL_STREAM_BASE_URL,
        ],
        [
            'api_messages' => [
                ['role' => 'user', 'content' => 'Try again'],
            ],
            'tools' => [],
            'tool_actions' => [],
            'client_actions' => [],
            'retry_attempts' => [['provider' => 'openai', 'model' => 'gpt-5.4', 'error' => 'Too many requests', 'error_type' => 'rate_limit', 'latency_ms' => 51]],
            'fallback_attempts' => [],
            'hooks' => [],
        ],
    ), false);

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('error')
        ->and($events[0]['data']['message'])->toContain('Rate limit exceeded.')
        ->and($events[0]['data']['meta']['error_type'])->toBe('rate_limit')
        ->and($events[0]['data']['meta']['retry_attempts'])->toHaveCount(1);
});

it('emits an empty-response error when the final stream completes without content or client actions', function (): void {
    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chatStream')
        ->once()
        ->with(Mockery::type(ChatRequest::class))
        ->andReturn((function (): Generator {
            yield [
                'type' => 'done',
                'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 0],
                'latency_ms' => 24,
            ];
        })());

    $runRecorder = Mockery::mock(RunRecorder::class);
    $runRecorder->shouldReceive('fail')
        ->once()
        ->with('run_789', Mockery::on(function (AiRuntimeError $error): bool {
            return $error->errorType === AiErrorType::EmptyResponse
                && $error->latencyMs === 24;
        }));

    $streamer = new AgenticFinalResponseStreamer(
        $llmClient,
        $runRecorder,
        app(RuntimeResponseFactory::class),
        Mockery::mock(WireLogger::class)->shouldIgnoreMissing(),
        app(AgenticExecutionControlResolver::class),
    );

    $events = iterator_to_array($streamer->streamFinalResponse(
        'run_789',
        [
            'api_type' => null,
            'model' => 'gpt-5.4',
            'execution_controls' => ExecutionControls::defaults(maxOutputTokens: 512, temperature: 0.3),
            'timeout' => 60,
            'provider_name' => 'openai',
        ],
        [
            'api_key' => 'test-key',
            'base_url' => AGENTIC_FINAL_STREAM_BASE_URL,
        ],
        [
            'api_messages' => [
                ['role' => 'user', 'content' => 'Say nothing'],
            ],
            'tools' => [],
            'tool_actions' => [],
            'client_actions' => [],
            'retry_attempts' => [],
            'fallback_attempts' => [],
            'hooks' => [],
        ],
    ), false);

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('error')
        ->and($events[0]['data']['message'])->toContain('Empty response.')
        ->and($events[0]['data']['meta']['error_type'])->toBe('empty_response')
        ->and($events[0]['data']['meta']['latency_ms'])->toBe(24);
});
