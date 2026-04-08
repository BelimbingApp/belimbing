<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\ControlPlane\RunRecorder;

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
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array{api_key: string, base_url: string}  $credentials
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
        $fullContent = '';
        $usage = null;
        $latencyMs = 0;

        $apiType = $config['api_type'] ?? AiApiType::OpenAiChatCompletions;

        $stream = $this->llmClient->chatStream(new ChatRequest(
            baseUrl: $credentials['base_url'],
            apiKey: $credentials['api_key'],
            model: $config['model'],
            messages: $streamState['api_messages'],
            maxTokens: $config['max_tokens'],
            temperature: $config['temperature'],
            timeout: $config['timeout'],
            providerName: $config['provider_name'],
            tools: $streamState['tools'] !== [] ? $streamState['tools'] : null,
            toolChoice: $streamState['tools'] !== [] ? 'auto' : null,
            apiType: $apiType,
            reasoningSummary: $apiType === AiApiType::OpenAiResponses ? 'auto' : null,
        ));

        foreach ($stream as $event) {
            if ($event['type'] === 'thinking_delta') {
                yield ['event' => 'status', 'data' => [
                    'phase' => 'thinking_delta',
                    'delta' => $event['text'],
                    'run_id' => $runId,
                ]];

                continue;
            }

            if ($event['type'] === 'content_delta') {
                $fullContent .= $event['text'];
                yield ['event' => 'delta', 'data' => ['text' => $event['text']]];

                continue;
            }

            if ($event['type'] === 'done') {
                $usage = $event['usage'] ?? null;
                $latencyMs = $event['latency_ms'] ?? 0;

                continue;
            }

            if ($event['type'] === 'error') {
                yield $this->streamFinalErrorEvent($runId, $config, $event, $streamState);

                return;
            }
        }

        $fullContent = $this->prependClientActions($fullContent, $streamState['client_actions']);

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
