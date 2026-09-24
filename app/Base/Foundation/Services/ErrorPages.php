<?php

namespace App\Base\Foundation\Services;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The branded error pages that answer when the application cannot.
 *
 * Three layers show a visitor a Belimbing page instead of a blank or vendor
 * error, and this class is the seam between them:
 *
 *  1. The application renders `errors::*` views itself (500, 503, ...). Every
 *     such response carries {@see self::RENDERED_HEADER} so the web server can
 *     tell an application-rendered error from a raw one.
 *  2. Caddy serves {@see self::STATIC_PAGE} when FrankenPHP fails or a PHP
 *     response is an unbranded 5xx (a fatal with an empty body).
 *  3. The CDN serves {@see self::CLOUDFLARE_PAGE} when the whole server is
 *     unreachable. Cloudflare requires its diagnostics token on that page.
 *
 * Layers 2 and 3 are static files published from the same Blade shell the
 * application uses, so the brand cannot drift between them.
 */
final class ErrorPages
{
    /** Response header marking an error page the application rendered itself. */
    public const RENDERED_HEADER = 'X-Belimbing-Error-Page';

    public const RENDERED_HEADER_VALUE = 'app';

    /** Directory under public/ holding the published static pages. */
    public const PUBLIC_DIRECTORY = 'errors';

    public const STATIC_PAGE = '5xx.html';

    public const CLOUDFLARE_PAGE = 'cloudflare-5xx.html';

    /** Cloudflare replaces this token with its own diagnostics; a custom 5xx page must carry it. */
    public const CLOUDFLARE_TOKEN = '::CLOUDFLARE_ERROR_500S_BOX::';

    private const VIEW = 'errors.fallback';

    public function __construct(private readonly ViewFactory $views) {}

    /**
     * Mark a response rendered by the exception handler so the ingress proxy
     * passes it through instead of replacing it with the static fallback.
     */
    public static function markRendered(Response $response): Response
    {
        if ($response->getStatusCode() >= 500) {
            $response->headers->set(self::RENDERED_HEADER, self::RENDERED_HEADER_VALUE);
        }

        return $response;
    }

    /**
     * The static pages and the markup each must hold, keyed by absolute path.
     *
     * @return array<string, string>
     */
    public function expected(): array
    {
        return [
            self::path(self::STATIC_PAGE) => $this->render(cloudflareBox: false),
            self::path(self::CLOUDFLARE_PAGE) => $this->render(cloudflareBox: true),
        ];
    }

    /**
     * Write both static pages under public/errors. Returns the paths written.
     *
     * @return list<string>
     */
    public function publish(): array
    {
        $directory = self::path('');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}");
        }

        $written = [];

        foreach ($this->expected() as $path => $html) {
            file_put_contents($path, $html);
            $written[] = $path;
        }

        return $written;
    }

    /**
     * Published pages whose content no longer matches the Blade shell.
     *
     * @return list<string>
     */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->expected() as $path => $html) {
            if (! is_file($path) || file_get_contents($path) !== $html) {
                $stale[] = $path;
            }
        }

        return $stale;
    }

    public function render(bool $cloudflareBox): string
    {
        return $this->views->make(self::VIEW, ['cloudflareBox' => $cloudflareBox])->render();
    }

    public static function path(string $file): string
    {
        return public_path(self::PUBLIC_DIRECTORY.($file === '' ? '' : '/'.$file));
    }
}
