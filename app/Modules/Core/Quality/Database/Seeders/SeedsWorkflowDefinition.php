<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Database\Seeders;

use App\Base\Workflow\Models\KanbanColumn;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use App\Base\Workflow\Models\Workflow;

trait SeedsWorkflowDefinition
{
    /**
     * @return array{code: string, label: string, module: string, description: string, model_class: class-string, is_active?: bool}
     */
    abstract protected function workflowDefinition(): array;

    /**
     * @return list<array{code: string, label: string, position: int, kanban_code: string}>
     */
    abstract protected function workflowStatuses(): array;

    /**
     * @return list<array{from_code: string, to_code: string, label: string, capability: ?string, position: int}>
     */
    abstract protected function workflowTransitions(): array;

    /**
     * @return list<array{code: string, label: string, position: int}>
     */
    abstract protected function workflowKanbanColumns(): array;

    protected function seedWorkflowDefinition(): void
    {
        $workflow = $this->workflowDefinition();

        Workflow::query()->updateOrCreate(
            ['code' => $workflow['code']],
            $workflow + ['is_active' => true],
        );

        foreach ($this->workflowStatuses() as $status) {
            StatusConfig::query()->updateOrCreate(
                ['flow' => $workflow['code'], 'code' => $status['code']],
                $status + ['flow' => $workflow['code'], 'is_active' => true],
            );
        }

        foreach ($this->workflowTransitions() as $transition) {
            StatusTransition::query()->updateOrCreate(
                ['flow' => $workflow['code'], 'from_code' => $transition['from_code'], 'to_code' => $transition['to_code']],
                $transition + ['flow' => $workflow['code'], 'is_active' => true],
            );
        }

        foreach ($this->workflowKanbanColumns() as $column) {
            KanbanColumn::query()->updateOrCreate(
                ['flow' => $workflow['code'], 'code' => $column['code']],
                $column + ['flow' => $workflow['code'], 'is_active' => true],
            );
        }
    }
}
