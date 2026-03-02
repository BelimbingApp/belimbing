<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Controllers\Admin;

use App\Modules\Core\Geonames\Database\Seeders\Admin1Seeder;
use App\Modules\Core\Geonames\Models\Admin1;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class Admin1Controller
{
    /**
     * Show admin1 list.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();
        $filterCountryIso = $request->string('filter_country_iso', '')->toString();

        $query = Admin1::query()
            ->withCountryName()
            ->orderBy('country_name')
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('geonames_admin1.name', 'like', '%'.$search.'%')
                    ->orWhere('geonames_admin1.code', 'like', '%'.$search.'%')
                    ->orWhere('geonames_countries.country', 'like', '%'.$search.'%');
            });
        }

        if ($filterCountryIso !== '') {
            $query->forCountry($filterCountryIso);
        }

        $importedCountries = DB::table('geonames_admin1')
            ->selectRaw("SPLIT_PART(code, '.', 1) as iso")
            ->distinct()
            ->pluck('iso')
            ->sort()
            ->values();

        $countryNames = Country::query()
            ->whereIn('iso', $importedCountries)
            ->orderBy('country')
            ->pluck('country', 'iso');

        $admin1s = $query->paginate(20)->withQueryString();

        return view('admin.geonames.admin1.index', compact('admin1s', 'search', 'filterCountryIso', 'countryNames'));
    }

    /**
     * Import latest admin1 data.
     */
    public function update(): RedirectResponse
    {
        app(Admin1Seeder::class)->run();
        Session::flash('success', __('Admin1 divisions updated from Geonames.'));

        return redirect()->route('admin.geonames.admin1.index');
    }
}
