<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\Models\MediaAsset;
use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;
use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Produces a BLB-owned `background_removed` derivative of an original media
 * asset via the configured {@see PhotoCleanupProvider}. The original asset is
 * never modified; re-running a provider replaces only that provider's
 * derivative.
 */
class PhotoCleanupService
{
    public function __construct(
        private readonly MediaAssetStore $mediaAssets,
        private readonly PhotoCleanupProvider $provider,
    ) {}

    public function clean(MediaAsset $original, ?int $companyId = null): MediaAsset
    {
        if ($original->disk === MediaAsset::DISK_EXTERNAL) {
            throw PhotoCleanupException::sourceNotStored();
        }

        $bytes = Storage::disk($original->disk)->get($original->storage_key);

        if ($bytes === null || $bytes === '') {
            throw PhotoCleanupException::sourceUnreadable();
        }

        $filename = (string) ($original->original_filename ?? 'photo-'.$original->id.'.jpg');
        $mimeType = (string) ($original->mime_type ?? 'image/jpeg');

        $result = $this->provider->removeBackground($bytes, $filename, $mimeType, $companyId);
        $providerKey = Str::slug($result['provider']) ?: 'provider';

        $existing = $original->backgroundRemovedDerivatives()
            ->get()
            ->first(fn (MediaAsset $asset): bool => data_get($asset->metadata, 'provider') === $result['provider']);

        if ($existing instanceof MediaAsset) {
            $this->mediaAssets->delete($existing);
        }

        $storageKey = Str::beforeLast($original->storage_key, '.').'.'.$providerKey.'.background_removed.png';

        return $this->mediaAssets->putDerivativeBytes(
            $original,
            MediaAsset::KIND_BACKGROUND_REMOVED,
            $original->disk,
            $storageKey,
            $result['bytes'],
            [
                'original_filename' => Str::beforeLast($filename, '.').'.background_removed.png',
                'mime_type' => 'image/png',
                'metadata' => [
                    'provider' => $result['provider'],
                    'provider_label' => $result['provider_label'],
                    'source_asset_id' => $original->id,
                    'status' => 'ready',
                    'cleaned_at' => now()->toIso8601String(),
                ],
            ],
        );
    }
}
