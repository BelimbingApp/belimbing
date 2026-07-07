<?php

use App\Base\Foundation\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

const FOUNDATION_SECURITY_HEADERS_TEST_PATH = '/dashboard';
const FOUNDATION_SECURITY_HEADERS_LOCKED_CSP = "default-src 'none'; sandbox";

function runSecurityHeaders(Request $request, ?Response $seed = null): Response
{
    return (new SecurityHeaders)->handle(
        $request,
        fn () => $seed ?? new Response('ok'),
    );
}

it('sets the static security headers on responses', function (): void {
    $response = runSecurityHeaders(Request::create(FOUNDATION_SECURITY_HEADERS_TEST_PATH, 'GET'));

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('geolocation=()');
});

it('emits a content security policy that locks down framing and injection sinks', function (): void {
    $response = runSecurityHeaders(Request::create(FOUNDATION_SECURITY_HEADERS_TEST_PATH, 'GET'));
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'")
        // TALL stack needs eval/inline for scripts; documented compromise.
        ->and($csp)->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'");
});

it('does not clobber a policy a controller already set', function (): void {
    $seed = new Response('img');
    $seed->headers->set('Content-Security-Policy', FOUNDATION_SECURITY_HEADERS_LOCKED_CSP);

    $response = runSecurityHeaders(Request::create('/media/assets/1/stream', 'GET'), $seed);

    expect($response->headers->get('Content-Security-Policy'))->toBe(FOUNDATION_SECURITY_HEADERS_LOCKED_CSP);
});

it('uses report-only mode when configured', function (): void {
    config()->set('security.csp.report_only', true);

    $response = runSecurityHeaders(Request::create(FOUNDATION_SECURITY_HEADERS_TEST_PATH, 'GET'));

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue();
});

it('withholds HSTS on plaintext requests and sends it over TLS', function (): void {
    config()->set('security.hsts.enabled', true);

    $plain = runSecurityHeaders(Request::create(FOUNDATION_SECURITY_HEADERS_TEST_PATH, 'GET'));
    expect($plain->headers->has('Strict-Transport-Security'))->toBeFalse();

    $secure = runSecurityHeaders(Request::create('https://local.blb.lara'.FOUNDATION_SECURITY_HEADERS_TEST_PATH, 'GET'));
    expect($secure->headers->get('Strict-Transport-Security'))->toContain('max-age=');
});

it('is a pass-through when disabled', function (): void {
    config()->set('security.enabled', false);

    $response = runSecurityHeaders(Request::create(FOUNDATION_SECURITY_HEADERS_TEST_PATH, 'GET'));

    expect($response->headers->has('X-Content-Type-Options'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});
