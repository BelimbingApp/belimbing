<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\AI\Services\ControlPlane\WireLoggingTransportTap;

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
        private readonly WireLogger $wireLogger,
        private readonly AgenticExecutionControlResolver $executionControls,
    ) {}

    /**
     * Stream a single LLM iteration, yielding thinking deltas and accumulating results.
     *
     * Consumes chatStream(), yields thinking_delta events to the outer generator,
     * accumulates tool_call_delta and content_delta events internally.
     *
     * @param  array{model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null}  $config
     * @param  array{api_key: string, base_url: string, headers?: array<string, string>}  $credentials
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
        $executionControls = $this->executionControls->resolve(
            $config['execution_controls'],
            $config['provider_name'] ?? null,
            $config['model'],
            $apiType,
            $toolLoopState['tools'] !== [],
        );

        $stream = $this->llmClient->chatStream(new ChatRequest(
            baseUrl: $credentials['base_url'],
            apiKey: $credentials['api_key'],
            model: $config['model'],
            messages: $toolLoopState['apiMessages'],
            executionControls: $executionControls,
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            tools: $toolLoopState['tools'] !== [] ? $toolLoopState['tools'] : null,
            apiType: $apiType,
            transportTap: $this->wireLogger->enabled()
                ? new WireLoggingTransportTap($this->wireLogger, $runId)
                : null,
            providerHeaders: $credentials['headers'] ?? [],
        ));

        $streamState = [
            'content' => '',
            'commentary' => '',
            'reasoning_content' => '',
            'tool_calls' => [],
            'tool_call_args' => [],
            'reasoning_blocks' => [],
            'usage' => null,
            'latency_ms' => 0,
            'finish_reason' => null,
            'provider_mapping' => null,
        ];

        foreach ($stream as $event) {
            $type = $event['type'] ?? null;

            if ($type === 'thinking_delta') {
                yield from $this->handleThinkingDelta($runId, $event, $streamState['commentary'], $streamState['reasoning_content']);

                continue;
            }

            if ($type === 'error') {
                return [
                    'runtime_error' => $this->runtimeErrorFromStreamEvent($event),
                ];
            }

            $this->accumulateStreamEvent($event, $streamState);
        }

        foreach ($streamState['tool_calls'] as $index => &$tc) {
            $tc['function']['arguments'] = $streamState['tool_call_args'][$index] ?? '{}';
        }
        unset($tc);

        return [
            'content' => $streamState['commentary'] !== '' ? $streamState['commentary'] : $streamState['content'],
            'tool_calls' => array_values($streamState['tool_calls']),
            'commentary' => $streamState['commentary'],
            'reasoning_content' => $streamState['reasoning_content'],
            'reasoning_blocks' => $streamState['reasoning_blocks'],
            'final_content' => $streamState['content'],
            'usage' => $streamState['usage'],
            'latency_ms' => $streamState['latency_ms'],
            'finish_reason' => $streamState['finish_reason'],
            'provider_mapping' => $streamState['provider_mapping'],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    private function handleThinkingDelta(string $runId, array $event, string &$commentary, string &$reasoningContent): \Generator
    {
        $text = (string) ($event['text'] ?? '');

        yield ['event' => 'status', 'data' => [
            'phase' => 'thinking_delta',
            'delta' => $text,
            'run_id' => $runId,
        ]];

        if (($event['source'] ?? '') === 'commentary') {
            $commentary .= $text;
        } elseif (($event['source'] ?? '') === 'reasoning_content') {
            $reasoningContent .= $text;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function runtimeErrorFromStreamEvent(array $event): AiRuntimeError
    {
        if ($event['runtime_error'] instanceof AiRuntimeError) {
            return $event['runtime_error'];
        }

        return AiRuntimeError::fromType(
            AiErrorType::ServerError,
            (string) ($event['message'] ?? 'Streaming iteration failed'),
            latencyMs: (int) ($event['latency_ms'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array{
     *     content: string,
     *     tool_calls: array<int, array<string, mixed>>,
     *     tool_call_args: array<int, string>,
     *     usage: array<string, mixed>|null,
     *     latency_ms: int,
     *     finish_reason: string|null,
     *     provider_mapping: array<string, mixed>|null,
     *     reasoning_blocks: array<int, array<string, mixed>>
     * }  $streamState
     */
    private function accumulateStreamEvent(array $event, array &$streamState): void
    {
        switch ($event['type'] ?? null) {
            case 'content_delta':
                $streamState['content'] .= (string) ($event['text'] ?? '');
                break;

            case 'tool_call_delta':
                $this->accumulateToolCallDelta($event, $streamState['tool_calls'], $streamState['tool_call_args']);
                break;

            case 'done':
                $streamState['usage'] = is_array($event['usage'] ?? null) ? $event['usage'] : null;
                $streamState['latency_ms'] = (int) ($event['latency_ms'] ?? 0);
                $streamState['finish_reason'] = is_string($event['finish_reason'] ?? null) ? $event['finish_reason'] : $streamState['finish_reason'];
                $streamState['provider_mapping'] = is_array($event['provider_mapping'] ?? null) ? $event['provider_mapping'] : $streamState['provider_mapping'];
                $streamState['reasoning_blocks'] = is_array($event['reasoning_blocks'] ?? null) ? $event['reasoning_blocks'] : $streamState['reasoning_blocks'];
                break;

            default:
                // Unhandled stream event types are ignored — forward-compatible with provider extensions.
                break;
        }
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
