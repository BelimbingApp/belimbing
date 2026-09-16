<?php

namespace App\Base\Media\Models;

use App\Base\Authz\Enums\PrincipalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-owned, authorized binding between private bytes and a business subject.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $media_asset_id
 * @property string $subject_type
 * @property int|string $subject_id
 * @property string $state
 * @property string $uploaded_by_type
 * @property int $uploaded_by_id
 * @property Carbon|null $submitted_at
 * @property-read MediaAsset $mediaAsset
 * @property-read Model $subject
 */
class MediaAttachment extends Model
{
    public const STATE_DRAFT = 'draft';

    public const STATE_SUBMITTED = 'submitted';

    protected $table = 'base_media_attachments';

    protected $fillable = [
        'public_id',
        'tenant_id',
        'media_asset_id',
        'subject_type',
        'subject_id',
        'state',
        'uploaded_by_type',
        'uploaded_by_id',
        'submitted_at',
        'retained_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $attachment): void {
            if ($attachment->getOriginal('state') !== self::STATE_SUBMITTED) {
                return;
            }

            $immutable = [
                'public_id', 'tenant_id', 'media_asset_id', 'subject_type', 'subject_id',
                'state', 'uploaded_by_type', 'uploaded_by_id', 'submitted_at',
                'retained_at',
            ];

            if ($attachment->isDirty($immutable)) {
                throw new \LogicException('Submitted attachment references are immutable.');
            }
        });

        static::deleting(function (self $attachment): void {
            if ($attachment->state === self::STATE_SUBMITTED) {
                throw new \LogicException('Submitted attachment references are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'uploaded_by_id' => 'integer',
            'uploaded_by_type' => PrincipalType::class,
            'submitted_at' => 'datetime',
            'retained_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSubmitted(): bool
    {
        return $this->state === self::STATE_SUBMITTED;
    }
}
