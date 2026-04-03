<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\Services\ControlPlane\RunInspectionService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Inspect AI runs by run ID, dispatch ID, or session.
 *
 * Assembles normalized run facts from the ai_runs ledger. Operators
 * see provider, model, timing, tool actions, fallback/retry history,
 * and outcome in one view.
 */
#[AsCommand(name: 'blb:ai:inspect:run')]
class InspectRunCommand extends Command
{
    protected $description = 'Inspect AI runs by run ID, dispatch ID, or session';

    protected $signature = 'blb:ai:inspect:run
        {--run= : Inspect a specific run by ID}
        {--employee= : Agent employee ID (for session inspection)}
        {--session= : Session ID (for session inspection)}
        {--dispatch= : Dispatch ID (for dispatch inspection)}';

    public function handle(RunInspectionService $service): int
    {
        $runId = $this->option('run');
        $dispatchId = $this->option('dispatch');
        $employeeId = $this->option('employee');
        $sessionId = $this->option('session');

        if ($runId !== null) {
            $inspection = $service->inspectRun($runId);

            if ($inspection === null) {
                $this->components->error("Run '{$runId}' not found.");

                return self::FAILURE;
            }

            $this->displayRun($inspection->toArray());

            return self::SUCCESS;
        }

        if ($dispatchId !== null) {
            return $this->displayRunList($service->inspectDispatchRun($dispatchId), 'dispatch');
        }

        if ($employeeId !== null && $sessionId !== null) {
            return $this->displayRunList(
                $service->inspectSession((int) $employeeId, $sessionId),
                'session',
            );
        }

        $this->components->error('Provide --run, --dispatch, or both --employee and --session.');

        return self::FAILURE;
    }

    /**
     * Display a list of run inspections with a contextual label.
     *
     * @param  list<RunInspection>  $inspections
     * @param  string  $context  Label for the "no runs" message (e.g. 'session', 'dispatch')
     */
    private function displayRunList(array $inspections, string $context): int
    {
        if ($inspections === []) {
            $this->components->info("No runs found for this {$context}.");

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
