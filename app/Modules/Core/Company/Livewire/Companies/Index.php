<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public string $sortBy = 'name';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'name' => 'companies.name',
        'status' => 'companies.status',
        'jurisdiction' => 'companies.jurisdiction',
    ];

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'name' => 'asc',
                'status' => 'asc',
                'jurisdiction' => 'asc',
            ],
        );
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'suspended' => 'danger',
            'pending' => 'warning',
            default => 'default',
        };
    }

    public function delete(int $companyId): void
    {
        $company = Company::query()->withCount('children')->findOrFail($companyId);

        if ($company->id === Company::LICENSEE_ID) {
            Session::flash('error', __('The licensee company cannot be deleted.'));

            return;
        }

        if ($company->children_count > 0) {
            Session::flash('error', __('Cannot delete a company that has subsidiaries.'));

            return;
        }

        $company->delete();

        Session::flash('success', __('Company deleted successfully.'));
    }

    public function render(): View
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'companies.name';

        return view('livewire.admin.companies.index', [
            'companies' => Company::query()
                ->with('parent')
                ->when($this->statusFilter !== 'all', fn (Builder $q) => $q->where('companies.status', $this->statusFilter))
                ->when($this->search, function ($query, $search): void {
                    $query->where(function (Builder $q) use ($search): void {
                        $q->where('companies.name', 'like', '%'.$search.'%')
                            ->orWhere('companies.legal_name', 'like', '%'.$search.'%')
                            ->orWhere('companies.code', 'like', '%'.$search.'%')
                            ->orWhere('companies.email', 'like', '%'.$search.'%')
                            ->orWhere('companies.jurisdiction', 'like', '%'.$search.'%');
                    });
                })
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('companies.id')
                ->paginate(15),
        ]);
    }
}
