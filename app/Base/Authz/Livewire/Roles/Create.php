<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\Roles;

use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
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
        if ($this->companyId === '') {
            $this->companyId = null;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('base_authz_roles', 'code')
                    ->when(
                        $this->companyId !== null,
                        fn ($rule) => $rule->where('company_id', $this->companyId),
                        fn ($rule) => $rule->whereNull('company_id'),
                    ),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'companyId' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $role = Role::query()->create([
            'company_id' => ($validated['companyId'] ?? null) ? (int) $validated['companyId'] : null,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => ($validated['description'] ?? '') ?: null,
            'is_system' => false,
        ]);

        $this->redirect(route('admin.roles.show', $role), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.roles.create', [
            'companies' => Company::query()
                ->where('id', Company::LICENSEE_ID)
                ->orWhere('parent_id', Company::LICENSEE_ID)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
