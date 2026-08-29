<?php

namespace App\Base\Schedule\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A persisted cron-cadence override for one scheduled task, keyed by the same
 * stable source+key identity the run ledger and suppressions use. The runtime
 * applies it at scheduler start (see ServiceProvider::applySchedulerOverrides),
 * so the board's "Effective" column and the schedule the runtime honors are
 * the same fact (#398). Deleting the row is the reset: the task immediately
 * re-adopts its code-declared default.
 */
class ScheduleOverride extends Model
{
    protected $table = 'base_schedule_overrides';

    protected $fillable = [
        'source',
        'key',
        'name',
        'expression',
    ];

    /**
     * Stable, neutral audit identity — configuration history for a task is
     * queried as `schedule-task / <source>:<key>` regardless of which table
     * (override, suppression) carried the change (#398).
     *
     * @return array{name: string, id: string}
     */
    public function getAuditSubject(): ?array
    {
        return ['name' => 'schedule-task', 'id' => $this->source.':'.$this->key];
    }
}
