<?php

namespace App\Base\Workflow\Models;

use App\Base\Workflow\Process\Enums\DependencyMode;
use App\Base\Workflow\Process\Enums\ProcessWorkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One claimable unit of work in a durable process run.
 *
 * @property int $id
 * @property int $process_run_id
 * @property string $step_key
 * @property string $label
 * @property string $executor_key
 * @property ProcessWorkStatus $status
 * @property DependencyMode $dependency_mode
 * @property string|null $required_signal
 * @property Carbon|null $signalled_at
 * @property array<string, mixed>|null $signal_payload
 * @property int $delay_seconds
 * @property Carbon|null $available_at
 * @property int $attempts
 * @property int $max_attempts
 * @property int $priority
 * @property string|null $lease_owner
 * @property string|null $lease_token
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $heartbeat_at
 * @property string|null $outcome
 * @property array<string, mixed>|null $input
 * @property string|null $input_ref
 * @property array<string, mixed>|null $output
 * @property string|null $result_ref
 * @property array<string, mixed>|null $metadata
 * @property string|null $last_error
 * @property Carbon|null $completed_at
 */
class ProcessWorkItem extends Model
{
    protected $table = 'base_workflow_process_work_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ProcessWorkStatus::class,
            'dependency_mode' => DependencyMode::class,
            'signalled_at' => 'datetime',
            'signal_payload' => 'array',
            'delay_seconds' => 'integer',
            'available_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'priority' => 'integer',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'input' => 'array',
            'output' => 'array',
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProcessRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcessRun::class, 'process_run_id');
    }

    /** @return HasMany<ProcessDependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(ProcessDependency::class, 'work_item_id');
    }
}
