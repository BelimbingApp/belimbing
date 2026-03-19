<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Business\IT\Database\Seeders;

use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use App\Modules\Business\IT\Models\Ticket;
use Illuminate\Database\Seeder;

class TicketWorkflowSeeder extends Seeder
{
    /**
     * The flow identifier for the IT Ticket workflow.
     */
    private const FLOW = 'it_ticket';

    /**
     * Seed the IT Ticket workflow: registry, statuses, transitions, and kanban columns.
     */
    public function run(): void
    {
        $this->seedWorkflow();
        $this->seedStatuses();
        $this->seedTransitions();
        $this->seedKanbanColumns();
    }

    /**
     * Register the workflow in the process registry.
     */
    private function seedWorkflow(): void
    {
        Workflow::query()->updateOrCreate(
            ['code' => self::FLOW],
            [
                'label' => 'IT Ticket',
                'module' => 'it_ticket',
                'description' => 'IT support ticket lifecycle — from open to resolution.',
                'model_class' => Ticket::class,
                'is_active' => true,
            ],
        );
    }

    /**
     * Seed the status nodes for the IT Ticket workflow.
     */
    private function seedStatuses(): void
    {
        $statuses = [
            ['code' => 'open',           'label' => 'Open',           'position' => 0, 'kanban_code' => 'backlog'],
            ['code' => 'assigned',       'label' => 'Assigned',       'position' => 1, 'kanban_code' => 'active'],
            ['code' => 'in_progress',    'label' => 'In Progress',    'position' => 2, 'kanban_code' => 'active'],
            ['code' => 'awaiting_parts', 'label' => 'Awaiting Parts', 'position' => 3, 'kanban_code' => 'active'],
            ['code' => 'resolved',       'label' => 'Resolved',       'position' => 4, 'kanban_code' => 'done'],
            ['code' => 'closed',         'label' => 'Closed',         'position' => 5, 'kanban_code' => 'done'],
        ];

        foreach ($statuses as $status) {
            StatusConfig::query()->updateOrCreate(
                ['flow' => self::FLOW, 'code' => $status['code']],
                array_merge($status, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }

    /**
     * Seed the transition edges for the IT Ticket workflow.
     */
    private function seedTransitions(): void
    {
        $transitions = [
            ['from_code' => 'open',        'to_code' => 'assigned',       'label' => 'Assign',      'capability' => 'workflow.it_ticket.assign', 'position' => 0],
            ['from_code' => 'assigned',    'to_code' => 'in_progress',    'label' => 'Start Work',   'capability' => null, 'position' => 0],
            ['from_code' => 'in_progress', 'to_code' => 'awaiting_parts', 'label' => 'Await Parts',  'capability' => null, 'position' => 0],
            ['from_code' => 'awaiting_parts', 'to_code' => 'in_progress', 'label' => 'Resume',       'capability' => null, 'position' => 0],
            ['from_code' => 'in_progress', 'to_code' => 'resolved',      'label' => 'Resolve',      'capability' => null, 'position' => 1],
            ['from_code' => 'resolved',    'to_code' => 'closed',         'label' => 'Close',        'capability' => null, 'position' => 0],
            ['from_code' => 'resolved',    'to_code' => 'open',           'label' => 'Reopen',       'capability' => null, 'position' => 1],
        ];

        foreach ($transitions as $transition) {
            StatusTransition::query()->updateOrCreate(
                ['flow' => self::FLOW, 'from_code' => $transition['from_code'], 'to_code' => $transition['to_code']],
                array_merge($transition, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }

    /**
     * Seed the kanban board columns for the IT Ticket workflow.
     */
    private function seedKanbanColumns(): void
    {
        $columns = [
            ['code' => 'backlog', 'label' => 'Backlog', 'position' => 0],
            ['code' => 'active',  'label' => 'Active',  'position' => 1],
            ['code' => 'done',    'label' => 'Done',     'position' => 2],
        ];

        foreach ($columns as $column) {
            KanbanColumn::query()->updateOrCreate(
                ['flow' => self::FLOW, 'code' => $column['code']],
                array_merge($column, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }
}
