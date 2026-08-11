<?php

namespace App\Core\Company\Livewire\Setup;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\Company;
use App\Core\Company\Services\PrimaryCompanyManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class PlatformOperator extends Component
{
    public string $mode = 'select';

    public ?int $selectedCompanyId = null;

    public string $name = '';

    public ?string $legalName = null;

    public ?string $registrationNumber = null;

    public ?string $taxId = null;

    public ?string $jurisdiction = null;

    public ?string $email = null;

    public ?string $website = null;

    public function mount(PrimaryCompanyManager $primaryCompanies): void
    {
        $tenant = $this->operatorTenant();
        $primaryCompany = $primaryCompanies->findForTenant($tenant);

        if ($primaryCompany !== null) {
            $this->redirect(route('admin.companies.show', $primaryCompany), navigate: true);
        }

        if (! Company::query()->forTenant((int) $tenant->id)->exists()) {
            $this->mode = 'create';
        }
    }

    public function designateExisting(PrimaryCompanyManager $primaryCompanies): void
    {
        $tenant = $this->operatorTenant();
        $validated = $this->validate([
            'selectedCompanyId' => [
                'required',
                'integer',
                Rule::exists(Company::class, 'id')->where('tenant_id', $tenant->id),
            ],
        ]);
        $company = Company::query()
            ->forTenant((int) $tenant->id)
            ->findOrFail($validated['selectedCompanyId']);

        $primaryCompanies->assign($tenant, $company);

        Session::flash('success', __('Platform-operator primary company designated successfully.'));
        $this->redirect(route('admin.companies.show', $company), navigate: true);
    }

    public function createPrimaryCompany(PrimaryCompanyManager $primaryCompanies): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'legalName' => ['nullable', 'string', 'max:255'],
            'registrationNumber' => ['nullable', 'string', 'max:255'],
            'taxId' => ['nullable', 'string', 'max:255'],
            'jurisdiction' => ['nullable', 'string', 'max:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);
        $tenant = $this->operatorTenant();

        $company = DB::transaction(function () use ($primaryCompanies, $tenant, $validated): Company {
            $company = Company::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $validated['name'],
                'status' => 'active',
                'legal_name' => $validated['legalName'],
                'registration_number' => $validated['registrationNumber'],
                'tax_id' => $validated['taxId'],
                'jurisdiction' => $validated['jurisdiction'],
                'email' => $validated['email'],
                'website' => $validated['website'],
            ]);
            $primaryCompanies->assign($tenant, $company);

            return $company;
        });

        Session::flash('success', __('Platform-operator primary company created successfully.'));
        $this->redirect(route('admin.companies.show', $company), navigate: true);
    }

    public function render(): View
    {
        $tenant = $this->operatorTenant();
        $companies = Company::query()
            ->forTenant((int) $tenant->id)
            ->orderBy('name')
            ->get(['id', 'name', 'legal_name', 'status']);

        return view('livewire.admin.setup.platform-operator', [
            'companies' => $companies,
            'hasCompanies' => $companies->isNotEmpty(),
        ]);
    }

    private function operatorTenant(): Tenant
    {
        $tenant = Tenant::requirePlatformOperator();

        if ((int) $tenant->id !== app(TenantContext::class)->requireTenantId()) {
            abort(404);
        }

        return $tenant;
    }
}
