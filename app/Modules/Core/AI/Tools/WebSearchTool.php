<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Services\WebSearchService;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * Web search tool for Agents.
 *
 * Allows an agent to search the web for real-time information via configurable
 * search providers (Parallel, Brave Search). Multiple providers can be configured
 * with priority ordering — on failure, the tool falls back to the next provider.
 * Results are cached to reduce API calls for repeated queries.
 *
 * Gated by `ai.tool_web_search.execute` authz capability.
 */
class WebSearchTool extends AbstractTool
{
    use ProvidesToolMetadata;

    private const TIMEOUT_SECONDS = 15;

    private const DEFAULT_COUNT = 5;

    private const MAX_COUNT = 10;

    private const DEFAULT_CACHE_TTL_MINUTES = 15;

    private const VALID_FRESHNESS = ['day', 'week', 'month'];

    /** @var array<string, string> Available search provider labels keyed by machine name */
    public const PROVIDERS = [
        'parallel' => 'Parallel',
        'brave' => 'Brave Search',
    ];

    /**
     * Providers injected via constructor (for tests); empty means resolve at runtime.
     *
     * @var list<array{name: string, api_key: string, enabled: bool}>
     */
    private array $directProviders;

    private int $cacheTtlMinutes;

    private readonly WebSearchService $webSearchService;

    /**
     * @param  string  $provider  Search provider name for direct instantiation (tests)
     * @param  string  $apiKey  API key for direct instantiation (tests)
     * @param  int  $cacheTtlMinutes  Cache TTL in minutes (0 = resolve from settings at runtime)
     */
    public function __construct(
        string $provider = '',
        string $apiKey = '',
        int $cacheTtlMinutes = self::DEFAULT_CACHE_TTL_MINUTES,
        ?WebSearchService $webSearchService = null,
    ) {
        $this->directProviders = ($provider !== '' && $apiKey !== '')
            ? [['name' => $provider, 'api_key' => $apiKey, 'enabled' => true]]
            : [];
        $this->cacheTtlMinutes = $cacheTtlMinutes;
        $this->webSearchService = $webSearchService ?? new WebSearchService;
    }

    /**
     * Create an instance if at least one provider has an API key configured.
     *
     * Checks the SettingsService providers array first, then falls back to
     * the legacy single-provider config keys. Returns null when no provider
     * is available, allowing the registry to skip registration.
     */
    public static function createIfConfigured(?WebSearchService $webSearchService = null): ?self
    {
        try {
            $settings = app(SettingsService::class);
            $providers = $settings->get('ai.tools.web_search.providers');

            if (is_array($providers)) {
                $hasConfigured = collect($providers)->contains(
                    fn ($p) => ($p['enabled'] ?? false) && ! empty($p['api_key'] ?? '')
                );

                if ($hasConfigured) {
                    return new self(webSearchService: $webSearchService);
                }
            }
        } catch (\Throwable) {
            // Settings table may not exist yet (e.g. during initial setup or unit tests).
            // Fall through to legacy config check.
        }

        // Fallback to legacy single-provider config (env-based)
        $provider = config('ai.tools.web_search.provider', 'parallel');
        $apiKey = config('ai.tools.web_search.'.$provider.'.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }

        return new self(webSearchService: $webSearchService);
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
            'explanation' => 'Searches the web for current information using configured providers (Parallel, Brave Search). '
                .'Multiple providers can be configured with priority — the tool tries each in order and falls back '
                .'on failure. Results include titles, URLs, and snippets. Cached for 15 minutes to reduce API calls. '
                .'This tool cannot access private networks or internal resources.',
            'setup_requirements' => [
                'At least one search provider configured with an API key',
            ],
            'test_examples' => [
                [
                    'label' => 'Simple search',
                    'input' => ['query' => 'latest Laravel release date and new features'],
                ],
                [
                    'label' => 'Get oil prices',
                    'input' => ['query' => 'crude oil prices today', 'freshness' => 'day'],
                ],
            ],
            'health_checks' => [
                'At least one provider API key present',
                'Provider endpoint reachable',
            ],
            'limits' => [
                'Maximum 10 results per query',
                '15-second API timeout per provider',
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

        $providers = $this->resolveProviders();

        if ($providers === []) {
            return ToolResult::error(
                'No search providers configured. Add at least one provider with an API key in the Configuration panel.',
                'unconfigured',
            );
        }

        $cacheTtl = $this->resolveCacheTtl();

        // Include provider names in cache key so config changes invalidate stale results.
        $providerNames = implode(',', array_column($providers, 'name'));
        $cacheKey = 'lara_tool:web_search:'.md5($providerNames.$query.$count.$freshness);

        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return ToolResult::success($cached);
        }

        $result = $this->performSearchWithFallback($providers, $query, $count, $freshness);

        // Only cache successful results — never cache errors.
        if (! str_starts_with($result, 'Search failed:')) {
            Cache::put($cacheKey, $result, $cacheTtl * 60);
        }

        return ToolResult::success($result);
    }

    /**
     * Try each provider in priority order, returning the first successful result.
     *
     * Falls back to the next provider on failure. Returns a formatted error
     * string if all providers fail.
     *
     * @param  list<array{name: string, api_key: string, enabled: bool}>  $providers
     */
    private function performSearchWithFallback(array $providers, string $query, int $count, ?string $freshness): string
    {
        $lastError = null;

        foreach ($providers as $provider) {
            $result = $this->webSearchService->search(
                provider: $provider['name'],
                apiKey: $provider['api_key'],
                query: $query,
                count: $count,
                freshness: $freshness,
                timeoutSeconds: self::TIMEOUT_SECONDS,
            );

            if (! isset($result['error'])) {
                $results = $result['results'] ?? [];

                if ($results === []) {
                    return 'No results found for: '.$query;
                }

                return $this->formatResults($results);
            }

            $lastError = $provider['name'].': '.$result['error'];
        }

        return 'Search failed: '.$lastError;
    }

    /**
     * Resolve the ordered list of enabled providers with API keys.
     *
     * Resolution order:
     *   1. Direct constructor injection (tests)
     *   2. SettingsService providers array (DB → config cascade)
     *   3. Legacy single-provider config (env-based)
     *
     * @return list<array{name: string, api_key: string, enabled: bool}>
     */
    private function resolveProviders(): array
    {
        if ($this->directProviders !== []) {
            return $this->directProviders;
        }

        $settings = app(SettingsService::class);
        $providers = $settings->get('ai.tools.web_search.providers');

        if (is_array($providers) && $providers !== []) {
            return array_values(array_filter(
                $providers,
                fn ($p) => ($p['enabled'] ?? false) && ! empty($p['api_key'] ?? ''),
            ));
        }

        // Fallback to legacy single-provider config
        $provider = $settings->get('ai.tools.web_search.provider', 'parallel');
        $apiKey = $settings->get("ai.tools.web_search.{$provider}.api_key");

        if (is_string($apiKey) && trim($apiKey) !== '') {
            return [['name' => $provider, 'api_key' => $apiKey, 'enabled' => true]];
        }

        return [];
    }

    /**
     * Resolve cache TTL from constructor or settings.
     */
    private function resolveCacheTtl(): int
    {
        if ($this->directProviders !== []) {
            return $this->cacheTtlMinutes;
        }

        $settings = app(SettingsService::class);

        return (int) $settings->get('ai.tools.web_search.cache_ttl_minutes', self::DEFAULT_CACHE_TTL_MINUTES);
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
