<?php

namespace App\Base\Schedule\DTO;

use Carbon\CarbonInterface;

/**
 * The single cached projection consumed by the global status bar.
 */
final readonly class ScheduleHealthSnapshot
{
    /**
     * @param  list<UnhealthyScheduleTask>  $unhealthyTasks
     */
    public function __construct(
        public ?CarbonInterface $lastRecordedActivity,
        public array $unhealthyTasks,
    ) {}
}
