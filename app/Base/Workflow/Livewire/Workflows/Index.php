<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Livewire\Workflows;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Workflow\Models\Workflow;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public function render(): View
    {
        return view('livewire.admin.workflows.index', [
            'workflows' => Workflow::query()
                ->withCount('statusConfigs', 'transitions', 'kanbanColumns')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('code', 'like', '%'.$search.'%')
                            ->orWhere('label', 'like', '%'.$search.'%')
                            ->orWhere('module', 'like', '%'.$search.'%');
                    });
                })
                ->orderBy('label')
                ->paginate(25),
        ]);
    }
}
