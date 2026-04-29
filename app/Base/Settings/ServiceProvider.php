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

    public function boot(): void
    {
        $editable = config('settings.editable', []);

        foreach ($this->discoverSettingsConfigFiles() as $file) {
            $config = require $file;

            if (! is_array($config)) {
                continue;
            }

            $editable = array_replace($editable, $config['editable'] ?? []);
        }

        config(['settings.editable' => $editable]);
    }

    /**
     * @return list<string>
     */
    private function discoverSettingsConfigFiles(): array
    {
        $files = array_merge(
            glob(app_path('Base/*/Config/settings.php')) ?: [],
            glob(app_path('Modules/*/*/Config/settings.php')) ?: [],
            glob(base_path('extensions/*/*/Config/settings.php')) ?: [],
        );

        sort($files);

        return array_values(array_unique($files));
    }
}
