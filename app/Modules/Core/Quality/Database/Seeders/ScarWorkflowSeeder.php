<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Database\Seeders;

use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;
use App\Modules\Core\Quality\Models\Scar;
use Illuminate\Database\Seeder;

class ScarWorkflowSeeder extends Seeder
{
    /**
     * The flow identifier for the Quality SCAR workflow.
     */
    private const FLOW = 'quality_scar';

    /**
     * Seed the Quality SCAR workflow: registry, statuses, transitions, and kanban columns.
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
                'label' => 'Quality SCAR',
                'module' => 'quality',
                'description' => 'Supplier corrective action request lifecycle — from draft through supplier response to closure.',
                'model_class' => Scar::class,
                'is_active' => true,
            ],
        );
    }

    /**
     * Seed the status nodes for the Quality SCAR workflow.
     */
    private function seedStatuses(): void
    {
        $statuses = [
            ['code' => 'draft',                  'label' => 'Draft',                  'position' => 0,  'kanban_code' => 'backlog'],
            ['code' => 'issued',                 'label' => 'Issued',                 'position' => 1,  'kanban_code' => 'active'],
            ['code' => 'acknowledged',           'label' => 'Acknowledged',           'position' => 2,  'kanban_code' => 'active'],
            ['code' => 'containment_submitted',  'label' => 'Containment Submitted',  'position' => 3,  'kanban_code' => 'active'],
            ['code' => 'under_investigation',    'label' => 'Under Investigation',    'position' => 4,  'kanban_code' => 'active'],
            ['code' => 'response_submitted',     'label' => 'Response Submitted',     'position' => 5,  'kanban_code' => 'active'],
            ['code' => 'under_review',           'label' => 'Under Review',           'position' => 6,  'kanban_code' => 'active'],
            ['code' => 'action_required',        'label' => 'Action Required',        'position' => 7,  'kanban_code' => 'active'],
            ['code' => 'verification_pending',   'label' => 'Verification Pending',   'position' => 8,  'kanban_code' => 'active'],
            ['code' => 'closed',                 'label' => 'Closed',                 'position' => 9,  'kanban_code' => 'done'],
            ['code' => 'rejected',               'label' => 'Rejected',               'position' => 10, 'kanban_code' => 'done'],
            ['code' => 'cancelled',              'label' => 'Cancelled',              'position' => 11, 'kanban_code' => 'done'],
        ];

        foreach ($statuses as $status) {
            StatusConfig::query()->updateOrCreate(
                ['flow' => self::FLOW, 'code' => $status['code']],
                array_merge($status, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }

    /**
     * Seed the transition edges for the Quality SCAR workflow.
     */
    private function seedTransitions(): void
    {
        $transitions = [
            ['from_code' => 'draft',                 'to_code' => 'issued',                'label' => 'Issue to Supplier',  'capability' => 'workflow.quality_scar.issue',  'position' => 0],
            ['from_code' => 'draft',                 'to_code' => 'cancelled',             'label' => 'Cancel',             'capability' => 'workflow.quality_scar.cancel', 'position' => 1],
            ['from_code' => 'draft',                 'to_code' => 'rejected',              'label' => 'Reject',             'capability' => 'workflow.quality_scar.reject', 'position' => 2],
            ['from_code' => 'issued',                'to_code' => 'acknowledged',          'label' => 'Acknowledge',        'capability' => null, 'position' => 0],
            ['from_code' => 'issued',                'to_code' => 'cancelled',             'label' => 'Cancel',             'capability' => 'workflow.quality_scar.cancel', 'position' => 1],
            ['from_code' => 'acknowledged',          'to_code' => 'containment_submitted', 'label' => 'Submit Containment', 'capability' => null, 'position' => 0],
            ['from_code' => 'acknowledged',          'to_code' => 'under_investigation',   'label' => 'Begin Investigation', 'capability' => null, 'position' => 1],
            ['from_code' => 'containment_submitted', 'to_code' => 'under_investigation',   'label' => 'Begin Investigation', 'capability' => null, 'position' => 0],
            ['from_code' => 'under_investigation',   'to_code' => 'response_submitted',    'label' => 'Submit Response',    'capability' => null, 'position' => 0],
            ['from_code' => 'response_submitted',    'to_code' => 'under_review',          'label' => 'Begin Review',       'capability' => 'workflow.quality_scar.review', 'position' => 0],
            ['from_code' => 'under_review',          'to_code' => 'action_required',       'label' => 'Request Revision',   'capability' => 'workflow.quality_scar.rework', 'position' => 0],
            ['from_code' => 'under_review',          'to_code' => 'verification_pending',  'label' => 'Accept Response',    'capability' => 'workflow.quality_scar.accept', 'position' => 1],
            ['from_code' => 'action_required',       'to_code' => 'response_submitted',    'label' => 'Resubmit',           'capability' => null, 'position' => 0],
            ['from_code' => 'verification_pending',  'to_code' => 'closed',                'label' => 'Verify and Close',   'capability' => 'workflow.quality_scar.close',  'position' => 0],
            ['from_code' => 'verification_pending',  'to_code' => 'action_required',       'label' => 'Verification Failed', 'capability' => 'workflow.quality_scar.rework', 'position' => 1],
        ];

        foreach ($transitions as $transition) {
            StatusTransition::query()->updateOrCreate(
                ['flow' => self::FLOW, 'from_code' => $transition['from_code'], 'to_code' => $transition['to_code']],
                array_merge($transition, ['flow' => self::FLOW, 'is_active' => true]),
            );
        }
    }

    /**
     * Seed the kanban board columns for the Quality SCAR workflow.
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
