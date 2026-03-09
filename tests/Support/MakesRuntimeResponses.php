<?php

namespace Tests\Support;

use App\Modules\Core\AI\DTO\Message;
use DateTimeImmutable;

trait MakesRuntimeResponses
{
    protected function makeConfig(
        string $provider,
        string $model,
        string $apiKey = 'sk-test',
        string $baseUrl = 'https://api.example.com/v1'
    ): array {
        return [
            'api_key' => $apiKey,
            'base_url' => $baseUrl,
            'model' => $model,
            'max_tokens' => 2048,
            'temperature' => 0.7,
            'timeout' => 60,
            'provider_name' => $provider,
        ];
    }

    protected function makeSuccessResponse(string $content, int $latencyMs = 200): array
    {
        return [
            'content' => $content,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'latency_ms' => $latencyMs,
        ];
    }

    protected function makeErrorResponse(string $error, string $errorType, int $latencyMs): array
    {
        return [
            'error' => $error,
            'error_type' => $errorType,
            'latency_ms' => $latencyMs,
        ];
    }

    protected function makeMessage(string $role, string $content): Message
    {
        return new Message(
            role: $role,
            content: $content,
            timestamp: new DateTimeImmutable,
        );
    }

    protected function assertFallbackAttempt(
        array $attempt,
        string $provider,
        string $model,
        string $errorFragment,
        string $errorType,
        int $latencyMs,
    ): void {
        expect($attempt['provider'])->toBe($provider)
            ->and($attempt['model'])->toBe($model)
            ->and($attempt['error'])->toContain($errorFragment)
            ->and($attempt['error_type'])->toBe($errorType)
            ->and($attempt['latency_ms'])->toBe($latencyMs);
    }
}
