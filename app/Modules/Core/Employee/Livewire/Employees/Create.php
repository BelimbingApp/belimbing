<?php

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

        $employee = Employee::query()->create([
            'company_id' => $validated['companyId'],
            'department_id' => $validated['departmentId'],
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
            'metadata' => $this->decodeJsonField(auth()->user()?->isPlatformAdmin() ? ($validated['metadataJson'] ?? null) : null),
        ]);

        if ($validated['userId'] !== null) {
            User::query()
                ->whereKey($validated['userId'])
                ->where('company_id', $employee->company_id)
                ->whereNull('employee_id')
                ->update(['employee_id' => $employee->id]);
        }

        Session::flash('success', __('Employee created successfully.'));

        $this->redirect(route('admin.employees.index'), navigate: true);
    }

    protected function rules(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return [
            'companyId' => [
                'required',
                'integer',
                Rule::exists(Company::class, 'id'),
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (! $user->isPlatformAdmin() && (int) $value !== $user->getCompanyId()) {
                        $fail(__('The selected company is not available for this tenant.'));
                    }
                },
            ],
            'departmentId' => [
                'nullable',
                'integer',
                Rule::exists(Department::class, 'id')->where('company_id', $this->companyId),
            ],
            'userId' => [
                'nullable',
                'integer',
                Rule::exists(User::class, 'id')
                    ->where('company_id', $this->companyId)
                    ->whereNull('employee_id'),
            ],
            'supervisorId' => [
                $this->employeeType === 'agent' ? 'required' : 'nullable',
                'integer',
                Rule::exists(Employee::class, 'id')->where('company_id', $this->companyId),
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
            'metadataJson' => [
                'nullable',
                'json',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (! $user->isPlatformAdmin() && filled($value)) {
                        $fail(__('Employee metadata can only be set by a platform administrator.'));
                    }
                },
            ],
        ];
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $companyScope = fn ($query) => $user->isPlatformAdmin()
            ? $query
            : $query->where('company_id', $user->getCompanyId());

        return view('livewire.admin.employees.create', [
            'companies' => Company::query()
                ->when(! $user->isPlatformAdmin(), fn ($q) => $q->where('id', $user->getCompanyId()))
                ->orderBy('name')
                ->get(['id', 'name']),
            'departments' => Department::query()
                ->with('type')
                ->when(! $user->isPlatformAdmin(), $companyScope)
                ->orderBy('department_type_id')
                ->get(['id', 'company_id', 'department_type_id']),
            'supervisors' => Employee::query()
                ->when(! $user->isPlatformAdmin(), $companyScope)
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'company_id']),
            'employeeTypes' => EmployeeType::query()->global()->orderBy('code')->get(['id', 'code', 'label', 'is_system']),
            'users' => User::query()
                ->whereNull('employee_id')
                ->when(! $user->isPlatformAdmin(), fn ($q) => $q->where('company_id', $user->getCompanyId()))
                ->orderBy('name')
                ->get(['id', 'name', 'company_id', 'employee_id']),
        ]);
    }
}
