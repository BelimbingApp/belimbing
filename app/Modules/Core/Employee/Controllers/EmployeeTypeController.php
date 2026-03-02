<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Controllers;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Modules\Core\Employee\Models\EmployeeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeTypeController
{
    /**
     * Show the employee type list page.
     */
    public function index(): View
    {
        $authUser = request()->user();
        $actor = new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $authUser->getAuthIdentifier(),
            companyId: $authUser->company_id !== null ? (int) $authUser->company_id : null,
        );

        $canCreate = app(AuthorizationService::class)->can($actor, 'core.employee_type.create')->allowed;
        $canUpdate = app(AuthorizationService::class)->can($actor, 'core.employee_type.update')->allowed;
        $canDelete = app(AuthorizationService::class)->can($actor, 'core.employee_type.delete')->allowed;

        $employeeTypes = EmployeeType::query()
            ->global()
            ->withCount('employees')
            ->orderByDesc('is_system')
            ->orderBy('code')
            ->paginate(15);

        return view('admin.employee-types.index', compact('employeeTypes', 'canCreate', 'canUpdate', 'canDelete'));
    }

    /**
     * Show the create-employee-type form.
     */
    public function create(): View
    {
        return view('admin.employee-types.create');
    }

    /**
     * Store a newly created employee type.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('employee_types', 'code')->whereNull('company_id'),
            ],
            'label' => ['required', 'string', 'max:255'],
        ]);

        EmployeeType::query()->create([
            'code' => $validated['code'],
            'label' => $validated['label'],
            'is_system' => false,
        ]);

        Session::flash('success', __('Employee type created.'));

        return redirect()->route('admin.employee-types.index');
    }

    /**
     * Show the edit-employee-type form.
     */
    public function edit(EmployeeType $employeeType): View
    {
        abort_if($employeeType->is_system, 403, __('System employee types cannot be edited.'));

        return view('admin.employee-types.edit', compact('employeeType'));
    }

    /**
     * Update an employee type.
     */
    public function update(Request $request, EmployeeType $employeeType): RedirectResponse
    {
        abort_if($employeeType->is_system, 403, __('System employee types cannot be edited.'));

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
        ]);

        $employeeType->update(['label' => $validated['label']]);

        Session::flash('success', __('Employee type updated.'));

        return redirect()->route('admin.employee-types.index');
    }

    /**
     * Delete an employee type.
     */
    public function destroy(EmployeeType $employeeType): RedirectResponse
    {
        if ($employeeType->is_system) {
            Session::flash('error', __('System employee types cannot be deleted.'));

            return redirect()->route('admin.employee-types.index');
        }

        if ($employeeType->employees()->exists()) {
            Session::flash('error', __('Cannot delete: employees are using this type.'));

            return redirect()->route('admin.employee-types.index');
        }

        $employeeType->delete();

        Session::flash('success', __('Employee type deleted.'));

        return redirect()->route('admin.employee-types.index');
    }
}
