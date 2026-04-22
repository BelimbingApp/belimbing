<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiErrorType;

/**
 * OpenAI Codex responses protocol client (ChatGPT backend API).
 *
 * Uses the OpenAI Responses event format, but a different endpoint path:
 * POST /codex/responses (not /responses).
 */
final class OpenAiCodexResponsesProtocolClient extends AbstractResponsesProtocolClient
{
    /**
     * Execute synchronous Codex calls through the streaming transport.
     *
     * Overrides the parent behavior because ChatGPT Codex rejects non-streaming
     * `/codex/responses` requests. BLB still exposes a sync `chat()` API, so
     * this method aggregates the SSE response back into the normal sync shape.
     */
    public function chat(ChatRequest $request): array
    {
        $content = '';
        $toolCalls = [];
        $usage = null;
        $latencyMs = 0;
        $providerMapping = null;

        foreach (parent::chatStream($request) as $event) {
            $eventType = (string) ($event['type'] ?? '');

            if ($eventType === 'content_delta') {
                $content .= (string) ($event['text'] ?? '');

                continue;
            }

            if ($eventType === 'tool_call_delta') {
                $this->applyToolCallDelta($toolCalls, $event);

                continue;
            }

            if ($eventType === 'done') {
                $usage = $event['usage'] ?? null;
                $latencyMs = (int) ($event['latency_ms'] ?? 0);
                $providerMapping = $event['provider_mapping'] ?? null;
            }

            if ($eventType === 'error') {
                return [
                    'runtime_error' => $event['runtime_error'],
                    'latency_ms' => (int) ($event['latency_ms'] ?? 0),
                ];
            }
        }

        if ($content === '' && $toolCalls === []) {
            return [
                'runtime_error' => AiRuntimeError::fromType(
                    AiErrorType::EmptyResponse,
                    "Model \"{$request->model}\" produced no text content",
                    'The model may be unavailable for this provider key or endpoint.',
                    latencyMs: $latencyMs,
                ),
                'latency_ms' => $latencyMs,
            ];
        }

        $result = [
            'content' => $content,
            'usage' => $usage,
            'latency_ms' => $latencyMs,
        ];

        if ($toolCalls !== []) {
            ksort($toolCalls);
            $result['tool_calls'] = array_values($toolCalls);
        }

        if (is_array($providerMapping)) {
            $result['provider_mapping'] = $providerMapping;
        }

        return $result;
    }

    protected function pathSuffix(): string
    {
        return 'codex/responses';
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  array<string, mixed>  $event
     */
    private function applyToolCallDelta(array &$toolCalls, array $event): void
    {
        $index = (int) ($event['index'] ?? count($toolCalls));
        $existing = $toolCalls[$index] ?? [
            'id' => '',
            'type' => 'function',
            'function' => [
                'name' => '',
                'arguments' => '',
            ],
        ];

        $id = $event['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $existing['id'] = $id;
        }

        $name = $event['name'] ?? null;
        if (is_string($name) && $name !== '') {
            $existing['function']['name'] = $name;
        }

        $existing['function']['arguments'] .= (string) ($event['arguments_delta'] ?? '');

        $toolCalls[$index] = $existing;
    }
}
