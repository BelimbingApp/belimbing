<?php

namespace App\Base\Schedule\Contracts;

use App\Base\Schedule\DTO\RecordedRun;
use App\Base\Schedule\DTO\ScheduleTask;

/**
 * A module-owned schedule source (AI schedule definitions, extension agent
 * tasks, ...) that surfaces its tasks and recent work on the central
 * Schedule page. Implementations are tagged `schedule.contributors` in
 * their module's ServiceProvider; the board aggregates them. Contributors
 * must never throw from these methods - degrade to empty lists.
 */
interface ScheduleContributor
{
    /**
     * @return list<ScheduleTask>
     */
    public function tasks(): array;

    /**
     * @return list<RecordedRun>
     */
    public function recentRuns(int $limit): array;
}
