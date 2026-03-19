<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Console\Commands;

use App\Base\Workflow\Services\StatusManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Add a status node to a workflow's directed graph.
 */
#[AsCommand(name: 'blb:workflow:add-status')]
class WorkflowAddStatusCommand extends Command
{
    protected $signature = 'blb:workflow:add-status
                            {--flow= : Flow identifier (e.g., leave_application)}
                            {--code= : Status code (e.g., pending_approval)}
                            {--label= : Human-readable label}
                            {--position=0 : Display order}
                            {--kanban-code= : Kanban column code to map to}
                            {--prompt= : AI guidance prompt for this status}
                            {--pic= : Person(s)-in-charge as JSON array}
                            {--comment-tags= : Available comment tags as JSON array}';

    protected $description = 'Add a status config node to a workflow';

    public function handle(StatusManager $statusManager): int
    {
        $flow = $this->option('flow') ?? $this->ask('Flow');
        $code = $this->option('code') ?? $this->ask('Status code');

        if (! $flow || ! $code) {
            $this->components->error('Both --flow and --code are required.');

            return Command::FAILURE;
        }

        if ($statusManager->getStatus($flow, $code) !== null) {
            $this->components->error("Status '{$code}' already exists in flow '{$flow}'.");

            return Command::FAILURE;
        }

        $label = $this->option('label') ?? $this->ask('Label', ucwords(str_replace('_', ' ', $code)));

        $attributes = [
            'flow' => $flow,
            'code' => $code,
            'label' => $label,
            'position' => (int) $this->option('position'),
            'kanban_code' => $this->option('kanban-code'),
            'prompt' => $this->option('prompt'),
        ];

        if ($this->option('pic')) {
            $attributes['pic'] = json_decode($this->option('pic'), true);
        }

        if ($this->option('comment-tags')) {
            $attributes['comment_tags'] = json_decode($this->option('comment-tags'), true);
        }

        $status = $statusManager->create($attributes);

        $this->components->info("Status '{$status->code}' added to flow '{$flow}' (position: {$status->position}).");

        return Command::SUCCESS;
    }
}
