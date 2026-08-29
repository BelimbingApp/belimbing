<?php

namespace App\Base\Schedule\Models;

use App\Base\Schedule\Services\ScheduleHealthService;
use Illuminate\Database\Eloquent\Model;

/**
 * A paused schedule entry, keyed by source + stable task key.
 * Row present = the entry is skipped at run time (see ServiceProvider's
 * CommandStarting hook); deleting the row resumes it.
 */
class ScheduleSuppression extends Model
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
