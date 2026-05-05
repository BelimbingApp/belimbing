<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Tools\WebSearchTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

const SEARCH_QUERY = 'test query';
const SEARCH_EXAMPLE_URL = 'https://example.com';

beforeEach(function () {
    $this->tool = new WebSearchTool('parallel', 'test-key');
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'web_search',
            'ai.tool_web_search.execute',
            ['query'],
            ['query'],
        );
    });

    it('exposes available providers as a constant', function () {
        expect(WebSearchTool::PROVIDERS)
            ->toBeArray()
            ->toHaveKeys(['parallel', 'brave']);
    });
});

describe('factory method', function () {
    it('returns null when no API key configured', function () {
        config([
            'ai.tools.web_search.provider' => 'parallel',
            'ai.tools.web_search.parallel.api_key' => null,
        ]);

        expect(WebSearchTool::createIfConfigured())->toBeNull();
    });

    it('returns instance when API key configured via env/config', function () {
        config([
            'ai.tools.web_search.provider' => 'parallel',
            'ai.tools.web_search.parallel.api_key' => 'test-key',
        ]);

        expect(WebSearchTool::createIfConfigured())->toBeInstanceOf(WebSearchTool::class);
    });

    it('reads provider from config', function () {
        config([
            'ai.tools.web_search.provider' => 'brave',
            'ai.tools.web_search.brave.api_key' => 'test-key',
        ]);

        expect(WebSearchTool::createIfConfigured())->toBeInstanceOf(WebSearchTool::class);
    });

    it('returns instance when providers array is configured in settings', function () {
        $settings = app(SettingsService::class);
        $settings->set('ai.tools.web_search.providers', [
            ['name' => 'parallel', 'api_key' => 'test-key', 'enabled' => true],
        ], encrypted: true);

        config([
            'ai.tools.web_search.provider' => 'parallel',
            'ai.tools.web_search.parallel.api_key' => null,
        ]);

        expect(WebSearchTool::createIfConfigured())->toBeInstanceOf(WebSearchTool::class);
    });

    it('returns null when all providers are disabled', function () {
        $settings = app(SettingsService::class);
        $settings->set('ai.tools.web_search.providers', [
            ['name' => 'parallel', 'api_key' => 'test-key', 'enabled' => false],
        ], encrypted: true);

        config([
            'ai.tools.web_search.provider' => 'parallel',
            'ai.tools.web_search.parallel.api_key' => null,
        ]);

        expect(WebSearchTool::createIfConfigured())->toBeNull();
    });
});

describe('input validation', function () {
    it('rejects missing or empty query', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('query');
    });
});

describe('parallel provider', function () {
    it('sends request to parallel endpoint', function () {
        Http::fake([
            'api.parallel.ai/*' => Http::response([
                'results' => [
                    ['title' => 'Test', 'url' => SEARCH_EXAMPLE_URL, 'excerpts' => ['A test result']],
                ],
            ]),
        ]);

        $this->tool->execute(['query' => SEARCH_QUERY]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'parallel.ai');
        });
    });

    it('formats results as numbered list', function () {
        Http::fake([
            'api.parallel.ai/*' => Http::response([
                'results' => [
                    ['title' => 'First Result', 'url' => SEARCH_EXAMPLE_URL.'/1', 'excerpts' => ['First snippet']],
                    ['title' => 'Second Result', 'url' => SEARCH_EXAMPLE_URL.'/2', 'excerpts' => ['Second snippet']],
                ],
            ]),
        ]);

        $result = $this->tool->execute(['query' => SEARCH_QUERY]);

        expect((string) $result)->toContain('1. First Result')
            ->and((string) $result)->toContain('2. Second Result')
            ->and((string) $result)->toContain(SEARCH_EXAMPLE_URL.'/1')
            ->and((string) $result)->toContain('First snippet');
    });

    it('handles empty results', function () {
        Http::fake([
            'api.parallel.ai/*' => Http::response([
                'results' => [],
            ]),
        ]);

        $result = $this->tool->execute(['query' => 'obscure query']);

        expect((string) $result)->toContain('No results found');
    });

    it('handles API errors', function () {
        Http::fake([
            'api.parallel.ai/*' => Http::response('Internal Server Error', 500),
        ]);

        $result = $this->tool->execute(['query' => SEARCH_QUERY]);

        expect((string) $result)->toContain('Search failed');
    });
});

