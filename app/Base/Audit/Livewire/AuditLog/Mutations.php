<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Audit\Livewire\AuditLog;

use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Mutations extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $filterEvent = '';

    public string $sortBy = 'occurred_at';

    public string $sortDir = 'desc';

    private const SORTABLE = [
        'occurred_at' => 'base_audit_mutations.occurred_at',
        'event' => 'base_audit_mutations.event',
        'actor_name' => 'users.name',
        'auditable_type' => 'base_audit_mutations.auditable_type',
        'auditable_id' => 'base_audit_mutations.auditable_id',
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

    public function updatedFilterEvent(): void
    {
        $this->resetPage();
    }

    /**
     * Override ResetsPaginationOnSearch to use the default paginator.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.audit.mutations', [
            'mutations' => $this->getMutations(),
        ]);
    }

    private function getMutations(): LengthAwarePaginator
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_audit_mutations.occurred_at';

        return AuditMutation::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_audit_mutations.actor_id', '=', 'users.id')
                    ->where('base_audit_mutations.actor_type', '=', PrincipalType::USER->value);
            })
            ->select('base_audit_mutations.*', 'users.name as actor_name')
            ->when($this->search, function ($query, $search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('users.name', 'like', '%'.$search.'%')
                        ->orWhere('base_audit_mutations.auditable_type', 'like', '%'.$search.'%')
                        ->orWhere('base_audit_mutations.event', 'like', '%'.$search.'%');
                });
            })
            ->when($this->filterEvent, function ($query, $event): void {
                $query->where('base_audit_mutations.event', $event);
            })
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('base_audit_mutations.id')
            ->paginate(25);
    }
}
