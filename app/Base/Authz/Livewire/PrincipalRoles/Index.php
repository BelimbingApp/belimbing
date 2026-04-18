<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\PrincipalRoles;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Foundation\Livewire\SearchablePaginatedList;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Index extends SearchablePaginatedList
{
    protected const string VIEW_NAME = 'livewire.admin.authz.principal-roles.index';

    protected const string VIEW_DATA_KEY = 'assignments';

    protected const string SORT_COLUMN = 'base_authz_principal_roles.created_at';

    protected function query(): EloquentBuilder|QueryBuilder
    {
        return PrincipalRole::query()
            ->with('role')
            ->leftJoin('users', function ($join): void {
                $join->on('base_authz_principal_roles.principal_id', '=', 'users.id')
                    ->where('base_authz_principal_roles.principal_type', '=', PrincipalType::USER->value);
            })
            ->leftJoin('companies', 'base_authz_principal_roles.company_id', '=', 'companies.id')
            ->select(
                'base_authz_principal_roles.*',
                'users.name as principal_name',
                'users.email as principal_email',
                'companies.name as company_name'
            );
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function ($builder) use ($search): void {
            $builder->where('users.name', 'like', '%'.$search.'%')
                ->orWhere('users.email', 'like', '%'.$search.'%')
                ->orWhereHas('role', function ($roleQuery) use ($search): void {
                    $roleQuery->where('name', 'like', '%'.$search.'%');
                });
        });
    }
}
