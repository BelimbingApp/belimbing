<?php

namespace App\Base\Locale;

use App\Base\Locale\Contracts\CurrencyDisplayService;
use App\Base\Locale\Contracts\LocaleContext;
use App\Base\Locale\Contracts\NumberDisplayService;
use App\Base\Locale\Contracts\PlatformOperatorLocaleBootstrapSource;
use App\Base\Locale\Services\ApplicationLocaleContext;
use App\Base\Locale\Services\LocaleCatalog;
use App\Base\Locale\Services\LocalizedCurrencyDisplayService;
use App\Base\Locale\Services\LocalizedNumberDisplayService;
use App\Base\Locale\Services\NullPlatformOperatorLocaleBootstrapSource;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/locale.php', 'locale');

        $this->app->singleton(LocaleCatalog::class);
        $this->app->bindIf(
            PlatformOperatorLocaleBootstrapSource::class,
            NullPlatformOperatorLocaleBootstrapSource::class,
            true,
        );
        $this->app->scoped(LocaleContext::class, ApplicationLocaleContext::class);
        $this->app->scoped(NumberDisplayService::class, LocalizedNumberDisplayService::class);
        $this->app->scoped(CurrencyDisplayService::class, LocalizedCurrencyDisplayService::class);
    }
}
