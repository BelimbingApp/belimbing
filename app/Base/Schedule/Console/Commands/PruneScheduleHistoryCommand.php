<?php

namespace App\Base\Schedule\Console\Commands;

use App\Base\Schedule\Services\ScheduleHistoryPruner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:schedule:history:prune')]
class PruneScheduleHistoryCommand extends Command
{
    protected $signature = 'blb:schedule:history:prune';

    protected $description = 'Prune scheduled-task run history per retention settings';

    public function handle(ScheduleHistoryPruner $pruner): int
    {
        $result = $pruner->prune();

        $this->components->info(sprintf(
            'Pruned %d history row(s) (keep_days=%d, keep_count=%d).',
            $result['deleted'],
            $result['keep_days'],
            $result['keep_count'],
        ));

        return self::SUCCESS;
    }
}
