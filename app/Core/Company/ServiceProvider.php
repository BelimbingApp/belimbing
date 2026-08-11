<?php

namespace App\Core\Company;

use App\Base\Authz\Contracts\TenantDirectory;
use App\Base\Foundation\Contracts\FrameworkPrimitivesProvisioner as FrameworkPrimitivesProvisionerContract;
use App\Base\Locale\Contracts\PlatformOperatorLocaleBootstrapSource;
use App\Core\Company\Services\CompanyTenantDirectory;
use App\Core\Company\Services\FrameworkPrimitivesProvisioner;
use App\Core\Company\Services\PlatformOperatorLocaleBootstrapSource as PlatformOperatorLocaleBootstrapSourceImpl;
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
            PlatformOperatorLocaleBootstrapSource::class,
            PlatformOperatorLocaleBootstrapSourceImpl::class,
        );
        $this->app->singleton(FrameworkPrimitivesProvisionerContract::class, FrameworkPrimitivesProvisioner::class);

        // Real company→tenant lookup for the authorization engine, replacing
        // Authz's null default. Singleton so the memo survives the process.
        $this->app->singleton(TenantDirectory::class, CompanyTenantDirectory::class);
    }
}
