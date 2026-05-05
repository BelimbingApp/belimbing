<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\Exceptions\ProviderDiscoveryException;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;

/**
 * Stateless model discovery via the OpenAI-compatible GET /models endpoint.
 *
 * Takes base URL and API key as explicit parameters — no knowledge of
 * provider records or database. Returns a raw list of discovered model IDs.
 */
class ProviderDiscoveryService
{
    public function __construct(
        private readonly ?IntegrationGateway $gateway = null,
    ) {}

    /**
     * Discover available models from an OpenAI-compatible /models endpoint.
     *
     * @param  string  $baseUrl  Provider base URL (e.g., 'https://api.openai.com/v1')
     * @param  string  $apiKey  Bearer token / API key (empty string if not required)
     * @return list<array{model_id: string, display_name: string}>
     *
     * @throws ProviderDiscoveryException
     */
    public function discoverModels(string $baseUrl, string $apiKey = ''): array
    {
        $headers = [];

        if ($apiKey !== '' && $apiKey !== 'not-required') {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        $response = $this->integrationGateway()->send(new IntegrationRequest(
            system: 'ai_provider',
            operation: 'ai.provider.models.discover',
            method: 'GET',
            endpoint: rtrim($baseUrl, '/').'/models',
            protocolOperation: 'GET /models',
            provider: $this->providerNameFromBaseUrl($baseUrl),
            headers: $headers,
            timeoutSeconds: 15,
            metadata: [
                'base_url' => rtrim($baseUrl, '/'),
            ],
        ));

        if ($response->failed()) {
            throw ProviderDiscoveryException::httpFailure(
                (int) ($response->status ?? 0),
                $response->exchange?->id,
            );
        }

        $data = $response->json('data', []);

        if (! is_array($data)) {
            return [];
        }

        $models = [];

        foreach ($data as $entry) {
            $id = $entry['id'] ?? null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $models[] = [
                'model_id' => $id,
                'display_name' => $this->humanizeModelId($id),
            ];
        }

        usort($models, fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));

        return $models;
    }

    private function integrationGateway(): IntegrationGateway
    {
        return $this->gateway ?? app(IntegrationGateway::class);
    }

    private function providerNameFromBaseUrl(string $baseUrl): ?string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Convert a model ID like "gpt-5-mini" into "GPT-5 Mini".
     */
    private function humanizeModelId(string $id): string
    {
        $name = str_replace(['-', '_'], ' ', $id);

        $name = (string) preg_replace_callback(
            '/\b(gpt|o\d+|claude|gemini|grok)\b/i',
            fn (array $m): string => ucfirst($m[1]),
            $name,
        );

        return ucwords($name);
    }
}
