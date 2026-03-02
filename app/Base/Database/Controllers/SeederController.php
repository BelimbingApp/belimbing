<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Controllers;

use App\Base\Database\Models\SeederRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class SeederController
{
    /**
     * Show seeder registry.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $seeders = SeederRegistry::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('seeder_class', 'like', '%'.$search.'%')
                        ->orWhere('module_name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('migration_file')
            ->orderBy('seeder_class')
            ->paginate(25)
            ->withQueryString();

        return view('admin.system.seeders.index', compact('seeders', 'search'));
    }

    /**
     * Run a seeder class.
     */
    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'seeder_class' => ['required', 'string'],
        ]);

        $seederClass = $validated['seeder_class'];

        try {
            app($seederClass)->run();
            Session::flash('success', __('Seeder executed successfully.'));
        } catch (\Throwable $throwable) {
            Session::flash('error', __('Seeder failed: :message', ['message' => $throwable->getMessage()]));
        }

        return redirect()->route('admin.system.seeders.index');
    }
}
