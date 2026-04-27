<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Livewire\Postcodes;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\Geonames\Database\Seeders\PostcodeSeeder;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\Geonames\Models\Postcode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    /** @var array<int, string> */
    public array $selectedCountries = [];

    public bool $showCountryPicker = false;

    public function with(): array
    {
        $query = Postcode::query()
            ->withCountryName()
            ->orderBy('country_name')
            ->orderBy('postcode');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('geonames_postcodes.postcode', 'like', '%'.$this->search.'%')
                    ->orWhere('geonames_postcodes.place_name', 'like', '%'.$this->search.'%')
                    ->orWhere('geonames_postcodes.country_iso', 'like', '%'.$this->search.'%')
                    ->orWhere('geonames_countries.country', 'like', '%'.$this->search.'%');
            });
        }

        $importedIsos = DB::table('geonames_postcodes')
            ->distinct()
            ->pluck('country_iso')
            ->all();

        $allCountries = Country::query()
            ->orderBy('country')
            ->pluck('country', 'iso');

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

        return [
            'postcodes' => $query->paginate(20),
            'allCountries' => $allCountries,
            'importedIsos' => $importedIsos,
            'hasData' => $hasData,
            'countryRecordCounts' => $countryRecordCounts,
        ];
    }

    public function import(): void
    {
        if (empty($this->selectedCountries)) {
            Session::flash('error', __('Please select at least one country to import.'));

            return;
        }

        $countryCodes = array_values(array_unique(array_map('strtoupper', $this->selectedCountries)));
        sort($countryCodes);

        $this->selectedCountries = [];
        $this->showCountryPicker = false;

        try {
            app(PostcodeSeeder::class)->run($countryCodes);
            Session::flash('success', __('Import completed for :count country(s).', ['count' => count($countryCodes)]));
        } catch (\Throwable $e) {
            Session::flash('error', __('Import failed: :message', ['message' => $e->getMessage()]));
        }
    }

    public function update(): void
    {
        $importedIsos = DB::table('geonames_postcodes')
            ->distinct()
            ->pluck('country_iso')
            ->all();

        if (empty($importedIsos)) {
            return;
        }

        try {
            app(PostcodeSeeder::class)->run($importedIsos);
            Session::flash('success', __('Update completed for :count country(s).', ['count' => count($importedIsos)]));
        } catch (\Throwable $e) {
            Session::flash('error', __('Update failed: :message', ['message' => $e->getMessage()]));
        }
    }

    public function toggleCountryPicker(): void
    {
        $this->showCountryPicker = ! $this->showCountryPicker;
    }

    public function render(): View
    {
        return view('livewire.admin.geonames.postcodes.index', $this->with());
    }
}
