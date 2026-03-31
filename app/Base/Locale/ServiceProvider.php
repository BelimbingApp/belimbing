<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale;

use App\Base\Locale\Contracts\CurrencyDisplayService;
use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\Contracts\NumberDisplayService;
use App\Base\Locale\Services\ApplicationLocaleContext;
use App\Base\Locale\Services\LocaleCatalog;
use App\Base\Locale\Services\LocalizedCurrencyDisplayService;
use App\Base\Locale\Services\LocalizedNumberDisplayService;
use App\Base\Locale\Services\NullLicenseeLocaleBootstrapSource;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/locale.php', 'locale');

        $this->app->singleton(LocaleCatalog::class);
        $this->app->bindIf(
            LicenseeLocaleBootstrapSource::class,
            NullLicenseeLocaleBootstrapSource::class,
            true,
        );
        $this->app->singleton(LocaleContext::class, ApplicationLocaleContext::class);
        $this->app->singleton(NumberDisplayService::class, LocalizedNumberDisplayService::class);
        $this->app->singleton(CurrencyDisplayService::class, LocalizedCurrencyDisplayService::class);
    }
}
