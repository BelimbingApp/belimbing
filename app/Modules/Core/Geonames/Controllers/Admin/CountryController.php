<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Controllers\Admin;

use App\Modules\Core\Geonames\Database\Seeders\CountrySeeder;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class CountryController
{
    /**
     * Show country list.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $countries = Country::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('country', 'like', '%'.$search.'%')
                    ->orWhere('iso', 'like', '%'.$search.'%');
            })
            ->orderBy('country')
            ->paginate(20)
            ->withQueryString();

        return view('admin.geonames.countries.index', compact('countries', 'search'));
    }

    /**
     * Import latest country data.
     */
    public function update(): RedirectResponse
    {
        app(CountrySeeder::class)->run();
        Session::flash('success', __('Countries updated from Geonames.'));

        return redirect()->route('admin.geonames.countries.index');
    }
}
