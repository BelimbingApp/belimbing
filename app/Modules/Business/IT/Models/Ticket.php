<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Business\IT\Models;

use App\Base\Workflow\Concerns\HasWorkflowStatus;
use App\Modules\Business\IT\Database\Factories\TicketFactory;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * IT Ticket — first business module using the workflow engine.
 *
 * @property int $id
 * @property int $company_id
 * @property int $reporter_id
 * @property int|null $assignee_id
 * @property string $status
 * @property string $priority
 * @property string|null $category
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User $reporter
 * @property-read User|null $assignee
 */
class Ticket extends Model
{
    use HasFactory, HasWorkflowStatus;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'it_tickets';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'reporter_id',
        'assignee_id',
        'status',
        'priority',
        'category',
        'title',
        'description',
        'location',
        'metadata',
        'resolved_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'json',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Return the flow identifier for this model.
     */
    public function flow(): string
    {
        return 'it_ticket';
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TicketFactory
    {
        return new TicketFactory;
    }

    /**
     * Get the company that owns this ticket.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user who reported this ticket.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * Get the user currently assigned to this ticket.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
