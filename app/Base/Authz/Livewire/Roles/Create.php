<?php

namespace App\Base\Authz\Livewire\Roles;

use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';

    public string $code = '';

    public string $description = '';

    /** @var int|string|null */
    public $companyId = null;

    /**
     * Create a new custom role.
     */
    public function createRole(): void
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('base_authz_roles', 'code')
                    ->where('company_id', $this->companyId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'companyId' => [
                'required',
                'integer',
                Rule::exists(Company::class, 'id')->where('tenant_id', $tenantId),
            ],
        ]);

        $role = Role::query()->create([
            'company_id' => (int) $validated['companyId'],
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => ($validated['description'] ?? '') ?: null,
            'is_system' => false,
        ]);

        $this->redirect(route('admin.roles.show', $role), navigate: true);
    }

    public function render(): View
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return view('livewire.admin.roles.create', [
            'companies' => Company::query()
                ->forTenant($tenantId)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
