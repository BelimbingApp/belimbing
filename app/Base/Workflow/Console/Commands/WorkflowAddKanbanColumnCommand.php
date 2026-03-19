<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Console\Commands;

use App\Base\Workflow\Models\KanbanColumn;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Add a kanban column definition to a workflow.
 */
#[AsCommand(name: 'blb:workflow:add-kanban-column')]
class WorkflowAddKanbanColumnCommand extends Command
{
    protected $signature = 'blb:workflow:add-kanban-column
                            {--flow= : Flow identifier}
                            {--code= : Column code (referenced by StatusConfig.kanban_code)}
                            {--label= : Display label}
                            {--position=0 : Column order}
                            {--wip-limit= : Max items allowed in this column}
                            {--description= : What this column represents}';

    protected $description = 'Add a kanban column definition to a workflow';

    public function handle(): int
    {
        $flow = $this->option('flow') ?? $this->ask('Flow');
        $code = $this->option('code') ?? $this->ask('Column code');

        if (! $flow || ! $code) {
            $this->components->error('Both --flow and --code are required.');

            return Command::FAILURE;
        }

        if (KanbanColumn::query()->where('flow', $flow)->where('code', $code)->exists()) {
            $this->components->error("Kanban column '{$code}' already exists in flow '{$flow}'.");

            return Command::FAILURE;
        }

        $label = $this->option('label') ?? $this->ask('Label', ucwords(str_replace('_', ' ', $code)));

        $attributes = [
            'flow' => $flow,
            'code' => $code,
            'label' => $label,
            'position' => (int) $this->option('position'),
            'description' => $this->option('description'),
        ];

        if ($this->option('wip-limit') !== null) {
            $attributes['wip_limit'] = (int) $this->option('wip-limit');
        }

        KanbanColumn::query()->create($attributes);

        $this->components->info("Kanban column '{$code}' added to flow '{$flow}' (position: {$attributes['position']}).");

        return Command::SUCCESS;
    }
}
