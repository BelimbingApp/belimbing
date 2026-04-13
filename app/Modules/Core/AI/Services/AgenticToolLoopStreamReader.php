<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;

/**
 * Consumes one LLM chat stream iteration for the agentic tool loop.
 *
 * Extracted from AgenticRuntime to cap cognitive complexity on the orchestrator
 * and satisfy static analysis limits without changing streaming behaviour.
 */
final class AgenticToolLoopStreamReader
{
    public function __construct(
        private readonly LlmClient $llmClient,
    ) {}

    /**
     * Stream a single LLM iteration, yielding thinking deltas and accumulating results.
     *
     * Consumes chatStream(), yields thinking_delta events to the outer generator,
     * accumulates tool_call_delta and content_delta events internally.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $toolLoopState
     * @return \Generator<int, array{event: string, data: array<string, mixed>}, mixed, array<string, mixed>>
     */
    public function consumeIterationStream(
        string $runId,
        array $config,
        array $credentials,
        array &$toolLoopState,
        AiApiType $apiType,
    ): \Generator {
        $stream = $this->llmClient->chatStream(new ChatRequest(
            baseUrl: $credentials['base_url'],
            apiKey: $credentials['api_key'],
            model: $config['model'],
            messages: $toolLoopState['apiMessages'],
            maxTokens: $config['max_tokens'],
            temperature: $config['temperature'],
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            tools: $toolLoopState['tools'] !== [] ? $toolLoopState['tools'] : null,
            toolChoice: $toolLoopState['tools'] !== [] ? 'auto' : null,
            apiType: $apiType,
            reasoningSummary: $apiType === AiApiType::OpenAiResponses ? 'auto' : null,
        ));

        $content = '';
        $commentary = '';
        $toolCalls = [];
        $toolCallArgs = [];
        $usage = null;
        $latencyMs = 0;

        foreach ($stream as $event) {
            switch ($event['type']) {
                case 'thinking_delta':
                    yield ['event' => 'status', 'data' => [
                        'phase' => 'thinking_delta',
                        'delta' => $event['text'],
                        'run_id' => $runId,
                    ]];

                    if (($event['source'] ?? '') === 'commentary') {
                        $commentary .= $event['text'];
                    }

                    break;

                case 'content_delta':
                    $content .= $event['text'];
                    break;

                case 'tool_call_delta':
                    $this->accumulateToolCallDelta($event, $toolCalls, $toolCallArgs);
                    break;

                case 'done':
                    $usage = $event['usage'] ?? null;
                    $latencyMs = $event['latency_ms'] ?? 0;
                    break;

                case 'error':
                    return [
                        'runtime_error' => $event['runtime_error'] ?? AiRuntimeError::fromType(
                            AiErrorType::ServerError,
                            $event['message'] ?? 'Streaming iteration failed',
                            latencyMs: $event['latency_ms'] ?? 0,
                        ),
                    ];

                default:
                    // Unhandled stream event types are ignored — forward-compatible with provider extensions.
                    break;
            }
        }

        foreach ($toolCalls as $index => &$tc) {
            $tc['function']['arguments'] = $toolCallArgs[$index] ?? '{}';
        }
        unset($tc);

        return [
            'content' => $commentary !== '' ? $commentary : $content,
            'tool_calls' => array_values($toolCalls),
            'commentary' => $commentary,
            'final_content' => $content,
            'usage' => $usage,
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  array<int, string>  $toolCallArgs
     */
    private function accumulateToolCallDelta(array $event, array &$toolCalls, array &$toolCallArgs): void
    {
        $index = $event['index'] ?? 0;

        if ($event['id'] !== null) {
            $toolCalls[$index] = [
                'id' => $event['id'],
                'type' => 'function',
                'function' => [
                    'name' => $event['name'] ?? '',
                    'arguments' => '',
                ],
            ];
            $toolCallArgs[$index] = '';
        }

        if (($event['arguments_delta'] ?? '') !== '') {
            $toolCallArgs[$index] = ($toolCallArgs[$index] ?? '').$event['arguments_delta'];
        }
    }
}
