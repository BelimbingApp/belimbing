<?php

namespace App\Base\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A paused schedule entry, keyed by source + stable task key.
 * Row present = the entry is skipped at run time (see ServiceProvider's
 * CommandStarting hook); deleting the row resumes it.
 */
class ScheduleSuppression extends Model
{
    protected $table = 'base_schedule_suppressions';

    protected $fillable = [
        'source',
        'key',
        'name',
    ];

    /**
     * Same stable audit identity as ScheduleOverride: pause/resume is
     * configuration history for the task, queryable as
     * `schedule-task / <source>:<key>` (#398).
     *
     * @return array{name: string, id: string}
     */
    public function getAuditSubject(): ?array
    {
        return ['name' => 'schedule-task', 'id' => $this->source.':'.$this->key];
    }
}
