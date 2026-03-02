<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Htmx;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * Registers HTMX framework services.
 *
 * Currently a thin provider; reserved for future HTMX middleware,
 * Blade directives, or debug tooling registration.
 */
class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register HTMX bindings in the container.
     */
    public function register(): void
    {
        $this->app->bind(HtmxRequest::class, fn ($app) => new HtmxRequest($app->make(\Illuminate\Http\Request::class)));
    }

    /**
     * Bootstrap HTMX services.
     */
    public function boot(): void {}
}
