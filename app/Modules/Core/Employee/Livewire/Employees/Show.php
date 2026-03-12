<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Livewire\Employees;

use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\Employee\Models\EmployeeType;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Show extends Component
{
    use SavesValidatedFields;

    public Employee $employee;

    public int $attachAddressId = 0;

    public array $attachKind = [];

    public bool $attachIsPrimary = false;

    public int $attachPriority = 0;

    public bool $showAttachModal = false;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee->load([
            'company',
            'department.type',
            'supervisor',
            'user',
            'subordinates',
            'addresses',
        ]);
    }

    public function saveField(string $field, mixed $value): void
    {
        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'job_description' => ['nullable', 'string', 'max:65535'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:255'],
            'employee_number' => ['required', 'string', 'max:255'],
        ];

        $this->saveValidatedField($this->employee, $field, $value, $rules);
    }

    public function saveStatus(string $status): void
    {
        if (! in_array($status, ['pending', 'probation', 'active', 'inactive', 'terminated'])) {
            return;
        }

        $this->employee->status = $status;
        $this->employee->save();
    }

    public function saveEmployeeType(string $type): void
    {
        $exists = EmployeeType::query()->where('code', $type)->exists();
        if (! $exists) {
            return;
        }

        $this->employee->employee_type = $type;
        if ($type === 'agent') {
            $this->employee->user_id = null;
        }
        $this->employee->save();
    }

    public function saveDepartment(?int $departmentId): void
    {
        $this->employee->department_id = $departmentId ?: null;
        $this->employee->save();
        $this->employee->load('department.type');
    }

    public function saveSupervisor(?int $supervisorId): void
    {
        $this->employee->supervisor_id = $supervisorId ?: null;
        $this->employee->save();
        $this->employee->load('supervisor');
    }

    public function saveUser(?int $userId): void
    {
        $this->employee->user_id = $userId ?: null;
        $this->employee->save();
        $this->employee->load('user');
    }

    public function addSubordinate(int $employeeId): void
    {
        $target = Employee::query()->find($employeeId);
        if (! $target || $target->company_id !== $this->employee->company_id) {
            return;
        }

        $target->supervisor_id = $this->employee->id;
        $target->save();
        $this->employee->load('subordinates');
    }

    public function removeSubordinate(int $employeeId): void
    {
        $target = Employee::query()
            ->where('id', $employeeId)
            ->where('supervisor_id', $this->employee->id)
            ->first();

        if (! $target) {
            return;
        }

        $target->supervisor_id = null;
        $target->save();
        $this->employee->load('subordinates');
    }

    public function attachAddress(): void
    {
        if ($this->attachAddressId === 0) {
            return;
        }

        $this->employee->addresses()->attach($this->attachAddressId, [
            'kind' => $this->attachKind,
            'is_primary' => $this->attachIsPrimary,
            'priority' => $this->attachPriority,
            'valid_from' => now()->toDateString(),
        ]);

        $this->employee->load('addresses');
        $this->showAttachModal = false;
        $this->reset(['attachAddressId', 'attachKind', 'attachIsPrimary', 'attachPriority']);
        Session::flash('success', __('Address attached.'));
    }

    public function unlinkAddress(int $addressId): void
    {
        $this->employee->addresses()->detach($addressId);
        $this->employee->load('addresses');
        Session::flash('success', __('Address unlinked.'));
    }

    public function updateAddressPivot(int $addressId, string $field, mixed $value): void
    {
        // UI sends camelCase field names; DB uses snake_case column names.
        $allowed = ['isPrimary', 'priority'];
        if (! in_array($field, $allowed)) {
            return;
        }

        if ($field === 'isPrimary') {
            $value = (bool) $value;
        } elseif ($field === 'priority') {
            $value = (int) $value;
        }

        // Map UI field to DB column
        $column = $field === 'isPrimary' ? 'is_primary' : $field;

        $this->employee->addresses()->updateExistingPivot($addressId, [$column => $value]);
        $this->employee->load('addresses');
    }

    public function saveAddressKinds(int $addressId, array $kinds): void
    {
        $valid = ['headquarters', 'billing', 'shipping', 'branch', 'other'];
        $kinds = array_values(array_intersect($kinds, $valid));

        $this->employee->addresses()->updateExistingPivot($addressId, ['kind' => $kinds]);
        $this->employee->load('addresses');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.employees.show', [
            'departments' => Department::query()
                ->where('company_id', $this->employee->company_id)
                ->with('type')
                ->get(['id', 'company_id', 'department_type_id']),
            'supervisors' => Employee::query()
                ->where('company_id', $this->employee->company_id)
                ->where('id', '!=', $this->employee->id)
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
            'employeeTypes' => EmployeeType::query()->global()->orderBy('code')->get(['id', 'code', 'label']),
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'availableSubordinates' => Employee::query()
                ->where('company_id', $this->employee->company_id)
                ->where('id', '!=', $this->employee->id)
                ->where(function ($query) {
                    $query->whereNull('supervisor_id')
                        ->orWhere('supervisor_id', '!=', $this->employee->id);
                })
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
            'availableAddresses' => Address::query()
                ->whereNotIn('id', $this->employee->addresses->pluck('id')->toArray())
                ->orderBy('label')
                ->get(['id', 'label', 'line1', 'locality', 'country_iso']),
        ]);
    }
}
