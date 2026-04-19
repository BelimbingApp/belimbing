<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClientSupport;
use App\Base\Support\Json as BlbJson;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

final class AnthropicMessagesProtocolClient extends AbstractLlmProtocolClient
{
    public function chat(ChatRequest $request): array
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: false);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/messages',
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            return LlmClientSupport::connectionError($e, $startTime);
        }

        return $this->withProviderMapping(
            $this->parseResponse($response, LlmClientSupport::latencyMs($startTime), $request->model),
            $mapping,
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(ChatRequest $request): Generator
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: true);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/messages',
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            yield from LlmClientSupport::connectionErrorStream($e, $startTime);

            return;
        }

        $error = LlmClientSupport::checkFailedResponse($response, $startTime);
        if ($error !== null) {
            yield $error;

            return;
        }

        yield from $this->streamSse($response, $startTime, $mapping);
    }

    private function parseResponse(Response $response, int $latencyMs, string $model): array
    {
        if ($response->failed()) {
            return LlmClientSupport::parseFailedResponse($response, $latencyMs);
        }

        $data = $response->json();
        if (! is_array($data)) {
            return LlmClientSupport::invalidPayloadError($response, $latencyMs, $model);
        }

        $content = '';
        $toolCalls = [];
        $reasoningBlocks = [];

        foreach ($data['content'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type === 'text') {
                $content .= (string) ($block['text'] ?? '');

                continue;
            }

            if ($type === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($block['name'] ?? ''),
                        'arguments' => $this->encodeToolInput($block['input'] ?? []),
                    ],
                ];

                continue;
            }

            if (in_array($type, ['thinking', 'redacted_thinking'], true)) {
                $reasoningBlocks[] = $block;
            }
        }

        $hasToolCalls = $toolCalls !== [];
        $usage = $data['usage'] ?? [];

        if ($content === '' && ! $hasToolCalls) {
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
                'prompt_tokens' => $usage['input_tokens'] ?? null,
                'completion_tokens' => $usage['output_tokens'] ?? null,
            ],
            'latency_ms' => $latencyMs,
        ];

        if ($hasToolCalls) {
            $result['tool_calls'] = $toolCalls;
        }

        if ($reasoningBlocks !== []) {
            $result['reasoning_blocks'] = $reasoningBlocks;
        }

        return $result;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function streamSse(Response $response, int $startTime, ProviderRequestMapping $mapping): Generator
    {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $pendingEventType = null;
        $contentBlocks = [];
        $toolInputJson = [];
        $reasoningBlocks = [];
        $promptTokens = null;
        $completionTokens = null;
        $finishReason = 'stop';

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                if (str_starts_with($line, 'event: ')) {
                    $pendingEventType = substr($line, 7);

                    continue;
                }

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = BlbJson::decodeArray(substr($line, 6));
                if ($data === null) {
                    continue;
                }

                switch ($pendingEventType) {
                    case 'message_start':
                        $promptTokens = $data['message']['usage']['input_tokens'] ?? null;
                        break;

                    case 'content_block_start':
                        $index = (int) ($data['index'] ?? 0);
                        $contentBlocks[$index] = $data['content_block'] ?? [];

                        if (($contentBlocks[$index]['type'] ?? null) === 'tool_use') {
                            $toolInputJson[$index] = '';

                            yield [
                                'type' => 'tool_call_delta',
                                'index' => $index,
                                'id' => $contentBlocks[$index]['id'] ?? null,
                                'name' => $contentBlocks[$index]['name'] ?? null,
                                'arguments_delta' => '',
                            ];
                        }
                        break;

                    case 'content_block_delta':
                        $index = (int) ($data['index'] ?? 0);
                        $delta = $data['delta'] ?? [];
                        $deltaType = $delta['type'] ?? null;

                        if ($deltaType === 'text_delta') {
                            $text = (string) ($delta['text'] ?? '');
                            if ($text !== '') {
                                $contentBlocks[$index]['text'] = ($contentBlocks[$index]['text'] ?? '').$text;
                                yield ['type' => 'content_delta', 'text' => $text];
                            }

                            break;
                        }

                        if ($deltaType === 'thinking_delta') {
                            $thinking = (string) ($delta['thinking'] ?? '');
                            if ($thinking !== '') {
                                $contentBlocks[$index]['thinking'] = ($contentBlocks[$index]['thinking'] ?? '').$thinking;
                                yield ['type' => 'thinking_delta', 'text' => $thinking, 'source' => 'anthropic_thinking'];
                            }

                            break;
                        }

                        if ($deltaType === 'signature_delta') {
                            $contentBlocks[$index]['signature'] = $delta['signature'] ?? null;

                            break;
                        }

                        if ($deltaType === 'input_json_delta') {
                            $partialJson = (string) ($delta['partial_json'] ?? '');
                            $toolInputJson[$index] = ($toolInputJson[$index] ?? '').$partialJson;

                            yield [
                                'type' => 'tool_call_delta',
                                'index' => $index,
                                'id' => $contentBlocks[$index]['id'] ?? null,
                                'name' => $contentBlocks[$index]['name'] ?? null,
                                'arguments_delta' => $partialJson,
                            ];
                        }
                        break;

                    case 'content_block_stop':
                        $index = (int) ($data['index'] ?? 0);
                        $block = $contentBlocks[$index] ?? null;

                        if (! is_array($block)) {
                            break;
                        }

                        if (($block['type'] ?? null) === 'tool_use') {
                            $block['input'] = BlbJson::decodeArray($toolInputJson[$index] ?? '') ?? [];
                            $contentBlocks[$index] = $block;
                        }

                        if (in_array($block['type'] ?? null, ['thinking', 'redacted_thinking'], true)) {
                            $reasoningBlocks[] = $block;
                        }

                        unset($toolInputJson[$index]);
                        break;

                    case 'message_delta':
                        $finishReason = $data['delta']['stop_reason'] ?? $finishReason;
                        $completionTokens = $data['usage']['output_tokens'] ?? $completionTokens;
                        break;

                    case 'message_stop':
                        yield $this->buildDoneEvent(
                            $finishReason,
                            [
                                'prompt_tokens' => $promptTokens,
                                'completion_tokens' => $completionTokens,
                            ],
                            $startTime,
                            $mapping,
                            $reasoningBlocks !== [] ? ['reasoning_blocks' => $reasoningBlocks] : [],
                        );

                        return;

                    case 'error':
                        $message = $data['error']['message'] ?? 'Anthropic stream error';

                        yield [
                            'type' => 'error',
                            'runtime_error' => AiRuntimeError::fromType(
                                AiErrorType::ServerError,
                                $message,
                                latencyMs: LlmClientSupport::latencyMs($startTime),
                            ),
                            'latency_ms' => LlmClientSupport::latencyMs($startTime),
                        ];

                        return;

                    default:
                        break;
                }
            }
        }

        yield $this->buildDoneEvent(
            $finishReason,
            [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
            $startTime,
            $mapping,
            $reasoningBlocks !== [] ? ['reasoning_blocks' => $reasoningBlocks] : [],
        );
    }

    private function encodeToolInput(mixed $input): string
    {
        if (! is_array($input)) {
            return '{}';
        }

        $normalized = $input === [] ? (object) [] : $input;

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
