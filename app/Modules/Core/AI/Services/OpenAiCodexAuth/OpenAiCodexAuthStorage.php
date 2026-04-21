<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\OpenAiCodexAuth;

use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Models\AiProvider;

/**
 * Persists durable provider auth state and credentials for OpenAI Codex.
 *
 * Pending OAuth handshake secrets (PKCE verifier, state) must remain in cache.
 */
final class OpenAiCodexAuthStorage
{
    /**
     * Mark the provider as pending with a durable state update.
     */
    public function markPending(AiProvider $provider, string $mode = 'browser_pkce'): void
    {
        $auth = $this->readAuthState($provider);
        $auth['status'] = 'pending';
        $auth['mode'] = $mode;
        $auth['started_at'] = now()->toIso8601String();
        $auth['completed_at'] = null;
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        $this->writeAuthState($provider, $auth);
    }

    public function markConnected(AiProvider $provider): void
    {
        $auth = $this->readAuthState($provider);
        $auth['status'] = 'connected';
        $auth['completed_at'] = now()->toIso8601String();
        $auth['last_refresh_at'] ??= null;
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        $this->writeAuthState($provider, $auth);
    }

    public function markRefreshed(AiProvider $provider): void
    {
        $auth = $this->readAuthState($provider);
        $auth['last_refresh_at'] = now()->toIso8601String();
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        $this->writeAuthState($provider, $auth);
    }

    public function markError(AiProvider $provider, string $code, string $message): void
    {
        $auth = $this->readAuthState($provider);
        $auth['status'] = 'error';
        $auth['last_error_code'] = $code;
        $auth['last_error_message'] = $message;

        $this->writeAuthState($provider, $auth);
    }

    public function markDisconnected(AiProvider $provider): void
    {
        $auth = $this->readAuthState($provider);
        $auth['status'] = 'disconnected';
        $auth['started_at'] = null;
        $auth['completed_at'] = null;
        $auth['last_refresh_at'] = null;
        $auth['last_error_code'] = null;
        $auth['last_error_message'] = null;

        $this->writeAuthState($provider, $auth);
    }

    /**
     * Persist token credentials into the encrypted credentials bag.
     *
     * @param  array{access_token: string, refresh_token: string, expires_at: string, account_id: string}  $credentials
     */
    public function persistCredentials(AiProvider $provider, array $credentials): void
    {
        $provider->update([
            'credentials' => array_merge($provider->credentials ?? [], [
                OpenAiCodexDefinition::CRED_ACCESS_TOKEN => $credentials['access_token'],
                OpenAiCodexDefinition::CRED_REFRESH_TOKEN => $credentials['refresh_token'],
                OpenAiCodexDefinition::CRED_EXPIRES_AT => $credentials['expires_at'],
                OpenAiCodexDefinition::CRED_ACCOUNT_ID => $credentials['account_id'],
            ]),
        ]);
    }

    public function clearCredentials(AiProvider $provider): void
    {
        $provider->update(['credentials' => []]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readAuthState(AiProvider $provider): array
    {
        $auth = $provider->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? null;

        return is_array($auth) ? $auth : [];
    }

    /**
     * @param  array<string, mixed>  $auth
     */
    private function writeAuthState(AiProvider $provider, array $auth): void
    {
        $provider->update([
            'connection_config' => array_merge($provider->connection_config ?? [], [
                OpenAiCodexDefinition::AUTH_STATE_KEY => $auth,
            ]),
        ]);
    }
}

