<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Services\DataOperation\DataOperationReconciler;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Reconcile stale running data operations to indeterminate. Safe to schedule:
 * it only touches runs that have been running past the timeout, and it never
 * guesses a run failed.
 */
#[AsCommand(name: 'blb:db:data-operations:reconcile')]
class ReconcileDataOperationsCommand extends Command
{
    protected $signature = 'blb:db:data-operations:reconcile {--minutes= : Override the stale-after threshold in minutes}';

    protected $description = 'Mark timed-out running data operations as indeterminate.';

    public function handle(DataOperationReconciler $reconciler): int
    {
        $minutes = $this->option('minutes');
        $count = $reconciler->reconcileStale($minutes !== null ? (int) $minutes : null);

        $this->components->info(sprintf('Reconciled %d stale running operation(s) to indeterminate.', $count));

        return self::SUCCESS;
    }
}
