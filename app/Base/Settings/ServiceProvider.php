<?php

namespace App\Base\Settings;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Foundation\Services\DomainState;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;
use App\Base\Settings\Services\CredentialSettingsDevelopmentSanitizer;
use App\Base\Settings\Services\DatabaseSettingsService;
use App\Base\Settings\Services\SettingDefinitionRegistry;
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

        $this->app->singleton(SettingDefinitionRegistry::class);
        $this->app->singleton(SettingsService::class, DatabaseSettingsService::class);
        $this->app->tag(CredentialSettingsDevelopmentSanitizer::class, DevelopmentSanitizationContributor::CONTAINER_TAG);
    }

    public function boot(): void
    {
        $definitions = [];
        $editable = config('settings.editable', []);

        foreach ($this->discoverSettingsConfigFiles() as $file) {
            $config = require $file;

            if (! is_array($config)) {
                continue;
            }

            foreach ((array) ($config['definitions'] ?? []) as $key => $definition) {
                if (! is_string($key) || ! is_array($definition)) {
                    throw new InvalidSettingDefinitionException(
                        "Settings definitions in [{$file}] must be keyed arrays.",
                    );
                }

                if (array_key_exists($key, $definitions)) {
                    throw new InvalidSettingDefinitionException(
                        "Setting [{$key}] is defined by more than one discovered module.",
                    );
                }

                $definitions[$key] = $definition;
            }

            $editable = array_replace($editable, $config['editable'] ?? []);
        }

        config([
            'settings.definitions' => $definitions,
            'settings.editable' => $editable,
        ]);

        $registry = $this->app->make(SettingDefinitionRegistry::class);
        $registry->refresh();
        $registry->all();
    }

    /**
     * @return list<string>
     */
    private function discoverSettingsConfigFiles(): array
    {
        $files = DomainState::filterPaths(array_merge(
            glob(app_path('Base/*/Config/settings.php')) ?: [],
            glob(app_path('Modules/*/*/Config/settings.php')) ?: [],
            glob(base_path('extensions/*/*/Config/settings.php')) ?: [],
        ));

        sort($files);

        return array_values(array_unique($files));
    }
}
