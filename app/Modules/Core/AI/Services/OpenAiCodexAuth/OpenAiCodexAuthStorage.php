<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\OpenAiCodexAuth;

use App\Modules\Core\AI\Definitions\OpenAiCodexDefinition;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ProviderOAuthState;

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
        $this->writeAuthState($provider, ProviderOAuthState::pending(
            $this->readAuthState($provider),
            $mode,
        ));
    }

    public function markConnected(AiProvider $provider): void
    {
        $this->writeAuthState($provider, ProviderOAuthState::connected(
            $this->readAuthState($provider),
            mode: 'browser_pkce',
        ));
    }

    public function markRefreshed(AiProvider $provider): void
    {
        $this->writeAuthState($provider, ProviderOAuthState::refreshed(
            $this->readAuthState($provider),
            mode: 'browser_pkce',
        ));
    }

    public function markError(AiProvider $provider, string $code, string $message): void
    {
        $this->writeAuthState($provider, ProviderOAuthState::error(
            $this->readAuthState($provider),
            $code,
            $message,
            mode: 'browser_pkce',
        ));
    }

    public function markExpired(AiProvider $provider, string $code, string $message): void
    {
        $this->writeAuthState($provider, ProviderOAuthState::expired(
            $this->readAuthState($provider),
            $code,
            $message,
            mode: 'browser_pkce',
        ));
    }

    public function markDisconnected(AiProvider $provider): void
    {
        $this->writeAuthState($provider, ProviderOAuthState::disconnected(
            $this->readAuthState($provider),
            mode: 'browser_pkce',
        ));
    }

    public function clearDiagnosticError(AiProvider $provider): void
    {
        $this->writeAuthState($provider, ProviderOAuthState::clearDiagnosticError(
            $this->readAuthState($provider),
            mode: 'browser_pkce',
        ));
    }

    public function recordDiagnosticFailure(AiProvider $provider, string $code, string $message): void
    {
        $this->writeAuthState($provider, ProviderOAuthState::diagnosticFailure(
            $this->readAuthState($provider),
            $code,
            $message,
            mode: 'browser_pkce',
        ));
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
        return ProviderOAuthState::normalize(
            $provider->connection_config[OpenAiCodexDefinition::AUTH_STATE_KEY] ?? null,
            mode: 'browser_pkce',
        );
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
