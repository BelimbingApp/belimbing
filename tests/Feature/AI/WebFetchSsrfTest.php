<?php

use App\Base\AI\Services\UrlSafetyGuard;
use App\Base\AI\Services\WebFetchService;
use Illuminate\Support\Facades\Http;

function webFetch(): WebFetchService
{
    return new WebFetchService(new UrlSafetyGuard);
}

// The initial host is allowlisted so pinnedIpFor() skips DNS; redirect targets
// are IP literals so re-validation needs no network either.

it('blocks a redirect that points at an internal target', function (): void {
    Http::fake([
        'http://start.test/*' => Http::response('go', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
    ]);

    $result = webFetch()->fetch('http://start.test/', 30, 1_000_000, 50_000, 'text', false, ['start.test']);

    expect($result)->toHaveKey('validation_error')
        ->and($result['validation_error'])->toContain('169.254.169.254');
});

it('stops after the redirect limit', function (): void {
    Http::fake([
        'http://start.test/*' => Http::response('', 302, ['Location' => 'http://start.test/next']),
    ]);

    $result = webFetch()->fetch('http://start.test/', 30, 1_000_000, 50_000, 'text', false, ['start.test']);

    expect($result)->toHaveKey('request_error')
        ->and($result['request_error'])->toContain('Too many redirects');
});

it('extracts content from a successful response', function (): void {
    Http::fake([
        'http://start.test/*' => Http::response(
            '<html><body><h1>Title</h1><p>Body text here.</p></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $result = webFetch()->fetch('http://start.test/', 30, 1_000_000, 50_000, 'text', false, ['start.test']);

    expect($result['content'] ?? '')->toContain('Title')
        ->and($result['content'])->toContain('Body text here.');
});

it('blocks an initial internal URL before any request', function (): void {
    Http::fake();

    $result = webFetch()->fetch('http://127.0.0.1/', 30, 1_000_000, 50_000, 'text');

    expect($result)->toHaveKey('validation_error');
    Http::assertNothingSent();
});
