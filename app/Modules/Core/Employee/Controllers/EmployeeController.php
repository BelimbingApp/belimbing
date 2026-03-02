<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Controllers;

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\Employee\Models\EmployeeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController
{
    /**
     * Show the employee list page.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $typeFilter = $request->string('type_filter', 'all')->toString();

        $employees = Employee::query()
            ->with('company', 'department.type', 'employeeType')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('short_name', 'like', '%'.$search.'%')
                        ->orWhere('employee_number', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('designation', 'like', '%'.$search.'%')
                        ->orWhere('job_description', 'like', '%'.$search.'%');
                });
            })
            ->when($typeFilter === 'human', fn ($query) => $query->human())
            ->when($typeFilter === 'digital_worker', fn ($query) => $query->digitalWorker())
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('employees.index', compact('employees', 'search', 'typeFilter'));
    }

    /**
     * Return the searchable table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $typeFilter = $request->string('type_filter', 'all')->toString();

        $employees = Employee::query()
            ->with('company', 'department.type', 'employeeType')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('short_name', 'like', '%'.$search.'%')
                        ->orWhere('employee_number', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('designation', 'like', '%'.$search.'%')
                        ->orWhere('job_description', 'like', '%'.$search.'%');
                });
            })
            ->when($typeFilter === 'human', fn ($query) => $query->human())
            ->when($typeFilter === 'digital_worker', fn ($query) => $query->digitalWorker())
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('employees.partials.table', compact('employees'));
    }

    /**
     * Show the create-employee form.
     */
    public function create(): View
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);
        $departments = Department::query()->with('type')->orderBy('department_type_id')->get(['id', 'company_id', 'department_type_id']);
        $supervisors = Employee::query()->orderBy('full_name')->get(['id', 'full_name']);
        $employeeTypes = EmployeeType::query()->global()->orderBy('code')->get(['id', 'code', 'label', 'is_system']);

        return view('employees.create', compact('companies', 'departments', 'supervisors', 'employeeTypes'));
    }

    /**
     * Store a newly created employee.
     */
    public function store(Request $request): RedirectResponse
    {
        $employeeType = $request->string('employee_type', '')->toString();

        $validated = $request->validate([
            'company_id' => ['required', 'integer', Rule::exists(Company::class, 'id')],
            'department_id' => ['nullable', 'integer', 'exists:company_departments,id'],
            'supervisor_id' => [
                $employeeType === 'digital_worker' ? 'required' : 'nullable',
                'integer',
                Rule::exists(Employee::class, 'id'),
            ],
            'employee_number' => ['required', 'string', 'max:255', Rule::unique('employees')->where('company_id', $request->input('company_id'))],
            'full_name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'employee_type' => ['required', Rule::exists(EmployeeType::class, 'code')],
            'job_description' => ['nullable', 'string', 'max:65535'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:pending,probation,active,inactive,terminated'],
            'employment_start' => ['nullable', 'date'],
            'employment_end' => ['nullable', 'date'],
            'metadata_json' => ['nullable', 'json'],
        ]);

        $validated['metadata'] = $this->decodeJsonField($validated['metadata_json'] ?? null);
        unset($validated['metadata_json']);

        Employee::query()->create($validated);

        Session::flash('success', __('Employee created successfully.'));

        return redirect()->route('admin.employees.index');
    }

    /**
     * Show the employee detail page.
     */
    public function show(Employee $employee): View
    {
        $employee->load(['company', 'department.type', 'supervisor', 'user', 'subordinates.department.type', 'addresses']);

        $departments = Department::query()
            ->where('company_id', $employee->company_id)
            ->with('type')
            ->get(['id', 'company_id', 'department_type_id']);

        $supervisors = Employee::query()
            ->where('company_id', $employee->company_id)
            ->where('id', '!=', $employee->id)
            ->orderBy('full_name')
            ->get(['id', 'full_name']);

        $employeeTypes = EmployeeType::query()->global()->orderBy('code')->get(['id', 'code', 'label']);

        $availableAddresses = Address::query()
            ->whereNotIn('id', $employee->addresses->pluck('id')->all())
            ->orderBy('label')
            ->get(['id', 'label', 'line1', 'locality', 'country_iso']);

        return view('employees.show', compact('employee', 'departments', 'supervisors', 'employeeTypes', 'availableAddresses'));
    }

    /**
     * Delete an employee.
     */
    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        Session::flash('success', __('Employee deleted successfully.'));

        return redirect()->route('admin.employees.index');
    }

    /**
     * Update a single employee field from inline edit forms.
     */
    public function updateField(Request $request, Employee $employee): RedirectResponse
    {
        $field = $request->string('field')->toString();
        $allowed = [
            'full_name',
            'short_name',
            'designation',
            'job_description',
            'email',
            'mobile_number',
            'employee_number',
            'department_id',
            'supervisor_id',
            'employee_type',
            'status',
        ];

        if (! in_array($field, $allowed, true)) {
            Session::flash('error', __('Unsupported field update request.'));

            return redirect()->route('admin.employees.show', $employee);
        }

        $rules = [
            'full_name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'job_description' => ['nullable', 'string', 'max:65535'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:255'],
            'employee_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('employees', 'employee_number')
                    ->where('company_id', $employee->company_id)
                    ->ignore($employee->id),
            ],
            'department_id' => ['nullable', 'integer', 'exists:company_departments,id'],
            'supervisor_id' => ['nullable', 'integer', Rule::exists(Employee::class, 'id')],
            'employee_type' => ['required', Rule::exists(EmployeeType::class, 'code')],
            'status' => ['required', 'in:pending,probation,active,inactive,terminated'],
        ];

        $validated = $request->validate([
            'value' => $rules[$field],
        ]);

        $value = $validated['value'];

        if (in_array($field, ['department_id', 'supervisor_id'], true) && ($value === '' || $value === null)) {
            $value = null;
        }

        if ($field === 'employee_type' && $value === 'digital_worker') {
            $employee->setAttribute('job_description', $employee->job_description);
        }

        $employee->setAttribute($field, $value);
        $employee->save();

        Session::flash('success', __('Employee updated successfully.'));

        return redirect()->route('admin.employees.show', $employee);
    }

    /**
     * Attach an address to the employee.
     */
    public function attachAddress(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'address_id' => ['required', 'integer', Rule::exists(Address::class, 'id')],
            'kind' => ['nullable', 'array'],
            'kind.*' => ['string', Rule::in(['headquarters', 'billing', 'shipping', 'branch', 'other'])],
            'is_primary' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($employee->addresses()->where('address_id', $validated['address_id'])->exists()) {
            Session::flash('error', __('Address is already attached.'));

            return redirect()->route('admin.employees.show', $employee);
        }

        $employee->addresses()->attach($validated['address_id'], [
            'kind' => $validated['kind'] ?? [],
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
            'priority' => (int) ($validated['priority'] ?? 0),
            'valid_from' => now()->toDateString(),
        ]);

        Session::flash('success', __('Address attached.'));

        return redirect()->route('admin.employees.show', $employee);
    }

    /**
     * Unlink an address from the employee.
     */
    public function unlinkAddress(Employee $employee, Address $address): RedirectResponse
    {
        $employee->addresses()->detach($address->id);

        Session::flash('success', __('Address unlinked.'));

        return redirect()->route('admin.employees.show', $employee);
    }

    /**
     * Decode JSON metadata field from the create form.
     */
    private function decodeJsonField(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
