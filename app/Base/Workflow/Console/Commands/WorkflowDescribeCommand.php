<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Console\Commands;

use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Dump the status graph (nodes, edges, kanban) for a workflow.
 */
#[AsCommand(name: 'blb:workflow:describe')]
class WorkflowDescribeCommand extends Command
{
    protected $signature = 'blb:workflow:describe
                            {flow : Flow identifier to describe}';

    protected $description = 'Display the status graph for a workflow (nodes, edges, kanban columns)';

    public function handle(): int
    {
        $flow = $this->argument('flow');

        $workflow = Workflow::query()->where('code', $flow)->first();

        if ($workflow) {
            $this->components->twoColumnDetail('Workflow', $workflow->label);
            $this->components->twoColumnDetail('Code', $workflow->code);
            $this->components->twoColumnDetail('Module', $workflow->module ?? '—');
            $this->components->twoColumnDetail('Active', $workflow->is_active ? 'Yes' : 'No');
            $this->line('');
        }

        $statuses = StatusConfig::query()
            ->forFlow($flow)
            ->orderBy('position')
            ->get();

        if ($statuses->isEmpty()) {
            $this->components->warn("No statuses found for flow '{$flow}'.");

            return Command::SUCCESS;
        }

        $this->components->info('Statuses');
        $this->table(
            ['#', 'Code', 'Label', 'Kanban', 'Active'],
            $statuses->map(fn (StatusConfig $s) => [
                $s->position,
                $s->code,
                $s->label,
                $s->kanban_code ?? '—',
                $s->is_active ? '✓' : '✗',
            ])->all(),
        );

        $transitions = StatusTransition::query()
            ->forFlow($flow)
            ->orderBy('from_code')
            ->orderBy('position')
            ->get();

        if ($transitions->isNotEmpty()) {
            $this->line('');
            $this->components->info('Transitions');
            $this->table(
                ['From', 'To', 'Label', 'Capability', 'Guard', 'SLA'],
                $transitions->map(fn (StatusTransition $t) => [
                    $t->from_code,
                    $t->to_code,
                    $t->label ?? '—',
                    $t->capability ?? '—',
                    $t->guard_class ? class_basename($t->guard_class) : '—',
                    $t->sla_seconds ? $this->formatSla($t->sla_seconds) : '—',
                ])->all(),
            );
        }

        $columns = KanbanColumn::query()
            ->forFlow($flow)
            ->orderBy('position')
            ->get();

        if ($columns->isNotEmpty()) {
            $this->line('');
            $this->components->info('Kanban Columns');
            $this->table(
                ['#', 'Code', 'Label', 'WIP Limit'],
                $columns->map(fn (KanbanColumn $c) => [
                    $c->position,
                    $c->code,
                    $c->label,
                    $c->wip_limit ?? '—',
                ])->all(),
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Format SLA seconds into a human-readable string.
     */
    private function formatSla(int $seconds): string
    {
        if ($seconds >= 86400) {
            return round($seconds / 86400, 1).'d';
        }

        if ($seconds >= 3600) {
            return round($seconds / 3600, 1).'h';
        }

        return $seconds.'s';
    }
}
