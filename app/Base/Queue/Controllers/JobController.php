<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Queue\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class JobController
{
    /**
     * Show queued jobs.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $jobs = DB::table('jobs')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('queue', 'like', '%'.$search.'%')
                        ->orWhere('payload', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.system.jobs.index', compact('jobs', 'search'));
    }
}
