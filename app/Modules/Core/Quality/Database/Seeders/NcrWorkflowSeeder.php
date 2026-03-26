<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Database\Seeders;

use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use App\Modules\Core\Quality\Models\Ncr;
use Illuminate\Database\Seeder;

class NcrWorkflowSeeder extends Seeder
{
    /**
     * The flow identifier for the Quality NCR workflow.
     */
    private const FLOW = 'quality_ncr';

    /**
     * Seed the Quality NCR workflow: registry, statuses, transitions, and kanban columns.
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
                'label' => 'Quality NCR',
                'module' => 'quality',
                'description' => 'Nonconformance report lifecycle — from open through CAPA to closure.',
                'model_class' => Ncr::class,
                'is_active' => true,
            ],
        );
    }

    /**
     * Seed the status nodes for the Quality NCR workflow.
     */
    private function seedStatuses(): void
    {
        $statuses = [
            ['code' => 'open',          'label' => 'Open',          'position' => 0, 'kanban_code' => 'backlog'],
            ['code' => 'under_triage',  'label' => 'Under Triage',  'position' => 1, 'kanban_code' => 'active'],
            ['code' => 'assigned',      'label' => 'Assigned',      'position' => 2, 'kanban_code' => 'active'],
            ['code' => 'in_progress',   'label' => 'In Progress',   'position' => 3, 'kanban_code' => 'active'],
            ['code' => 'under_review',  'label' => 'Under Review',  'position' => 4, 'kanban_code' => 'active'],
            ['code' => 'verified',      'label' => 'Verified',      'position' => 5, 'kanban_code' => 'active'],
            ['code' => 'closed',        'label' => 'Closed',        'position' => 6, 'kanban_code' => 'done'],
            ['code' => 'rejected',      'label' => 'Rejected',      'position' => 7, 'kanban_code' => 'done'],
        ];

        foreach ($statuses as $status) {
            StatusConfig::query()->updateOrCreate(
                ['flow' => self::FLOW, 'code' => $status['code']],
                array_merge($status, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }

    /**
     * Seed the transition edges for the Quality NCR workflow.
     */
    private function seedTransitions(): void
    {
        $transitions = [
            ['from_code' => 'open',          'to_code' => 'under_triage', 'label' => 'Triage',             'capability' => 'workflow.quality_ncr.triage', 'position' => 0],
            ['from_code' => 'open',          'to_code' => 'rejected',     'label' => 'Reject',             'capability' => 'workflow.quality_ncr.reject', 'position' => 1],
            ['from_code' => 'under_triage',  'to_code' => 'assigned',     'label' => 'Assign',             'capability' => 'workflow.quality_ncr.assign', 'position' => 0],
            ['from_code' => 'under_triage',  'to_code' => 'rejected',     'label' => 'Reject',             'capability' => 'workflow.quality_ncr.reject', 'position' => 1],
            ['from_code' => 'assigned',      'to_code' => 'in_progress',  'label' => 'Start Investigation', 'capability' => null, 'position' => 0],
            ['from_code' => 'in_progress',   'to_code' => 'under_review', 'label' => 'Submit Response',    'capability' => null, 'position' => 0],
            ['from_code' => 'under_review',  'to_code' => 'in_progress',  'label' => 'Request Rework',     'capability' => 'workflow.quality_ncr.rework', 'position' => 0],
            ['from_code' => 'under_review',  'to_code' => 'verified',     'label' => 'Verify Effective',   'capability' => 'workflow.quality_ncr.verify', 'position' => 1],
            ['from_code' => 'under_review',  'to_code' => 'rejected',     'label' => 'Reject',             'capability' => 'workflow.quality_ncr.reject', 'position' => 2],
            ['from_code' => 'verified',      'to_code' => 'closed',       'label' => 'Close',              'capability' => 'workflow.quality_ncr.close',  'position' => 0],
        ];

        foreach ($transitions as $transition) {
            StatusTransition::query()->updateOrCreate(
                ['flow' => self::FLOW, 'from_code' => $transition['from_code'], 'to_code' => $transition['to_code']],
                array_merge($transition, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }

    /**
     * Seed the kanban board columns for the Quality NCR workflow.
     */
    private function seedKanbanColumns(): void
    {
        $columns = [
            ['code' => 'backlog', 'label' => 'Backlog', 'position' => 0],
            ['code' => 'active',  'label' => 'Active',  'position' => 1],
            ['code' => 'done',    'label' => 'Done',    'position' => 2],
        ];

        foreach ($columns as $column) {
            KanbanColumn::query()->updateOrCreate(
                ['flow' => self::FLOW, 'code' => $column['code']],
                array_merge($column, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }
}
