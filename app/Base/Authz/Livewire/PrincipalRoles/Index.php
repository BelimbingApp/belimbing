<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\PrincipalRoles;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
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
        'principal_type' => 'base_authz_principal_roles.principal_type',
        'role_name' => 'base_authz_roles.name',
        'company_name' => 'companies.name',
        'created_at' => 'base_authz_principal_roles.created_at',
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
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_authz_principal_roles.created_at';

        return view('livewire.admin.authz.principal-roles.index', [
            'assignments' => $this->assignments($sortColumn),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, PrincipalRole>
     */
    private function assignments(string $sortColumn): LengthAwarePaginator
    {
        return PrincipalRole::query()
            ->with('role')
            ->leftJoin('users', function ($join): void {
                $join->on('base_authz_principal_roles.principal_id', '=', 'users.id')
                    ->where('base_authz_principal_roles.principal_type', '=', PrincipalType::USER->value);
            })
            ->leftJoin('companies', 'base_authz_principal_roles.company_id', '=', 'companies.id')
            ->leftJoin('base_authz_roles', 'base_authz_principal_roles.role_id', '=', 'base_authz_roles.id')
            ->select(
                'base_authz_principal_roles.*',
                'users.name as principal_name',
                'users.email as principal_email',
                'companies.name as company_name',
                'base_authz_roles.name as role_name',
            )
            ->when($this->search, function ($query, $search): void {
                $query->where(function ($builder) use ($search): void {
                    $builder->where('users.name', 'like', '%'.$search.'%')
                        ->orWhere('users.email', 'like', '%'.$search.'%')
                        ->orWhere('base_authz_roles.name', 'like', '%'.$search.'%')
                        ->orWhereHas('role', function ($roleQuery) use ($search): void {
                            $roleQuery->where('name', 'like', '%'.$search.'%');
                        });
                });
            })
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('base_authz_principal_roles.id')
            ->paginate(25);
    }
}
