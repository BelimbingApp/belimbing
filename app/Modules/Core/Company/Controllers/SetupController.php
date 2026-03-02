<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Controllers;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SetupController
{
    /**
     * Show the licensee setup page.
     */
    public function licensee(): View|RedirectResponse
    {
        if (Company::query()->find(Company::LICENSEE_ID)) {
            return redirect()->route('admin.companies.show', Company::LICENSEE_ID);
        }

        $companies = Company::query()->orderBy('name')->get(['id', 'name', 'legal_name', 'status']);
        $hasCompanies = $companies->isNotEmpty();
        $mode = request()->string('mode', $hasCompanies ? 'select' : 'create')->toString();

        return view('admin.setup.licensee', compact('companies', 'hasCompanies', 'mode'));
    }

    /**
     * Create or promote the licensee company.
     */
    public function updateLicensee(Request $request): RedirectResponse
    {
        $mode = $request->string('mode', 'select')->toString();

        if ($mode === 'create') {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'legal_name' => ['nullable', 'string', 'max:255'],
                'registration_number' => ['nullable', 'string', 'max:255'],
                'tax_id' => ['nullable', 'string', 'max:255'],
                'jurisdiction' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'website' => ['nullable', 'string', 'max:255'],
            ]);

            DB::table('companies')->insert(array_merge($validated, [
                'id' => Company::LICENSEE_ID,
                'code' => Str::lower(Str::slug($validated['name'], '_')),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            Session::flash('success', __('Licensee company created successfully.'));

            return redirect()->route('admin.companies.show', Company::LICENSEE_ID);
        }

        $validated = $request->validate([
            'selected_company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $company = Company::query()->findOrFail($validated['selected_company_id']);
        $oldId = $company->id;

        DB::transaction(function () use ($oldId): void {
            $row = (array) DB::table('companies')->where('id', $oldId)->first();
            unset($row['id']);

            DB::table('companies')->where('id', $oldId)->update(['code' => $row['code'].'_reassigning']);
            DB::table('companies')->insert(array_merge($row, ['id' => Company::LICENSEE_ID]));

            $fkTables = [
                ['companies', 'parent_id'],
                ['users', 'company_id'],
                ['employees', 'company_id'],
                ['departments', 'company_id'],
                ['company_departments', 'company_id'],
                ['company_relationships', 'company_id'],
                ['company_relationships', 'related_company_id'],
                ['external_accesses', 'company_id'],
            ];

            foreach ($fkTables as [$table, $column]) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where($column, $oldId)->update([$column => Company::LICENSEE_ID]);
                }
            }

            if (Schema::hasTable('addressables')) {
                DB::table('addressables')
                    ->where('addressable_id', $oldId)
                    ->where('addressable_type', Company::class)
                    ->update(['addressable_id' => Company::LICENSEE_ID]);
            }

            DB::table('companies')->where('id', $oldId)->delete();
        });

        Session::flash('success', __('Licensee set successfully.'));

        return redirect()->route('admin.companies.show', Company::LICENSEE_ID);
    }
}
