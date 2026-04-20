<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\Contracts\LlmTransportTap;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Services\LlmClientSupport;
use App\Base\AI\Services\ProviderMapping\ProviderRequestMapperRegistry;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\StreamInterface;

abstract class AbstractLlmProtocolClient implements LlmProtocolClient
{
    public function __construct(
        private readonly ?ProviderRequestMapperRegistry $requestMappers = null,
    ) {}

    public function chat(ChatRequest $request): array
    {
        return $this->chatOverHttp(
            $request,
            $this->pathSuffix(),
            fn (Response $response, int $latencyMs, string $model): array => $this->parseResponse($response, $latencyMs, $model),
        );
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(ChatRequest $request): Generator
    {
        yield from $this->chatStreamOverHttp($request, $this->pathSuffix());
    }

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
        $endpoint = '/'.$pathSuffix;

        $request->transportTap?->request($request, $mapping, $endpoint, false);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/'.$pathSuffix,
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            $request->transportTap?->error('connection', $e->getMessage());
            $request->transportTap?->complete([
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ]);

            return LlmClientSupport::connectionError($e, $startTime);
        }

        $request->transportTap?->responseStatus($response->status(), false);
        $request->transportTap?->responseBody($response->body(), $response->status());

        $result = $parseResponse(
            $response,
            LlmClientSupport::latencyMs($startTime),
            $request->model,
        );

        if (isset($result['runtime_error'])) {
            $request->transportTap?->error('normalize', $result['runtime_error']->userMessage, [
                'error_type' => $result['runtime_error']->errorType->value,
            ]);
        }

        $request->transportTap?->complete([
            'latency_ms' => LlmClientSupport::latencyMs($startTime),
        ]);

        return $this->withProviderMapping($result, $mapping);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function chatStreamOverHttp(ChatRequest $request, string $pathSuffix): Generator
    {
        $startTime = hrtime(true);
        $mapping = $this->mapRequest($request, stream: true);
        $endpoint = '/'.$pathSuffix;

        $request->transportTap?->request($request, $mapping, $endpoint, true);

        try {
            $http = LlmClientSupport::buildHttp($request, $mapping->headers, stream: true);

            $response = $http->post(
                rtrim($request->baseUrl, '/').'/'.$pathSuffix,
                $mapping->payload,
            );
        } catch (ConnectionException $e) {
            $request->transportTap?->error('connection', $e->getMessage());
            $request->transportTap?->complete([
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ]);
            yield from LlmClientSupport::connectionErrorStream($e, $startTime);

            return;
        }

        $request->transportTap?->responseStatus($response->status(), true);

        $error = LlmClientSupport::checkFailedResponse($response, $startTime);
        if ($error !== null) {
            $request->transportTap?->responseBody($response->body(), $response->status());
            $request->transportTap?->error(
                'http',
                (string) (($error['runtime_error'] ?? null)?->userMessage ?? 'Streaming request failed'),
            );
            $request->transportTap?->complete([
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ]);
            yield $error;

            return;
        }

        try {
            yield from $this->protocolStreamSse($response, $startTime, $mapping, $request->transportTap);
        } finally {
            $request->transportTap?->complete([
                'latency_ms' => LlmClientSupport::latencyMs($startTime),
            ]);
        }
    }

    /**
     * @return Generator<int, string>
     */
    protected function sseLines(
        Response $response,
        ?LlmTransportTap $transportTap = null,
        bool $flushTrailingBuffer = false,
    ): Generator {
        yield from $this->sseLinesFromStream(
            $response->toPsrResponse()->getBody(),
            $transportTap,
            $flushTrailingBuffer,
        );
    }

    /**
     * @return Generator<int, string>
     */
    private function sseLinesFromStream(
        StreamInterface $stream,
        ?LlmTransportTap $transportTap,
        bool $flushTrailingBuffer,
    ): Generator {
        $buffer = '';
        $firstByteRecorded = false;

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            if (! $firstByteRecorded) {
                $transportTap?->firstByte();
                $firstByteRecorded = true;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = (string) array_pop($lines);

            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                $transportTap?->streamLine($trimmedLine);

                yield $trimmedLine;
            }
        }

        if (! $flushTrailingBuffer || trim($buffer) === '') {
            return;
        }

        foreach (explode("\n", $buffer) as $line) {
            $trimmedLine = trim($line);
            $transportTap?->streamLine($trimmedLine);

            yield $trimmedLine;
        }
    }

    /**
     * Decode provider-specific Server-Sent Events after a successful streaming POST.
     *
     * @return Generator<int, array<string, mixed>>
     */
    abstract protected function pathSuffix(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function parseResponse(Response $response, int $latencyMs, string $model): array;

    /**
     * Decode provider-specific Server-Sent Events after a successful streaming POST.
     *
     * @return Generator<int, array<string, mixed>>
     */
    abstract protected function protocolStreamSse(
        Response $response,
        int $startTime,
        ProviderRequestMapping $mapping,
        ?LlmTransportTap $transportTap,
    ): Generator;
}
