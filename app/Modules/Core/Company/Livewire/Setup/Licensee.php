<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Setup;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Licensee extends Component
{
    public string $mode = 'select';

    public ?int $selectedCompanyId = null;

    public string $name = '';

    public ?string $legalName = null;

    public ?string $registrationNumber = null;

    public ?string $taxId = null;

    public ?string $legalEntityType = null;

    public ?string $jurisdiction = null;

    public ?string $email = null;

    public ?string $website = null;

    public function mount(): void
    {
        if (Company::query()->find(Company::LICENSEE_ID)) {
            $this->redirect(route('admin.companies.show', Company::LICENSEE_ID), navigate: true);
        }

        if (! Company::query()->exists()) {
            $this->mode = 'create';
        }
    }

    /**
     * Promote an existing company to licensee by reassigning its id to 1.
     */
    public function promoteExisting(): void
    {
        $this->validate([
            'selectedCompanyId' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $company = Company::query()->findOrFail($this->selectedCompanyId);
        $oldId = $company->id;

        DB::transaction(function () use ($oldId): void {
            $row = (array) DB::table('companies')->where('id', $oldId)->first();
            unset($row['id']);

            DB::table('companies')->where('id', $oldId)->update(['slug' => $row['slug'].'-reassigning']);
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
        $this->redirect(route('admin.companies.show', Company::LICENSEE_ID), navigate: true);
    }

    /**
     * Create a new company as the licensee with id=1.
     *
     * Delegates bootstrap to Company::provisionLicensee(), then updates
     * additional fields collected from the form.
     */
    public function createLicensee(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'legalName' => ['nullable', 'string', 'max:255'],
            'registrationNumber' => ['nullable', 'string', 'max:255'],
            'taxId' => ['nullable', 'string', 'max:255'],
            'legalEntityType' => ['nullable', 'string', 'max:255'],
            'jurisdiction' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        Company::provisionLicensee($validated['name']);

        // Update additional fields beyond what provisionLicensee() sets
        $extra = collect([
            'legal_name' => $validated['legalName'],
            'registration_number' => $validated['registrationNumber'],
            'tax_id' => $validated['taxId'],
            'legal_entity_type' => $validated['legalEntityType'],
            'jurisdiction' => $validated['jurisdiction'],
            'email' => $validated['email'],
            'website' => $validated['website'],
        ])->filter()->all();
        if ($extra) {
            Company::query()->where('id', Company::LICENSEE_ID)->update($extra);
        }

        Session::flash('success', __('Licensee company created successfully.'));
        $this->redirect(route('admin.companies.show', Company::LICENSEE_ID), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.setup.licensee', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'legal_name', 'status']),
            'hasCompanies' => Company::query()->exists(),
        ]);
    }
}
