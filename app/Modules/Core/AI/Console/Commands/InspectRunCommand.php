<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\ControlPlane\RunInspectionService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Inspect a specific AI run or all runs in a session.
 *
 * Assembles normalized run facts from session metadata and dispatch
 * records. Operators see provider, model, timing, tool actions,
 * fallback/retry history, and outcome in one view.
 */
#[AsCommand(name: 'blb:ai:inspect:run')]
class InspectRunCommand extends Command
{
    protected $description = 'Inspect an AI run or all runs in a session';

    protected $signature = 'blb:ai:inspect:run
        {employee : Agent employee ID}
        {session : Session ID}
        {--run= : Specific run ID (omit to show all runs in the session)}';

    public function handle(RunInspectionService $service): int
    {
        $employeeId = (int) $this->argument('employee');
        $sessionId = (string) $this->argument('session');
        $runId = $this->option('run');

        if ($runId !== null) {
            $inspection = $service->inspectRun($employeeId, $sessionId, $runId);

            if ($inspection === null) {
                $this->components->error("Run '{$runId}' not found in session '{$sessionId}'.");

                return self::FAILURE;
            }

            $this->displayRun($inspection->toArray());

            return self::SUCCESS;
        }

        $inspections = $service->inspectSession($employeeId, $sessionId);

        if ($inspections === []) {
            $this->components->info('No runs found in this session.');

            return self::SUCCESS;
        }

        $this->components->info(count($inspections).' run(s) found:');

        foreach ($inspections as $inspection) {
            $this->displayRun($inspection->toArray());
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function displayRun(array $run): void
    {
        $this->components->twoColumnDetail('Run ID', $run['run_id']);
        $this->components->twoColumnDetail('Provider', $run['provider']);
        $this->components->twoColumnDetail('Model', $run['model']);
        $this->components->twoColumnDetail('Outcome', $run['outcome']);
        $this->components->twoColumnDetail('Latency', $run['latency_ms'] !== null ? $run['latency_ms'].'ms' : 'N/A');
        $this->components->twoColumnDetail('Tokens (prompt/completion)',
            ($run['tokens']['prompt'] ?? '?').' / '.($run['tokens']['completion'] ?? '?'));
        $this->components->twoColumnDetail('Tool Actions', (string) count($run['tool_actions']));
        $this->components->twoColumnDetail('Retries', (string) $run['retry_attempts']);
        $this->components->twoColumnDetail('Fallbacks', (string) count($run['fallback_attempts']));

        if ($run['dispatch_id'] !== null) {
            $this->components->twoColumnDetail('Dispatch', $run['dispatch_id']);
        }

        if ($run['error_type'] !== null) {
            $this->components->twoColumnDetail('Error Type', $run['error_type']);
            $this->components->twoColumnDetail('Error', $run['error_message'] ?? 'N/A');
        }

        $this->components->twoColumnDetail('Recorded At', $run['recorded_at']);
    }
}
