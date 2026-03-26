<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Tracked action item with owner and deadline, linked to an NCR.
 *
 * @property int $id
 * @property int $ncr_id
 * @property string|null $actionable_type
 * @property int|null $actionable_id
 * @property int|null $assigned_to_user_id
 * @property int|null $created_by_user_id
 * @property string $title
 * @property string|null $description
 * @property string|null $status
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ncr $ncr
 * @property-read Model|null $actionable
 * @property-read User|null $assignedToUser
 * @property-read User|null $createdByUser
 */
class QualityActionItem extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'quality_action_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ncr_id',
        'actionable_type',
        'actionable_id',
        'assigned_to_user_id',
        'created_by_user_id',
        'title',
        'description',
        'status',
        'due_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the NCR that this action item belongs to.
     */
    public function ncr(): BelongsTo
    {
        return $this->belongsTo(Ncr::class);
    }

    /**
     * Get the actionable model (polymorphic).
     */
    public function actionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user this action item is assigned to.
     */
    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Get the user who created this action item.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
