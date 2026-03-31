<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company;

use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Modules\Core\Company\Services\LicenseeLocaleBootstrapSource as LicenseeLocaleBootstrapSourceImpl;
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
