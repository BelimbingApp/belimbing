<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

use App\Base\Workflow\Concerns\HasWorkflowStatus;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Quality\Database\Factories\NcrFactory;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * NCR — Nonconformance Report.
 *
 * A logged quality issue: defect, deviation, complaint, or failure
 * requiring investigation and corrective action.
 *
 * @property int $id
 * @property int $company_id
 * @property string $ncr_no
 * @property string $ncr_kind
 * @property string|null $source
 * @property string $status
 * @property string|null $severity
 * @property string|null $classification
 * @property string $title
 * @property string|null $summary
 * @property string|null $product_name
 * @property string|null $product_code
 * @property string|null $quantity_affected
 * @property string|null $uom
 * @property Carbon $reported_at
 * @property string $reported_by_name
 * @property string|null $reported_by_email
 * @property int|null $created_by_user_id
 * @property int|null $current_owner_user_id
 * @property string|null $current_owner_department
 * @property Carbon|null $current_owner_assigned_at
 * @property bool $is_supplier_related
 * @property bool $requires_follow_up
 * @property Carbon|null $follow_up_completed_at
 * @property string|null $reject_reason
 * @property Carbon|null $rejected_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User|null $createdByUser
 * @property-read User|null $currentOwner
 * @property-read Capa|null $capa
 * @property-read Collection<int, Scar> $scars
 * @property-read Collection<int, QualityEvidence> $evidence
 * @property-read Collection<int, QualityEvent> $events
 * @property-read Collection<int, QualityActionItem> $actionItems
 */
class Ncr extends Model
{
    use HasFactory, HasWorkflowStatus;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'quality_ncrs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'ncr_no',
        'ncr_kind',
        'source',
        'status',
        'severity',
        'classification',
        'title',
        'summary',
        'product_name',
        'product_code',
        'quantity_affected',
        'uom',
        'reported_at',
        'reported_by_name',
        'reported_by_email',
        'created_by_user_id',
        'current_owner_user_id',
        'current_owner_department',
        'current_owner_assigned_at',
        'is_supplier_related',
        'requires_follow_up',
        'follow_up_completed_at',
        'reject_reason',
        'rejected_at',
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
            'quantity_affected' => 'decimal:4',
            'reported_at' => 'datetime',
            'current_owner_assigned_at' => 'datetime',
            'follow_up_completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'metadata' => 'json',
            'is_supplier_related' => 'boolean',
            'requires_follow_up' => 'boolean',
        ];
    }

    /**
     * Return the flow identifier for this model.
     */
    public function flow(): string
    {
        return 'quality_ncr';
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NcrFactory
    {
        return new NcrFactory;
    }

    /**
     * Get the company that owns this NCR.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user who created this NCR.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user currently owning this NCR.
     */
    public function currentOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_owner_user_id');
    }

    /**
     * Get the CAPA record linked to this NCR.
     */
    public function capa(): HasOne
    {
        return $this->hasOne(Capa::class);
    }

    /**
     * Get the SCARs linked to this NCR.
     */
    public function scars(): HasMany
    {
        return $this->hasMany(Scar::class);
    }

    /**
     * Get the evidence attachments for this NCR.
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(QualityEvidence::class, 'evidenceable');
    }

    /**
     * Get the domain events for this NCR.
     */
    public function events(): HasMany
    {
        return $this->hasMany(QualityEvent::class, 'ncr_id');
    }

    /**
     * Get the action items for this NCR.
     */
    public function actionItems(): HasMany
    {
        return $this->hasMany(QualityActionItem::class, 'ncr_id');
    }
}
