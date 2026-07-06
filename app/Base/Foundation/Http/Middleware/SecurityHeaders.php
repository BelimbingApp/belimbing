<?php

namespace App\Base\Foundation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies application-wide security response headers (CSP, framing, MIME
 * sniffing, referrer, permissions, HSTS) from config/security.php.
 *
 * Existing header values on the response are never clobbered: a controller that
 * sets a stricter Content-Security-Policy or Content-Disposition for a specific
 * asset keeps its own value. Static files served directly by FrankenPHP bypass
 * this middleware entirely — the Caddyfile carries the same headers for those.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! (bool) config('security.enabled', true)) {
            return $response;
        }

        $this->applyStaticHeaders($response);
        $this->applyHsts($request, $response);
        $this->applyContentSecurityPolicy($response);

        return $response;
    }

    private function applyStaticHeaders(Response $response): void
    {
        /** @var array<string, string> $headers */
        $headers = (array) config('security.headers', []);

        foreach ($headers as $name => $value) {
            if ($value !== '' && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
    }

    private function applyHsts(Request $request, Response $response): void
    {
        if (! (bool) config('security.hsts.enabled', false)) {
            return;
        }

        // HSTS is only meaningful over TLS; sending it on plain http is ignored
        // by browsers and misleading in logs.
        if (! $request->isSecure()) {
            return;
        }

        $value = (string) config('security.hsts.value', '');

        if ($value !== '' && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', $value);
        }
    }

    private function applyContentSecurityPolicy(Response $response): void
    {
        if (! (bool) config('security.csp.enabled', true)) {
            return;
        }

        $policy = $this->buildPolicy((array) config('security.csp.directives', []));

        if ($policy === '') {
            return;
        }

        $header = (bool) config('security.csp.report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        if (! $response->headers->has($header)) {
            $response->headers->set($header, $policy);
        }
    }

    /**
     * @param  array<string, list<string>>  $directives
     */
    private function buildPolicy(array $directives): string
    {
        $parts = [];

        foreach ($directives as $directive => $sources) {
            $directive = trim((string) $directive);

            if ($directive === '') {
                continue;
            }

            $sources = array_values(array_filter(
                array_map('trim', (array) $sources),
                static fn (string $source): bool => $source !== '',
            ));

            $parts[] = $sources === []
                ? $directive
                : $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }
}
