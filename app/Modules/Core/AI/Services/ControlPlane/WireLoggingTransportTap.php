<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\AI\Contracts\LlmTransportTap;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\Support\Json as BlbJson;

class WireLoggingTransportTap implements LlmTransportTap
{
    public function __construct(
        private readonly WireLogger $wireLogger,
        private readonly string $runId,
    ) {}

    public function request(ChatRequest $request, ProviderRequestMapping $mapping, string $endpoint, bool $stream): void
    {
        $this->wireLogger->append($this->runId, [
            'type' => 'llm.request',
            'endpoint' => $endpoint,
            'stream' => $stream,
            'request' => [
                'base_url' => $request->baseUrl,
                'api_key' => '[redacted]',
                'model' => $request->model,
                'messages' => $request->messages,
                'execution_controls' => $request->executionControls->toArray(),
                'timeout' => $request->timeout,
                'provider_name' => $request->providerName,
                'tools' => $request->tools,
                'api_type' => $request->apiType->value,
            ],
            'mapped' => [
                'payload' => $mapping->payload,
                'headers' => $this->redactHeaders($mapping->headers),
                'meta' => $mapping->meta(),
            ],
        ]);
    }

    public function responseStatus(int $statusCode, bool $stream): void
    {
        $this->wireLogger->append($this->runId, [
            'type' => 'llm.response_status',
            'status_code' => $statusCode,
            'stream' => $stream,
        ]);
    }

    public function responseBody(string $body, int $statusCode): void
    {
        $this->wireLogger->append($this->runId, [
            'type' => 'llm.response_body',
            'status_code' => $statusCode,
            'raw_body' => $body,
            'decoded_body' => BlbJson::decodeArray($body),
        ]);
    }

    public function firstByte(): void
    {
        $this->wireLogger->append($this->runId, [
            'type' => 'llm.first_byte',
        ]);
    }

    public function streamLine(string $line): void
    {
        $this->wireLogger->append($this->runId, [
            'type' => 'llm.stream_line',
            'raw_line' => $line,
        ]);
    }

    public function error(string $stage, string $message, array $context = []): void
    {
        $this->wireLogger->append($this->runId, [
            'type' => 'llm.error',
            'stage' => $stage,
            'message' => $message,
            'context' => $context,
        ]);
    }

    public function complete(array $context = []): void
    {
        $this->wireLogger->append($this->runId, [
            'type' => 'llm.complete',
            'context' => $context,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $key => $value) {
            $normalizedKey = strtolower($key);
            $redacted[$key] = in_array($normalizedKey, ['authorization', 'x-api-key', 'api-key'], true)
                ? '[redacted]'
                : $value;
        }

        return $redacted;
    }
}
