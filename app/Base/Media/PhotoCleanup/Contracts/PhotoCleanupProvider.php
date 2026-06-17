<?php

namespace App\Base\Media\PhotoCleanup\Contracts;

use App\Base\Media\PhotoCleanup\PhotoCleanupService;

/**
 * Removes the background from an image, returning the cleaned bytes plus the
 * provenance that the {@see PhotoCleanupService}
 * records on the derivative. PhotoRoom is the first implementation; the engine
 * depends on this contract so additional providers register without touching
 * the derivative lifecycle.
 */
interface PhotoCleanupProvider
{
    /**
     * @return array{bytes: string, provider: string, provider_label: string}
     */
    public function removeBackground(
        string $imageBytes,
        string $filename,
        string $mimeType,
        ?int $companyId = null,
    ): array;
}
