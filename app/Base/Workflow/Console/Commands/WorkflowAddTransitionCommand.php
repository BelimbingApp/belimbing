<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Console\Commands;

use App\Base\Workflow\Services\TransitionManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Add a transition edge between two statuses in a workflow.
 */
#[AsCommand(name: 'blb:workflow:add-transition')]
class WorkflowAddTransitionCommand extends Command
{
    protected $signature = 'blb:workflow:add-transition
                            {--flow= : Flow identifier}
                            {--from= : Source status code}
                            {--to= : Target status code}
                            {--label= : Action label (e.g., Approve)}
                            {--capability= : AuthZ capability key}
                            {--guard-class= : Guard class FQCN}
                            {--action-class= : Action class FQCN}
                            {--sla-seconds= : Expected turnaround time in seconds}
                            {--position=0 : Order when multiple transitions exist from the same source}';

    protected $description = 'Add a transition edge between two statuses in a workflow';

    public function handle(TransitionManager $transitionManager): int
    {
        $flow = $this->option('flow') ?? $this->ask('Flow');
        $from = $this->option('from') ?? $this->ask('From status code');
        $to = $this->option('to') ?? $this->ask('To status code');

        if (! $flow || ! $from || ! $to) {
            $this->components->error('--flow, --from, and --to are required.');

            return Command::FAILURE;
        }

        if ($transitionManager->getTransition($flow, $from, $to) !== null) {
            $this->components->error("Transition '{$from}' → '{$to}' already exists in flow '{$flow}'.");

            return Command::FAILURE;
        }

        $attributes = [
            'flow' => $flow,
            'from_code' => $from,
            'to_code' => $to,
            'label' => $this->option('label'),
            'capability' => $this->option('capability'),
            'guard_class' => $this->option('guard-class'),
            'action_class' => $this->option('action-class'),
            'position' => (int) $this->option('position'),
        ];

        if ($this->option('sla-seconds') !== null) {
            $attributes['sla_seconds'] = (int) $this->option('sla-seconds');
        }

        $transition = $transitionManager->create($attributes);

        $label = $transition->label ?? $to;
        $this->components->info("Transition '{$from}' → '{$to}' ({$label}) added to flow '{$flow}'.");

        return Command::SUCCESS;
    }
}
