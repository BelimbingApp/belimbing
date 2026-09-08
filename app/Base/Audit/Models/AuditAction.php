<?php

namespace App\Base\Audit\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $id
 * @property int|null $company_id
 * @property int|null $tenant_id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_role
 * @property string|null $ip_address
 * @property string|null $url
 * @property string|null $user_agent
 * @property string|null $event
 * @property array<string, mixed>|null $payload
 * @property string|null $trace_id
 * @property bool|null $is_retained
 * @property CarbonInterface|null $occurred_at
 * @property string|null $actor_name
 */
class AuditAction extends Model
{
    use MassPrunable;

    /**
     * Default retention period in days.
     */
    private const RETENTION_DAYS = 90;

    /**
     * @var string
     */
    protected $table = 'base_audit_actions';

    /**
     * Disable Eloquent timestamps since this table stores only event time.
     */
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
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
        'event',
        'payload',
        'trace_id',
        'occurred_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'is_retained' => 'boolean',
        'occurred_at' => 'datetime',
    ];

    /**
     * Prune action logs older than the configured retention period.
     *
     * Rows marked as retained are never pruned.
     */
    public function prunable(): Builder
    {
        $days = (int) config('audit.action_retention_days', self::RETENTION_DAYS);

        return static::query()
            ->where('occurred_at', '<', now()->subDays($days))
            ->where('is_retained', false);
    }
}
