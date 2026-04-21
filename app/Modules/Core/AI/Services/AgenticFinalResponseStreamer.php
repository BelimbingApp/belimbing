<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorder;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\AI\Services\ControlPlane\WireLoggingTransportTap;

/**
 * Streams the final LLM text response after tool calls complete in agentic runs.
 *
 * Extracted from AgenticRuntime to cap class size and cognitive load on the
 * orchestrator without changing streaming behaviour or metadata shape.
 */
final class AgenticFinalResponseStreamer
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly RunRecorder $runRecorder,
        private readonly RuntimeResponseFactory $responseFactory,
        private readonly WireLogger $wireLogger,
    ) {}

    /**
     * @param  array{api_type: AiApiType|null, model: string, execution_controls: ExecutionControls, timeout: int, provider_name: string|null}  $config
     * @param  array{api_key: string, base_url: string, headers?: array<string, string>}  $credentials
     * @param  array{
     *     api_messages: list<array<string, mixed>>,
     *     tools: list<array<string, mixed>>,
     *     tool_actions: list<array<string, mixed>>,
     *     client_actions: list<string>,
     *     retry_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     fallback_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     hooks: array<string, mixed>
     * }  $streamState
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    public function streamFinalResponse(
        string $runId,
        array $config,
        array $credentials,
        array $streamState,
    ): \Generator {
        $apiType = $config['api_type'] ?? AiApiType::OpenAiChatCompletions;
        $executionControls = $streamState['tools'] !== []
            ? $config['execution_controls']->withToolChoice(ToolChoiceMode::Auto)
            : $config['execution_controls']->withToolChoice(null);

        if ($apiType === AiApiType::OpenAiResponses) {
            $executionControls = $executionControls
                ->withReasoningVisibility(ReasoningVisibility::Summary)
                ->withReasoningContextPreservation(true);
        } elseif ($apiType === AiApiType::AnthropicMessages) {
            $executionControls = $executionControls
                ->withReasoningContextPreservation(true);
        }

        $stream = $this->llmClient->chatStream(new ChatRequest(
            baseUrl: $credentials['base_url'],
            apiKey: $credentials['api_key'],
            model: $config['model'],
            messages: $streamState['api_messages'],
            executionControls: $executionControls,
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            tools: $streamState['tools'] !== [] ? $streamState['tools'] : null,
            apiType: $apiType,
            transportTap: $this->wireLogger->enabled()
                ? new WireLoggingTransportTap($this->wireLogger, $runId)
                : null,
            providerHeaders: $credentials['headers'] ?? [],
        ));

        $accumulator = [
            'full_content' => '',
            'usage' => null,
            'latency_ms' => 0,
            'provider_mapping' => null,
        ];

        $streamFailed = yield from $this->yieldFinalResponseStreamEvents($runId, $stream, $accumulator, $config, $streamState);

        if ($streamFailed) {
            return;
        }

        $fullContent = $this->prependClientActions($accumulator['full_content'], $streamState['client_actions']);
        $usage = $accumulator['usage'];
        $latencyMs = $accumulator['latency_ms'];

        if (trim($fullContent) === '' && $streamState['client_actions'] === []) {
            yield $this->streamEmptyContentError($runId, $config, $latencyMs, $streamState);

            return;
        }

        $meta = [
            'model' => $config['model'],
            'provider_name' => $config['provider_name'],
            'llm' => [
                'provider' => (string) ($config['provider_name'] ?? 'unknown'),
                'model' => $config['model'],
            ],
            'latency_ms' => $latencyMs,
            'tokens' => [
                'prompt' => $usage['prompt_tokens'] ?? null,
                'completion' => $usage['completion_tokens'] ?? null,
            ],
            'fallback_attempts' => $streamState['fallback_attempts'],
            'retry_attempts' => $streamState['retry_attempts'],
        ];

        if (is_array($accumulator['provider_mapping'] ?? null) && $accumulator['provider_mapping'] !== []) {
            $meta['provider_mapping'] = $accumulator['provider_mapping'];
        }

        if ($streamState['tool_actions'] !== []) {
            $meta['tool_actions'] = $streamState['tool_actions'];
        }

        if (($streamState['hooks'] ?? []) !== []) {
            $meta['hooks'] = $streamState['hooks'];
        }

        $this->runRecorder->complete($runId, $meta);

        yield ['event' => 'done', 'data' => [
            'run_id' => $runId,
            'content' => $fullContent,
            'meta' => $meta,
        ]];
    }

    /**
     * @param  array{full_content: string, usage: mixed, latency_ms: int, provider_mapping: mixed}  $accumulator
     * @param  array{
     *     retry_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     fallback_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     client_actions: list<string>,
     *     tool_actions: list<array<string, mixed>>,
     *     hooks: array<string, mixed>,
     *     tools: list<array<string, mixed>>,
     *     api_messages: list<array<string, mixed>>
     * }  $streamState
     * @return \Generator<int, array{event: string, data: array<string, mixed>}, mixed, bool>
     */
    private function yieldFinalResponseStreamEvents(
        string $runId,
        iterable $stream,
        array &$accumulator,
        array $config,
        array $streamState,
    ): \Generator {
        foreach ($stream as $event) {
            switch ($event['type']) {
                case 'thinking_delta':
                    yield ['event' => 'status', 'data' => [
                        'phase' => 'thinking_delta',
                        'delta' => $event['text'],
                        'run_id' => $runId,
                    ]];
                    break;

                case 'content_delta':
                    $accumulator['full_content'] .= $event['text'];
                    yield ['event' => 'delta', 'data' => ['text' => $event['text']]];
                    break;

                case 'done':
                    $accumulator['usage'] = $event['usage'] ?? null;
                    $accumulator['latency_ms'] = $event['latency_ms'] ?? 0;
                    $accumulator['provider_mapping'] = $event['provider_mapping'] ?? null;
                    break;

                case 'error':
                    yield $this->streamFinalErrorEvent($runId, $config, $event, $streamState);

                    return true;

                default:
                    break;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $clientActions
     */
    private function prependClientActions(string $fullContent, array $clientActions): string
    {
        if ($clientActions === []) {
            return $fullContent;
        }

        return implode("\n", $clientActions)."\n".$fullContent;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $event
     * @param  array{
     *     retry_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     fallback_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>
     * }  $streamState
     * @return array{event: string, data: array<string, mixed>}
     */
    private function streamFinalErrorEvent(string $runId, array $config, array $event, array $streamState): array
    {
        $runtimeError = $event['runtime_error'] ?? null;
        $message = $runtimeError instanceof AiRuntimeError
            ? $runtimeError->userMessage
            : ($event['message'] ?? __('An unexpected error occurred. Please try again.'));

        if ($runtimeError instanceof AiRuntimeError) {
            $this->runRecorder->fail($runId, $runtimeError);
        }

        return ['event' => 'error', 'data' => [
            'message' => $message,
            'run_id' => $runId,
            'meta' => $runtimeError instanceof AiRuntimeError
                ? array_merge(
                    $this->responseFactory->errorMeta(
                        $config['model'],
                        (string) ($config['provider_name'] ?? 'unknown'),
                        $runtimeError,
                    ),
                    [
                        'retry_attempts' => $streamState['retry_attempts'],
                        'fallback_attempts' => $streamState['fallback_attempts'],
                    ],
                )
                : null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{
     *     retry_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>,
     *     fallback_attempts: list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>
     * }  $streamState
     * @return array{event: string, data: array<string, mixed>}
     */
    private function streamEmptyContentError(string $runId, array $config, int $latencyMs, array $streamState): array
    {
        $emptyError = AiRuntimeError::fromType(
            AiErrorType::EmptyResponse,
            'Streaming response completed with no content',
            latencyMs: $latencyMs,
        );
        $this->runRecorder->fail($runId, $emptyError);

        return ['event' => 'error', 'data' => [
            'message' => $emptyError->userMessage,
            'run_id' => $runId,
            'meta' => array_merge(
                $this->responseFactory->errorMeta(
                    $config['model'],
                    (string) ($config['provider_name'] ?? 'unknown'),
                    $emptyError,
                ),
                [
                    'retry_attempts' => $streamState['retry_attempts'],
                    'fallback_attempts' => $streamState['fallback_attempts'],
                ],
            ),
        ]];
    }
}
