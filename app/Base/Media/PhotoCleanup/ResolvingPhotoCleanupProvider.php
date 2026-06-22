<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;

/**
 * Bound as the container's {@see PhotoCleanupProvider}: resolves the active
 * adapter per company through {@see PhotoCleanupSelection} on each call, then
 * delegates. This keeps {@see PhotoCleanupService} sealed — the engine still
 * depends only on the contract and never learns which provider is active, so
 * adding a provider never touches the engine or the derivative lifecycle.
 * Provenance stays attributable because each adapter returns its own
 * `provider` / `provider_label`. See docs/plans/media-photo-cleanup-providers.md.
 */
class ResolvingPhotoCleanupProvider implements PhotoCleanupProvider
{
    public function __construct(
        private readonly PhotoCleanupSelection $selection,
    ) {}

    /**
     * @return array{bytes: string, provider: string, provider_label: string}
     */
    public function removeBackground(string $imageBytes, string $filename, string $mimeType, ?int $companyId = null): array
    {
        return $this->selection
            ->resolveProvider($companyId)
            ->removeBackground($imageBytes, $filename, $mimeType, $companyId);
    }
}
