<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Controllers;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Company\Models\DepartmentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DepartmentController
{
    /**
     * Show company departments.
     */
    public function index(Company $company): View
    {
        $departments = Department::query()
            ->where('company_id', $company->id)
            ->with('type')
            ->paginate(15)
            ->withQueryString();

        $existingTypeIds = Department::query()
            ->where('company_id', $company->id)
            ->pluck('department_type_id')
            ->toArray();

        $availableTypes = DepartmentType::query()
            ->active()
            ->whereNotIn('id', $existingTypeIds)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return view('companies.departments', compact('company', 'departments', 'availableTypes'));
    }

    /**
     * Create a department for the company.
     */
    public function store(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            'department_type_id' => ['required', 'integer', Rule::exists(DepartmentType::class, 'id')],
            'status' => ['required', 'in:active,inactive,suspended'],
        ]);

        Department::query()->create([
            'company_id' => $company->id,
            'department_type_id' => (int) $validated['department_type_id'],
            'status' => $validated['status'],
        ]);

        Session::flash('success', __('Department created.'));

        return redirect()->route('admin.companies.departments', $company);
    }

    /**
     * Delete a department.
     */
    public function destroy(Company $company, Department $department): RedirectResponse
    {
        if ($department->company_id !== $company->id) {
            abort(404);
        }

        $department->delete();
        Session::flash('success', __('Department deleted.'));

        return redirect()->route('admin.companies.departments', $company);
    }
}
