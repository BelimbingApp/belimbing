<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Livewire\Workflows;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Workflow\Models\Workflow;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'label';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'label' => 'base_workflow.label',
        'code' => 'base_workflow.code',
        'module' => 'base_workflow.module',
        'status_configs_count' => 'status_configs_count',
        'transitions_count' => 'transitions_count',
        'kanban_columns_count' => 'kanban_columns_count',
        'is_active' => 'base_workflow.is_active',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'label' => 'asc',
                'code' => 'asc',
                'module' => 'asc',
                'status_configs_count' => 'desc',
                'transitions_count' => 'desc',
                'kanban_columns_count' => 'desc',
                'is_active' => 'desc',
            ],
        );
    }

    public function render(): View
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_workflow.label';

        return view('livewire.admin.workflows.index', [
            'workflows' => Workflow::query()
                ->withCount('statusConfigs', 'transitions', 'kanbanColumns')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('base_workflow.code', 'like', '%'.$search.'%')
                            ->orWhere('base_workflow.label', 'like', '%'.$search.'%')
                            ->orWhere('base_workflow.module', 'like', '%'.$search.'%');
                    });
                })
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('base_workflow.id')
                ->paginate(25),
        ]);
    }
}
