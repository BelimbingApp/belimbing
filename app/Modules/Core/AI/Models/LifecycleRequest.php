<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Enums\LifecycleActionStatus;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lifecycle Request — tracks compaction, prune, and sweep operations.
 *
 * Every lifecycle action is recorded as an explicit request with scope,
 * preview, status, and outcome so operators can audit what happened.
 *
 * @property string $id
 * @property LifecycleAction $action
 * @property array<string, mixed> $scope
 * @property LifecycleActionStatus $status
 * @property array<string, mixed>|null $preview
 * @property array<string, mixed>|null $result
 * @property string|null $error_message
 * @property int|null $requested_by
 * @property Carbon|null $executed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $requester
 */
class LifecycleRequest extends Model
{
    /**
     * Prefix for lifecycle request IDs.
     */
    public const ID_PREFIX = 'lc_';

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
    protected $table = 'ai_lifecycle_requests';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'action',
        'scope',
        'status',
        'preview',
        'result',
        'error_message',
        'requested_by',
        'executed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => LifecycleAction::class,
            'status' => LifecycleActionStatus::class,
            'scope' => 'json',
            'preview' => 'json',
            'result' => 'json',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * Get the user who requested this lifecycle action.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Whether this request has reached a terminal status.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Transition to executing status.
     */
    public function markExecuting(): void
    {
        $this->update([
            'status' => LifecycleActionStatus::Executing,
        ]);
    }

    /**
     * Transition to completed status with result.
     *
     * @param  array<string, mixed>  $result  Outcome summary
     */
    public function markCompleted(array $result): void
    {
        $this->update([
            'status' => LifecycleActionStatus::Completed,
            'result' => $result,
            'executed_at' => now(),
        ]);
    }

    /**
     * Transition to failed status.
     *
     * @param  string  $errorMessage  Description of the failure
     */
    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => LifecycleActionStatus::Failed,
            'error_message' => $errorMessage,
            'executed_at' => now(),
        ]);
    }

    /**
     * Transition to cancelled status.
     */
    public function markCancelled(): void
    {
        $this->update([
            'status' => LifecycleActionStatus::Cancelled,
        ]);
    }
}
