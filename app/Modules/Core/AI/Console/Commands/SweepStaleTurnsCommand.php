<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Fail turns that remain unclaimed or stuck beyond a configurable threshold.
 *
 * Finds turns stuck in non-terminal states (Queued, Booting, Running) past
 * the threshold and transitions them to Failed with a clear user-facing
 * explanation. Emits a `turn.failed` event so any connected SSE client
 * receives the failure immediately.
 *
 * Intended to run every 5 minutes via the scheduler or manually by operators.
 * Safe for concurrent execution — only transitions rows still in active states.
 */
#[AsCommand(name: 'blb:ai:turns:sweep-stale')]
class SweepStaleTurnsCommand extends Command
{
    protected $description = 'Fail AI chat turns stuck in active states beyond threshold';

    protected $signature = 'blb:ai:turns:sweep-stale
        {--queued-minutes=10 : Minutes before a queued turn is considered stale}
        {--running-minutes=30 : Minutes before a running turn is considered stale}';

    public function handle(TurnEventPublisher $publisher): int
    {
        $queuedMinutes = (int) $this->option('queued-minutes');
        $runningMinutes = (int) $this->option('running-minutes');

        $this->components->info("Sweeping stale turns (queued > {$queuedMinutes}m, running > {$runningMinutes}m)...");

        $queuedCount = $this->sweepByStatuses(
            $publisher,
            [TurnStatus::Queued, TurnStatus::Booting],
            Carbon::now()->subMinutes($queuedMinutes),
            'stale_queued',
            'No worker claimed this turn — it may have been lost in the queue. Please try again.',
        );

        $runningCount = $this->sweepByStatuses(
            $publisher,
            [TurnStatus::Running],
            Carbon::now()->subMinutes($runningMinutes),
            'stale_running',
            'This turn ran longer than expected and was stopped. The worker may have crashed.',
        );

        $total = $queuedCount + $runningCount;

        if ($total === 0) {
            $this->components->info('No stale turns found.');
        } else {
            $this->components->warn("Swept {$total} stale turn(s) ({$queuedCount} queued/booting, {$runningCount} running).");
        }

        return self::SUCCESS;
    }

    /**
     * Find and fail turns in the given statuses created before the cutoff.
     *
     * @param  list<TurnStatus>  $statuses
     */
    private function sweepByStatuses(
        TurnEventPublisher $publisher,
        array $statuses,
        Carbon $cutoff,
        string $errorType,
        string $message,
    ): int {
        $statusValues = array_map(fn (TurnStatus $s): string => $s->value, $statuses);

        $staleTurns = ChatTurn::query()
            ->whereIn('status', $statusValues)
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;

        foreach ($staleTurns as $turn) {
            // Double-check the turn is still active (concurrent safety)
            if ($turn->isTerminal()) {
                continue;
            }

            $publisher->turnFailed($turn, $errorType, $message);
            $count++;
        }

        return $count;
    }
}
