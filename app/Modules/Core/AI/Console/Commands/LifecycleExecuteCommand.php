<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Services\ControlPlane\LifecycleControlService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Execute a lifecycle action with preview, audit, and status tracking.
 *
 * Creates a lifecycle request record, executes the action, and records
 * the outcome. Destructive actions require the `--confirm` flag.
 */
#[AsCommand(name: 'blb:ai:lifecycle:execute')]
class LifecycleExecuteCommand extends Command
{
    protected $description = 'Execute a lifecycle action (compact, prune, sweep, refresh) with audit';

    protected $signature = 'blb:ai:lifecycle:execute
        {action : Action type (compact_memory, prune_sessions, prune_artifacts, sweep_browser_sessions, sweep_operations, prune_wire_logs, refresh_pricing_snapshot)}
        {--employee= : Agent employee ID (for memory/session actions)}
        {--retention-days=30 : Retention period in days (for prune actions)}
        {--session= : Browser session ID (for artifact pruning)}
        {--stale-minutes=30 : Stale threshold in minutes (for sweep actions)}
        {--confirm : Confirm destructive actions}';

    public function handle(LifecycleControlService $service): int
    {
        $action = LifecycleAction::tryFrom((string) $this->argument('action'));

        if ($action === null) {
            $this->components->error('Invalid action. Valid actions: '.implode(', ', array_map(
                fn (LifecycleAction $a) => $a->value,
                LifecycleAction::cases(),
            )));

            return self::FAILURE;
        }

        $scope = $this->buildScope($action);

        // Require confirmation for destructive actions
        if ($action->isDestructive() && ! $this->option('confirm')) {
            $this->components->error("Action '{$action->label()}' is destructive. Use --confirm to proceed.");

            return self::FAILURE;
        }

        $this->components->info("Executing: {$action->label()}...");

        $result = $service->execute($action, $scope);
        $data = $result->toArray();

        $this->components->twoColumnDetail('Request ID', $data['request_id']);
        $this->components->twoColumnDetail('Status', $data['status']);

        if ($data['result'] !== null) {
            $this->components->info('Result:');

            foreach ($data['result'] as $key => $value) {
                $this->components->twoColumnDetail($key, (string) $value);
            }
        }

        if ($data['error_message'] !== null) {
            $this->components->error("Error: {$data['error_message']}");
        }

        return $data['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScope(LifecycleAction $action): array
    {
        $scope = [];

        if (in_array($action, [LifecycleAction::CompactMemory, LifecycleAction::PruneSessions], true)) {
            $scope['employee_id'] = (int) $this->option('employee');
        }

        if (in_array($action, [LifecycleAction::PruneSessions, LifecycleAction::PruneWireLogs], true)) {
            $scope['retention_days'] = (int) $this->option('retention-days');
        }

        if ($action === LifecycleAction::PruneArtifacts) {
            $scope['session_id'] = $this->option('session');
        }

        if ($action === LifecycleAction::SweepOperations) {
            $scope['stale_minutes'] = (int) $this->option('stale-minutes');
        }

        return $scope;
    }
}
