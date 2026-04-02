<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\Scheduling\SchedulePlanner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Find and dispatch all due schedule definitions.
 *
 * Queries enabled schedules whose next_due_at has passed, creates
 * OperationDispatch records, and queues execution jobs. Intended to
 * be called by Laravel's scheduler every minute (or a suitable interval).
 *
 * Concurrency-safe: the planner advances next_due_at atomically, so
 * overlapping ticks do not fire the same schedule twice.
 */
#[AsCommand(name: 'blb:ai:schedules:tick')]
class SchedulesTickCommand extends Command
{
    protected $description = 'Find and dispatch all due AI schedule definitions';

    protected $signature = 'blb:ai:schedules:tick';

    public function handle(SchedulePlanner $planner): int
    {
        $this->components->info('Checking for due schedules...');

        $dispatched = $planner->dispatchDue();

        if ($dispatched === 0) {
            $this->components->info('No schedules due.');
        } else {
            $this->components->info("Dispatched {$dispatched} schedule(s).");
        }

        return self::SUCCESS;
    }
}
