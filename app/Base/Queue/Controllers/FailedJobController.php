<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Queue\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FailedJobController
{
    /**
     * Show failed jobs.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $failedJobs = DB::table('failed_jobs')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('queue', 'like', '%'.$search.'%')
                        ->orWhere('uuid', 'like', '%'.$search.'%')
                        ->orWhere('exception', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('failed_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.system.failed-jobs.index', compact('failedJobs', 'search'));
    }

    /**
     * Retry a failed job or all failed jobs.
     */
    public function retry(Request $request): RedirectResponse
    {
        $id = $request->string('id')->toString();
        $retryId = $id !== '' ? $id : 'all';

        Artisan::call('queue:retry', ['id' => [$retryId]]);

        return redirect()->route('admin.system.failed-jobs.index');
    }

    /**
     * Delete a failed job.
     */
    public function destroy(int $id): RedirectResponse
    {
        DB::table('failed_jobs')->where('id', $id)->delete();

        return redirect()->route('admin.system.failed-jobs.index');
    }
}
