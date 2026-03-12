<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\PrincipalCapabilities;

use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Foundation\Livewire\SearchablePaginatedList;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Index extends SearchablePaginatedList
{
    protected function query(): EloquentBuilder|QueryBuilder
    {
        return PrincipalCapability::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_authz_principal_capabilities.principal_id', '=', 'users.id')
                    ->where('base_authz_principal_capabilities.principal_type', '=', 'human_user');
            })
            ->leftJoin('companies', 'base_authz_principal_capabilities.company_id', '=', 'companies.id')
            ->select(
                'base_authz_principal_capabilities.*',
                'users.name as principal_name',
                'users.email as principal_email',
                'companies.name as company_name'
            );
    }

    protected function viewName(): string
    {
        return 'livewire.admin.authz.principal-capabilities.index';
    }

    protected function viewDataKey(): string
    {
        return 'capabilities';
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function ($builder) use ($search): void {
            $builder->where('capability_key', 'like', '%'.$search.'%')
                ->orWhere('users.name', 'like', '%'.$search.'%')
                ->orWhere('users.email', 'like', '%'.$search.'%');
        });
    }

    protected function sortQuery(EloquentBuilder|QueryBuilder $query): void
    {
        $query->orderByDesc('base_authz_principal_capabilities.created_at');
    }
}