describe('brave provider', function () {
    beforeEach(function () {
        $this->tool = new WebSearchTool('brave', 'test-key');
    });

    it('sends request to brave endpoint', function () {
        Http::fake([
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        ['title' => 'Brave Result', 'url' => SEARCH_EXAMPLE_URL, 'description' => 'A brave result'],
                    ],
                ],
            ]),
        ]);

        $this->tool->execute(['query' => 'brave query']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.search.brave.com');
        });
    });

    it('passes freshness parameter', function () {
        Http::fake([
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        ['title' => 'Fresh Result', 'url' => SEARCH_EXAMPLE_URL, 'description' => 'Fresh'],
                    ],
                ],
            ]),
        ]);

        $this->tool->execute(['query' => 'recent news', 'freshness' => 'day']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'freshness=day');
        });
    });
});

describe('multi-provider fallback', function () {
    it('surfaces provider failure without silent fallback', function () {
        $tool = new WebSearchTool;

        $settings = app(SettingsService::class);
        $settings->set('ai.tools.web_search.providers', [
            ['name' => 'parallel', 'api_key' => 'bad-key', 'enabled' => true],
            ['name' => 'brave', 'api_key' => 'good-key', 'enabled' => true],
        ], encrypted: true);

        Http::fake([
            'api.parallel.ai/*' => Http::response('Unauthorized', 401),
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        ['title' => 'Brave Fallback', 'url' => SEARCH_EXAMPLE_URL, 'description' => 'Fallback result'],
                    ],
                ],
            ]),
        ]);

        $result = $tool->execute(['query' => 'fallback test']);

        expect((string) $result)->toContain('Search failed: parallel: HTTP 401: Unauthorized');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.search.brave.com'));
    });

    it('returns error when all providers fail', function () {
        $tool = new WebSearchTool;

        $settings = app(SettingsService::class);
        $settings->set('ai.tools.web_search.providers', [
            ['name' => 'parallel', 'api_key' => 'bad-key-1', 'enabled' => true],
            ['name' => 'brave', 'api_key' => 'bad-key-2', 'enabled' => true],
        ], encrypted: true);

        Http::fake([
            'api.parallel.ai/*' => Http::response('Unauthorized', 401),
            'api.search.brave.com/*' => Http::response('Forbidden', 403),
        ]);

        $result = $tool->execute(['query' => 'all fail test']);

        expect((string) $result)->toContain('Search failed');
    });

    it('skips disabled providers', function () {
        $tool = new WebSearchTool;

        $settings = app(SettingsService::class);
        $settings->set('ai.tools.web_search.providers', [
            ['name' => 'parallel', 'api_key' => 'key-1', 'enabled' => false],
            ['name' => 'brave', 'api_key' => 'key-2', 'enabled' => true],
        ], encrypted: true);

        Http::fake([
            'api.parallel.ai/*' => Http::response([
                'results' => [['title' => 'Should Not Appear', 'url' => SEARCH_EXAMPLE_URL, 'excerpts' => ['nope']]],
            ]),
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [['title' => 'Brave Only', 'url' => SEARCH_EXAMPLE_URL, 'description' => 'yes']],
                ],
            ]),
        ]);

        $result = $tool->execute(['query' => 'skip disabled']);

        expect((string) $result)->toContain('Brave Only');
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'parallel.ai'));
    });

    it('returns unconfigured error when no providers available', function () {
        $tool = new WebSearchTool;

        config([
            'ai.tools.web_search.provider' => 'parallel',
            'ai.tools.web_search.parallel.api_key' => null,
        ]);

        $result = $tool->execute(['query' => 'no providers']);

        expect($result->isError)->toBeTrue()
            ->and((string) $result)->toContain('No search provider configured');
    });
});

describe('caching', function () {
    it('caches results', function () {
        $tool = new WebSearchTool('parallel', 'test-key', 15);

        Http::fake([
            'api.parallel.ai/*' => Http::sequence()
                ->push([
                    'results' => [
                        ['title' => 'First Call', 'url' => SEARCH_EXAMPLE_URL, 'excerpts' => ['First']],
                    ],
                ])
                ->push([
                    'results' => [
                        ['title' => 'Second Call', 'url' => SEARCH_EXAMPLE_URL, 'excerpts' => ['Second']],
                    ],
                ]),
        ]);

        $firstResult = $tool->execute(['query' => 'cached query']);
        $secondResult = $tool->execute(['query' => 'cached query']);

        expect((string) $firstResult)->toBe((string) $secondResult)
            ->and((string) $firstResult)->toContain('First Call');
    });
});
