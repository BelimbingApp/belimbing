<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Operation Dispatch — unified ledger for all AI async operations.
 *
 * Tracks agent tasks, scheduled task executions, background artisan
 * commands, and any other durable asynchronous work through a single
 * lifecycle model (queued → running → succeeded/failed/cancelled).
 *
 * Uses a polymorphic entity relationship so dispatches can reference
 * any domain object (IT tickets, QAC cases, etc.) without cross-module
 * foreign key constraints.
 *
 * @property string $id
 * @property OperationType $operation_type
 * @property int|null $employee_id
 * @property int|null $acting_for_user_id
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string $task
 * @property OperationStatus $status
 * @property string|null $run_id
 * @property string|null $result_summary
 * @property string|null $error_message
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee|null $employee
 * @property-read User|null $actingForUser
 * @property-read Model|null $entity
 */
class OperationDispatch extends Model
{
    /**
     * Prefix for operation dispatch IDs.
     */
    public const ID_PREFIX = 'op_';

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
    protected $table = 'ai_operation_dispatches';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'operation_type',
        'employee_id',
        'acting_for_user_id',
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
            'operation_type' => OperationType::class,
            'status' => OperationStatus::class,
            'meta' => 'json',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the agent (employee) assigned to execute this operation.
     *
     * Null for operations that do not target an agent (e.g., background commands).
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user on whose behalf this operation is acting.
     *
     * Null for system-initiated operations (cron, webhook, scheduled).
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
        return $this->status->isTerminal();
    }

    /**
     * Transition the dispatch to running status.
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => OperationStatus::Running,
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
            'status' => OperationStatus::Succeeded,
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
            'status' => OperationStatus::Failed,
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
            'status' => OperationStatus::Cancelled,
            'finished_at' => now(),
        ]);
    }
}
