<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Media\Services;

use App\Base\Media\Models\MediaAsset;
use InvalidArgumentException;

/**
 * Row-level registry for stored media assets.
 *
 * The store owns the durable {@see MediaAsset} record (disk, storage key,
 * derived-asset linkage, metadata). Callers remain responsible for writing
 * and removing the actual file bytes on the chosen disk; this keeps the
 * service usable from any consumer regardless of how it produced the file.
 */
class MediaAssetStore
{
    /**
     * Register a freshly stored original asset.
     *
     * @param  array<string, mixed>  $attributes  Optional overrides — original_filename, mime_type, file_size, metadata.
     */
    public function storeOriginal(string $disk, string $storageKey, array $attributes = []): MediaAsset
    {
        $this->guardNotEmpty('disk', $disk);
        $this->guardNotEmpty('storage_key', $storageKey);

        return MediaAsset::query()->create([
            'parent_id' => null,
            'disk' => $disk,
            'storage_key' => $storageKey,
            'kind' => MediaAsset::KIND_ORIGINAL,
            'original_filename' => $attributes['original_filename'] ?? null,
            'mime_type' => $attributes['mime_type'] ?? null,
            'file_size' => $attributes['file_size'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }

    /**
     * Register a derivative asset linked to a parent original.
     *
     * @param  array<string, mixed>  $attributes  Optional overrides — original_filename, mime_type, file_size, metadata.
     */
    public function storeDerivative(MediaAsset $parent, string $kind, string $disk, string $storageKey, array $attributes = []): MediaAsset
    {
        $this->guardNotEmpty('kind', $kind);
        $this->guardNotEmpty('disk', $disk);
        $this->guardNotEmpty('storage_key', $storageKey);

        if ($kind === MediaAsset::KIND_ORIGINAL) {
            throw new InvalidArgumentException('Derivative kind must not be "original"; use storeOriginal() for raw assets.');
        }

        return MediaAsset::query()->create([
            'parent_id' => $parent->id,
            'disk' => $disk,
            'storage_key' => $storageKey,
            'kind' => $kind,
            'original_filename' => $attributes['original_filename'] ?? null,
            'mime_type' => $attributes['mime_type'] ?? null,
            'file_size' => $attributes['file_size'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }

    private function guardNotEmpty(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("MediaAsset {$field} must be a non-empty string.");
        }
    }
}
