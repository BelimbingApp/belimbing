<?php

namespace App\Base\Schedule\Contracts;

use App\Base\Schedule\DTO\ScheduleHistoryPage;
use App\Base\Schedule\DTO\ScheduleHistoryQuery;
use App\Base\Schedule\DTO\ScheduleTask;

/**
 * A module-owned schedule source (AI schedule definitions, extension agent
 * tasks, ...) that surfaces its tasks and recent work on the central
 * Schedule page. Implementations are tagged `schedule.contributors` in
 * their module's ServiceProvider; the board aggregates them. Contributors
 * must never throw from these methods - degrade to empty results.
 */
interface ScheduleContributor
{
    /**
     * @return list<ScheduleTask>
     */
    public function tasks(): array;

    /**
     * The source's history for one query, newest-first window of at most
     * $limit rows, plus the complete filtered total and whether the source
     * has any retained history at all. The source must honor the query's
     * period, status, and search constraints before its own truncation.
     */
    public function history(ScheduleHistoryQuery $query, int $limit): ScheduleHistoryPage;
}
