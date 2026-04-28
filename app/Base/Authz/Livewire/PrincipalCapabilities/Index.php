<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\PrincipalCapabilities;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'created_at';

    public string $sortDir = 'desc';

    private const SORTABLE = [
        'principal_name' => 'users.name',
        'principal_type' => 'base_authz_principal_capabilities.principal_type',
        'capability_key' => 'base_authz_principal_capabilities.capability_key',
        'is_allowed' => 'base_authz_principal_capabilities.is_allowed',
        'company_name' => 'companies.name',
        'created_at' => 'base_authz_principal_capabilities.created_at',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'created_at' => 'desc',
            ],
        );
    }

    public function render(): View
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_authz_principal_capabilities.created_at';

        return view('livewire.admin.authz.principal-capabilities.index', [
            'capabilities' => $this->capabilities($sortColumn),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, PrincipalCapability>
     */
    private function capabilities(string $sortColumn): LengthAwarePaginator
    {
        return PrincipalCapability::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_authz_principal_capabilities.principal_id', '=', 'users.id')
                    ->where('base_authz_principal_capabilities.principal_type', '=', PrincipalType::USER->value);
            })
            ->leftJoin('companies', 'base_authz_principal_capabilities.company_id', '=', 'companies.id')
            ->select(
                'base_authz_principal_capabilities.*',
                'users.name as principal_name',
                'users.email as principal_email',
                'companies.name as company_name'
            )
            ->when($this->search, function ($query, $search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('base_authz_principal_capabilities.capability_key', 'like', '%'.$search.'%')
                        ->orWhere('users.name', 'like', '%'.$search.'%')
                        ->orWhere('users.email', 'like', '%'.$search.'%');
                });
            })
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('base_authz_principal_capabilities.id')
            ->paginate(25);
    }
}
