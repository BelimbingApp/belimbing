<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Controllers\Admin;

use App\Modules\Core\Geonames\Database\Seeders\PostcodeSeeder;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\Geonames\Models\Postcode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class PostcodeController
{
    /**
     * Show postcode list.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $query = Postcode::query()
            ->withCountryName()
            ->orderBy('country_name')
            ->orderBy('postcode');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('geonames_postcodes.postcode', 'like', '%'.$search.'%')
                    ->orWhere('geonames_postcodes.place_name', 'like', '%'.$search.'%')
                    ->orWhere('geonames_postcodes.country_iso', 'like', '%'.$search.'%')
                    ->orWhere('geonames_countries.country', 'like', '%'.$search.'%');
            });
        }

        $importedIsos = DB::table('geonames_postcodes')->distinct()->pluck('country_iso')->all();
        $allCountries = Country::query()->orderBy('country')->pluck('country', 'iso');
        $hasData = ! empty($importedIsos);

        $countryRecordCounts = collect();
        if ($hasData) {
            $countryRecordCounts = DB::table('geonames_postcodes')
                ->leftJoin('geonames_countries', 'geonames_postcodes.country_iso', '=', 'geonames_countries.iso')
                ->select('geonames_postcodes.country_iso')
                ->selectRaw('geonames_countries.country as country_name')
                ->selectRaw('count(*) as record_count')
                ->groupBy('geonames_postcodes.country_iso', 'geonames_countries.country')
                ->orderBy('geonames_countries.country')
                ->orderBy('geonames_postcodes.country_iso')
                ->get();
        }

        $postcodes = $query->paginate(20)->withQueryString();

        return view('admin.geonames.postcodes.index', compact('postcodes', 'search', 'allCountries', 'importedIsos', 'hasData', 'countryRecordCounts'));
    }

    /**
     * Import postcode data for selected countries.
     */
    public function import(Request $request): RedirectResponse
    {
        $selectedCountries = $request->input('selected_countries', []);
        if (! is_array($selectedCountries) || count($selectedCountries) === 0) {
            Session::flash('error', __('Please select at least one country to import.'));

            return redirect()->route('admin.geonames.postcodes.index');
        }

        $countryCodes = array_values(array_unique(array_map('strtoupper', $selectedCountries)));
        sort($countryCodes);

        try {
            app(PostcodeSeeder::class)->run($countryCodes);
            Session::flash('success', __('Import completed for :count country(s).', ['count' => count($countryCodes)]));
        } catch (\Throwable $throwable) {
            Session::flash('error', __('Import failed: :message', ['message' => $throwable->getMessage()]));
        }

        return redirect()->route('admin.geonames.postcodes.index');
    }

    /**
     * Update postcode data for imported countries.
     */
    public function update(): RedirectResponse
    {
        $importedIsos = DB::table('geonames_postcodes')->distinct()->pluck('country_iso')->all();

        if (count($importedIsos) === 0) {
            return redirect()->route('admin.geonames.postcodes.index');
        }

        try {
            app(PostcodeSeeder::class)->run($importedIsos);
            Session::flash('success', __('Update completed for :count country(s).', ['count' => count($importedIsos)]));
        } catch (\Throwable $throwable) {
            Session::flash('error', __('Update failed: :message', ['message' => $throwable->getMessage()]));
        }

        return redirect()->route('admin.geonames.postcodes.index');
    }
}
