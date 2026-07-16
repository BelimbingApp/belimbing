<?php

namespace App\Base\Workflow\Console\Commands;

use App\Base\Workflow\Process\ProcessCoordinator;
use App\Base\Workflow\Services\TransitionOutboxDispatcher;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:workflow:reconcile')]
class WorkflowReconcileCommand extends Command
{
    protected $signature = 'blb:workflow:reconcile
                            {--run= : Reconcile one durable process run id}
                            {--outbox-limit=100 : Maximum due transition events to deliver}';

    protected $description = 'Repair durable process leases and deliver committed workflow transition events';

    public function handle(ProcessCoordinator $processes, TransitionOutboxDispatcher $outbox): int
    {
        $run = $this->option('run');
        $reconciled = $processes->reconcile($run === null ? null : (int) $run);
        $delivered = $outbox->deliverDue((int) $this->option('outbox-limit'));

        $this->components->twoColumnDetail('Process runs reconciled', (string) $reconciled);
        $this->components->twoColumnDetail('Transition events delivered', (string) $delivered);

        return self::SUCCESS;
    }
}
