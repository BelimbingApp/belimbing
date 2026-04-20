<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Services\LlmClientSupport;
use App\Base\AI\Services\ProviderMapping\ProviderRequestMapperRegistry;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

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

    /**
     * @param  callable(Response, int, string): array<string, mixed>  $parseResponse
     * @return array<string, mixed>
     */
    protected function chatOverHttp(ChatRequest $request, string $pathSuffix, callable $parseResponse): array
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: false);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/'.$pathSuffix,
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            return LlmClientSupport::connectionError($e, $startTime);
        }

        return $this->withProviderMapping(
            $parseResponse(
                $response,
                LlmClientSupport::latencyMs($startTime),
                $request->model,
            ),
            $mapping,
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function chatStreamOverHttp(ChatRequest $request, string $pathSuffix): Generator
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: true);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/'.$pathSuffix,
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

        yield from $this->protocolStreamSse($response, $startTime, $mapping);
    }

    /**
     * Decode provider-specific Server-Sent Events after a successful streaming POST.
     *
     * @return Generator<int, array<string, mixed>>
     */
    abstract protected function protocolStreamSse(
        Response $response,
        int $startTime,
        ProviderRequestMapping $mapping,
    ): Generator;
}
