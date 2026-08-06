<?php
namespace App\Core\Company;

use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Core\Company\Services\LicenseeLocaleBootstrapSource as LicenseeLocaleBootstrapSourceImpl;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/Config/company.php',
            'company'
        );

        $this->app->singleton(
            LicenseeLocaleBootstrapSource::class,
            LicenseeLocaleBootstrapSourceImpl::class,
        );
    }
}
