<?php

namespace App\Core\AI\Console\Commands\Concerns;

use App\Core\AI\Enums\LifecycleAction;

trait BuildsLifecycleActionScope
{
    /**
     * @return array<string, mixed>
     */
    protected function buildLifecycleActionScope(LifecycleAction $action): array
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
