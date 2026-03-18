<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\ChatRequest;
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

            return [
                'error' => $e->getMessage(),
                'error_type' => 'connection_error',
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
            $errorDetail = $body['error']['message']
                ?? $body['error']['code']
                ?? $response->body();

            $errorType = match (true) {
                $response->status() === 429 => 'rate_limit',
                $response->status() >= 500 => 'server_error',
                default => 'client_error',
            };

            return [
                'error' => "HTTP {$response->status()}: {$errorDetail}",
                'error_type' => $errorType,
                'latency_ms' => $latencyMs,
            ];
        }

        $data = $response->json();
        $choice = $data['choices'][0]['message'] ?? [];
        $content = $choice['content'] ?? '';
        $toolCalls = $choice['tool_calls'] ?? null;
        $hasToolCalls = is_array($toolCalls) && count($toolCalls) > 0;
        $usage = $data['usage'] ?? [];

        if (($content === '' || $content === null) && ! $hasToolCalls) {
            return [
                'error' => "Model \"{$model}\" returned an empty response — it may be unavailable via this provider.",
                'error_type' => 'empty_response',
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
            yield ['type' => 'error', 'message' => $e->getMessage(), 'latency_ms' => $this->latencyMs($startTime)];

            return;
        }

        if ($response->failed()) {
            $body = $response->json();
            $errorDetail = $body['error']['message']
                ?? $body['error']['code']
                ?? $response->body();

            yield ['type' => 'error', 'message' => "HTTP {$response->status()}: {$errorDetail}", 'latency_ms' => $this->latencyMs($startTime)];

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

            $payload = substr($line, 6);

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

            $data = json_decode($payload, true);
            if (! is_array($data)) {
                continue;
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

                return;
            }
        }
    }

    private function latencyMs(int|float $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1_000_000);
    }
}
