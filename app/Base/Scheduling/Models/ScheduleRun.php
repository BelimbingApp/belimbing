<?php

namespace App\Base\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded execution of scheduled work. Rows for `source = scheduler`
 * are written automatically by ScheduleRunRecorder from Laravel scheduler
 * events; other sources surface their runs through SchedulingContributor
 * instead of writing here.
 */
class ScheduleRun extends Model
{
    protected $table = 'base_schedule_runs';

    protected $fillable = [
        'source',
        'name',
        'status',
        'started_at',
        'finished_at',
        'exit_code',
        'output_excerpt',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'exit_code' => 'integer',
    ];
}
