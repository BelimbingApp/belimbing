<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Contracts\ProvidesDisplaySummary;
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
 * Allows an agent to search the web for real-time information via a configured
 * search provider (Parallel, Brave Search). When multiple providers are
 * configured, the highest-priority enabled one with an API key is used; failures
 * surface honestly without silent fallback to a different provider.
 * Results are cached to reduce API calls for repeated queries.
 *
 * Gated by `admin.ai.tool.web-search.execute` authz capability.
 */
class WebSearchTool extends AbstractTool implements ProvidesDisplaySummary
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
     * Checks the canonical SettingsService provider records. Returns null
     * when no provider is available, allowing the registry to skip registration.
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
            // Settings table may not exist yet during initial setup.
        }

        return null;
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
        return 'admin.ai.tool.web-search.execute';
    }

    public function displaySummary(array $arguments): string
    {
        $query = is_string($arguments['query'] ?? null) ? trim($arguments['query']) : '';

        return $query !== '' ? __('Search the web for ":query"', ['query' => $query]) : __('Search the web');
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Web Search',
            'summary' => 'Search the public web and return summarized results.',
            'explanation' => 'Searches the web for current information using a configured provider (Parallel, Brave Search). '
                .'When multiple providers are configured, the highest-priority enabled one is used — failures surface '
                .'directly without silent fallback to a different provider. Results include titles, URLs, and snippets. '
                .'Cached for 15 minutes to reduce API calls. '
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

        $provider = $this->resolveProvider();

        if ($provider === null) {
            return ToolResult::error(
                'No search provider configured. Add at least one provider with an API key in the Configuration panel.',
                'unconfigured',
            );
        }

        $cacheTtl = $this->resolveCacheTtl();

        $cacheKey = 'lara_tool:web_search:'.md5($provider['name'].$query.$count.$freshness);

        $cached = Cache::get($cacheKey);

        if (is_string($cached)) {
            return ToolResult::success($cached);
        }

        $result = $this->performSearch($provider, $query, $count, $freshness);

        // Only cache successful results — never cache errors.
        if (! str_starts_with($result, 'Search failed:')) {
            Cache::put($cacheKey, $result, $cacheTtl * 60);
        }

        return ToolResult::success($result);
    }

    /**
     * Run the search against the resolved provider. Failures surface directly.
     *
     * @param  array{name: string, api_key: string, enabled: bool}  $provider
     */
    private function performSearch(array $provider, string $query, int $count, ?string $freshness): string
    {
        $result = $this->webSearchService->search(
            provider: $provider['name'],
            apiKey: $provider['api_key'],
            query: $query,
            count: $count,
            freshness: $freshness,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        if (isset($result['error'])) {
            return 'Search failed: '.$provider['name'].': '.$result['error'];
        }

        $results = $result['results'] ?? [];

        if ($results === []) {
            return 'No results found for: '.$query;
        }

        return $this->formatResults($results);
    }

    /**
     * Resolve the single search provider to use.
     *
     * Resolution order:
     *   1. Direct constructor injection (tests)
     *   2. First enabled SettingsService provider with an API key
     *   3. Legacy single-provider config (env-based)
     *
     * @return array{name: string, api_key: string, enabled: bool}|null
     */
    private function resolveProvider(): ?array
    {
        if ($this->directProviders !== []) {
            return $this->directProviders[0];
        }

        $settings = app(SettingsService::class);
        $providers = $settings->get('ai.tools.web_search.providers');

        if (is_array($providers) && $providers !== []) {
            foreach ($providers as $candidate) {
                if (($candidate['enabled'] ?? false) && ! empty($candidate['api_key'] ?? '')) {
                    return $candidate;
                }
            }
        }

        // Fallback to legacy single-provider config
        $provider = $settings->get('ai.tools.web_search.provider');
        $apiKey = $settings->get("ai.tools.web_search.{$provider}.api_key");

        if (is_string($apiKey) && trim($apiKey) !== '') {
            return ['name' => $provider, 'api_key' => $apiKey, 'enabled' => true];
        }

        return null;
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

        return (int) $settings->get('ai.tools.web_search.cache_ttl_minutes');
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
