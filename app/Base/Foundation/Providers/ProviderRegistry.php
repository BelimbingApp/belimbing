<?php

namespace App\Base\Foundation\Providers;

use App\Base\Foundation\Services\DomainState;
use App\Base\Support\AppPath;
use App\Base\Support\Str;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ProviderRegistry
{
    /**
     * Resolve the application service provider list.
     *
     * Every provider is discovered from its owning component — there is no
     * app-level escape hatch, because a provider that does not belong to a
     * Base component, a module, or an extension does not belong anywhere.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function resolve(): array
    {
        // Ordering is part of the framework contract:
        // Base infrastructure -> business modules -> extensions.
        // This keeps bootstrapping deterministic and prevents subtle dependency breakage.
        return array_merge(
            self::discoverBaseProviders(),
            self::discoverModuleProviders(),
            self::discoverExtensionProviders(),
        );
    }

    /**
     * Discover module service providers from app/Modules.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverModuleProviders(): array
    {
        $paths = DomainState::filterPaths(glob(app_path('Modules/*/*/ServiceProvider.php')) ?: []);

        sort($paths);

        $providers = [];
        foreach ($paths as $path) {
            $providers[] = AppPath::toClass($path)
                ?? throw new InvalidArgumentException("Expected app path under app/: [$path].");
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
            $providers[] = AppPath::toClass($path)
                ?? throw new InvalidArgumentException("Expected app path under app/: [$path].");
        }

        return self::validateProviders($providers);
    }

    /**
     * Discover extension service providers from extensions/.
     *
     * Extensions follow the two-level {owner}/{module}/ layout and load
     * after Base and Module providers so they can safely override core bindings.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverExtensionProviders(): array
    {
        $pattern = base_path('extensions/*/*/ServiceProvider.php');
        $paths = glob($pattern) ?: [];

        sort($paths);

        $providers = [];
        foreach ($paths as $path) {
            $providers[] = self::extensionClassFromPath($path);
        }

        return self::validateProviders($providers);
    }

    /**
     * Convert an extension path into a fully-qualified class name.
     *
     * Maps `extensions/sb-group/qac/ServiceProvider.php` to `Extensions\SbGroup\Qac\ServiceProvider`.
     */
    private static function extensionClassFromPath(string $path): string
    {
        $basePath = rtrim(self::normalizePathSeparators(base_path('extensions')), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relativePath = str_replace($basePath, '', self::normalizePathSeparators($path));

        $segments = explode(DIRECTORY_SEPARATOR, str_replace('.php', '', $relativePath));
        $classSegments = array_map(Str::kebabToPascal(...), $segments);

        return 'Extensions\\'.implode('\\', $classSegments);
    }

    /**
     * Normalize path separators so discovery works across slash styles.
     */
    private static function normalizePathSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
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
