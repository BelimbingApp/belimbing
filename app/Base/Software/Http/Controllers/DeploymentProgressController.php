<?php

namespace App\Base\Software\Http\Controllers;

use App\Base\Software\Livewire\Deployment\Concerns\FormatsDeploymentRunOutput;
use App\Base\Software\Services\DeploymentRunHistory;
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

    public function __invoke(DeploymentRunHistory $history): JsonResponse
    {
        $run = $history->lastDeploymentRun();

        if ($run === null) {
            return response()->json([
                'status' => 'idle',
                'summary' => '',
                'attempted_at' => null,
                'lines' => [],
            ]);
        }

        return response()->json([
            'status' => $run['status'],
            'summary' => $run['summary'],
            'attempted_at' => $run['attempted_at'],
            'lines' => array_map(fn (string $line): array => [
                'text' => $line,
                'class' => $this->runLineClass($line),
            ], $run['log']),
        ]);
    }
}
