<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Base\AI\DTO\AiRuntimeError;

/**
 * Value object representing the result of a provider connectivity test.
 */
final readonly class ProviderTestResult
{
    /**
     * @param  bool  $connected  Whether the test succeeded
     * @param  string  $providerName  Provider name
     * @param  string  $model  Model identifier
     * @param  ?int  $latencyMs  Response latency (null on config/credential failures that don't reach the API)
     * @param  ?AiRuntimeError  $error  Structured error (null on success)
     */
    public function __construct(
        public bool $connected,
        public string $providerName,
        public string $model,
        public ?int $latencyMs,
        public ?AiRuntimeError $error,
    ) {}

    /**
     * Create a successful test result.
     *
     * @param  string  $providerName  Provider name
     * @param  string  $model  Model identifier
     * @param  int  $latencyMs  Response latency in milliseconds
     */
    public static function success(string $providerName, string $model, int $latencyMs): self
    {
        return new self(
            connected: true,
            providerName: $providerName,
            model: $model,
            latencyMs: $latencyMs,
            error: null,
        );
    }

    /**
     * Create a failed test result.
     *
     * @param  string  $providerName  Provider name
     * @param  string  $model  Model identifier
     * @param  AiRuntimeError  $error  Structured error describing the failure
     */
    public static function failure(string $providerName, string $model, AiRuntimeError $error): self
    {
        return new self(
            connected: false,
            providerName: $providerName,
            model: $model,
            latencyMs: null,
            error: $error,
        );
    }

    /**
     * Serialize to an array suitable for Livewire public properties.
     *
     * @return array{connected: bool, provider_name: string, model: string, latency_ms: ?int, error_type: ?string, user_message: ?string, hint: ?string}
     */
    public function toArray(): array
    {
        return [
            'connected' => $this->connected,
            'provider_name' => $this->providerName,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            'error_type' => $this->error?->errorType->value,
            'user_message' => $this->error?->userMessage,
            'hint' => $this->error?->hint,
        ];
    }
}
