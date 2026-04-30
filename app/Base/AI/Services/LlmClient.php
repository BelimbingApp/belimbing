<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Services\Protocols\LlmProtocolClientRegistry;
use Generator;

/**
 * Stateless LLM client facade routing requests to protocol-specific handlers.
 *
 * Dispatches on ChatRequest::$apiType to the correct wire-protocol client.
 * Model-agnostic — never inspects model names. Returns a normalized
 * response array regardless of which protocol was used.
 */
class LlmClient
{
    public function __construct(
        private readonly ?LlmProtocolClientRegistry $protocols = null,
    ) {}

    /**
     * Execute a sync LLM call using the protocol specified by the request.
     */
    public function chat(ChatRequest $request): array
    {
        return $this->protocolClientRegistry()
            ->forApiType($request->apiType)
            ->chat($request);
    }

    /**
     * Execute a streaming LLM call using the protocol specified by the request.
     *
     * Yields normalized events regardless of protocol:
     * - ['type' => 'content_delta', 'text' => '...']
     * - ['type' => 'tool_call_delta', 'index' => int, 'id' => ?string, 'name' => ?string, 'arguments_delta' => string]
     * - ['type' => 'done', 'finish_reason' => string, 'usage' => ?array, 'latency_ms' => int]
     *   where usage uses prompt_tokens / cached_input_tokens / completion_tokens /
     *   reasoning_tokens / total_tokens when reported by the provider.
     * - ['type' => 'error', 'runtime_error' => AiRuntimeError, 'latency_ms' => int]
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(ChatRequest $request): Generator
    {
        $protocol = $this->protocolClientRegistry()->forApiType($request->apiType);

        foreach ($protocol->chatStream($request) as $event) {
            yield $event;
        }
    }

    private function protocolClientRegistry(): LlmProtocolClientRegistry
    {
        return $this->protocols ?? app(LlmProtocolClientRegistry::class);
    }
}
