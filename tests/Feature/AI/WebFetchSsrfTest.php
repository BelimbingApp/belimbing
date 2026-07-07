<?php

use App\Base\AI\Services\UrlSafetyGuard;
use App\Base\AI\Services\WebFetchService;
use Illuminate\Support\Facades\Http;

const WEB_FETCH_SSRF_START_PATTERN = 'https://start.test/*';
const WEB_FETCH_SSRF_START_URL = 'https://start.test/';
const WEB_FETCH_SSRF_NEXT_URL = 'https://start.test/next';
const WEB_FETCH_SSRF_PUBLIC_IP = '93.184.'.'216.34';
const WEB_FETCH_SSRF_PRIVATE_IP = '127.0.'.'0.1';

/**
 * @param  list<string>  $hostnameAllowlist
 * @return array{validation_error?: string, request_error?: string, http_status?: int, content?: string, char_count?: int, truncated?: bool}
 */
function webFetchSsrfFetch(
    WebFetchService $service,
    string $url = WEB_FETCH_SSRF_START_URL,
    array $hostnameAllowlist = ['start.test'],
): array {
    return $service->fetch($url, 30, 1_000_000, 50_000, 'text', false, $hostnameAllowlist);
}

// The initial host is allowlisted in redirect tests so pinnedIpFor() skips DNS.
// Redirect targets use local names so re-validation needs no network.

it('blocks a redirect that points at an internal target', function (): void {
    Http::fake([
        WEB_FETCH_SSRF_START_PATTERN => Http::response('go', 302, ['Location' => 'https://localhost/latest/meta-data']),
    ]);

    $result = webFetchSsrfFetch(new WebFetchService(new UrlSafetyGuard));

    expect($result)->toHaveKey('validation_error')
        ->and($result['validation_error'])->toContain('localhost');
});

it('stops after the redirect limit', function (): void {
    Http::fake([
        WEB_FETCH_SSRF_START_PATTERN => Http::response('', 302, ['Location' => WEB_FETCH_SSRF_NEXT_URL]),
    ]);

    $result = webFetchSsrfFetch(new WebFetchService(new UrlSafetyGuard));

    expect($result)->toHaveKey('request_error')
        ->and($result['request_error'])->toContain('Too many redirects');
});

it('extracts content from a successful response', function (): void {
    Http::fake([
        WEB_FETCH_SSRF_START_PATTERN => Http::response(
            '<html><body><h1>Title</h1><p>Body text here.</p></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $result = webFetchSsrfFetch(new WebFetchService(new UrlSafetyGuard));

    expect($result['content'] ?? '')->toContain('Title')
        ->and($result['content'])->toContain('Body text here.');
});

it('blocks an initial internal URL before any request', function (): void {
    Http::fake();

    $result = webFetchSsrfFetch(new WebFetchService(new UrlSafetyGuard), 'https://localhost/', []);

    expect($result)->toHaveKey('validation_error');
    Http::assertNothingSent();
});

it('pins DNS fetches to the validated public IP', function (): void {
    $lookups = [[WEB_FETCH_SSRF_PUBLIC_IP], [WEB_FETCH_SSRF_PUBLIC_IP]];
    $optionsSeen = [];
    $guard = new UrlSafetyGuard(function (string $host) use (&$lookups): array {
        return array_shift($lookups) ?? [];
    });

    Http::fake(function ($request, array $options) use (&$optionsSeen) {
        $optionsSeen = $options;

        return Http::response('Pinned body', 200, ['Content-Type' => 'text/plain']);
    });

    $result = webFetchSsrfFetch(new WebFetchService($guard), hostnameAllowlist: []);

    expect($result['content'] ?? null)->toBe('Pinned body')
        ->and($optionsSeen['curl'][CURLOPT_RESOLVE][0] ?? null)
        ->toBe('start.test:443:'.WEB_FETCH_SSRF_PUBLIC_IP);
});

it('blocks DNS fetches when the validated hostname cannot be pinned', function (): void {
    $lookups = [[WEB_FETCH_SSRF_PUBLIC_IP], [WEB_FETCH_SSRF_PRIVATE_IP]];
    $guard = new UrlSafetyGuard(function (string $host) use (&$lookups): array {
        return array_shift($lookups) ?? [];
    });

    Http::fake([
        WEB_FETCH_SSRF_START_PATTERN => Http::response('should not be fetched'),
    ]);

    $result = webFetchSsrfFetch(new WebFetchService($guard), hostnameAllowlist: []);

    expect($result)->toHaveKey('validation_error')
        ->and($result['validation_error'])->toContain('unable to pin');
    Http::assertNothingSent();
});
