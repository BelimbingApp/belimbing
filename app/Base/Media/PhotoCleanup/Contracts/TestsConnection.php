<?php

namespace App\Base\Media\PhotoCleanup\Contracts;

use App\Base\Media\PhotoCleanup\PhotoCleanupService;

/**
 * Optional connectivity handshake for a {@see PhotoCleanupProvider}.
 *
 * A provider whose stored credentials can be verified cheaply implements this
 * contract so the operator surface can offer a "Test connection" affordance
 * without running a real cleanup. Providers without a cheap read or probe
 * endpoint (Claid, Poof today) simply do not implement it — their `Ready`
 * state follows from a real cleanup run instead.
 *
 * The means is provider-specific and stays honest: PhotoRoom verifies a
 * production key with a no-image `GET /v2/account` read, but a sandbox key has
 * no account state, so it is verified by a minimal probe edit on the same
 * cleanup endpoint instead. The handshake is a provider/selection concern.
 * {@see PhotoCleanupService} never depends on this contract, so the engine
 * that owns the derivative lifecycle stays sealed. See
 * docs/plans/media-photo-cleanup-providers.md.
 */
interface TestsConnection
{
    /**
     * The provider key this client tests (e.g. `photoroom`). Lets the dispatch
     * service route a test request to the bound client that actually owns the
     * key, without the engine or selection layer importing provider
     * configuration.
     */
    public function providerKey(): string;

    /**
     * Verifies the stored credentials authenticate against the provider. A
     * production key uses a cheap no-image read where the provider exposes one;
     * a sandbox key that has no account state may fall back to a minimal probe
     * edit. The result stays honest about which path was taken.
     *
     * @param  int|null  $companyId  Company scope for the stored key.
     */
    public function testConnection(?int $companyId = null): ConnectionTestResult;
}
