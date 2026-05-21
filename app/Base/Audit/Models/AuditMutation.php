<?php

namespace App\Base\Audit\Models;

use Illuminate\Database\Eloquent\Model;

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
        'subject_id' => 'integer',
        'occurred_at' => 'datetime',
    ];
}
