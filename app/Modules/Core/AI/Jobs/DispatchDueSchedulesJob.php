<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\Services\Scheduling\SchedulePlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queue job that finds and dispatches all due schedule definitions.
 *
 * Invoked by the SchedulesTickCommand on each scheduler tick. Delegates
 * all logic to the SchedulePlanner service.
 */
class DispatchDueSchedulesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Dedicated queue for schedule dispatch work.
     */
    public const QUEUE = 'ai-schedules';

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Find and dispatch all due schedules.
     */
    public function handle(SchedulePlanner $planner): void
    {
        $dispatched = $planner->dispatchDue();

        Log::info('Schedule tick completed.', [
            'dispatched' => $dispatched,
        ]);
    }
}
