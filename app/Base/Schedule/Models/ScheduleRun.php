<?php

namespace App\Base\Schedule\Models;

use App\Base\Schedule\Services\ScheduleHealthService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One recorded execution of scheduled work. Rows for `source = scheduler`
 * are written automatically by ScheduleRunRecorder from Laravel scheduler
 * events; other sources surface their runs through ScheduleContributor
 * instead of writing here.
 *
 * @property int|null $id
 * @property string|null $source
 * @property string|null $trigger
 * @property int|null $triggered_by_user_id
 * @property string|null $triggered_by_name
 * @property string|null $key
 * @property string|null $name
 * @property string|null $expression
 * @property string|null $status
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property int|null $exit_code
 * @property int|null $runtime_ms
 * @property string|null $output_excerpt
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ScheduleRun extends Model
{
    protected static function booted(): void
    {
        static::saved(function (): void {
            ScheduleHealthService::invalidate();
        });
        static::deleted(function (): void {
            ScheduleHealthService::invalidate();
        });
    }

    protected $table = 'base_schedule_runs';

    protected $fillable = [
        'source',
        'trigger',
        'triggered_by_user_id',
        'triggered_by_name',
        'key',
        'name',
        'expression',
        'status',
        'started_at',
        'finished_at',
        'exit_code',
        'runtime_ms',
        'output_excerpt',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'exit_code' => 'integer',
        'runtime_ms' => 'integer',
    ];
}
