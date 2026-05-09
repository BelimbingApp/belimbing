<?php
namespace App\Base\Audit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

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
        'event',
        'payload',
        'correlation_id',
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
