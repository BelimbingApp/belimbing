<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Operation\Quality;

use App\Modules\Operation\Quality\Contracts\NumberingService;
use App\Modules\Operation\Quality\Services\DefaultNumberingService;
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
