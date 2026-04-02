<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Services\ControlPlane\HealthAndPresenceService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Display health snapshots for tools, agents, or providers.
 *
 * Shows readiness, health, and presence as distinct dimensions
 * with human-readable explanations for each target.
 */
#[AsCommand(name: 'blb:ai:health:snapshot')]
class HealthSnapshotCommand extends Command
{
    protected $description = 'Show health, readiness, and presence snapshots for AI subsystems';

    protected $signature = 'blb:ai:health:snapshot
        {--tools : Show all tool snapshots}
        {--agent= : Show snapshot for a specific agent (employee ID)}
        {--provider= : Show snapshot for a specific provider}';

    public function handle(HealthAndPresenceService $service): int
    {
        $showTools = $this->option('tools');
        $agentId = $this->option('agent');
        $provider = $this->option('provider');

        // Default to tools if nothing specified
        if (! $showTools && $agentId === null && $provider === null) {
            $showTools = true;
        }

        if ($showTools) {
            $this->displayToolSnapshots($service);
        }

        if ($agentId !== null) {
            $snapshot = $service->agentSnapshot((int) $agentId);
            $this->displaySnapshot($snapshot->toArray());
        }

        if ($provider !== null) {
            $snapshot = $service->providerSnapshot($provider);
            $this->displaySnapshot($snapshot->toArray());
        }

        return self::SUCCESS;
    }

    private function displayToolSnapshots(HealthAndPresenceService $service): void
    {
        $snapshots = $service->allToolSnapshots();

        if ($snapshots === []) {
            $this->components->info('No tools found.');

            return;
        }

        $this->components->info(count($snapshots).' tool(s):');

        $rows = [];

        foreach ($snapshots as $snapshot) {
            $data = $snapshot->toArray();
            $rows[] = [
                $data['target_id'],
                $data['readiness'],
                $data['health'],
                $data['presence'],
            ];
        }

        $this->table(['Tool', 'Readiness', 'Health', 'Presence'], $rows);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function displaySnapshot(array $snapshot): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('Target', $snapshot['target_type'].':'.$snapshot['target_id']);
        $this->components->twoColumnDetail('Readiness', $snapshot['readiness']);
        $this->components->twoColumnDetail('Health', $snapshot['health']);
        $this->components->twoColumnDetail('Presence', $snapshot['presence']);
        $this->components->twoColumnDetail('Explanation', $snapshot['explanation']);
        $this->components->twoColumnDetail('Measured At', $snapshot['measured_at']);
    }
}
