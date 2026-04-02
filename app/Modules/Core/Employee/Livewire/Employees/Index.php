<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Livewire\Employees;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component implements ProvidesLaraPageContext
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public string $typeFilter = 'all'; // all | human | agent

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
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
        return view('livewire.admin.employees.index', [
            'employees' => Employee::query()
                ->with('company', 'department.type', 'employeeType')
                ->when($this->search, function ($query, $search): void {
                    $query
                        ->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('short_name', 'like', '%'.$search.'%')
                        ->orWhere('employee_number', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('designation', 'like', '%'.$search.'%')
                        ->orWhere('job_description', 'like', '%'.$search.'%');
                })
                ->when($this->typeFilter === 'human', fn ($q) => $q->human())
                ->when($this->typeFilter === 'agent', fn ($q) => $q->agent())
                ->latest()
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
            visibleActions: ['Create employee', 'Search', 'Filter by type', 'Delete'],
            filters: $filters,
            searchQuery: $this->search !== '' ? $this->search : null,
        );
    }
}
