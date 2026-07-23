<?php

namespace App\Base\Settings;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Foundation\Services\DomainState;
use App\Base\Settings\Console\Commands\ImportEnvironmentSettingsCommand;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;
use App\Base\Settings\Services\CredentialSettingsDevelopmentSanitizer;
use App\Base\Settings\Services\DatabaseSettingsService;
use App\Base\Settings\Services\RuntimeSettingClaimRegistry;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use App\Base\Settings\Services\SettingManifestCompiler;
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
        $this->app->singleton(RuntimeSettingClaimRegistry::class);
        $this->app->singleton(SettingManifestCompiler::class);
        $this->app->scoped(SettingsService::class, DatabaseSettingsService::class);
        $this->app->tag(CredentialSettingsDevelopmentSanitizer::class, DevelopmentSanitizationContributor::CONTAINER_TAG);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportEnvironmentSettingsCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $definitions = [];
        $editable = config('settings.editable', []);
        $runtime = config('settings.runtime', []);
        $compiler = $this->app->make(SettingManifestCompiler::class);

        foreach ($this->discoverSettingsConfigFiles() as $file) {
            $config = require $file;

            if (! is_array($config)) {
                continue;
            }

            $manifest = $compiler->compile($this->ownerFor($file), $config);

            foreach ($manifest['definitions'] as $key => $definition) {
                if (array_key_exists($key, $definitions)) {
                    throw new InvalidSettingDefinitionException(
                        "Setting [{$key}] is defined by more than one discovered module.",
                    );
                }

                $definitions[$key] = $definition;
            }

            $editable = array_replace($editable, $manifest['editable']);
            $runtime = array_merge($runtime, $manifest['runtime']);
        }

        config([
            'settings.definitions' => $definitions,
            'settings.editable' => $editable,
            'settings.runtime' => array_values(array_unique($runtime)),
        ]);

        $registry = $this->app->make(SettingDefinitionRegistry::class);
        $registry->refresh();
        $registry->all();
        $runtimeRegistry = $this->app->make(RuntimeSettingClaimRegistry::class);
        $runtimeRegistry->refresh();
        $runtimeRegistry->all();
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

    private function ownerFor(string $file): string
    {
        $relative = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
        $segments = explode('/', $relative);

        return match ($segments[0] ?? null) {
            'app' => match ($segments[1] ?? null) {
                'Base' => 'base.'.strtolower((string) ($segments[2] ?? 'unknown')),
                'Modules' => strtolower((string) ($segments[2] ?? 'unknown'))
                    .'.'.strtolower((string) ($segments[3] ?? 'unknown')),
                default => 'app.unknown',
            },
            'extensions' => 'extension.'
                .strtolower((string) ($segments[1] ?? 'unknown'))
                .'.'.strtolower((string) ($segments[2] ?? 'unknown')),
            default => 'unknown',
        };
    }
}
