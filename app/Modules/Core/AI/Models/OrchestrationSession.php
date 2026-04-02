<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\OrchestrationSessionStatus;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Orchestration Session — bounded child execution context with lineage.
 *
 * Represents a spawned child agent session with explicit parent lineage,
 * bounded scope, and lifecycle tracking. Distinct from OperationDispatch:
 * dispatches track async queue work, while orchestration sessions model
 * conversational execution contexts with parent-child relationships.
 *
 * @property string $id
 * @property string|null $parent_session_id
 * @property string|null $parent_run_id
 * @property string|null $parent_dispatch_id
 * @property int $parent_employee_id
 * @property int $child_employee_id
 * @property int|null $acting_for_user_id
 * @property string $task
 * @property string|null $task_type
 * @property OrchestrationSessionStatus $status
 * @property array<string, mixed>|null $spawn_envelope
 * @property string|null $result_summary
 * @property array<string, mixed>|null $result_meta
 * @property string|null $error_message
 * @property int $depth
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee|null $parentEmployee
 * @property-read Employee|null $childEmployee
 * @property-read OrchestrationSession|null $parentSession
 */
class OrchestrationSession extends Model
{
    /**
     * Prefix for orchestration session IDs.
     */
    public const ID_PREFIX = 'orch_';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var string
     */
    protected $table = 'ai_orchestration_sessions';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'parent_session_id',
        'parent_run_id',
        'parent_dispatch_id',
        'parent_employee_id',
        'child_employee_id',
        'acting_for_user_id',
        'task',
        'task_type',
        'status',
        'spawn_envelope',
        'result_summary',
        'result_meta',
        'error_message',
        'depth',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrchestrationSessionStatus::class,
            'spawn_envelope' => 'json',
            'result_meta' => 'json',
            'depth' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The agent that spawned this child session.
     */
    public function parentEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'parent_employee_id');
    }

    /**
     * The agent executing this child session.
     */
    public function childEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'child_employee_id');
    }

    /**
     * The parent orchestration session (if this is a nested child).
     */
    public function parentSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_session_id');
    }

    /**
     * Whether the session has reached a terminal status.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Transition to running status.
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => OrchestrationSessionStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * Transition to completed status with a result.
     *
     * @param  string  $resultSummary  Human-readable result
     * @param  array<string, mixed>  $resultMeta  Structured result metadata
     */
    public function markCompleted(string $resultSummary, array $resultMeta = []): void
    {
        $this->update([
            'status' => OrchestrationSessionStatus::Completed,
            'result_summary' => $resultSummary,
            'result_meta' => $resultMeta !== [] ? $resultMeta : null,
            'finished_at' => now(),
        ]);
    }

    /**
     * Transition to failed status.
     */
    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => OrchestrationSessionStatus::Failed,
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);
    }

    /**
     * Transition to cancelled status.
     */
    public function markCancelled(): void
    {
        $this->update([
            'status' => OrchestrationSessionStatus::Cancelled,
            'finished_at' => now(),
        ]);
    }
}
