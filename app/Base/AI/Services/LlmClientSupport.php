<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class LlmClientSupport
{
    /**
     * @param  array<string, string>  $providerHeaders
     */
    public static function buildHttp(
        ChatRequest $request,
        array $providerHeaders = [],
        bool $stream = false,
    ): PendingRequest {
        $http = Http::timeout($request->timeout);

        if ($stream) {
            $http = $http->withOptions(['stream' => true]);
        }

        $headers = array_merge($providerHeaders, $request->providerHeaders);

        if ($headers !== []) {
            $http = $http->withHeaders($headers);
        }

        if ($request->apiType === AiApiType::AnthropicMessages && $request->apiKey !== '') {
            $http = $http->withHeaders(['x-api-key' => $request->apiKey]);
        } elseif ($request->apiKey !== '') {
            $http = $http->withToken($request->apiKey);
        }

        return $http;
    }

    public static function parseFailedResponse(Response $response, int $latencyMs): array
    {
        $body = $response->json();
        $diagnostic = $body['error']['message']
            ?? $body['error']['code']
            ?? $response->body();

        $errorType = match (true) {
            $response->status() === 400 => AiErrorType::BadRequest,
            $response->status() === 401 => AiErrorType::AuthError,
            $response->status() === 404 => AiErrorType::NotFound,
            $response->status() === 429 => AiErrorType::RateLimit,
            $response->status() >= 500 => AiErrorType::ServerError,
            default => AiErrorType::UnexpectedError,
        };

        $hint = $errorType === AiErrorType::BadRequest
            ? (string) $diagnostic
            : null;

        // Some backends (notably ChatGPT/Codex) return quota/plan errors with terse messages.
        // When present, surface a more actionable hint without binding this logic to a provider name.
        if ($errorType === AiErrorType::RateLimit && is_array($body)) {
            $code = $body['error']['code'] ?? null;
            $message = $body['error']['message'] ?? null;

            if (is_string($code) && str_contains($code, 'usage_limit')) {
                $hint = 'Your Codex/ChatGPT plan may not allow this request (usage limit). Try again later or switch to a different provider/model.';
            } elseif (is_string($message) && (str_contains(strtolower($message), 'usage limit') || str_contains(strtolower($message), 'quota'))) {
                $hint = 'The provider rejected the request due to plan/quota limits. Try a smaller model, reduce output tokens, or switch providers.';
            }
        }

        return [
            'runtime_error' => AiRuntimeError::fromType(
                $errorType,
                "HTTP {$response->status()}: {$diagnostic}",
                $hint,
                httpStatus: $response->status(),
                latencyMs: $latencyMs,
            ),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function checkFailedResponse(Response $response, int $startTime): ?array
    {
        if (! $response->failed()) {
            return null;
        }

        $latencyMs = self::latencyMs($startTime);
        $parsed = self::parseFailedResponse($response, $latencyMs);

        return [
            'type' => 'error',
            'runtime_error' => $parsed['runtime_error'],
            'latency_ms' => $latencyMs,
        ];
    }

    public static function connectionError(ConnectionException $e, int $startTime): array
    {
        $latencyMs = self::latencyMs($startTime);
        $errorType = self::classifyConnectionException($e);

        return [
            'runtime_error' => AiRuntimeError::fromType(
                $errorType,
                $e->getMessage(),
                $errorType === AiErrorType::Timeout
                    ? 'Increase the provider timeout or check network connectivity.'
                    : null,
                latencyMs: $latencyMs,
            ),
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public static function connectionErrorStream(ConnectionException $e, int $startTime): Generator
    {
        $latencyMs = self::latencyMs($startTime);
        $errorType = self::classifyConnectionException($e);

        yield [
            'type' => 'error',
            'runtime_error' => AiRuntimeError::fromType($errorType, $e->getMessage(), latencyMs: $latencyMs),
            'latency_ms' => $latencyMs,
        ];
    }

    public static function invalidPayloadError(Response $response, int $latencyMs, string $model): array
    {
        $payloadType = self::classifyInvalidPayload($response);

        return [
            'runtime_error' => AiRuntimeError::fromType(
                $payloadType,
                "Model \"{$model}\" returned non-JSON payload (Content-Type: {$response->header('Content-Type')})",
                $payloadType === AiErrorType::HtmlResponse
                    ? 'Check that the provider base URL points to the API endpoint, not the provider website.'
                    : null,
                httpStatus: $response->status(),
                latencyMs: $latencyMs,
            ),
            'latency_ms' => $latencyMs,
        ];
    }

    public static function latencyMs(int|float $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1_000_000);
    }

    private static function classifyInvalidPayload(Response $response): AiErrorType
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        $body = ltrim($response->body());

        if (str_contains($contentType, 'text/html') || str_starts_with($body, '<!DOCTYPE html') || str_starts_with($body, '<html')) {
            return AiErrorType::HtmlResponse;
        }

        return AiErrorType::UnsupportedResponseShape;
    }

    private static function classifyConnectionException(ConnectionException $e): AiErrorType
    {
        $message = $e->getMessage();

        if (str_contains($message, 'timed out') || str_contains($message, 'cURL error 28')) {
            return AiErrorType::Timeout;
        }

        return AiErrorType::ConnectionError;
    }
}
