<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Controllers;

use App\Base\Authz\Models\PrincipalRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrincipalRoleController
{
    /**
     * Show the principal role list page.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $assignments = $this->queryAssignments($search);

        return view('admin.authz.principal-roles.index', compact('assignments', 'search'));
    }

    /**
     * Return the searchable principal role table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $assignments = $this->queryAssignments($search);

        return view('admin.authz.principal-roles.partials.table', compact('assignments'));
    }

    /**
     * Build the principal role listing query.
     */
    private function queryAssignments(string $search): LengthAwarePaginator
    {
        return PrincipalRole::query()
            ->with('role')
            ->leftJoin('users', function ($join): void {
                $join->on('base_authz_principal_roles.principal_id', '=', 'users.id')
                    ->where('base_authz_principal_roles.principal_type', '=', 'human_user');
            })
            ->leftJoin('companies', 'base_authz_principal_roles.company_id', '=', 'companies.id')
            ->select(
                'base_authz_principal_roles.*',
                'users.name as principal_name',
                'users.email as principal_email',
                'companies.name as company_name'
            )
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('users.name', 'like', '%'.$search.'%')
                        ->orWhere('users.email', 'like', '%'.$search.'%')
                        ->orWhereHas('role', function ($roleQuery) use ($search): void {
                            $roleQuery->where('name', 'like', '%'.$search.'%');
                        });
                });
            })
            ->orderByDesc('base_authz_principal_roles.created_at')
            ->paginate(25)
            ->withQueryString();
    }
}
