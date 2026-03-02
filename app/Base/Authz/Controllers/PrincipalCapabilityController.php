<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Controllers;

use App\Base\Authz\Models\PrincipalCapability;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrincipalCapabilityController
{
    /**
     * Show the principal capability list page.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $capabilities = $this->queryCapabilities($search);

        return view('admin.authz.principal-capabilities.index', compact('capabilities', 'search'));
    }

    /**
     * Return the searchable principal capability table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $capabilities = $this->queryCapabilities($search);

        return view('admin.authz.principal-capabilities.partials.table', compact('capabilities'));
    }

    /**
     * Build the principal capability listing query.
     */
    private function queryCapabilities(string $search): LengthAwarePaginator
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
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('capability_key', 'like', '%'.$search.'%')
                        ->orWhere('users.name', 'like', '%'.$search.'%')
                        ->orWhere('users.email', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('base_authz_principal_capabilities.created_at')
            ->paginate(25)
            ->withQueryString();
    }
}
