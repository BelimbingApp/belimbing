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

it('serves the static fallback from the instance Caddyfiles when FrankenPHP cannot answer', function (string $caddyfile): void {
    $config = File::get(base_path($caddyfile));
    $handler = substr($config, (int) strpos($config, 'handle_errors 5xx {'));

    expect($config)->toContain('handle_errors 5xx {')
        ->and($handler)->toContain('root * public/'.ErrorPages::PUBLIC_DIRECTORY)
        ->and($handler)->toContain('rewrite * /'.ErrorPages::STATIC_PAGE)
        // file_server answers 200 unless told otherwise; a fallback must keep the failure status.
        ->and($handler)->toContain('status {err.status_code}');
})->with(['Caddyfile', 'Caddyfile.orb']);

it('renders a system ingress block that keeps app-rendered errors and brands raw ones', function (): void {
    $result = Process::path(base_path())->run([
        'bash', '-c',
        'source scripts/shared/caddy.sh && caddy_render_system_site_block example.test "" 8000 /etc/caddy/blb/errors',
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    $block = $result->output();
    $responseHandler = substr($block, (int) strpos($block, 'reverse_proxy'), (int) strpos($block, 'handle_errors') - (int) strpos($block, 'reverse_proxy'));
    $errorHandler = substr($block, (int) strpos($block, 'handle_errors'));

    expect($block)->toStartWith('example.test {')
        ->and($responseHandler)->toContain('reverse_proxy 127.0.0.1:8000 {')
        // First matching handle_response wins: the app's own error pages pass through...
        ->and($responseHandler)->toContain('header '.ErrorPages::RENDERED_HEADER.' '.ErrorPages::RENDERED_HEADER_VALUE)
        ->and($responseHandler)->toContain('copy_response')
        // ...before any other 5xx is swapped for the static page, keeping its status.
        ->and(strpos($responseHandler, 'copy_response'))->toBeLessThan((int) strpos($responseHandler, 'status 5xx'))
        ->and($responseHandler)->toContain('root * /etc/caddy/blb/errors')
        ->and($responseHandler)->toContain('status {rp.status_code}')
        ->and($errorHandler)->toContain('root * /etc/caddy/blb/errors')
        ->and($errorHandler)->toContain('rewrite * /'.ErrorPages::STATIC_PAGE)
        ->and($errorHandler)->toContain('status {err.status_code}');
});
