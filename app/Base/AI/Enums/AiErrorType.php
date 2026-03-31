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
    case EmptyResponse = 'empty_response';
    case ConfigError = 'config_error';
    case MaxIterations = 'max_iterations';
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
     * Return a safe, actionable, translatable message for end users.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::Timeout,
            self::ConnectionError => __('The AI provider did not respond in time. Please try again.'),
            self::RateLimit => __('The AI provider is busy right now. Please try again in a moment.'),
            self::ServerError => __('The AI provider encountered a server error. Please try again later.'),
            self::AuthError => __('AI provider authentication failed. Please ask an administrator to check the provider credentials.'),
            self::NotFound => __('The configured AI model or endpoint could not be found. Please ask an administrator to verify the setup.'),
            self::HtmlResponse,
            self::UnsupportedResponseShape => __('The AI provider returned an invalid response. Please ask an administrator to check the provider endpoint.'),
            self::EmptyResponse => __('The AI model returned an empty reply. Please try again or switch to a different model.'),
            self::ConfigError => __('AI chat is not fully configured. Please ask an administrator to set up an AI provider.'),
            self::MaxIterations => __('Maximum tool-calling iterations reached. Please try a simpler request.'),
            self::UnexpectedError => __('An unexpected error occurred. Please try again.'),
        };
    }
}
