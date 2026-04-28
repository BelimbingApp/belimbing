<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Company\Models\DepartmentType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Departments extends Component
{
    use TogglesSort;
    use WithPagination;

    public Company $company;

    public bool $showCreateModal = false;

    public int $createDepartmentTypeId = 0;

    public string $createStatus = 'active';

    public string $sortBy = 'type_name';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'type_name' => 'company_department_types.name',
        'category' => 'company_department_types.category',
        'status' => 'company_departments.status',
    ];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'type_name' => 'asc',
                'category' => 'asc',
                'status' => 'asc',
            ],
        );
    }

    public function createDepartment(): void
    {
        if ($this->createDepartmentTypeId === 0) {
            return;
        }

        Department::query()->create([
            'company_id' => $this->company->id,
            'department_type_id' => $this->createDepartmentTypeId,
            'status' => $this->createStatus,
        ]);

        $this->showCreateModal = false;
        $this->reset(['createDepartmentTypeId', 'createStatus']);
        Session::flash('success', __('Department created.'));
    }

    public function saveStatus(int $departmentId, string $status): void
    {
        if (! in_array($status, ['active', 'inactive', 'suspended'])) {
            return;
        }

        $dept = Department::query()->findOrFail($departmentId);
        $dept->status = $status;
        $dept->save();

        Session::flash('success', __('Department status updated.'));
    }

    public function deleteDepartment(int $departmentId): void
    {
        Department::query()->findOrFail($departmentId)->delete();
        Session::flash('success', __('Department deleted.'));
    }

    public function render(): View
    {
        $existingTypeIds = Department::query()
            ->where('company_id', $this->company->id)
            ->pluck('department_type_id')
            ->toArray();

        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'company_department_types.name';

        return view('livewire.admin.companies.departments', [
            'departments' => Department::query()
                ->select('company_departments.*')
                ->where('company_departments.company_id', $this->company->id)
                ->leftJoin('company_department_types', 'company_departments.department_type_id', '=', 'company_department_types.id')
                ->with('type')
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('company_departments.id')
                ->paginate(15),
            'availableTypes' => DepartmentType::query()
                ->active()
                ->whereNotIn('id', $existingTypeIds)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
