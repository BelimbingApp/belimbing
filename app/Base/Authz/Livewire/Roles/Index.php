<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\Roles;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Models\Role;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component implements ProvidesLaraPageContext
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'name';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'name' => 'base_authz_roles.name',
        'code' => 'base_authz_roles.code',
        'is_system' => 'base_authz_roles.is_system',
        'company_name' => 'companies.name',
        'capabilities_count' => 'capabilities_count',
        'principal_roles_count' => 'principal_roles_count',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
        );
    }

    public function render(): View
    {
        $authUser = auth()->user();

        $authActor = Actor::forUser($authUser);

        $canCreate = app(AuthorizationService::class)
            ->can($authActor, 'admin.role.create')
            ->allowed;

        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'base_authz_roles.name';

        return view('livewire.admin.roles.index', [
            'canCreate' => $canCreate,
            'roles' => Role::query()
                ->select('base_authz_roles.*')
                ->with('company')
                ->leftJoin('companies', 'base_authz_roles.company_id', '=', 'companies.id')
                ->withCount('capabilities', 'principalRoles')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('base_authz_roles.name', 'like', '%'.$search.'%')
                            ->orWhere('base_authz_roles.code', 'like', '%'.$search.'%')
                            ->orWhere('base_authz_roles.description', 'like', '%'.$search.'%');
                    });
                })
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('base_authz_roles.id')
                ->paginate(10),
        ]);
    }

    public function pageContext(): PageContext
    {
        return new PageContext(
            route: 'admin.roles.index',
            url: route('admin.roles.index'),
            title: 'Roles',
            module: 'Role',
            resourceType: 'role',
            visibleActions: ['Create role', 'Search'],
            searchQuery: $this->search !== '' ? $this->search : null,
        );
    }
}
