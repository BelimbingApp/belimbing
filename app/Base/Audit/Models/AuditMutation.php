<?php

namespace App\Base\Audit\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $id
 * @property int|null $company_id
 * @property int|null $tenant_id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_role
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property string|null $subject_name
 * @property string|null $subject_id
 * @property string|null $subject_identifier
 * @property string|null $source
 * @property string|null $event
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $trace_id
 * @property CarbonInterface|null $occurred_at
 * @property string|null $actor_name
 */
class AuditMutation extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_audit_mutations';

    /**
     * Disable Eloquent timestamps since this table stores only event time.
     */
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'tenant_id',
        'actor_type',
        'actor_id',
        'actor_role',
        'ip_address',
        'url',
        'user_agent',
        'auditable_type',
        'auditable_id',
        'subject_name',
        'subject_id',
        'subject_identifier',
        'source',
        'event',
        'old_values',
        'new_values',
        'trace_id',
        'occurred_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'auditable_id' => 'string',
        'subject_id' => 'string',
        'occurred_at' => 'datetime',
    ];
}
