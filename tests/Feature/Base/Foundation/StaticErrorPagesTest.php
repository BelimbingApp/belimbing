<?php

use App\Base\Foundation\Services\ErrorPages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// Layers 2 and 3 of the error-page contract (docs/runbooks/error-pages.md):
// the static pages Caddy and the CDN serve when PHP cannot answer. They are
// committed artifacts rendered from the Blade error shell, so the guard that
// matters is drift — a restyled shell with stale static pages, or a static
// page that quietly grew a dependency on the server it is meant to replace.

it('keeps the committed static fallback pages in step with the Blade error shell', function (): void {
    expect(app(ErrorPages::class)->stale())->toBe([]);
});

it('publishes static pages that need nothing from the server', function (string $file): void {
    $html = File::get(ErrorPages::path($file));

    expect(strlen($html))->toBeLessThan(1_000_000)
        ->and($html)->toContain('<svg')
        ->and($html)->toContain('http-equiv="refresh"')
        ->and($html)->toContain('href=""')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<link')
        ->and($html)->not->toContain('src=')
        ->and($html)->not->toContain('href="http')
        ->and($html)->not->toContain('url(')
        ->and($html)->not->toContain('@import');
})->with([ErrorPages::STATIC_PAGE, ErrorPages::CLOUDFLARE_PAGE]);

it('carries the Cloudflare diagnostics token only on the CDN page', function (): void {
    expect(File::get(ErrorPages::path(ErrorPages::CLOUDFLARE_PAGE)))->toContain(ErrorPages::CLOUDFLARE_TOKEN)
        ->and(File::get(ErrorPages::path(ErrorPages::STATIC_PAGE)))->not->toContain(ErrorPages::CLOUDFLARE_TOKEN);
});

it('reports stale pages from --check and repairs them by publishing', function (): void {
    $publicPath = storage_path('framework/testing/static-error-pages-'.uniqid());
    File::ensureDirectoryExists($publicPath);
    File::copy(public_path('favicon.svg'), $publicPath.'/favicon.svg');
    $this->app->usePublicPath($publicPath);

    try {
        expect(Artisan::call('blb:error-pages:publish', ['--check' => true]))->toBe(1);
        $missing = Artisan::output();
        expect($missing)->toContain(ErrorPages::STATIC_PAGE)
            ->and($missing)->toContain(ErrorPages::CLOUDFLARE_PAGE);

        expect(Artisan::call('blb:error-pages:publish'))->toBe(0)
            ->and(File::exists($publicPath.'/errors/'.ErrorPages::STATIC_PAGE))->toBeTrue()
            ->and(Artisan::call('blb:error-pages:publish', ['--check' => true]))->toBe(0);

        File::append($publicPath.'/errors/'.ErrorPages::STATIC_PAGE, '<!-- hand edit -->');

        expect(Artisan::call('blb:error-pages:publish', ['--check' => true]))->toBe(1);
        $stale = Artisan::output();
        expect($stale)->toContain(ErrorPages::STATIC_PAGE)
            ->and($stale)->not->toContain(ErrorPages::CLOUDFLARE_PAGE);
    } finally {
        File::deleteDirectory($publicPath);
    }
});

/**
 * Adapt a Caddyfile to Caddy's JSON config with the real consumer, so the
 * assertions below read what Caddy will run rather than the file's text.
 *
 * @return array<string, mixed>
 */
