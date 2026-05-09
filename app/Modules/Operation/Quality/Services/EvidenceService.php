<?php
namespace App\Modules\Operation\Quality\Services;

use App\Base\Media\Services\MediaAssetStore;
use App\Modules\Operation\Quality\Models\QualityEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            $asset = $this->mediaAssets->putUploadedFile(self::STORAGE_DISK, self::STORAGE_PREFIX, $file);

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
     * Stores the new file first so the old asset is preserved if the upload
     * fails, then swaps the pointer and removes the old asset.
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

            $newAsset = $this->mediaAssets->putUploadedFile(self::STORAGE_DISK, self::STORAGE_PREFIX, $file);

            $evidence->update([
                'media_asset_id' => $newAsset->id,
                'uploaded_by_user_id' => $uploadedByUserId,
                'uploaded_at' => Carbon::now(),
            ]);

            $this->mediaAssets->delete($oldAsset);

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

            $evidence->delete();
            $this->mediaAssets->delete($asset);
        });
    }
}
