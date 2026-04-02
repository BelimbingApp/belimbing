<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\OperationsDispatchService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Sweep stale operations that have been running beyond their threshold.
 *
 * Finds operations stuck in Running status past the configurable
 * threshold and marks them as Failed. Intended to be run periodically
 * via the scheduler or manually by operators.
 */
#[AsCommand(name: 'blb:ai:operations:sweep')]
class OperationsSweepCommand extends Command
{
    protected $description = 'Mark stale AI operations as failed (running beyond threshold)';

    protected $signature = 'blb:ai:operations:sweep
        {--stale-minutes=30 : Minutes before a running operation is considered stale}';

    public function handle(OperationsDispatchService $service): int
    {
        $staleMinutes = (int) $this->option('stale-minutes');

        $this->components->info("Sweeping operations stale for over {$staleMinutes} minutes...");

        $count = $service->sweepStale($staleMinutes);

        if ($count === 0) {
            $this->components->info('No stale operations found.');
        } else {
            $this->components->warn("Swept {$count} stale operation(s).");
        }

        return self::SUCCESS;
    }
}
