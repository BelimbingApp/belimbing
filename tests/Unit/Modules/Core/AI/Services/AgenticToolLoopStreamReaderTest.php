<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\AgenticExecutionControlResolver;
use App\Modules\Core\AI\Services\AgenticToolLoopStreamReader;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

const AGENTIC_STREAM_READER_NEED_TOOL_RESULT = 'Need tool result.';

it('captures reasoning_content deltas for follow-up tool loop requests', function (): void {
    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chatStream')
        ->once()
        ->with(Mockery::on(function (ChatRequest $request): bool {
            return $request->executionControls->tools->choice === ToolChoiceMode::Auto
                && $request->executionControls->reasoning->visibility === ReasoningVisibility::None
                && $request->executionControls->tools->preserveReasoningContext === false;
        }))
        ->andReturn((function (): Generator {
            yield [
                'type' => 'thinking_delta',
                'text' => AGENTIC_STREAM_READER_NEED_TOOL_RESULT,
                'source' => 'reasoning_content',
            ];
            yield [
                'type' => 'tool_call_delta',
                'index' => 0,
                'id' => 'call_stream_reasoning_1',
                'name' => 'echo_tool',
                'arguments_delta' => '',
            ];
            yield [
                'type' => 'tool_call_delta',
                'index' => 0,
                'id' => null,
                'name' => null,
                'arguments_delta' => '{"input":"world"}',
            ];
            yield [
                'type' => 'done',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'latency_ms' => 123,
            ];
        })());

    $reader = new AgenticToolLoopStreamReader(
        $llmClient,
        Mockery::mock(WireLogger::class)->shouldIgnoreMissing(),
        app(AgenticExecutionControlResolver::class),
    );
    $toolLoopState = [
        'apiMessages' => [
            ['role' => 'user', 'content' => 'Echo world'],
        ],
        'tools' => [[
            'type' => 'function',
            'function' => [
                'name' => 'echo_tool',
                'description' => 'Echo input',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ]],
    ];

    $stream = $reader->consumeIterationStream(
        'run_123',
        [
            'model' => 'moonshotai/kimi-k2.5',
            'execution_controls' => ExecutionControls::defaults(maxOutputTokens: 512, temperature: 0.7),
            'timeout' => 60,
            'provider_name' => 'moonshotai',
        ],
        [
            'base_url' => 'https://api.example.test/v1',
            'api_key' => 'test-key',
        ],
        $toolLoopState,
        AiApiType::OpenAiChatCompletions,
    );

    $events = iterator_to_array($stream, false);
    $result = $stream->getReturn();

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('status')
        ->and($events[0]['data']['phase'])->toBe('thinking_delta')
        ->and($events[0]['data']['delta'])->toBe(AGENTIC_STREAM_READER_NEED_TOOL_RESULT);

    expect($result['reasoning_content'])->toBe(AGENTIC_STREAM_READER_NEED_TOOL_RESULT)
        ->and($result['tool_calls'][0]['id'])->toBe('call_stream_reasoning_1')
        ->and($result['tool_calls'][0]['function']['arguments'])->toBe('{"input":"world"}')
        ->and($result['latency_ms'])->toBe(123);
});

it('returns the runtime error when the iteration stream fails', function (): void {
    $runtimeError = AiRuntimeError::fromType(
        AiErrorType::RateLimit,
        'Rate limit exceeded by provider.',
        latencyMs: 77,
    );

    $llmClient = Mockery::mock(LlmClient::class);
    $llmClient->shouldReceive('chatStream')
        ->once()
        ->with(Mockery::type(ChatRequest::class))
        ->andReturn((function () use ($runtimeError): Generator {
            yield [
                'type' => 'thinking_delta',
                'text' => 'Checking quotas...',
                'source' => 'reasoning_content',
            ];
            yield [
                'type' => 'error',
                'runtime_error' => $runtimeError,
            ];
        })());

    $reader = new AgenticToolLoopStreamReader(
        $llmClient,
        Mockery::mock(WireLogger::class)->shouldIgnoreMissing(),
        app(AgenticExecutionControlResolver::class),
    );
    $toolLoopState = [
        'apiMessages' => [
            ['role' => 'user', 'content' => 'Retry later'],
        ],
        'tools' => [],
    ];

    $stream = $reader->consumeIterationStream(
        'run_456',
        [
            'model' => 'gpt-5.4',
            'execution_controls' => ExecutionControls::defaults(maxOutputTokens: 512, temperature: 0.7),
            'timeout' => 60,
            'provider_name' => 'openai',
        ],
        [
            'base_url' => 'https://api.example.test/v1',
            'api_key' => 'test-key',
        ],
        $toolLoopState,
        AiApiType::OpenAiChatCompletions,
    );

    $events = iterator_to_array($stream, false);
    $result = $stream->getReturn();

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('status')
        ->and($events[0]['data']['delta'])->toBe('Checking quotas...')
        ->and($result['runtime_error'])->toBe($runtimeError);
});
