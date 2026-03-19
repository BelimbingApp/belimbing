<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\Providers;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ProviderRegistry
{
    /**
     * Resolve the application service provider list.
     *
     * @param  array<int, class-string<ServiceProvider>>  $appProviders  Application-level providers loaded last
     * @param  array<int, class-string<ServiceProvider>>  $priorityProviders  Explicit framework providers loaded before discovered providers
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function resolve(array $appProviders = [], array $priorityProviders = []): array
    {
        $providers = array_merge(
            self::validateProviders($priorityProviders),
            // Ordering is part of the framework contract:
            // explicit priorities -> Base infrastructure -> business modules -> app providers.
            // This keeps bootstrapping deterministic and prevents subtle dependency breakage.
            self::discoverBaseProviders(),
            self::discoverModuleProviders(),
            self::validateProviders($appProviders)
        );

        return array_values(array_unique($providers));
    }

    /**
     * Discover module service providers from app/Modules.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverModuleProviders(): array
    {
        $paths = glob(app_path('Modules/*/*/ServiceProvider.php')) ?: [];

        sort($paths);

        $providers = [];
        foreach ($paths as $path) {
            $providers[] = self::classFromPath($path);
        }

        return self::validateProviders($providers);
    }

    /**
     * Discover Base service providers from app/Base.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverBaseProviders(): array
    {
        $pattern = app_path('Base/*/ServiceProvider.php');
        $paths = glob($pattern) ?: [];

        sort($paths);

        $providers = [];
        foreach ($paths as $path) {
            $providers[] = self::classFromPath($path);
        }

        return self::validateProviders($providers);
    }

    /**
     * Convert an app path into a fully-qualified class name.
     */
    private static function classFromPath(string $path): string
    {
        $appPath = rtrim(app_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relativePath = str_replace($appPath, '', $path);

        return 'App\\'.str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relativePath
        );
    }

    /**
     * Validate provider classes and fail fast on invalid entries.
     *
     * @param  array<int, string>  $providers
     * @return array<int, class-string<ServiceProvider>>
     */
    private static function validateProviders(array $providers): array
    {
        $validProviders = [];

        foreach ($providers as $provider) {
            if (! class_exists($provider)) {
                throw new InvalidArgumentException("Service provider class [$provider] does not exist.");
            }

            if (! is_subclass_of($provider, ServiceProvider::class)) {
                throw new InvalidArgumentException(
                    "Service provider class [$provider] must extend ".ServiceProvider::class.'.'
                );
            }

            $validProviders[] = $provider;
        }

        return $validProviders;
    }
}
