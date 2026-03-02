<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MigrationController
{
    /**
     * Show migration history.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $query = DB::table('migrations')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('migration', 'like', '%'.$search.'%');
            })
            ->orderByDesc('batch')
            ->orderByDesc('id');

        $migrations = $query->paginate(25)->withQueryString();
        $totalCount = DB::table('migrations')->count();
        $latestBatch = DB::table('migrations')->max('batch');

        return view('admin.system.migrations.index', compact('migrations', 'search', 'totalCount', 'latestBatch'));
    }
}
