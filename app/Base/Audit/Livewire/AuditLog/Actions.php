<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Audit\Livewire\AuditLog;

use App\Base\Audit\Models\AuditAction;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Actions extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $filterActorType = '';

    public string $sortBy = 'occurred_at';

    public string $sortDir = 'desc';

    private const SORTABLE = [
        'occurred_at' => 'base_audit_actions.occurred_at',
        'event' => 'base_audit_actions.event',
        'actor_name' => 'users.name',
        'url' => 'base_audit_actions.url',
        'ip_address' => 'base_audit_actions.ip_address',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'occurred_at' => 'desc',
            ],
        );
    }

    public function updatedFilterActorType(): void
    {
        $this->resetPage();
    }

    public function toggleRetain(int $id): void
    {
        $action = AuditAction::query()->findOrFail($id);
        $action->is_retained = ! $action->is_retained;
        $action->save();
    }

    public function render(): View
    {
        return view('livewire.admin.audit.actions', [
            'actions' => $this->getActions(),
            'actorTypeOptions' => PrincipalType::orderedCases(),
        ]);
    }

    private function getActions(): LengthAwarePaginator
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_audit_actions.occurred_at';

        return AuditAction::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_actions.actor_id', '=', 'users.id')
                    ->where('base_audit_actions.actor_type', '=', PrincipalType::USER->value);
            })
            ->select('base_audit_actions.*', 'users.name as actor_name')
            ->when($this->search, function ($query, $search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('base_audit_actions.event', 'like', '%'.$search.'%')
                        ->orWhere('users.name', 'like', '%'.$search.'%');
                });
            })
            ->when($this->filterActorType, function ($query, $actorType): void {
                $query->where('base_audit_actions.actor_type', $actorType);
            })
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('base_audit_actions.id')
            ->paginate(25);
    }
}
