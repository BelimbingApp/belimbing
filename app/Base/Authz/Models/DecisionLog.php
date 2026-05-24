<?php

namespace App\Base\Authz\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class DecisionLog extends Model
{
    use MassPrunable;

    /**
     * Default retention period in days.
     */
    private const RETENTION_DAYS = 90;

    /**
     * @var string
     */
    protected $table = 'base_authz_decision_logs';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'actor_type',
        'actor_id',
        'acting_for_user_id',
        'capability',
        'resource_type',
        'resource_id',
        'allowed',
        'reason_code',
        'applied_policies',
        'context',
        'trace_id',
        'occurred_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'allowed' => 'boolean',
        'applied_policies' => 'array',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Prune decision logs older than the configured retention period.
     */
    public function prunable(): Builder
    {
        $days = (int) config('authz.decision_log_retention_days', self::RETENTION_DAYS);

        return static::query()->where('occurred_at', '<', now()->subDays($days));
    }
}
