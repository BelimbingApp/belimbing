<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

use App\Base\Workflow\Concerns\HasWorkflowStatus;
use App\Modules\Core\Quality\Database\Factories\ScarFactory;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * SCAR — Supplier Corrective Action Request.
 *
 * A formal supplier-facing corrective-action workflow linked to an NCR.
 * Operates as an independent workflow participant with its own statuses.
 *
 * @property int $id
 * @property int $ncr_id
 * @property string $scar_no
 * @property string $status
 * @property string $supplier_name
 * @property string|null $supplier_site
 * @property string|null $supplier_contact_name
 * @property string|null $supplier_contact_email
 * @property string|null $supplier_contact_phone
 * @property string|null $po_do_invoice_no
 * @property string|null $product_name
 * @property string|null $product_code
 * @property string|null $detected_area
 * @property string|null $issued_by
 * @property Carbon|null $issuing_date
 * @property string|null $request_type
 * @property string|null $severity
 * @property string|null $claim_quantity
 * @property string|null $uom
 * @property string|null $claim_value
 * @property string|null $problem_description
 * @property int|null $issue_owner_user_id
 * @property Carbon|null $acknowledgement_due_at
 * @property Carbon|null $containment_due_at
 * @property Carbon|null $response_due_at
 * @property Carbon|null $verification_due_at
 * @property string|null $containment_response
 * @property string|null $root_cause_response
 * @property string|null $corrective_action_response
 * @property Carbon|null $supplier_response_submitted_at
 * @property string|null $commercial_resolution_type
 * @property string|null $commercial_resolution_amount
 * @property Carbon|null $commercial_resolution_at
 * @property int|null $verified_by_user_id
 * @property Carbon|null $verified_at
 * @property int|null $closed_by_user_id
 * @property Carbon|null $closed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ncr $ncr
 * @property-read User|null $issueOwner
 * @property-read User|null $verifiedByUser
 * @property-read User|null $closedByUser
 * @property-read Collection<int, QualityEvidence> $evidence
 * @property-read Collection<int, QualityEvent> $events
 */
class Scar extends Model
{
    use HasFactory, HasWorkflowStatus;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'quality_scars';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ncr_id',
        'scar_no',
        'status',
        'supplier_name',
        'supplier_site',
        'supplier_contact_name',
        'supplier_contact_email',
        'supplier_contact_phone',
        'po_do_invoice_no',
        'product_name',
        'product_code',
        'detected_area',
        'issued_by',
        'issuing_date',
        'request_type',
        'severity',
        'claim_quantity',
        'uom',
        'claim_value',
        'problem_description',
        'issue_owner_user_id',
        'acknowledgement_due_at',
        'containment_due_at',
        'response_due_at',
        'verification_due_at',
        'containment_response',
        'root_cause_response',
        'corrective_action_response',
        'supplier_response_submitted_at',
        'commercial_resolution_type',
        'commercial_resolution_amount',
        'commercial_resolution_at',
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
            'claim_quantity' => 'decimal:4',
            'claim_value' => 'decimal:2',
            'issuing_date' => 'date',
            'acknowledgement_due_at' => 'datetime',
            'containment_due_at' => 'datetime',
            'response_due_at' => 'datetime',
            'verification_due_at' => 'datetime',
            'supplier_response_submitted_at' => 'datetime',
            'commercial_resolution_amount' => 'decimal:2',
            'commercial_resolution_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'json',
        ];
    }

    /**
     * Return the flow identifier for this model.
     */
    public function flow(): string
    {
        return 'quality_scar';
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ScarFactory
    {
        return new ScarFactory;
    }

    /**
     * Get the NCR that this SCAR is linked to.
     */
    public function ncr(): BelongsTo
    {
        return $this->belongsTo(Ncr::class);
    }

    /**
     * Get the user who owns this SCAR issue.
     */
    public function issueOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issue_owner_user_id');
    }

    /**
     * Get the user who verified this SCAR.
     */
    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Get the user who closed this SCAR.
     */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * Get the evidence attachments for this SCAR.
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(QualityEvidence::class, 'evidenceable');
    }

    /**
     * Get the domain events for this SCAR.
     */
    public function events(): HasMany
    {
        return $this->hasMany(QualityEvent::class, 'scar_id');
    }
}
