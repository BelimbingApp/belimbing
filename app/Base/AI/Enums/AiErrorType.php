<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Enums;

use App\Base\AI\DTO\AiRuntimeError;

/**
 * Classification of AI runtime errors.
 *
 * Stateless infrastructure shared by LlmClient (Base) and AgenticRuntime (Core).
 */
enum AiErrorType: string
{
    case Timeout = 'timeout';
    case ConnectionError = 'connection_error';
    case RateLimit = 'rate_limit';
    case AuthError = 'auth_error';
    case NotFound = 'not_found';
    case ServerError = 'server_error';
    case HtmlResponse = 'html_response';
    case UnsupportedResponseShape = 'unsupported_response_shape';
    case BadRequest = 'bad_request';
    case EmptyResponse = 'empty_response';
    case ConfigError = 'config_error';
    case UnexpectedError = 'unexpected_error';

    /**
     * Whether this error type is worth retrying automatically.
     */
    public function retryable(): bool
    {
        return match ($this) {
            self::Timeout,
            self::ConnectionError,
            self::RateLimit,
            self::ServerError,
            self::EmptyResponse => true,
            default => false,
        };
    }

    /**
     * Return a short, translatable label for end users.
     *
     * This is the first sentence only — the full user-facing message is
     * composed by {@see AiRuntimeError} which appends
     * the provider diagnostic when available.
     *
     * GUIDANCE: Keep messages concise, factual, and transparent.
     * No inaccurate messaging.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::Timeout => __('Request timed out.'),
            self::ConnectionError => __('Connection failed.'),
            self::RateLimit => __('Rate limit exceeded.'),
            self::ServerError => __('Server error.'),
            self::AuthError => __('Authentication failed.'),
            self::NotFound => __('Model or endpoint not found.'),
            self::BadRequest => __('Invalid request.'),
            self::HtmlResponse => __('HTML response received.'),
            self::UnsupportedResponseShape => __('Unexpected response format.'),
            self::EmptyResponse => __('Empty response.'),
            self::ConfigError => __('Configuration error.'),
            self::UnexpectedError => __('An unexpected error occurred.'),
        };
    }
}
