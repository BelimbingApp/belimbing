<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * CAPA — Corrective Action / Preventive Action.
 *
 * The resolution work package for an NCR: triage, assignment,
 * investigation, containment, root cause, corrective action,
 * review, and verification.
 *
 * @property int $id
 * @property int $ncr_id
 * @property string $workflow_status
 * @property string|null $triage_summary
 * @property string|null $triage_confidence
 * @property string|null $assigned_department
 * @property string|null $assigned_supplier_name
 * @property string|null $assignment_comment
 * @property Carbon|null $assignment_due_at
 * @property int|null $assigned_by_user_id
 * @property Carbon|null $assigned_at
 * @property string|null $approval_state
 * @property int|null $approved_by_user_id
 * @property Carbon|null $approved_at
 * @property string|null $rework_reason
 * @property string|null $containment_action
 * @property string|null $correction
 * @property string|null $root_cause_occurred
 * @property string|null $root_cause_leakage
 * @property string|null $corrective_action_occurred
 * @property Carbon|null $effective_date_occurred
 * @property string|null $corrective_action_leakage
 * @property Carbon|null $effective_date_leakage
 * @property string|null $quality_review_comment
 * @property string|null $quality_feedback
 * @property string|null $verification_result
 * @property int|null $verified_by_user_id
 * @property Carbon|null $verified_at
 * @property int|null $closed_by_user_id
 * @property Carbon|null $closed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ncr $ncr
 * @property-read User|null $assignedByUser
 * @property-read User|null $approvedByUser
 * @property-read User|null $verifiedByUser
 * @property-read User|null $closedByUser
 * @property-read Collection<int, QualityEvidence> $evidence
 * @property-read Collection<int, QualityEvent> $events
 */
class Capa extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'quality_capas';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ncr_id',
        'workflow_status',
        'triage_summary',
        'triage_confidence',
        'assigned_department',
        'assigned_supplier_name',
        'assignment_comment',
        'assignment_due_at',
        'assigned_by_user_id',
        'assigned_at',
        'approval_state',
        'approved_by_user_id',
        'approved_at',
        'rework_reason',
        'containment_action',
        'correction',
        'root_cause_occurred',
        'root_cause_leakage',
        'corrective_action_occurred',
        'effective_date_occurred',
        'corrective_action_leakage',
        'effective_date_leakage',
        'quality_review_comment',
        'quality_feedback',
        'verification_result',
        'verified_by_user_id',
        'verified_at',
        'closed_by_user_id',
        'closed_at',
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
            'assignment_due_at' => 'datetime',
            'assigned_at' => 'datetime',
            'approved_at' => 'datetime',
            'effective_date_occurred' => 'date',
            'effective_date_leakage' => 'date',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'json',
        ];
    }

    /**
     * Get the NCR that this CAPA resolves.
     */
    public function ncr(): BelongsTo
    {
        return $this->belongsTo(Ncr::class);
    }

    /**
     * Get the user who assigned this CAPA.
     */
    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /**
     * Get the user who approved this CAPA.
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Get the user who verified this CAPA.
     */
    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Get the user who closed this CAPA.
     */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * Get the evidence attachments for this CAPA.
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(QualityEvidence::class, 'evidenceable');
    }

    /**
     * Get the domain events for this CAPA.
     */
    public function events(): HasMany
    {
        return $this->hasMany(QualityEvent::class, 'capa_id');
    }
}
