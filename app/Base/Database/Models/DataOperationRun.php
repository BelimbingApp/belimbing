<?php

namespace App\Base\Database\Models;

use App\Base\Database\Enums\DataOperationStatus;
use App\Base\Database\Enums\DataOperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One durable, actor-attributed record of a mass data operation. This is the
 * authoritative history; the audit action is a best-effort projection that
 * references this run by id.
 *
 * @property int $id
 * @property string $status
 * @property int $table_count
 */
class DataOperationRun extends Model
{
    protected $table = 'base_database_data_operation_runs';

    protected $fillable = [
        'operation_type',
        'source',
        'direction',
        'is_forced',
        'transfer_mode',
        'local_instance_id',
        'remote_instance_id',
        'actor_type',
        'actor_id',
        'company_id',
        'actor_role',
        'actor_label',
        'trace_id',
        'schedule_run_ref',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'table_count',
        'total_rows_affected',
        'failure_summary',
        'audit_projection_attempted_at',
    ];

    protected $casts = [
        'operation_type' => DataOperationType::class,
        'status' => DataOperationStatus::class,
        'is_forced' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'table_count' => 'integer',
        'total_rows_affected' => 'integer',
        'audit_projection_attempted_at' => 'datetime',
    ];

    /** @return HasMany<DataOperationTableSummary, $this> */
    public function tables(): HasMany
    {
        return $this->hasMany(DataOperationTableSummary::class, 'run_id');
    }
}
