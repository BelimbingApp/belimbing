<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Values;

/**
 * Pure helper for durable OAuth provider auth state stored in connection_config.
 *
 * This models only long-lived provider state. Pending handshake secrets
 * (state, PKCE verifier, device codes) must stay in ephemeral cache.
 */
final class ProviderOAuthState
{
    public const CONNECTION_CONFIG_KEY = 'auth';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(?string $mode = null): array
    {
        return [
            'status' => 'disconnected',
            'mode' => $mode,
            'started_at' => null,
            'completed_at' => null,
            'last_refresh_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'plan_type' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalize(mixed $state, ?string $mode = null): array
    {
        $normalized = is_array($state) ? $state : [];

        return array_replace(self::defaults($mode), $normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public static function pending(mixed $state, string $mode): array
    {
        $auth = self::normalize($state, $mode);
        $auth['status'] = 'pending';
        $auth['mode'] = $mode;
        $auth['started_at'] = now()->toIso8601String();
        $auth['completed_at'] = null;
        $auth['plan_type'] = null;
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        return $auth;
    }

    /**
     * @return array<string, mixed>
     */
    public static function connected(mixed $state, ?string $mode = null): array
    {
        $auth = self::normalize($state, $mode);
        $auth['status'] = 'connected';
        $auth['completed_at'] = now()->toIso8601String();
        $auth['last_refresh_at'] ??= null;
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        return $auth;
    }

    /**
     * @return array<string, mixed>
     */
    public static function refreshed(mixed $state, ?string $mode = null): array
    {
        $auth = self::normalize($state, $mode);
        $auth['last_refresh_at'] = now()->toIso8601String();
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        return $auth;
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(mixed $state, string $code, string $message, ?string $mode = null): array
    {
        $auth = self::normalize($state, $mode);
        $auth['status'] = 'error';
        $auth['last_error_code'] = $code;
        $auth['last_error_message'] = $message;

        return $auth;
    }

    /**
     * @return array<string, mixed>
     */
    public static function expired(mixed $state, string $code, string $message, ?string $mode = null): array
    {
        $auth = self::normalize($state, $mode);
        $auth['status'] = 'expired';
        $auth['last_error_code'] = $code;
        $auth['last_error_message'] = $message;

        return $auth;
    }

    /**
     * @return array<string, mixed>
     */
    public static function disconnected(mixed $state, ?string $mode = null): array
    {
        $auth = self::normalize($state, $mode);
        $auth['status'] = 'disconnected';
        $auth['started_at'] = null;
        $auth['completed_at'] = null;
        $auth['last_refresh_at'] = null;
        $auth['plan_type'] = null;
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        return $auth;
    }

    /**
     * @return array<string, mixed>
     */
    public static function clearDiagnosticError(mixed $state, ?string $mode = null): array
    {
        $auth = self::normalize($state, $mode);
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        return $auth;
    }

    /**
     * @return array<string, mixed>
     */
    public static function diagnosticFailure(mixed $state, string $code, string $message, ?string $mode = null): array
    {
        $auth = self::normalize($state, $mode);
        $auth['last_error_code'] = $code;
        $auth['last_error_message'] = $message;

        if (! isset($auth['status']) || $auth['status'] === null || $auth['status'] === '') {
            $auth['status'] = 'error';
        }

        return $auth;
    }
}
