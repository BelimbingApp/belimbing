<?php

namespace App\Core\Company;

use App\Base\Authz\Contracts\TenantDirectory;
use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Core\Company\Services\CompanyTenantDirectory;
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

        // Real company→tenant lookup for the authorization engine, replacing
        // Authz's null default. Singleton so the memo survives the process.
        $this->app->singleton(TenantDirectory::class, CompanyTenantDirectory::class);
    }
}
