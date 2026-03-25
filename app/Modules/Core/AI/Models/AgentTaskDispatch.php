<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Agent Task Dispatch — tracks AI agent task executions.
 *
 * Uses a polymorphic entity relationship so dispatches can reference
 * any domain object (IT tickets, QAC cases, etc.) without cross-module
 * foreign key constraints.
 *
 * @property string $id
 * @property int $employee_id
 * @property int|null $acting_for_user_id
 * @property string $task_type
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string $task
 * @property string $status
 * @property string|null $run_id
 * @property string|null $result_summary
 * @property string|null $error_message
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read User|null $actingForUser
 * @property-read Model|null $entity
 */
class AgentTaskDispatch extends Model
{
    /**
     * Terminal statuses that indicate the dispatch is complete.
     */
    private const TERMINAL_STATUSES = ['succeeded', 'failed', 'cancelled'];

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_agent_task_dispatches';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'employee_id',
        'acting_for_user_id',
        'task_type',
        'entity_type',
        'entity_id',
        'task',
        'status',
        'run_id',
        'result_summary',
        'error_message',
        'meta',
        'started_at',
        'finished_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'json',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the agent (employee) assigned to execute this task.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user on whose behalf this task is acting.
     *
     * Null for system-initiated tasks (cron, webhook, scheduled).
     */
    public function actingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_user_id');
    }

    /**
     * Get the associated domain entity (ticket, QAC case, etc.).
     *
     * Uses Laravel's polymorphic relationship. Entity types should be
     * registered in the morph map via Relation::morphMap().
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine whether the dispatch has reached a terminal status.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Transition the dispatch to running status.
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Transition the dispatch to succeeded status.
     *
     * @param  string  $runId  External run identifier
     * @param  string  $resultSummary  Human-readable result summary
     * @param  array<string, mixed>  $runtimeMeta  Additional metadata to merge
     */
    public function markSucceeded(string $runId, string $resultSummary, array $runtimeMeta = []): void
    {
        $meta = array_merge($this->meta ?? [], $runtimeMeta);

        $this->update([
            'status' => 'succeeded',
            'run_id' => $runId,
            'result_summary' => $resultSummary,
            'meta' => $meta,
            'finished_at' => now(),
        ]);
    }

    /**
     * Transition the dispatch to failed status.
     *
     * @param  string  $errorMessage  Description of the failure
     */
    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);
    }

    /**
     * Transition the dispatch to cancelled status.
     */
    public function markCancelled(): void
    {
        $this->update([
            'status' => 'cancelled',
            'finished_at' => now(),
        ]);
    }
}
