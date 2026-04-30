<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Media\Services;

use App\Base\Media\Exceptions\MediaStorageException;
use App\Base\Media\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Storage abstraction over the {@see MediaAsset} table.
 *
 * Two layers:
 * - High-level put/delete methods own the bytes-on-disk side and return or
 *   accept a {@see MediaAsset}. Consumers should reach for these by default.
 * - Low-level store methods accept a (disk, storage_key) pair when the
 *   caller has already produced the file bytes elsewhere.
 */
class MediaAssetStore
{
    /**
     * Persist an uploaded file to disk and register it as an original asset.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function putUploadedFile(string $disk, string $directory, UploadedFile $file, ?array $metadata = null): MediaAsset
    {
        $this->guardNotEmpty('disk', $disk);
        $this->guardNotEmpty('directory', $directory);

        $filename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();

        $storageKey = $file->store($directory, ['disk' => $disk]);

        if ($storageKey === false) {
            throw MediaStorageException::storeFailed($disk, $directory);
        }

        return $this->storeOriginal($disk, $storageKey, [
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Persist derivative bytes to disk and register the row linked to a parent.
     *
     * @param  array<string, mixed>  $attributes  Optional overrides — original_filename, mime_type, file_size, metadata.
     */
    public function putDerivativeBytes(MediaAsset $parent, string $kind, string $disk, string $storageKey, string $bytes, array $attributes = []): MediaAsset
    {
        $this->guardNotEmpty('disk', $disk);
        $this->guardNotEmpty('storage_key', $storageKey);

        if (! Storage::disk($disk)->put($storageKey, $bytes)) {
            throw MediaStorageException::storeFailed($disk, $storageKey);
        }

        return $this->storeDerivative($parent, $kind, $disk, $storageKey, [
            'original_filename' => $attributes['original_filename'] ?? null,
            'mime_type' => $attributes['mime_type'] ?? null,
            'file_size' => $attributes['file_size'] ?? strlen($bytes),
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }

    /**
     * Delete an asset's row and its file bytes. The row delete cascades any
     * derivative rows; their files remain the caller's responsibility for now.
     */
    public function delete(MediaAsset $asset): void
    {
        $disk = $asset->disk;
        $storageKey = $asset->storage_key;

        DB::transaction(function () use ($asset, $disk, $storageKey): void {
            $asset->delete();
            Storage::disk($disk)->delete($storageKey);
        });
    }

    /**
     * Register an original asset whose bytes are already on disk.
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
     * Register a derivative asset whose bytes are already on disk.
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
