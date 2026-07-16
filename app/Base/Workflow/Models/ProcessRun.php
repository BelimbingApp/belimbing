<?php

namespace App\Base\Workflow\Models;

use App\Base\Workflow\Process\Enums\ProcessRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Durable execution of one immutable, code-owned process definition version.
 *
 * @property int $id
 * @property string $definition_key
 * @property int $definition_version
 * @property string $definition_fingerprint
 * @property ProcessRunStatus $status
 * @property int $priority
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $correlation_key
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $idempotency_key
 * @property string|null $last_error
 * @property Carbon $started_at
 * @property Carbon $available_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $paused_at
 * @property string|null $pause_reason
 * @property Carbon|null $completed_at
 */
class ProcessRun extends Model
{
    protected $table = 'base_workflow_process_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'definition_version' => 'integer',
            'status' => ProcessRunStatus::class,
            'priority' => 'integer',
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'available_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<ProcessWorkItem, $this> */
    public function workItems(): HasMany
    {
        return $this->hasMany(ProcessWorkItem::class, 'process_run_id');
    }

    /** @return HasMany<ProcessEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProcessEvent::class, 'process_run_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }
}
