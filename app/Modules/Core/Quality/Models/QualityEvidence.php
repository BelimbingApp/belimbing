<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

use App\Base\Media\Models\MediaAsset;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Typed evidence attachment linked to an NCR, CAPA, or SCAR.
 *
 * @property int $id
 * @property string $evidenceable_type
 * @property int $evidenceable_id
 * @property string $evidence_type
 * @property int $media_asset_id
 * @property bool $is_primary
 * @property int|null $uploaded_by_user_id
 * @property Carbon $uploaded_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $evidenceable
 * @property-read MediaAsset $mediaAsset
 * @property-read User|null $uploadedByUser
 */
class QualityEvidence extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'quality_evidence';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'evidenceable_type',
        'evidenceable_id',
        'evidence_type',
        'media_asset_id',
        'is_primary',
        'uploaded_by_user_id',
        'uploaded_at',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'uploaded_at' => 'datetime',
            'metadata' => 'json',
        ];
    }

    /**
     * Get the parent evidenceable model (NCR, CAPA, or SCAR).
     */
    public function evidenceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the underlying stored asset for this evidence attachment.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    /**
     * Get the user who uploaded this evidence.
     */
    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
