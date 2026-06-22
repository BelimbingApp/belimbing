<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\ConnectionTestResult;
use App\Base\Media\PhotoCleanup\Contracts\TestsConnection;

/**
 * Dispatches a "test connection" handshake to the adapter registered for a
 * provider key when that adapter also implements {@see TestsConnection}.
 *
 * Providers without a cheap read or probe endpoint do not implement the
 * contract and get an honest "no handshake available" result; their `Ready`
 * state follows from a real cleanup run. The handshake stays out of
 * {@see PhotoCleanupService} — this is a provider/selection concern, never an
 * engine branch. See docs/plans/media-photo-cleanup-providers.md.
 *
 * Per-provider routing: the {@see PhotoCleanupProviderRegistry} maps a provider
 * key to its bound adapter, so each adapter that implements
 * {@see TestsConnection} becomes testable here without touching the engine or
 * the selection layer.
 */
class PhotoCleanupConnectionTester
{
    public function __construct(
        private readonly PhotoCleanupProviderRegistry $registry,
    ) {}

    /**
     * Whether a "Test connection" affordance is honest for the given provider
     * key — i.e. a registered adapter exists for the key and it implements
     * {@see TestsConnection}.
     */
    public function supports(string $providerKey): bool
    {
        return $this->registry->adapter($providerKey) instanceof TestsConnection;
    }

    public function test(?int $companyId, string $providerKey): ConnectionTestResult
    {
        $adapter = $this->registry->adapter($providerKey);

        if (! $adapter instanceof TestsConnection) {
            return ConnectionTestResult::noHandshake($providerKey);
        }

        return $adapter->testConnection($companyId);
    }
}
