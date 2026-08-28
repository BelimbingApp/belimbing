<?php

namespace App\Base\Schedule\Contracts;

use App\Base\Schedule\DTO\UnhealthyScheduleTask;

/**
 * Optional low-cost health projection for a ScheduleContributor.
 *
 * The status bar must not call a contributor's full board projection because
 * that projection may include presentation-only work. Implementations return
 * only the currently active failures they can establish from their own ledger.
 */
interface ScheduleHealthContributor
{
    public const CONTAINER_TAG = 'schedule.health-contributors';

    /**
     * @return list<UnhealthyScheduleTask>
     */
    public function unhealthyTasks(): array;
}
