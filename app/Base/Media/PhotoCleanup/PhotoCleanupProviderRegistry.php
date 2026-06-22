<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves a {@see PhotoCleanupProvider} adapter by provider key. Each shipped
 * adapter registers here; the engine stays sealed — it depends on the
 * {@see PhotoCleanupProvider} contract, never on a concrete client or on this
 * registry. Selection ({@see PhotoCleanupSelection}) reads this registry to
 * turn the operator's chosen provider key into a runnable adapter, and the
 * handshake tester ({@see PhotoCleanupConnectionTester}) routes a "Test
 * connection" request to the adapter that owns the key.
 *
 * Adding a provider is "ship a client + register its key here", not "edit the
 * engine". See docs/plans/media-photo-cleanup-providers.md.
 */
class PhotoCleanupProviderRegistry
{
    /**
     * Provider key → adapter class. Each class is resolved through the
     * container so its own dependencies (configuration, integration gateway)
     * inject. Keep this map the single source of truth for which providers have
     * a working cleanup client — `ImageProviderFamily` reads `supports()` to
     * decide `Ready` vs `Key stored`.
     *
     * @var array<string, class-string<PhotoCleanupProvider>>
     */
    private const ADAPTERS = [
        PhotoRoomConfiguration::PROVIDER => PhotoRoomClient::class,
        PoofConfiguration::PROVIDER => PoofClient::class,
    ];

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * All registered provider keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::ADAPTERS);
    }

    public function supports(string $providerKey): bool
    {
        return isset(self::ADAPTERS[$providerKey]);
    }

    /**
     * Resolve the adapter for a key, or null if no adapter is registered.
     */
    public function adapter(string $providerKey): ?PhotoCleanupProvider
    {
        return isset(self::ADAPTERS[$providerKey])
            ? $this->app->make(self::ADAPTERS[$providerKey])
            : null;
    }
}
