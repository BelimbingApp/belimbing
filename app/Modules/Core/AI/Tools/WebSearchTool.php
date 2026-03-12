<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Services\WebSearchService;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\HasToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use Illuminate\Support\Facades\Cache;

/**
 * Web search tool for Agents.
 *
 * Allows a agent to search the web for real-time information via configurable
 * search providers (Parallel, Brave Search). Results are cached to reduce
 * API calls for repeated queries.
 *
 * Gated by `ai.tool_web_search.execute` authz capability.
 */
class WebSearchTool extends AbstractTool
{
    use HasToolMetadata;

    private const TIMEOUT_SECONDS = 15;

    private const DEFAULT_COUNT = 5;

    private const MAX_COUNT = 10;

    private const DEFAULT_CACHE_TTL_MINUTES = 15;

    private const VALID_FRESHNESS = ['day', 'week', 'month'];

    private string $provider;

    private string $apiKey;

    private int $cacheTtlMinutes;

    private readonly WebSearchService $webSearchService;

    /**
     * @param  string  $provider  Search provider name ('parallel' or 'brave')
     * @param  string  $apiKey  API key for the configured provider
     * @param  int  $cacheTtlMinutes  Cache TTL in minutes for search results
     */
    public function __construct(
        string $provider,
        string $apiKey,
        int $cacheTtlMinutes = self::DEFAULT_CACHE_TTL_MINUTES,
        ?WebSearchService $webSearchService = null,
    ) {
        $this->provider = $provider;
        $this->apiKey = $apiKey;
        $this->cacheTtlMinutes = $cacheTtlMinutes;
        $this->webSearchService = $webSearchService ?? new WebSearchService;
    }

    /**
     * Create an instance if the active provider has an API key configured.
     *
     * Returns null when no API key is available, allowing the registry
     * to skip registration of this tool.
     */
    public static function createIfConfigured(?WebSearchService $webSearchService = null): ?self
    {
        $provider = config('ai.tools.web_search.provider', 'parallel');
        $apiKey = config('ai.tools.web_search.'.$provider.'.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }

        $cacheTtl = (int) config('ai.tools.web_search.cache_ttl_minutes', self::DEFAULT_CACHE_TTL_MINUTES);

        return new self($provider, $apiKey, $cacheTtl, $webSearchService);
    }

    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Search the web for current information. '
            .'Use this when the user asks about recent events, needs up-to-date data, '
            .'or when your training data may be outdated. '
            .'Returns a list of relevant web pages with titles, URLs, and snippets.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('query', 'The search query or objective text.')->required()
            ->integer(
                'count',
                'Number of results to return (1–'.self::MAX_COUNT.', default '.self::DEFAULT_COUNT.').',
                min: 1,
                max: self::MAX_COUNT,
            )
            ->string(
                'freshness',
                'Recency filter: "day", "week", or "month".',
                enum: self::VALID_FRESHNESS,
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::WEB;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::EXTERNAL_IO;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_web_search.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Web Search',
            'summary' => 'Search the public web and return summarized results.',
            'explanation' => 'Searches the web for current information using a configured provider (Parallel or Brave Search). '
                .'Results include titles, URLs, and snippets. Cached for 15 minutes to reduce API calls. '
                .'This tool cannot access private networks or internal resources.',
            'setup_requirements' => [
                'Search provider selected (Parallel or Brave)',
                'API key configured for the selected provider',
            ],
            'test_examples' => [
                [
                    'label' => 'Simple search',
                    'input' => ['query' => 'Laravel 12 new features'],
                ],
                [
                    'label' => 'Recent news',
                    'input' => ['query' => 'latest PHP releases', 'freshness' => 'week'],
                ],
            ],
            'health_checks' => [
                'Provider API key present',
                'Provider endpoint reachable',
            ],
            'limits' => [
                'Maximum 10 results per query',
                '15-second API timeout',
                '15-minute result cache TTL',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $query = $this->requireString($arguments, 'query', 'search query');
        $count = $this->optionalInt($arguments, 'count', self::DEFAULT_COUNT, min: 1, max: self::MAX_COUNT);

        $freshness = $this->optionalString($arguments, 'freshness');
        if ($freshness !== null && ! in_array($freshness, self::VALID_FRESHNESS, true)) {
            $freshness = null;
        }

        // Cache key only — not used for crypto; md5 is sufficient and more efficient.
        $cacheKey = 'lara_tool:web_search:'.md5($query.$count.$freshness);

        /** @var string $cached */
        $cached = Cache::remember($cacheKey, $this->cacheTtlMinutes * 60, function () use ($query, $count, $freshness): string {
            return $this->performSearch($query, $count, $freshness);
        });

        return ToolResult::success($cached);
    }

    /**
     * Dispatch to the appropriate provider and return formatted results.
     *
     * Returns a plain string for cache storage. The calling method wraps
     * the cached string in ToolResult::success().
     */
    private function performSearch(string $query, int $count, ?string $freshness): string
    {
        $result = $this->webSearchService->search(
            provider: $this->provider,
            apiKey: $this->apiKey,
            query: $query,
            count: $count,
            freshness: $freshness,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        if (isset($result['error'])) {
            return 'Search failed: '.$result['error'];
        }

        $results = $result['results'] ?? [];

        if ($results === []) {
            return 'No results found for: '.$query;
        }

        return $this->formatResults($results);
    }

    /**
     * Format search results as a numbered list.
     *
     * @param  list<array{title: string, url: string, snippet: string}>  $results
     */
    private function formatResults(array $results): string
    {
        $lines = [];

        foreach ($results as $index => $result) {
            $number = $index + 1;
            $title = $result['title'] ?? 'Untitled';
            $url = $result['url'] ?? '';
            $snippet = $result['snippet'] ?? '';

            $lines[] = $number.'. '.$title;
            $lines[] = '   '.$url;
            $lines[] = '   '.$snippet;

            if ($index < count($results) - 1) {
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}
