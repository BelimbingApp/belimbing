<?php

namespace App\Base\Routing;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(RouteDiscoveryService::class);
    }

    /**
     * Bootstrap module route discovery and registration.
     *
     * Discovers route files from all modules and loads them
     * with the appropriate middleware group (web or api).
     */
    public function boot(): void
    {
        $this->forceConfiguredRootUrl();

        $this->app->make(RouteDiscoveryService::class)->registerRoutes();
    }

    /**
     * Pin URL generation to APP_URL when running behind a reverse proxy
     * (e.g. Caddy terminating TLS), which otherwise emits the wrong host and
     * bogus ports like `:0` in generated links.
     */
    private function forceConfiguredRootUrl(): void
    {
        $appUrl = config('app.url');

        if (! is_string($appUrl) || $appUrl === '') {
            return;
        }

        URL::forceRootUrl($appUrl);

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
