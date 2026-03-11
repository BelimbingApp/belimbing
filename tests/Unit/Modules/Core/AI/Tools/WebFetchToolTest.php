<?php

use App\Modules\Core\AI\Tools\WebFetchTool;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new WebFetchTool;
    $this->executeTool = fn (array $arguments): string => (string) $this->tool->execute($arguments);
    $this->fakeHttpResponse = function (
        string $body,
        array $arguments = [],
        string $contentType = 'text/html',
        int $status = 200,
    ): string {
        Http::fake([
            '*' => Http::response($body, $status, ['Content-Type' => $contentType]),
        ]);

        return (string) $this->tool->execute(array_merge([
            'url' => 'http://example.com/page',
        ], $arguments));
    };
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'web_fetch',
            'ai.tool_web_fetch.execute',
            ['url'],
            ['url'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing or empty URL', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('url');
    });
});

describe('SSRF protection', function () {
    it('blocks localhost', function () {
        $result = ($this->executeTool)(['url' => 'http://localhost/test']);
        expect($result)->toContain('Blocked');
    });

    it('blocks 0.0.0.0', function () {
        $result = ($this->executeTool)(['url' => 'https://0.0.0.0/test']);
        expect($result)->toContain('Blocked');
    });

    it('blocks .local domains', function () {
        $result = ($this->executeTool)(['url' => 'https://myserver.local/test']);
        expect($result)->toContain('Blocked');
    });

    it('blocks non-http schemes', function () {
        $result = ($this->executeTool)(['url' => 'ftp://example.com/file']);
        expect($result)->toContain('Only http and https');
    });

    it('blocks file scheme', function () {
        $result = ($this->executeTool)(['url' => 'file:///etc/passwd']);
        expect($result)->toContain('Error');
    });

    it('allows ssrf_allow_private config to bypass', function () {
        config(['ai.tools.web_fetch.ssrf_allow_private' => true]);

        $result = ($this->fakeHttpResponse)(
            '<html><body><p>OK</p></body></html>',
            ['url' => 'https://192.168.1.1/test'],
        );

        expect($result)->not->toContain('Blocked');
    });
});

describe('content fetching', function () {
    it('fetches and returns text content', function () {
        $result = ($this->fakeHttpResponse)('<html><body><p>Hello World</p></body></html>');

        expect($result)->toContain('Hello World');
    });

    it('strips script tags', function () {
        $result = ($this->fakeHttpResponse)('<html><body><script>alert(1)</script><p>Content</p></body></html>');

        expect($result)->toContain('Content')
            ->and($result)->not->toContain('alert');
    });

    it('strips style tags', function () {
        $result = ($this->fakeHttpResponse)('<html><body><style>body{}</style><p>Content</p></body></html>');

        expect($result)->toContain('Content')
            ->and($result)->not->toContain('body{}');
    });

    it('handles non-HTML content', function () {
        $result = ($this->fakeHttpResponse)(
            '{"key":"value"}',
            ['url' => 'http://example.com/api'],
            'application/json',
        );

        expect($result)->toContain('{"key":"value"}');
    });

    it('truncates content to max_chars', function () {
        $longText = '<html><body><p>'.str_repeat('a', 1000).'</p></body></html>';
        $result = ($this->fakeHttpResponse)($longText, ['max_chars' => 100]);

        expect($result)->toContain('truncated');
    });

    it('returns error for failed HTTP requests', function () {
        $result = ($this->fakeHttpResponse)(
            'Not Found',
            ['url' => 'http://example.com/missing'],
            'text/html',
            404,
        );

        expect($result)->toContain('HTTP 404');
    });

    it('includes source URL in output', function () {
        $result = ($this->fakeHttpResponse)('<html><body><p>Test</p></body></html>');

        expect($result)->toContain('http://example.com/page');
    });
});

describe('markdown extraction', function () {
    it('converts headings to markdown', function () {
        $html = '<html><body><h1>Title</h1><h2>Sub</h2></body></html>';
        $result = ($this->fakeHttpResponse)($html, ['extract_mode' => 'markdown']);

        expect($result)->toContain('# Title')
            ->and($result)->toContain('## Sub');
    });

    it('converts links to markdown', function () {
        $html = '<html><body><a href="http://example.com">link text</a></body></html>';
        $result = ($this->fakeHttpResponse)($html, ['extract_mode' => 'markdown']);

        expect($result)->toContain('[link text](http://example.com)');
    });

    it('converts bold to markdown', function () {
        $html = '<html><body><strong>bold text</strong></body></html>';
        $result = ($this->fakeHttpResponse)($html, ['extract_mode' => 'markdown']);

        expect($result)->toContain('**bold text**');
    });
});
