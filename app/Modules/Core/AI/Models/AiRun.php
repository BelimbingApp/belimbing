<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI Run — canonical record of a single LLM execution.
 *
 * Each row represents one invocation of AgenticRuntime (sync or streaming).
 * Contains provider path, timing, token usage, tool actions, retry/fallback
 * history, and error details. Never stores prompts, response bodies, or secrets.
 *
 * @property string $id
 * @property int $employee_id
 * @property string|null $session_id
 * @property int|null $acting_for_user_id
 * @property string|null $dispatch_id
 * @property string $source
 * @property string $execution_mode
 * @property AiRunStatus $status
 * @property string|null $provider_name
 * @property string|null $model
 * @property int|null $timeout_seconds
 * @property int|null $latency_ms
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>|null $retry_attempts
 * @property list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int, diagnostic: string|null}>|null $fallback_attempts
 * @property list<array{tool: string, result_length: int|null}>|null $tool_actions
 * @property string|null $error_type
 * @property string|null $error_message
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read User|null $actingForUser
 * @property-read OperationDispatch|null $dispatch
 */
class AiRun extends Model
{
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
    protected $table = 'ai_runs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'employee_id',
        'session_id',
        'acting_for_user_id',
        'dispatch_id',
        'source',
        'execution_mode',
        'status',
        'provider_name',
        'model',
        'timeout_seconds',
        'latency_ms',
        'prompt_tokens',
        'completion_tokens',
        'retry_attempts',
        'fallback_attempts',
        'tool_actions',
        'error_type',
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
            'status' => AiRunStatus::class,
            'retry_attempts' => 'json',
            'fallback_attempts' => 'json',
            'tool_actions' => 'json',
            'meta' => 'json',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the agent (employee) that executed this run.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user on whose behalf this run was executed.
     */
    public function actingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_user_id');
    }

    /**
     * Get the linked operation dispatch (for background/async runs).
     */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(OperationDispatch::class, 'dispatch_id');
    }
}
