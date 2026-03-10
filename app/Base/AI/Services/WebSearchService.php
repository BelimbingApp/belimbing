<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Stateless web search engine for supported providers.
 */
class WebSearchService
{
    private const PARALLEL_ENDPOINT = 'https://api.parallel.ai/v1beta/search';

    private const BRAVE_ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    /**
     * Search the web via the configured provider.
     *
     * @param  string  $provider  Provider key: parallel|brave
     * @param  string  $apiKey  Provider API key
     * @param  string  $query  Search query text
     * @param  int  $count  Number of results to return
     * @param  string|null  $freshness  Optional recency filter
     * @param  int  $timeoutSeconds  HTTP timeout in seconds
     * @return array{error?: string, results?: list<array{title: string, url: string, snippet: string}>}
     */
    public function search(
        string $provider,
        string $apiKey,
        string $query,
        int $count,
        ?string $freshness,
        int $timeoutSeconds,
    ): array {
        return match ($provider) {
            'brave' => $this->searchBrave($apiKey, $query, $count, $freshness, $timeoutSeconds),
            default => $this->searchParallel($apiKey, $query, $count, $timeoutSeconds),
        };
    }

    /**
     * @return array{error?: string, results?: list<array{title: string, url: string, snippet: string}>}
     */
    private function searchParallel(string $apiKey, string $query, int $count, int $timeoutSeconds): array
    {
        $response = $this->sendParallelRequest($apiKey, $query, $count, $timeoutSeconds);

        if (isset($response['error'])) {
            return $response;
        }

        $results = $response['response']->json('results', []);

        if (! is_array($results)) {
            return ['results' => []];
        }

        return ['results' => $this->normalizeResults($results, 'snippet')];
    }

    /**
     * @return array{error?: string, results?: list<array{title: string, url: string, snippet: string}>}
     */
    private function searchBrave(
        string $apiKey,
        string $query,
        int $count,
        ?string $freshness,
        int $timeoutSeconds,
    ): array {
        $params = [
            'q' => $query,
            'count' => $count,
        ];

        if ($freshness !== null) {
            $params['freshness'] = $freshness;
        }

        $response = $this->sendBraveRequest($apiKey, $params, $timeoutSeconds);

        if (isset($response['error'])) {
            return $response;
        }

        $results = $response['response']->json('web.results', []);

        if (! is_array($results)) {
            return ['results' => []];
        }

        return ['results' => $this->normalizeResults($results, 'description')];
    }

    /**
     * @return array{response?: \Illuminate\Http\Client\Response, error?: string}
     */
    private function sendParallelRequest(string $apiKey, string $query, int $count, int $timeoutSeconds): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
            ])
                ->timeout($timeoutSeconds)
                ->post(self::PARALLEL_ENDPOINT, [
                    'query' => $query,
                    'max_results' => $count,
                ]);
        } catch (ConnectionException $e) {
            return ['error' => $e->getMessage()];
        }

        return $response->failed()
            ? ['error' => 'HTTP '.$response->status()]
            : ['response' => $response];
    }

    /**
     * @param  array{q: string, count: int, freshness?: string}  $params
     * @return array{response?: \Illuminate\Http\Client\Response, error?: string}
     */
    private function sendBraveRequest(string $apiKey, array $params, int $timeoutSeconds): array
    {
        try {
            $response = Http::withHeaders([
                'X-Subscription-Token' => $apiKey,
            ])
                ->timeout($timeoutSeconds)
                ->get(self::BRAVE_ENDPOINT, $params);
        } catch (ConnectionException $e) {
            return ['error' => $e->getMessage()];
        }

        return $response->failed()
            ? ['error' => 'HTTP '.$response->status()]
            : ['response' => $response];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private function normalizeResults(array $results, string $snippetKey): array
    {
        return array_values(array_map(function (array $result) use ($snippetKey): array {
            $title = $result['title'] ?? 'Untitled';
            $url = $result['url'] ?? '';
            $snippet = $result[$snippetKey] ?? $result['description'] ?? $result['snippet'] ?? '';

            return [
                'title' => is_string($title) ? $title : 'Untitled',
                'url' => is_string($url) ? $url : '',
                'snippet' => is_string($snippet) ? $snippet : '',
            ];
        }, $results));
    }
}
