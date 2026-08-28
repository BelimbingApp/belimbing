<?php

namespace App\Base\Schedule\Models;

use App\Base\Schedule\Services\ScheduleHealthService;
use Illuminate\Database\Eloquent\Model;

/**
 * One recorded execution of scheduled work. Rows for `source = scheduler`
 * are written automatically by ScheduleRunRecorder from Laravel scheduler
 * events; other sources surface their runs through ScheduleContributor
 * instead of writing here.
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
