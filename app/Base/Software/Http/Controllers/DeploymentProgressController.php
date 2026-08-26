<?php

namespace App\Base\Software\Http\Controllers;

use App\Base\Software\Livewire\Deployment\Concerns\FormatsDeploymentRunOutput;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\SoftwareUpdateLauncher;
use Illuminate\Http\JsonResponse;

/**
 * Live progress feed for the Updates run box.
 *
 * A software update runs in a detached process while the site sits in
 * maintenance mode, appending every log line to the durable run record.
 * Livewire's own endpoint 503s during maintenance, so the Updates page
 * follows the run through this plain route instead — it is excepted from
 * maintenance in bootstrap/app.php, like the console page itself.
 */
class DeploymentProgressController
{
    use FormatsDeploymentRunOutput;

    public function __invoke(DeploymentRunHistory $history, SoftwareUpdateLauncher $launcher): JsonResponse
    {
        $failedStartupRunId = $history->failExpiredScheduledUpdate();

        if ($failedStartupRunId !== null) {
            $launcher->releaseStaleUpdateLock();
        }

        // The poller stops on a terminal status, so an abandoned pending run would
        // keep it polling forever. Reconcile here too, not just on render.
        $history->abandonStalePendingRun($launcher->inProgress() || $history->reloadIsInProgress());

        $run = $history->lastDeploymentRun();

        if ($run === null) {
            return response()->json([
                'run_id' => null,
                'status' => 'idle',
                'phase' => null,
                'summary' => '',
                'attempted_at' => null,
                'lines' => [],
            ]);
        }

        return response()->json([
            'run_id' => $run['run_id'],
            'status' => $run['status'],
            'phase' => $run['phase'],
            'summary' => $run['summary'],
            'attempted_at' => $run['attempted_at'],
            'lines' => array_map(fn (string $line): array => [
                'text' => $line,
                'class' => $this->runLineClass($line),
            ], $run['log']),
        ]);
    }
}
