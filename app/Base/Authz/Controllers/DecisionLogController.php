<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Controllers;

use App\Base\Authz\Models\DecisionLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DecisionLogController
{
    /**
     * Show the decision log list page.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $filterResult = $request->string('filter_result', '')->toString();

        $logs = $this->queryLogs($search, $filterResult);

        return view('admin.authz.decision-logs.index', compact('logs', 'search', 'filterResult'));
    }

    /**
     * Return the searchable decision log table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $filterResult = $request->string('filter_result', '')->toString();

        $logs = $this->queryLogs($search, $filterResult);

        return view('admin.authz.decision-logs.partials.table', compact('logs'));
    }

    /**
     * Build the decision log listing query.
     */
    private function queryLogs(string $search, string $filterResult): LengthAwarePaginator
    {
        return DecisionLog::query()
            ->leftJoin('users', function ($join): void {
                $join->on('base_authz_decision_logs.actor_id', '=', 'users.id')
                    ->where('base_authz_decision_logs.actor_type', '=', 'human_user');
            })
            ->select('base_authz_decision_logs.*', 'users.name as actor_name')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('capability', 'like', '%'.$search.'%')
                        ->orWhere('reason_code', 'like', '%'.$search.'%')
                        ->orWhere('users.name', 'like', '%'.$search.'%')
                        ->orWhere('resource_type', 'like', '%'.$search.'%');
                });
            })
            ->when($filterResult === 'allowed', function ($query): void {
                $query->where('base_authz_decision_logs.allowed', true);
            })
            ->when($filterResult === 'denied', function ($query): void {
                $query->where('base_authz_decision_logs.allowed', false);
            })
            ->orderByDesc('base_authz_decision_logs.occurred_at')
            ->paginate(25)
            ->withQueryString();
    }
}
