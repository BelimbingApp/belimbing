<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use Generator;
use Illuminate\Http\Client\ConnectionException;
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
     *
     * @param  string  $baseUrl  Provider base URL (e.g., 'https://api.openai.com/v1')
     * @param  string  $apiKey  Bearer token / API key
     * @param  string  $model  Model ID (e.g., 'gpt-5.2')
     * @param  list<array{role: string, content: string|null, tool_calls?: list<array<string, mixed>>, tool_call_id?: string}>  $messages  Chat messages
     * @param  int  $maxTokens  Maximum tokens in response
     * @param  float  $temperature  Sampling temperature
     * @param  int  $timeout  HTTP timeout in seconds
     * @param  string|null  $providerName  Provider name (used for provider-specific headers)
     * @param  list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>|null  $tools  Tool definitions (OpenAI format)
     * @param  string|null  $toolChoice  Tool choice strategy ('auto', 'none', 'required', or specific tool)
     * @return array{content?: string, tool_calls?: list<array{id: string, type: string, function: array{name: string, arguments: string}}>, usage?: array<string, int|null>, latency_ms: int, error?: string, error_type?: string}
     */
    public function chat(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        int $maxTokens = 2048,
        float $temperature = 0.7,
        int $timeout = 60,
        ?string $providerName = null,
        ?array $tools = null,
        ?string $toolChoice = null,
    ): array {
        $startTime = hrtime(true);

        try {
            $request = Http::withToken($apiKey)
                ->timeout($timeout);

            if ($providerName === 'github-copilot') {
                $request = $request->withHeaders(self::COPILOT_HEADERS);
            }

            $response = $request->post(rtrim($baseUrl, '/').'/chat/completions', array_filter([
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'tools' => $tools,
                'tool_choice' => $toolChoice,
            ], fn ($v) => $v !== null));
        } catch (ConnectionException $e) {
            $latencyMs = $this->latencyMs($startTime);

            return [
                'error' => $e->getMessage(),
                'error_type' => 'connection_error',
                'latency_ms' => $latencyMs,
            ];
        }

        $latencyMs = $this->latencyMs($startTime);

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
        $usage = $data['usage'] ?? [];

        $result = [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
            ],
            'latency_ms' => $latencyMs,
        ];

        if (is_array($toolCalls) && count($toolCalls) > 0) {
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
     * @param  string  $baseUrl  Provider base URL
     * @param  string  $apiKey  Bearer token / API key
     * @param  string  $model  Model ID
     * @param  list<array{role: string, content: string|null, tool_calls?: list<array<string, mixed>>, tool_call_id?: string}>  $messages
     * @param  int  $maxTokens  Maximum tokens in response
     * @param  float  $temperature  Sampling temperature
     * @param  int  $timeout  HTTP timeout in seconds
     * @param  string|null  $providerName  Provider name (used for provider-specific headers)
     * @param  list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>|null  $tools
     * @param  string|null  $toolChoice  Tool choice strategy
     * @return Generator<int, array{type: string, text?: string, index?: int, id?: string|null, name?: string|null, arguments_delta?: string, finish_reason?: string, usage?: array<string, int|null>|null, message?: string, latency_ms?: int}>
     */
    public function chatStream(
        string $baseUrl,
        string $apiKey,
        string $model,
        array $messages,
        int $maxTokens = 2048,
        float $temperature = 0.7,
        int $timeout = 120,
        ?string $providerName = null,
        ?array $tools = null,
        ?string $toolChoice = null,
    ): Generator {
        $startTime = hrtime(true);

        try {
            $request = Http::withToken($apiKey)
                ->timeout($timeout)
                ->withOptions(['stream' => true]);

            if ($providerName === 'github-copilot') {
                $request = $request->withHeaders(self::COPILOT_HEADERS);
            }

            $response = $request->post(rtrim($baseUrl, '/').'/chat/completions', array_filter([
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'stream' => true,
                'tools' => $tools,
                'tool_choice' => $toolChoice,
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
            $buffer = array_pop($lines); // Keep incomplete line in buffer

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                if (! str_starts_with($line, 'data: ')) {
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

                    return;
                }

                $data = json_decode($payload, true);
                if (! is_array($data)) {
                    continue;
                }

                $delta = $data['choices'][0]['delta'] ?? [];
                $finishReason = $data['choices'][0]['finish_reason'] ?? $finishReason;
                $usage = $data['usage'] ?? null;

                // Content delta
                $contentDelta = $delta['content'] ?? null;
                if (is_string($contentDelta) && $contentDelta !== '') {
                    yield ['type' => 'content_delta', 'text' => $contentDelta];
                }

                // Tool call deltas
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

                // If finish_reason is set and usage is available, yield done
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

                    return;
                }
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

    private function latencyMs(int|float $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1_000_000);
    }
}
