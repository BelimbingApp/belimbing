<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use App\Base\AI\Exceptions\ProviderDiscoveryException;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Integration\Services\IntegrationResponse;

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
     * @param  array<string, string>  $additionalHeaders  Merged before Authorization (e.g. ChatGPT `ChatGPT-Account-ID`)
     * @param  array<string, scalar>  $query  Query string params (e.g. Codex requires `client_version`)
     * @return list<array{model_id: string, display_name: string}>
     *
     * @throws ProviderDiscoveryException
     */
    public function discoverModels(
        string $baseUrl,
        string $apiKey = '',
        array $additionalHeaders = [],
        array $query = [],
    ): array {
        $headers = [];

        foreach ($additionalHeaders as $name => $value) {
            $n = is_string($name) ? $name : '';

            if ($n === '' || ! is_string($value) || $value === '') {
                continue;
            }

            $headers[$n] = $value;
        }

        if ($apiKey !== '' && $apiKey !== 'not-required') {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        $normalizedQuery = [];
        foreach ($query as $key => $value) {
            if (is_string($key) && $key !== '') {
                if (is_scalar($value) || $value === null) {
                    $normalizedQuery[$key] = $value;
                }
            }
        }

        $response = $this->integrationGateway()->send(new IntegrationRequest(
            system: 'ai_provider',
            operation: 'ai.provider.models.discover',
            method: 'GET',
            endpoint: rtrim($baseUrl, '/').'/models',
            protocolOperation: 'GET /models',
            provider: $this->providerNameFromBaseUrl($baseUrl),
            headers: $headers,
            query: $normalizedQuery,
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

        return $this->modelsFromDiscoveryResponse($response);
    }

    /**
     * Normalize OpenAI-compatible `{ "data": [{ "id": ... }] }` and Codex `{ "models": [{ "slug": ... }] }` payloads.
     *
     * @return list<array{model_id: string, display_name: string}>
     */
    private function modelsFromDiscoveryResponse(IntegrationResponse $response): array
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return [];
        }

        // ChatGPT Codex can return both shapes: a small OpenAI-style `data` array and a full
        // `models` list (codex_protocol::ModelsResponse). Prefer non-empty `models` so we never
        // drop the real catalog when both keys are present.
        $fromModels = isset($decoded['models']) && is_array($decoded['models']) ? $decoded['models'] : [];
        $fromData = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
        $candidates = $fromModels !== [] ? $fromModels : $fromData;

        $models = [];

        foreach ($candidates as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $entry['id'] ?? $entry['slug'] ?? null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $display = $entry['display_name'] ?? null;
            $displayName = is_string($display) && $display !== ''
                ? $display
                : $this->humanizeModelId($id);

            $models[] = [
                'model_id' => $id,
                'display_name' => $displayName,
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
