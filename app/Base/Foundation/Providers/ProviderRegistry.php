<?php

namespace App\Base\Foundation\Providers;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Foundation\Services\DomainState;
use App\Base\Support\AppPath;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class ProviderRegistry
{
    /**
     * Resolve the application service provider list.
     *
     * Every provider is discovered from its owning component — there is no
     * app-level escape hatch, because a provider that does not belong to a
     * Base component, Core/Domain module, or Extension does not belong anywhere.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function resolve(): array
    {
        // Ordering is part of the framework contract:
        // Base infrastructure -> Core -> enabled Domains -> Extensions.
        // This keeps bootstrapping deterministic and prevents subtle dependency breakage.
        return array_merge(
            self::discoverBaseProviders(),
            self::discoverCoreProviders(),
            self::discoverDomainProviders(),
            self::discoverExtensionProviders(),
        );
    }

    /**
     * Discover platform-owned Core module providers.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverCoreProviders(): array
    {
        return self::providersAt(ApplicationTopology::coreModulePattern('ServiceProvider.php'));
    }

    /**
     * Discover providers from enabled optional Domains.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverDomainProviders(): array
    {
        $paths = DomainState::filterPaths(
            glob(ApplicationTopology::domainModulePattern('ServiceProvider.php')) ?: [],
        );

        sort($paths);

        return self::providersFromPaths($paths);
    }

    /**
     * Discover Core and enabled Domain providers as one module list.
     *
     * Retained for introspection callers that reason about modules rather than
     * provider ordering. Runtime boot uses the explicit methods above.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverModuleProviders(): array
    {
        return array_merge(self::discoverCoreProviders(), self::discoverDomainProviders());
    }

    /**
     * Discover Base service providers from app/Base.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverBaseProviders(): array
    {
        return self::providersAt(ApplicationTopology::baseComponentPattern('ServiceProvider.php'));
    }

    /**
     * Discover installed Extension module providers.
     *
     * Extensions load last so explicit contribution seams may decorate the
     * platform. They must not rely on accidental provider ordering.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public static function discoverExtensionProviders(): array
    {
        return self::providersAt(ApplicationTopology::extensionModulePattern('ServiceProvider.php'));
    }

    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    private static function providersAt(string $pattern): array
    {
        $paths = glob($pattern) ?: [];
        sort($paths);

        return self::providersFromPaths($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return array<int, class-string<ServiceProvider>>
     *
     */
    private static function providersFromPaths(array $paths): array
    {
        $providers = [];
        foreach ($paths as $path) {
            $providers[] = AppPath::toClass($path)
                ?? throw new InvalidArgumentException("Expected app path under app/: [$path].");
        }

        return self::validateProviders($providers);
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
