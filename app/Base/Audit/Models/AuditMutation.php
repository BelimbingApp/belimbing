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
     * Disable updated_at since this table is append-only.
     */
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
        'event',
        'old_values',
        'new_values',
        'correlation_id',
        'occurred_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'occurred_at' => 'datetime',
    ];
}
