<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Settings;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Services\DatabaseSettingsService;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register Settings services.
     *
     * Merges settings meta-config and binds the SettingsService contract
     * to the DatabaseSettingsService implementation.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/settings.php', 'settings');

        $this->app->singleton(SettingsService::class, DatabaseSettingsService::class);
    }
}
