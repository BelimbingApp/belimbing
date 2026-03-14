<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Log;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap Base Log helpers.
     *
     * Loads global helper functions (e.g. blb_log_var()).
     */
    public function boot(): void
    {
        require app_path('Base/Log/helpers.php');
    }
}
