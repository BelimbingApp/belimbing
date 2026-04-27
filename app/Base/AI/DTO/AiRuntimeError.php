<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Enums\AiErrorType;

/**
 * Normalized value object for all AI runtime errors.
 */
final readonly class AiRuntimeError
{
    public string $userMessage;

    public bool $retryable;

    public function __construct(
        public AiErrorType $errorType,
        ?string $userMessage,
        public string $diagnostic,
        public ?string $hint = null,
        public ?int $httpStatus = null,
        public int $latencyMs = 0,
        ?bool $retryable = null,
    ) {
        $this->userMessage = $userMessage ?? self::composeUserMessage($errorType, $diagnostic);
        $this->retryable = $retryable ?? $errorType->retryable();
    }

    /**
     * Compose a user-facing message from the enum label and provider diagnostic.
     *
     * Appends the raw diagnostic so end users see the actual provider error
     * instead of a generic "ask your administrator" second sentence.
     */
    private static function composeUserMessage(AiErrorType $errorType, string $diagnostic): string
    {
        $label = $errorType->userMessage();

        if ($diagnostic === '') {
            return $label;
        }

        return $label.' '.$diagnostic;
    }

    /**
     * Create an error from a classified type with sensible defaults.
     *
     * @param  AiErrorType  $type  The classified error type
     * @param  string  $diagnostic  Raw detail for logs/admin only
     * @param  ?string  $hint  Optional actionable hint for admins
     * @param  ?int  $httpStatus  HTTP status code if applicable
     * @param  int  $latencyMs  Response latency in milliseconds
     */
    public static function fromType(
        AiErrorType $type,
        string $diagnostic,
        ?string $hint = null,
        ?int $httpStatus = null,
        int $latencyMs = 0,
    ): self {
        return new self(
            errorType: $type,
            userMessage: null,
            diagnostic: $diagnostic,
            hint: $hint,
            httpStatus: $httpStatus,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Shorthand factory for unexpected errors.
     *
     * @param  string  $diagnostic  Raw detail for logs/admin only
     * @param  int  $latencyMs  Response latency in milliseconds
     */
    public static function unexpected(string $diagnostic, int $latencyMs = 0): self
    {
        return self::fromType(
            type: AiErrorType::UnexpectedError,
            diagnostic: $diagnostic,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Return an array safe for structured logging (no secrets).
     *
     * @return array{error_type: string, user_message: string, diagnostic: string, hint: ?string, http_status: ?int, latency_ms: int, retryable: bool}
     */
    public function toLogContext(): array
    {
        return [
            'error_type' => $this->errorType->value,
            'user_message' => $this->userMessage,
            'diagnostic' => $this->diagnostic,
            'hint' => $this->hint,
            'http_status' => $this->httpStatus,
            'latency_ms' => $this->latencyMs,
            'retryable' => $this->retryable,
        ];
    }
}
