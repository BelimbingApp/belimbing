<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Services\LlmClientSupport;
use App\Base\AI\Services\ProviderMapping\ProviderRequestMapperRegistry;

abstract class AbstractLlmProtocolClient implements LlmProtocolClient
{
    public function __construct(
        private readonly ?ProviderRequestMapperRegistry $requestMappers = null,
    ) {}

    protected function mapRequest(ChatRequest $request, bool $stream): ProviderRequestMapping
    {
        return $this->requestMapperRegistry()
            ->forApiType($request->apiType)
            ->mapPayload($request, $stream);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function withProviderMapping(array $result, ProviderRequestMapping $mapping): array
    {
        $meta = $mapping->meta();

        if ($meta !== null) {
            $result['provider_mapping'] = $meta;
        }

        return $result;
    }

    /**
     * @param  array{prompt_tokens: int|null, completion_tokens: int|null}|null  $usage
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function buildDoneEvent(
        string $finishReason,
        ?array $usage,
        int $startTime,
        ProviderRequestMapping $mapping,
        array $extra = [],
    ): array {
        $event = array_merge([
            'type' => 'done',
            'finish_reason' => $finishReason,
            'usage' => $usage,
            'latency_ms' => LlmClientSupport::latencyMs($startTime),
        ], $extra);

        $meta = $mapping->meta();
        if ($meta !== null) {
            $event['provider_mapping'] = $meta;
        }

        return $event;
    }

    private function requestMapperRegistry(): ProviderRequestMapperRegistry
    {
        return $this->requestMappers ?? app(ProviderRequestMapperRegistry::class);
    }
}
