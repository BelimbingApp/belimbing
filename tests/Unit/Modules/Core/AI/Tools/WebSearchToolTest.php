<?php

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
});

describe('factory method', function () {
    it('returns null when no API key configured', function () {
        config([
            'ai.tools.web_search.provider' => 'parallel',
            'ai.tools.web_search.parallel.api_key' => null,
        ]);

        expect(WebSearchTool::createIfConfigured())->toBeNull();
    });

    it('returns instance when API key configured', function () {
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
                    ['title' => 'Test', 'url' => SEARCH_EXAMPLE_URL, 'snippet' => 'A test result'],
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
                    ['title' => 'First Result', 'url' => SEARCH_EXAMPLE_URL.'/1', 'snippet' => 'First snippet'],
                    ['title' => 'Second Result', 'url' => SEARCH_EXAMPLE_URL.'/2', 'snippet' => 'Second snippet'],
                ],
            ]),
        ]);

        $result = $this->tool->execute(['query' => SEARCH_QUERY]);

        expect($result)->toContain('1. First Result')
            ->and($result)->toContain('2. Second Result')
            ->and($result)->toContain(SEARCH_EXAMPLE_URL.'/1')
            ->and($result)->toContain('First snippet');
    });

    it('handles empty results', function () {
        Http::fake([
            'api.parallel.ai/*' => Http::response([
                'results' => [],
            ]),
        ]);

        $result = $this->tool->execute(['query' => 'obscure query']);

        expect($result)->toContain('No results found');
    });

    it('handles API errors', function () {
        Http::fake([
            'api.parallel.ai/*' => Http::response('Internal Server Error', 500),
        ]);

        $result = $this->tool->execute(['query' => SEARCH_QUERY]);

        expect($result)->toContain('Search failed');
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

describe('caching', function () {
    it('caches results', function () {
        $tool = new WebSearchTool('parallel', 'test-key', 15);

        Http::fake([
            'api.parallel.ai/*' => Http::sequence()
                ->push([
                    'results' => [
                        ['title' => 'First Call', 'url' => SEARCH_EXAMPLE_URL, 'snippet' => 'First'],
                    ],
                ])
                ->push([
                    'results' => [
                        ['title' => 'Second Call', 'url' => SEARCH_EXAMPLE_URL, 'snippet' => 'Second'],
                    ],
                ]),
        ]);

        $firstResult = $tool->execute(['query' => 'cached query']);
        $secondResult = $tool->execute(['query' => 'cached query']);

        expect($firstResult)->toBe($secondResult)
            ->and($firstResult)->toContain('First Call');
    });
});
