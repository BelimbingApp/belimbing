<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

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
 * @property string $filename
 * @property string $storage_key
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property bool $is_primary
 * @property int|null $uploaded_by_user_id
 * @property Carbon $uploaded_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $evidenceable
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
        'filename',
        'storage_key',
        'mime_type',
        'file_size',
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
            'file_size' => 'integer',
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
     * Get the user who uploaded this evidence.
     */
    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
