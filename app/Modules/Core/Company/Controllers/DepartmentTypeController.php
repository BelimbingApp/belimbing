<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Controllers;

use App\Modules\Core\Company\Models\DepartmentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DepartmentTypeController
{
    /**
     * Show department types page.
     */
    public function index(): View
    {
        $types = DepartmentType::query()
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('companies.department-types', compact('types'));
    }

    /**
     * Create a department type.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('company_department_types', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(['administrative', 'operational', 'revenue', 'support'])],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        DepartmentType::query()->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        Session::flash('success', __('Department type created.'));

        return redirect()->route('admin.companies.department-types');
    }

    /**
     * Update a department type.
     */
    public function update(Request $request, DepartmentType $departmentType): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(['administrative', 'operational', 'revenue', 'support'])],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $departmentType->update([
            'name' => $validated['name'],
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        Session::flash('success', __('Department type updated.'));

        return redirect()->route('admin.companies.department-types');
    }

    /**
     * Delete a department type.
     */
    public function destroy(DepartmentType $departmentType): RedirectResponse
    {
        $departmentType->loadCount('departments');

        if ($departmentType->departments_count > 0) {
            Session::flash('error', __('Cannot delete a department type that is in use by departments.'));

            return redirect()->route('admin.companies.department-types');
        }

        $departmentType->delete();
        Session::flash('success', __('Department type deleted.'));

        return redirect()->route('admin.companies.department-types');
    }
}
