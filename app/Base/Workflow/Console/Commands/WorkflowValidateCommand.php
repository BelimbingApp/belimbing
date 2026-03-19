<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Console\Commands;

use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Validate graph integrity for a workflow: orphans, unreachable nodes, missing capabilities.
 */
#[AsCommand(name: 'blb:workflow:validate')]
class WorkflowValidateCommand extends Command
{
    protected $signature = 'blb:workflow:validate
                            {flow : Flow identifier to validate}';

    protected $description = 'Check workflow graph integrity: orphans, unreachable nodes, dangling edges';

    public function handle(): int
    {
        $flow = $this->argument('flow');
        $issues = [];

        $statuses = StatusConfig::query()
            ->forFlow($flow)
            ->active()
            ->pluck('code')
            ->all();

        if (empty($statuses)) {
            $this->components->error("No active statuses found for flow '{$flow}'.");

            return Command::FAILURE;
        }

        $transitions = StatusTransition::query()
            ->forFlow($flow)
            ->active()
            ->get();

        $statusSet = array_flip($statuses);

        foreach ($transitions as $transition) {
            if (! isset($statusSet[$transition->from_code])) {
                $issues[] = "Transition references non-existent source status: '{$transition->from_code}' → '{$transition->to_code}'";
            }

            if (! isset($statusSet[$transition->to_code])) {
                $issues[] = "Transition references non-existent target status: '{$transition->from_code}' → '{$transition->to_code}'";
            }
        }

        $sourceCodes = $transitions->pluck('from_code')->unique()->all();
        $targetCodes = $transitions->pluck('to_code')->unique()->all();
        $allReferenced = array_unique(array_merge($sourceCodes, $targetCodes));

        foreach ($statuses as $code) {
            if (! in_array($code, $allReferenced, true)) {
                $issues[] = "Orphan status: '{$code}' has no inbound or outbound transitions";
            }
        }

        $startCandidates = array_diff($statuses, $targetCodes);
        $terminalCandidates = array_diff($statuses, $sourceCodes);

        if (empty($startCandidates) && ! empty($statuses)) {
            $issues[] = 'No start status detected (all statuses have inbound transitions — possible cycle without entry point)';
        }

        if (empty($terminalCandidates) && ! empty($statuses)) {
            $issues[] = 'No terminal status detected (all statuses have outbound transitions — possible infinite loop)';
        }

        foreach ($transitions as $transition) {
            if ($transition->guard_class !== null && ! class_exists($transition->guard_class)) {
                $issues[] = "Guard class not found: '{$transition->guard_class}' on '{$transition->from_code}' → '{$transition->to_code}'";
            }

            if ($transition->action_class !== null && ! class_exists($transition->action_class)) {
                $issues[] = "Action class not found: '{$transition->action_class}' on '{$transition->from_code}' → '{$transition->to_code}'";
            }
        }

        if (empty($issues)) {
            $this->components->info("Flow '{$flow}' is valid.");
            $this->components->twoColumnDetail('Statuses', (string) count($statuses));
            $this->components->twoColumnDetail('Transitions', (string) $transitions->count());
            $this->components->twoColumnDetail('Start candidates', implode(', ', $startCandidates) ?: '—');
            $this->components->twoColumnDetail('Terminal candidates', implode(', ', $terminalCandidates) ?: '—');

            return Command::SUCCESS;
        }

        $this->components->error("Flow '{$flow}' has ".count($issues).' issue(s):');
        $this->line('');

        foreach ($issues as $issue) {
            $this->components->bulletList([$issue]);
        }

        return Command::FAILURE;
    }
}
