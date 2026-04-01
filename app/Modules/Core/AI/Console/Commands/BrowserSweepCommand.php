<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Sweep stale browser sessions that have expired their TTL.
 *
 * Finds all non-terminal sessions past their expiry time and marks them
 * as Expired. Intended to be run periodically via the scheduler or
 * manually by operators when cleaning up long-running browser sessions.
 */
#[AsCommand(name: 'blb:ai:browser:sweep')]
class BrowserSweepCommand extends Command
{
    protected $description = 'Expire stale browser sessions that have exceeded their TTL';

    protected $signature = 'blb:ai:browser:sweep';

    public function handle(BrowserSessionManager $manager): int
    {
        $this->components->info('Sweeping stale browser sessions...');

        $count = $manager->sweepStaleSessions();

        if ($count === 0) {
            $this->components->info('No stale sessions found.');
        } else {
            $this->components->info("Expired {$count} stale session(s).");
        }

        return self::SUCCESS;
    }
}
