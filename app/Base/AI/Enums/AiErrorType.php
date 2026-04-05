<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Enums;

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
     * composed by {@see \App\Base\AI\DTO\AiRuntimeError} which appends
     * the provider diagnostic when available.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::Timeout,
            self::ConnectionError => __('The AI provider did not respond in time.'),
            self::RateLimit => __('The AI provider is busy right now.'),
            self::ServerError => __('The AI provider encountered a server error.'),
            self::AuthError => __('AI provider authentication failed.'),
            self::NotFound => __('The configured AI model or endpoint could not be found.'),
            self::BadRequest => __('The AI provider rejected the request.'),
            self::HtmlResponse,
            self::UnsupportedResponseShape => __('The AI provider returned an invalid response.'),
            self::EmptyResponse => __('The AI model returned an empty reply.'),
            self::ConfigError => __('AI chat is not fully configured.'),
            self::UnexpectedError => __('An unexpected error occurred.'),
        };
    }
}
