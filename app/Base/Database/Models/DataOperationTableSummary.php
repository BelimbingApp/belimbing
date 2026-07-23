<?php

namespace App\Base\Database\Models;

use App\Base\Database\Enums\DataOperationRangeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One summary per affected table: the actions applied, honest per-effect
 * counts, and — where the key is ordered — the key range touched.
 *
 * @property int $id
 * @property int $run_id
 * @property string $table_name
 * @property list<string> $actions
 */
class DataOperationTableSummary extends Model
{
    protected $table = 'base_database_data_operation_tables';

    protected $fillable = [
        'run_id',
        'table_name',
        'actions',
        'rows_source',
        'rows_attempted',
        'rows_inserted',
        'rows_updated',
        'rows_written',
        'rows_deleted',
        'rows_unchanged',
        'rows_rejected',
        'rows_before',
        'rows_after',
        'key_columns',
        'range_kind',
        'first_key',
        'last_key',
        'local_schema_fingerprint',
        'remote_schema_fingerprint',
        'terminal_status',
        'observed_at',
    ];

    protected $casts = [
        'actions' => 'array',
        'key_columns' => 'array',
        'range_kind' => DataOperationRangeKind::class,
        'rows_source' => 'integer',
        'rows_attempted' => 'integer',
        'rows_inserted' => 'integer',
        'rows_updated' => 'integer',
        'rows_written' => 'integer',
        'rows_deleted' => 'integer',
        'rows_unchanged' => 'integer',
        'rows_rejected' => 'integer',
        'rows_before' => 'integer',
        'rows_after' => 'integer',
        'observed_at' => 'datetime',
    ];

    /** @return BelongsTo<DataOperationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DataOperationRun::class, 'run_id');
    }
}
