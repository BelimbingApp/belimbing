<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Operation\Quality\Database\Seeders;

use App\Modules\Operation\Quality\Models\Ncr;
use Illuminate\Database\Seeder;

class NcrWorkflowSeeder extends Seeder
{
    use SeedsWorkflowDefinition;

    protected function workflowDefinition(): array
    {
        return [
            'code' => 'quality_ncr',
            'label' => 'Quality NCR',
            'module' => 'quality',
            'description' => 'Nonconformance report lifecycle — from open through CAPA to closure.',
            'model_class' => Ncr::class,
            'is_active' => true,
        ];
    }

    protected function workflowStatuses(): array
    {
        return [
            ['code' => 'open',          'label' => 'Open',          'position' => 0, 'kanban_code' => 'backlog'],
            ['code' => 'under_triage',  'label' => 'Under Triage',  'position' => 1, 'kanban_code' => 'active'],
            ['code' => 'assigned',      'label' => 'Assigned',      'position' => 2, 'kanban_code' => 'active'],
            ['code' => 'in_progress',   'label' => 'In Progress',   'position' => 3, 'kanban_code' => 'active'],
            ['code' => 'under_review',  'label' => 'Under Review',  'position' => 4, 'kanban_code' => 'active'],
            ['code' => 'verified',      'label' => 'Verified',      'position' => 5, 'kanban_code' => 'active'],
            ['code' => 'closed',        'label' => 'Closed',        'position' => 6, 'kanban_code' => 'done'],
            ['code' => 'rejected',      'label' => 'Rejected',      'position' => 7, 'kanban_code' => 'done'],
        ];
    }

    protected function workflowTransitions(): array
    {
        return [
            ['from_code' => 'open',          'to_code' => 'under_triage', 'label' => 'Triage',             'capability' => 'operations.quality.ncr.triage', 'position' => 0],
            ['from_code' => 'open',          'to_code' => 'rejected',     'label' => 'Reject',             'capability' => 'operations.quality.ncr.reject', 'position' => 1],
            ['from_code' => 'under_triage',  'to_code' => 'assigned',     'label' => 'Assign',             'capability' => 'operations.quality.ncr.assign', 'position' => 0],
            ['from_code' => 'under_triage',  'to_code' => 'rejected',     'label' => 'Reject',             'capability' => 'operations.quality.ncr.reject', 'position' => 1],
            ['from_code' => 'assigned',      'to_code' => 'in_progress',  'label' => 'Start Investigation', 'capability' => null, 'position' => 0],
            ['from_code' => 'in_progress',   'to_code' => 'under_review', 'label' => 'Submit Response',    'capability' => null, 'position' => 0],
            ['from_code' => 'under_review',  'to_code' => 'in_progress',  'label' => 'Request Rework',     'capability' => 'operations.quality.ncr.rework', 'position' => 0],
            ['from_code' => 'under_review',  'to_code' => 'verified',     'label' => 'Verify Effective',   'capability' => 'operations.quality.ncr.verify', 'position' => 1],
            ['from_code' => 'under_review',  'to_code' => 'rejected',     'label' => 'Reject',             'capability' => 'operations.quality.ncr.reject', 'position' => 2],
            ['from_code' => 'verified',      'to_code' => 'closed',       'label' => 'Close',              'capability' => 'operations.quality.ncr.close',  'position' => 0],
        ];
    }
}
