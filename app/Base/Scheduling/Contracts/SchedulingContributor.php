<?php

namespace App\Base\Scheduling\Contracts;

use App\Base\Scheduling\DTO\RecordedRun;
use App\Base\Scheduling\DTO\UpcomingRun;

/**
 * A module-owned scheduling source (AI schedule definitions, extension agent
 * tasks, ...) that surfaces its upcoming and recent work on the central
 * Scheduling page. Implementations are tagged `scheduling.contributors` in
 * their module's ServiceProvider; the board aggregates them. Contributors
 * must never throw from these methods - degrade to empty lists.
 */
interface SchedulingContributor
{
    /**
     * @return list<UpcomingRun>
     */
    public function upcoming(): array;

    /**
     * @return list<RecordedRun>
     */
    public function recentRuns(int $limit): array;
}
