<?php

namespace App\Base\Schedule\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per scheduler attempt (running row mutated to terminal).
 *
 * @property int $id
 * @property string $command_key
 * @property string $command
 * @property string|null $expression
 * @property string $attempt_key
 * @property string $status
 * @property int|null $exit_code
 * @property int|null $runtime_ms
 * @property string|null $output
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class ScheduleRunHistory extends Model
{
    protected $table = 'base_schedule_run_history';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'command_key',
        'command',
        'expression',
        'attempt_key',
        'status',
        'exit_code',
        'runtime_ms',
        'output',
        'started_at',
        'finished_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'exit_code' => 'integer',
        'runtime_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
