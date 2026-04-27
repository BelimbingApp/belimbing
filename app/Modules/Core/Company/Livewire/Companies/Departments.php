<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Company\Models\DepartmentType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Departments extends Component
{
    use WithPagination;

    public Company $company;

    public bool $showCreateModal = false;

    public int $createDepartmentTypeId = 0;

    public string $createStatus = 'active';

    public function mount(Company $company): void
    {
        $this->company = $company;
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

        return view('livewire.admin.companies.departments', [
            'departments' => Department::query()
                ->where('company_id', $this->company->id)
                ->with('type')
                ->paginate(15),
            'availableTypes' => DepartmentType::query()
                ->active()
                ->whereNotIn('id', $existingTypeIds)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
