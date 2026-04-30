<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Services;

use App\Base\Media\Services\MediaAssetStore;
use App\Modules\Core\Quality\Exceptions\EvidenceStorageException;
use App\Modules\Core\Quality\Models\QualityEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Domain service for quality evidence file operations.
 *
 * Handles upload, replacement, and archival of typed evidence
 * attachments linked to NCR, CAPA, or SCAR records.
 */
class EvidenceService
{
    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'quality/evidence';

    public function __construct(private readonly MediaAssetStore $mediaAssets) {}

    /**
     * Upload an evidence file and create the QualityEvidence record.
     *
     * @param  Model  $evidenceable  The parent model (Ncr, Capa, or Scar)
     * @param  UploadedFile  $file  The uploaded file
     * @param  string  $evidenceType  Evidence type code from config('quality.evidence_types')
     * @param  int|null  $uploadedByUserId  The user who uploaded the file
     * @param  array{is_primary?: bool, metadata?: array<string, mixed>|null}  $options
     */
    public function upload(
        Model $evidenceable,
        UploadedFile $file,
        string $evidenceType,
        ?int $uploadedByUserId = null,
        array $options = [],
    ): QualityEvidence {
        return DB::transaction(function () use ($evidenceable, $file, $evidenceType, $uploadedByUserId, $options): QualityEvidence {
            $storageKey = $file->store(self::STORAGE_PREFIX, ['disk' => self::STORAGE_DISK]);

            if ($storageKey === false) {
                throw EvidenceStorageException::storeFailed();
            }

            $asset = $this->mediaAssets->storeOriginal(self::STORAGE_DISK, $storageKey, [
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);

            return QualityEvidence::query()->create([
                'evidenceable_type' => $evidenceable->getMorphClass(),
                'evidenceable_id' => $evidenceable->getKey(),
                'evidence_type' => $evidenceType,
                'media_asset_id' => $asset->id,
                'is_primary' => $options['is_primary'] ?? false,
                'uploaded_by_user_id' => $uploadedByUserId,
                'uploaded_at' => Carbon::now(),
                'metadata' => $options['metadata'] ?? null,
            ]);
        });
    }

    /**
     * Replace an existing evidence file with a new one.
     *
     * Deletes the old file from storage and updates the record.
     *
     * @param  QualityEvidence  $evidence  The evidence record to replace
     * @param  UploadedFile  $file  The replacement file
     * @param  int|null  $uploadedByUserId  The user replacing the file
     */
    public function replace(
        QualityEvidence $evidence,
        UploadedFile $file,
        ?int $uploadedByUserId = null,
    ): QualityEvidence {
        return DB::transaction(function () use ($evidence, $file, $uploadedByUserId): QualityEvidence {
            $oldAsset = $evidence->mediaAsset;
            $oldDisk = $oldAsset->disk;
            $oldStorageKey = $oldAsset->storage_key;

            $storageKey = $file->store(self::STORAGE_PREFIX, ['disk' => self::STORAGE_DISK]);

            if ($storageKey === false) {
                throw EvidenceStorageException::storeFailed();
            }

            $asset = $this->mediaAssets->storeOriginal(self::STORAGE_DISK, $storageKey, [
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);

            $evidence->update([
                'media_asset_id' => $asset->id,
                'uploaded_by_user_id' => $uploadedByUserId,
                'uploaded_at' => Carbon::now(),
            ]);

            $oldAsset->delete();
            Storage::disk($oldDisk)->delete($oldStorageKey);

            return $evidence;
        });
    }

    /**
     * Archive (soft-delete) an evidence record and remove its file from storage.
     *
     * @param  QualityEvidence  $evidence  The evidence record to archive
     */
    public function archive(QualityEvidence $evidence): void
    {
        DB::transaction(function () use ($evidence): void {
            $asset = $evidence->mediaAsset;
            $disk = $asset->disk;
            $storageKey = $asset->storage_key;

            $evidence->delete();
            $asset->delete();

            Storage::disk($disk)->delete($storageKey);
        });
    }
}
