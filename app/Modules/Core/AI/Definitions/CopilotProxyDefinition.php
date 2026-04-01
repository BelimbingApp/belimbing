<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Definitions;

use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Definition for VS Code Copilot Proxy (local proxy for GitHub Copilot models).
 *
 * Extends GenericLocalDefinition behavior with a connectivity probe at runtime.
 */
final readonly class CopilotProxyDefinition extends GenericLocalDefinition
{
    public function __construct()
    {
        parent::__construct('copilot-proxy', 'http://localhost:1337/v1');
    }

    /**
     * Verifies the local proxy is reachable before returning runtime config.
     *
     * @throws RuntimeException When the proxy is unreachable
     */
    public function resolveRuntime(AiProvider $provider): ResolvedProviderConfig
    {
        $baseUrl = $provider->base_url;

        try {
            $response = Http::timeout(5)
                ->get(rtrim($baseUrl, '/').'/models');

            if ($response->failed()) {
                throw new RuntimeException(
                    "Copilot Proxy at {$baseUrl} returned HTTP {$response->status()} — ensure the proxy extension is running in VS Code.",
                );
            }
        } catch (ConnectionException) {
            throw new RuntimeException(
                "Could not connect to Copilot Proxy at {$baseUrl} — is the VS Code extension running?",
            );
        }

        return new ResolvedProviderConfig(
            baseUrl: $baseUrl,
            apiKey: $provider->credentials['api_key'] ?? null,
        );
    }
}
