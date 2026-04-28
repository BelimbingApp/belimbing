<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Livewire\Employees;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component implements ProvidesLaraPageContext
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $typeFilter = 'all'; // all | human | agent

    public string $sortBy = 'full_name';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'full_name' => 'employees.full_name',
        'company_name' => 'companies.name',
        'employee_type_label' => 'employee_types.label',
        'status' => 'employees.status',
    ];

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'full_name' => 'asc',
                'company_name' => 'asc',
                'employee_type_label' => 'asc',
                'status' => 'asc',
            ],
        );
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'terminated' => 'danger',
            'probation' => 'warning',
            'inactive', 'pending' => 'default',
            default => 'default',
        };
    }

    public function employeeTypeLabel(Employee $employee): string
    {
        return $employee->employeeType?->label ?? ucfirst(str_replace('_', ' ', $employee->employee_type));
    }

    public function delete(int $employeeId): void
    {
        $employee = Employee::query()->findOrFail($employeeId);

        $employee->delete();

        Session::flash('success', __('Employee deleted successfully.'));
    }

    public function render(): View
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'employees.full_name';

        return view('livewire.admin.employees.index', [
            'employees' => Employee::query()
                ->select('employees.*')
                ->with('company', 'department.type', 'employeeType')
                ->leftJoin('companies', 'employees.company_id', '=', 'companies.id')
                ->leftJoin('employee_types', 'employees.employee_type', '=', 'employee_types.code')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function (Builder $q) use ($search): void {
                        $q->where('employees.full_name', 'like', '%'.$search.'%')
                            ->orWhere('employees.short_name', 'like', '%'.$search.'%')
                            ->orWhere('employees.employee_number', 'like', '%'.$search.'%')
                            ->orWhere('employees.email', 'like', '%'.$search.'%')
                            ->orWhere('employees.designation', 'like', '%'.$search.'%')
                            ->orWhere('employees.job_description', 'like', '%'.$search.'%');
                    });
                })
                ->when($this->typeFilter === 'human', fn ($q) => $q->human())
                ->when($this->typeFilter === 'agent', fn ($q) => $q->agent())
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('employees.id')
                ->paginate(15),
        ]);
    }

    public function pageContext(): PageContext
    {
        $filters = [];

        if ($this->typeFilter !== 'all') {
            $filters[] = 'type:'.$this->typeFilter;
        }

        return new PageContext(
            route: 'admin.employees.index',
            url: route('admin.employees.index'),
            title: 'Employees',
            module: 'Employee',
            resourceType: 'employee',
            visibleActions: ['Create employee', 'Search', 'Filter by type', 'Row actions'],
            filters: $filters,
            searchQuery: $this->search !== '' ? $this->search : null,
        );
    }
}
