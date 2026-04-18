<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\PrincipalCapabilities;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Foundation\Livewire\SearchablePaginatedList;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Index extends SearchablePaginatedList
{
    protected const string VIEW_NAME = 'livewire.admin.authz.principal-capabilities.index';

    protected const string VIEW_DATA_KEY = 'capabilities';

    protected const string SORT_COLUMN = 'base_authz_principal_capabilities.created_at';

    protected const array SEARCH_COLUMNS = [
        'capability_key',
        'users.name',
        'users.email',
    ];

    protected function query(): EloquentBuilder|QueryBuilder
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
            );
    }
}
