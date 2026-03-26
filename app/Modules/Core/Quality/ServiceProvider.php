<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality;

use App\Modules\Core\Quality\Contracts\NumberingService;
use App\Modules\Core\Quality\Services\DefaultNumberingService;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/Config/quality.php',
            'quality'
        );

        $this->app->bind(
            NumberingService::class,
            DefaultNumberingService::class
        );
    }
}
