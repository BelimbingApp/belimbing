<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Session\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SessionController
{
    /**
     * Show session list.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $currentSessionId = session()->getId();

        $sessions = DB::table('sessions')
            ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
            ->select('sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name as user_name')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('sessions.ip_address', 'like', '%'.$search.'%')
                        ->orWhere('sessions.user_agent', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('sessions.last_activity')
            ->paginate(25)
            ->withQueryString();

        return view('admin.system.sessions.index', compact('sessions', 'search', 'currentSessionId'));
    }
}