function adaptStaticErrorPagesCaddyfile(string $config, bool $needsFrankenPhp): array
{
    $binaries = $needsFrankenPhp ? ['frankenphp'] : ['frankenphp', 'caddy'];
    $binary = collect($binaries)->first(fn (string $name): bool => Process::run(['which', $name])->successful());

    if ($binary === null) {
        test()->markTestSkipped(implode(' or ', $binaries).' is required to adapt the Caddyfile.');
    }

    $result = Process::path(base_path())->run([$binary, 'adapt', '--config', $config, '--adapter', 'caddyfile']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The handlers a list of routes runs, in order, with subroutes flattened.
 *
 * @param  list<array<string, mixed>>  $routes
 * @return list<array<string, mixed>>
 */
function staticErrorPagesHandlers(array $routes): array
{
    $handlers = [];

    foreach ($routes as $route) {
        foreach ($route['handle'] ?? [] as $handler) {
            if ($handler['handler'] === 'subroute') {
                array_push($handlers, ...staticErrorPagesHandlers($handler['routes']));
            } else {
                $handlers[] = $handler;
            }
        }
    }

    return $handlers;
}

/**
 * The route a static fallback must run: set the root, rewrite to the page, and
 * serve it with the failure status (file_server answers 200 unless told otherwise).
 *
 * @param  list<array<string, mixed>>  $handlers
 * @return list<array<string, mixed>>
 */
function staticErrorPagesFallback(array $handlers): array
{
    return collect($handlers)
        ->filter(fn (array $handler): bool => in_array($handler['handler'], ['vars', 'rewrite', 'file_server'], true))
        ->map(fn (array $handler): array => match ($handler['handler']) {
            'vars' => ['vars', $handler['root'] ?? null],
            'rewrite' => ['rewrite', $handler['uri']],
            'file_server' => ['file_server', $handler['status_code'] ?? null],
        })
        ->values()
        ->all();
}

it('serves the static fallback from the instance Caddyfiles when FrankenPHP cannot answer', function (string $caddyfile): void {
    $servers = adaptStaticErrorPagesCaddyfile($caddyfile, needsFrankenPhp: true)['apps']['http']['servers'];
    $errorRoutes = collect($servers)->flatMap(fn (array $server): array => $server['errors']['routes'] ?? [])->all();

    expect($errorRoutes)->not->toBeEmpty()
        ->and(staticErrorPagesFallback(staticErrorPagesHandlers($errorRoutes)))->toBe([
            ['vars', 'public/'.ErrorPages::PUBLIC_DIRECTORY],
            ['rewrite', '/'.ErrorPages::STATIC_PAGE],
            ['file_server', '{http.error.status_code}'],
        ]);
})->with(['Caddyfile', 'Caddyfile.orb']);

it('renders a system ingress block that keeps app-rendered errors and brands raw ones', function (): void {
    $result = Process::path(base_path())->run([
        'bash', '-c',
        'source scripts/shared/caddy.sh && caddy_render_system_site_block example.test "" 8000 /etc/caddy/blb/errors',
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    $config = storage_path('framework/testing/system-ingress-'.uniqid().'.caddy');
    File::ensureDirectoryExists(dirname($config));
    File::put($config, $result->output());

    try {
        $server = collect(adaptStaticErrorPagesCaddyfile($config, needsFrankenPhp: false)['apps']['http']['servers'])->sole();
    } finally {
        File::delete($config);
    }

    $siteRoute = collect($server['routes'])->sole();
    $proxy = collect(staticErrorPagesHandlers([$siteRoute]))->sole(fn (array $handler): bool => $handler['handler'] === 'reverse_proxy');
    [$renderedByApp, $unbranded] = $proxy['handle_response'];

    expect($siteRoute['match'])->toBe([['host' => ['example.test']]])
        ->and($proxy['upstreams'])->toBe([['dial' => '127.0.0.1:8000']])
        ->and($proxy['handle_response'])->toHaveCount(2)
        // First matching handle_response wins: the app's own error pages pass through
        // with their status, body, and headers (Content-Type, Retry-After, CSP)...
        ->and($renderedByApp['match'])->toBe(['headers' => [ErrorPages::RENDERED_HEADER => [ErrorPages::RENDERED_HEADER_VALUE]]])
        ->and(array_column(staticErrorPagesHandlers($renderedByApp['routes']), 'handler'))->toBe(['copy_response_headers', 'copy_response'])
        // ...before any other 5xx is swapped for the static page, keeping its status.
        ->and($unbranded['match'])->toBe(['status_code' => [5]])
        ->and(staticErrorPagesFallback(staticErrorPagesHandlers($unbranded['routes'])))->toBe([
            ['vars', '/etc/caddy/blb/errors'],
            ['rewrite', '/'.ErrorPages::STATIC_PAGE],
            ['file_server', '{http.reverse_proxy.status_code}'],
        ])
        ->and(staticErrorPagesFallback(staticErrorPagesHandlers($server['errors']['routes'])))->toBe([
            ['vars', '/etc/caddy/blb/errors'],
            ['rewrite', '/'.ErrorPages::STATIC_PAGE],
            ['file_server', '{http.error.status_code}'],
        ]);
});
