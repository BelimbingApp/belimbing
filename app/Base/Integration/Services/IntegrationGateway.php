<?php

namespace App\Base\Integration\Services;

use App\Base\Integration\Models\OutboundExchange;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class IntegrationGateway
{
    private const OUTCOME_SUCCESS = 'success';

    private const OUTCOME_HTTP_ERROR = 'http_error';

    private const OUTCOME_CONNECTION_ERROR = 'connection_error';

    private ?bool $outboundExchangeTableExists = null;

    /**
     * @return list<string>
     */
    private function secretHeaderNames(): array
    {
        return [
            'authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'api-key',
            'x-auth-token',
        ];
    }

    public function send(IntegrationRequest $request): IntegrationResponse
    {
        $startedAt = hrtime(true);
        $response = null;
        $error = null;
        $retryCount = 0;

        do {
            try {
                $response = $this->sendHttp($request);
                $error = null;

                break;
            } catch (ConnectionException $e) {
                $error = $e;

                if ($retryCount >= $request->retryTimes) {
                    break;
                }

                $retryCount++;

                if ($request->retrySleepMilliseconds > 0) {
                    usleep($request->retrySleepMilliseconds * 1_000);
                }
            }
        } while (true);

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $exchange = $this->recordExchange($request, $response, $error, $durationMs, $retryCount);

        if ($response instanceof Response) {
            return new IntegrationResponse(
                status: $response->status(),
                body: $response->body(),
                headers: $response->headers(),
                exchange: $exchange,
            );
        }

        return new IntegrationResponse(
            status: null,
            body: '',
            headers: [],
            exchange: $exchange,
        );
    }

    /**
     * @throws ConnectionException
     */
    private function sendHttp(IntegrationRequest $request): Response
    {
        $pending = Http::timeout($request->timeoutSeconds)
            ->withOptions([
                // Windows PHP builds do not reliably inherit the OS trust
                // store. Keep TLS verification mandatory while resolving a
                // portable configured/system/Mozilla CA bundle.
                'verify' => CaBundle::getSystemCaRootBundlePath(),
            ]);

        if ($request->headers !== []) {
            $pending = $pending->withHeaders($request->headers);
        }

        if ($request->basicAuth !== null) {
            $pending = $pending->withBasicAuth($request->basicAuth[0], $request->basicAuth[1]);
        }

        if ($request->asForm) {
            $pending = $pending->asForm()->acceptJson();
        } elseif ($request->asJson) {
            $pending = $pending->acceptJson()->asJson();
        }

        $options = [];
        if ($request->query !== []) {
            $options['query'] = $request->query;
        }

        if ($request->body !== null && $request->asForm && is_array($request->body)) {
            $options['form_params'] = $request->body;
        } elseif ($request->body !== null) {
            $options[$request->asJson ? 'json' : 'body'] = $request->body;
        }

        return $pending->send(strtoupper($request->method), $request->endpoint, $options);
    }

    private function recordExchange(
        IntegrationRequest $request,
        ?Response $response,
        ?ConnectionException $error,
        int $durationMs,
        int $retryCount,
    ): ?OutboundExchange {
        if (! $this->outboundExchangeTableExists()) {
            return null;
        }

        $requestPreview = $this->payloadPreview($request->body);
        $responsePreview = $response instanceof Response ? $this->payloadPreview($response->body()) : null;
        $metadata = $this->metadata($request);
        $exchangeMetadata = $this->exchangeMetadata($metadata);

        return OutboundExchange::query()->create([
            'system' => $request->system,
            'provider' => $request->provider,
            'operation' => $request->operation,
            'transport' => $request->transport,
            'protocol' => $request->protocol,
            'protocol_operation' => $request->protocolOperation,
            'endpoint' => $this->endpointWithQuery($request->endpoint, $request->query),
            'owner_type' => $request->ownerType,
            'owner_id' => $request->ownerId,
            'correlation_id' => $request->correlationId,
            'traceparent' => $request->traceparent,
            'tracestate' => $request->tracestate,
            'request_headers' => $this->redactHeaders($this->requestHeaders($request)),
            'request_body' => $requestPreview['payload'] ?? null,
            'request_body_truncated' => (bool) ($requestPreview['truncated'] ?? false),
            'request_body_original_bytes' => $requestPreview['original_bytes'] ?? null,
            'response_status' => $response?->status(),
            'response_headers' => $response instanceof Response ? $this->redactHeaders($response->headers()) : null,
            'response_body' => $responsePreview['payload'] ?? null,
            'response_body_truncated' => (bool) ($responsePreview['truncated'] ?? false),
            'response_body_original_bytes' => $responsePreview['original_bytes'] ?? null,
            'duration_ms' => $durationMs,
            'retry_count' => $retryCount,
            'outcome' => $this->outcome($response, $error),
            'error_class' => $error instanceof ConnectionException ? $error::class : null,
            'error_message' => $error?->getMessage(),
            'fallback_used' => (bool) ($metadata['fallback_used'] ?? false),
            'fallback_reason' => isset($metadata['fallback_reason'])
                ? (string) $metadata['fallback_reason']
                : null,
            'metadata' => $exchangeMetadata,
            'occurred_at' => now(),
        ]);
    }

    private function outboundExchangeTableExists(): bool
    {
        if ($this->outboundExchangeTableExists !== null) {
            return $this->outboundExchangeTableExists;
        }

        return $this->outboundExchangeTableExists = Schema::hasTable('base_integration_outbound_exchanges');
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(IntegrationRequest $request): array
    {
        if ($request->transport !== 'http') {
            return $request->metadata;
        }

        return array_merge([
            'http_method' => strtoupper($request->method),
        ], $request->metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function exchangeMetadata(array $metadata): array
    {
        unset($metadata['fallback_used'], $metadata['fallback_reason']);

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestHeaders(IntegrationRequest $request): array
    {
        if ($request->basicAuth === null) {
            return $request->headers;
        }

        return ['Authorization' => 'Basic'] + $request->headers;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower((string) $name);
            $redacted[$name] = in_array($normalized, $this->secretHeaderNames(), true)
                ? '[redacted]'
                : $value;
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>|string|null  $payload
     * @return array{payload: array<string, mixed>, truncated: bool, original_bytes: int}|null
     */
    private function payloadPreview(array|string|null $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        // Raw binary bodies (e.g. marketplace picture uploads) cannot live in a
        // JSON column — json_encode fails and the exchange row becomes
        // unwritable, which would fail the call after it already succeeded
        // remotely. Record the shape, not the bytes.
        if (is_string($payload) && ! mb_check_encoding($payload, 'UTF-8')) {
            return [
                'payload' => ['kind' => 'binary'],
                'truncated' => true,
                'original_bytes' => strlen($payload),
            ];
        }

        $normalized = $this->normalizePayload($payload);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encoded = $encoded === false ? '' : $encoded;
        $originalBytes = strlen($encoded);

        return [
            'payload' => $normalized,
            'truncated' => false,
            'original_bytes' => $originalBytes,
        ];
    }

    /**
     * @param  array<string, mixed>|string  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array|string $payload): array
    {
        if (is_array($payload)) {
            return [
                'kind' => 'json',
                'value' => $payload,
            ];
        }

        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            return [
                'kind' => 'json',
                'value' => $decoded,
            ];
        }

        return [
            'kind' => 'text',
            'value' => $payload,
        ];
    }

    private function outcome(?Response $response, ?ConnectionException $error): string
    {
        if ($error instanceof ConnectionException) {
            return self::OUTCOME_CONNECTION_ERROR;
        }

        if ($response instanceof Response && $response->failed()) {
            return self::OUTCOME_HTTP_ERROR;
        }

        return self::OUTCOME_SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function endpointWithQuery(string $endpoint, array $query): string
    {
        if ($query === []) {
            return $endpoint;
        }

        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint.$separator.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
