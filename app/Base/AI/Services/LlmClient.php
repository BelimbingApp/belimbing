<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiErrorType;
use App\Base\Support\Json as BlbJson;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Stateless OpenAI-compatible chat completion client.
 *
 * Takes all configuration as explicit parameters — no knowledge of providers,
 * companies, or workspaces. Returns a normalized response array.
 */
class LlmClient
{
    /**
     * Copilot-required headers for IDE auth.
     *
     * GitHub Copilot's API rejects requests without these headers.
     * Values mirror those used by VS Code Copilot Chat.
     */
    private const COPILOT_HEADERS = [
        'User-Agent' => 'GitHubCopilotChat/0.35.0',
        'Editor-Version' => 'vscode/1.107.0',
        'Editor-Plugin-Version' => 'copilot-chat/0.35.0',
        'Copilot-Integration-Id' => 'vscode-chat',
    ];

    /**
     * Execute a chat completion against any OpenAI-compatible endpoint.
     */
    public function chat(ChatRequest $request): array
    {
        $startTime = hrtime(true);

        try {
            $http = Http::withToken($request->apiKey)
                ->timeout($request->timeout);

            if ($request->providerName === 'github-copilot') {
                $http = $http->withHeaders(self::COPILOT_HEADERS);
            }

            $response = $http->post(rtrim($request->baseUrl, '/').'/chat/completions', array_filter([
                'model' => $request->model,
                'messages' => $request->messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature,
                'tools' => $request->tools,
                'tool_choice' => $request->toolChoice,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            $latencyMs = $this->latencyMs($startTime);
            $errorType = $this->classifyConnectionException($e);

            return [
                'runtime_error' => AiRuntimeError::fromType(
                    $errorType,
                    $e->getMessage(),
                    $errorType === AiErrorType::Timeout
                        ? 'Increase the provider timeout or check network connectivity.'
                        : null,
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        return $this->parseResponse($response, $this->latencyMs($startTime), $request->model);
    }

    /**
     * Parse a completed HTTP response into a normalized result array.
     */
    private function parseResponse(Response $response, int $latencyMs, string $model): array
    {
        if ($response->failed()) {
            $body = $response->json();
            $diagnostic = $body['error']['message']
                ?? $body['error']['code']
                ?? $response->body();

            $errorType = match (true) {
                $response->status() === 401 => AiErrorType::AuthError,
                $response->status() === 404 => AiErrorType::NotFound,
                $response->status() === 429 => AiErrorType::RateLimit,
                $response->status() >= 500 => AiErrorType::ServerError,
                default => AiErrorType::UnexpectedError,
            };

            return [
                'runtime_error' => AiRuntimeError::fromType(
                    $errorType,
                    "HTTP {$response->status()}: {$diagnostic}",
                    httpStatus: $response->status(),
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        $data = $response->json();
        if (! is_array($data)) {
            $payloadType = $this->classifyInvalidPayload($response);

            return [
                'runtime_error' => AiRuntimeError::fromType(
                    $payloadType,
                    "Model \"{$model}\" returned non-JSON payload (Content-Type: {$response->header('Content-Type')})",
                    $payloadType === AiErrorType::HtmlResponse
                        ? 'Check that the provider base URL points to the API endpoint, not the provider website.'
                        : null,
                    httpStatus: $response->status(),
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        $choice = $data['choices'][0]['message'] ?? [];
        if (! is_array($choice)) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::UnsupportedResponseShape,
                    "Model \"{$model}\" returned unsupported message format",
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        $content = $choice['content'] ?? '';
        $toolCalls = $choice['tool_calls'] ?? null;
        $hasToolCalls = is_array($toolCalls) && count($toolCalls) > 0;
        $usage = $data['usage'] ?? [];

        if (($content === '' || $content === null) && ! $hasToolCalls) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::EmptyResponse,
                    "Model \"{$model}\" produced no text content",
                    'The model may be unavailable for this provider key or endpoint.',
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        $result = [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
            ],
            'latency_ms' => $latencyMs,
        ];

        if ($hasToolCalls) {
            $result['tool_calls'] = $toolCalls;
        }

        return $result;
    }

    /**
     * Execute a streaming chat completion against any OpenAI-compatible endpoint.
     *
     * Yields normalized events as the response streams in. The caller is responsible
     * for iterating the generator and closing it when done.
     *
     * Event types:
     * - ['type' => 'content_delta', 'text' => '...']
     * - ['type' => 'tool_call_delta', 'index' => int, 'id' => string|null, 'name' => string|null, 'arguments_delta' => string]
     * - ['type' => 'done', 'finish_reason' => string, 'usage' => array|null, 'latency_ms' => int]
     * - ['type' => 'error', 'message' => string, 'latency_ms' => int]
     *
     * @return Generator<int, array{type: string, text?: string, index?: int, id?: string|null, name?: string|null, arguments_delta?: string, finish_reason?: string, usage?: array<string, int|null>|null, message?: string, latency_ms?: int}>
     */
    public function chatStream(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);

        try {
            $http = Http::withToken($request->apiKey)
                ->timeout($request->timeout)
                ->withOptions(['stream' => true]);

            if ($request->providerName === 'github-copilot') {
                $http = $http->withHeaders(self::COPILOT_HEADERS);
            }

            $response = $http->post(rtrim($request->baseUrl, '/').'/chat/completions', array_filter([
                'model' => $request->model,
                'messages' => $request->messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature,
                'stream' => true,
                'tools' => $request->tools,
                'tool_choice' => $request->toolChoice,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            $latencyMs = $this->latencyMs($startTime);
            $errorType = $this->classifyConnectionException($e);

            yield [
                'type' => 'error',
                'runtime_error' => AiRuntimeError::fromType($errorType, $e->getMessage(), latencyMs: $latencyMs),
                'latency_ms' => $latencyMs,
            ];

            return;
        }

        if ($response->failed()) {
            $latencyMs = $this->latencyMs($startTime);
            $body = $response->json();
            $diagnostic = $body['error']['message']
                ?? $body['error']['code']
                ?? $response->body();

            $errorType = match (true) {
                $response->status() === 401 => AiErrorType::AuthError,
                $response->status() === 404 => AiErrorType::NotFound,
                $response->status() === 429 => AiErrorType::RateLimit,
                $response->status() >= 500 => AiErrorType::ServerError,
                default => AiErrorType::UnexpectedError,
            };

            yield [
                'type' => 'error',
                'runtime_error' => AiRuntimeError::fromType($errorType, "HTTP {$response->status()}: {$diagnostic}", httpStatus: $response->status(), latencyMs: $latencyMs),
                'latency_ms' => $latencyMs,
            ];

            return;
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $finishReason = null;

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            yield from $this->processStreamLines($lines, $finishReason, $startTime);

            if ($finishReason !== null) {
                return;
            }
        }

        // Stream ended without [DONE] — still yield done
        yield [
            'type' => 'done',
            'finish_reason' => $finishReason ?? 'stop',
            'usage' => null,
            'latency_ms' => $this->latencyMs($startTime),
        ];
    }

    /**
     * Process a batch of SSE lines from the stream buffer.
     *
     * Yields parsed events and sets $finishReason when a terminal event is seen.
     *
     * @param  list<string>  $lines
     * @return Generator<int, array<string, mixed>>
     */
    private function processStreamLines(array $lines, ?string &$finishReason, int $startTime): Generator
    {
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data: ')) {
                continue;
            }

            yield from $this->parseSsePayload(substr($line, 6), $finishReason, $startTime);

            if ($finishReason === '__done__') {
                return;
            }
        }
    }

    /**
     * Parse a single SSE data payload and yield normalized events.
     *
     * Sets $finishReason to '__done__' when a terminal event is encountered,
     * signalling the caller to stop processing further lines.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function parseSsePayload(string $payload, ?string &$finishReason, int $startTime): Generator
    {
        if ($payload === '[DONE]') {
            yield [
                'type' => 'done',
                'finish_reason' => $finishReason ?? 'stop',
                'usage' => null,
                'latency_ms' => $this->latencyMs($startTime),
            ];

            $finishReason = '__done__';

            return;
        }

        $data = BlbJson::decodeArray($payload);
        if ($data === null) {
            return;
        }

        $delta = $data['choices'][0]['delta'] ?? [];
        $finishReason = $data['choices'][0]['finish_reason'] ?? $finishReason;
        $usage = $data['usage'] ?? null;

        $contentDelta = $delta['content'] ?? null;
        if (is_string($contentDelta) && $contentDelta !== '') {
            yield ['type' => 'content_delta', 'text' => $contentDelta];
        }

        $toolCallDeltas = $delta['tool_calls'] ?? null;
        if (is_array($toolCallDeltas)) {
            foreach ($toolCallDeltas as $tcDelta) {
                yield [
                    'type' => 'tool_call_delta',
                    'index' => $tcDelta['index'] ?? 0,
                    'id' => $tcDelta['id'] ?? null,
                    'name' => $tcDelta['function']['name'] ?? null,
                    'arguments_delta' => $tcDelta['function']['arguments'] ?? '',
                ];
            }
        }

        if ($finishReason !== null && is_array($usage)) {
            yield [
                'type' => 'done',
                'finish_reason' => $finishReason,
                'usage' => [
                    'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                    'completion_tokens' => $usage['completion_tokens'] ?? null,
                ],
                'latency_ms' => $this->latencyMs($startTime),
            ];

            $finishReason = '__done__';
        }
    }

    private function latencyMs(int|float $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1_000_000);
    }

    /**
     * Classify a non-JSON provider response as HTML or generic unsupported shape.
     */
    private function classifyInvalidPayload(Response $response): AiErrorType
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        $body = ltrim($response->body());

        if (str_contains($contentType, 'text/html') || str_starts_with($body, '<!DOCTYPE html') || str_starts_with($body, '<html')) {
            return AiErrorType::HtmlResponse;
        }

        return AiErrorType::UnsupportedResponseShape;
    }

    /**
     * Classify a connection exception as timeout or generic connection error.
     */
    private function classifyConnectionException(ConnectionException $e): AiErrorType
    {
        $message = $e->getMessage();

        if (str_contains($message, 'timed out') || str_contains($message, 'cURL error 28')) {
            return AiErrorType::Timeout;
        }

        return AiErrorType::ConnectionError;
    }
}
