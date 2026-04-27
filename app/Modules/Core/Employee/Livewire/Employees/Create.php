<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Livewire\Employees;

use App\Base\Foundation\Livewire\Concerns\DecodesJsonFields;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\Employee\Models\EmployeeType;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    use DecodesJsonFields;

    public ?int $companyId = null;

    public ?int $departmentId = null;

    public ?int $supervisorId = null;

    public string $employeeNumber = '';

    public string $fullName = '';

    public ?string $shortName = null;

    public ?string $designation = null;

    public string $employeeType = 'full_time';

    public string $status = 'active';

    public ?string $email = null;

    public ?string $mobileNumber = null;

    public ?string $employmentStart = null;

    public ?string $employmentEnd = null;

    public ?string $jobDescription = null;

    public ?int $userId = null;

    public string $metadataJson = '';

    public function store(): void
    {
        $validated = $this->validate($this->rules());

        if (($validated['employeeType'] ?? '') === 'agent') {
            $validated['userId'] = null;
        }

        Employee::query()->create([
            'company_id' => $validated['companyId'],
            'department_id' => $validated['departmentId'],
            'user_id' => $validated['userId'],
            'supervisor_id' => $validated['supervisorId'],
            'employee_number' => $validated['employeeNumber'],
            'full_name' => $validated['fullName'],
            'short_name' => $validated['shortName'],
            'designation' => $validated['designation'],
            'employee_type' => $validated['employeeType'],
            'job_description' => $validated['jobDescription'] ?? null,
            'email' => $validated['email'],
            'mobile_number' => $validated['mobileNumber'],
            'status' => $validated['status'],
            'employment_start' => $validated['employmentStart'],
            'employment_end' => $validated['employmentEnd'],
            'metadata' => $this->decodeJsonField($validated['metadataJson'] ?? null),
        ]);

        Session::flash('success', __('Employee created successfully.'));

        $this->redirect(route('admin.employees.index'), navigate: true);
    }

    protected function rules(): array
    {
        return [
            'companyId' => ['required', 'integer', Rule::exists(Company::class, 'id')],
            'departmentId' => ['nullable', 'integer', 'exists:company_departments,id'],
            'userId' => ['nullable', 'integer', 'exists:users,id'],
            'supervisorId' => [
                $this->employeeType === 'agent' ? 'required' : 'nullable',
                'integer',
                Rule::exists(Employee::class, 'id'),
            ],
            'employeeNumber' => ['required', 'string', 'max:255', Rule::unique('employees')->where('company_id', $this->companyId)],
            'fullName' => ['required', 'string', 'max:255'],
            'shortName' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'employeeType' => ['required', Rule::exists(EmployeeType::class, 'code')],
            'jobDescription' => ['nullable', 'string', 'max:65535'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobileNumber' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:pending,probation,active,inactive,terminated'],
            'employmentStart' => ['nullable', 'date'],
            'employmentEnd' => ['nullable', 'date'],
            'metadataJson' => ['nullable', 'json'],
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.employees.create', [
            'companies' => Company::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'departments' => Department::query()
                ->with('type')
                ->orderBy('department_type_id')
                ->get(['id', 'company_id', 'department_type_id']),
            'supervisors' => Employee::query()
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'company_id']),
            'employeeTypes' => EmployeeType::query()->global()->orderBy('code')->get(['id', 'code', 'label', 'is_system']),
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
