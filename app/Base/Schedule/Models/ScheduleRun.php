<?php

namespace App\Base\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded execution of scheduled work. Rows for `source = scheduler`
 * are written automatically by ScheduleRunRecorder from Laravel scheduler
 * events; other sources surface their runs through ScheduleContributor
 * instead of writing here.
 */
class ScheduleRun extends Model
{
    protected $table = 'base_schedule_runs';

    protected $fillable = [
        'source',
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
