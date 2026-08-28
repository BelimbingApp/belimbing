<?php

namespace App\Base\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stateless per-(source, key) lock target — see ScheduleRunRecorder::reserveManualRun().
 */
class ScheduleRunGate extends Model
{
    protected $table = 'base_schedule_run_gates';

    protected $fillable = [
        'source',
        'key',
    ];
}
