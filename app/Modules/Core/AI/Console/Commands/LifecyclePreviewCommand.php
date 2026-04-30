<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Services\ControlPlane\LifecycleControlService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Preview what a lifecycle action would affect before executing it.
 *
 * Shows affected items and scope without making any changes.
 * Operators review the preview before running `blb:ai:lifecycle:execute`.
 */
#[AsCommand(name: 'blb:ai:lifecycle:preview')]
class LifecyclePreviewCommand extends Command
{
    protected $description = 'Preview what a lifecycle action would affect';

    protected $signature = 'blb:ai:lifecycle:preview
        {action : Action type (compact_memory, prune_sessions, prune_artifacts, sweep_browser_sessions, sweep_operations, prune_wire_logs, refresh_pricing_snapshot)}
        {--employee= : Agent employee ID (for memory/session actions)}
        {--retention-days=30 : Retention period in days (for prune actions)}
        {--session= : Browser session ID (for artifact pruning)}
        {--stale-minutes=30 : Stale threshold in minutes (for sweep actions)}';

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
        $preview = $service->preview($action, $scope);
        $data = $preview->toArray();

        $this->components->info("Preview: {$action->label()}");
        $this->components->twoColumnDetail('Affected Count', (string) $data['affected_count']);
        $this->components->twoColumnDetail('Destructive', $data['is_destructive'] ? 'Yes' : 'No');

        $this->newLine();
        $this->components->info('Affected Items:');

        foreach ($data['affected_summary'] as $item) {
            $this->line("  - {$item}");
        }

        return self::SUCCESS;
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
