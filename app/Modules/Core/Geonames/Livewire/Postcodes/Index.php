<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Livewire\Postcodes;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Geonames\Database\Seeders\PostcodeSeeder;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\Geonames\Models\Postcode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    /** @var array<int, string> */
    public array $selectedCountries = [];

    public bool $showCountryPicker = false;

    public string $summarySortBy = 'country_name';

    public string $summarySortDir = 'asc';

    public string $sortBy = 'country_name';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'country_name' => 'country_name',
        'postcode' => 'geonames_postcodes.postcode',
        'place_name' => 'geonames_postcodes.place_name',
        'admin1Code' => 'geonames_postcodes.admin1Code',
        'updated_at' => 'geonames_postcodes.updated_at',
    ];

    private const SUMMARY_SORTABLE = [
        'country_name' => true,
        'country_iso' => true,
        'record_count' => true,
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'country_name' => 'asc',
                'postcode' => 'asc',
                'place_name' => 'asc',
                'admin1Code' => 'asc',
                'updated_at' => 'desc',
            ],
        );
    }

    public function sortSummary(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SUMMARY_SORTABLE,
            defaultDir: [
                'country_name' => 'asc',
                'country_iso' => 'asc',
                'record_count' => 'desc',
            ],
            sortByProperty: 'summarySortBy',
            sortDirProperty: 'summarySortDir',
            resetPage: false,
        );
    }

    public function with(): array
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'country_name';

        $query = Postcode::query()
            ->withCountryName()
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('geonames_postcodes.id');

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
                ->get();

            $countryRecordCounts = $this->sortPostcodeCountrySummary($countryRecordCounts);
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

    /**
     * @param  Collection<int, object{country_iso: string, country_name: ?string, record_count: int|string}>  $rows
     * @return Collection<int, object{country_iso: string, country_name: ?string, record_count: int|string}>
     */
    private function sortPostcodeCountrySummary(Collection $rows): Collection
    {
        $dir = $this->summarySortDir === 'desc' ? -1 : 1;

        return $rows
            ->sort(function (object $a, object $b) use ($dir): int {
                $nameA = (string) ($a->country_name ?? $a->country_iso);
                $nameB = (string) ($b->country_name ?? $b->country_iso);
                $isoA = (string) $a->country_iso;
                $isoB = (string) $b->country_iso;
                $countA = (int) $a->record_count;
                $countB = (int) $b->record_count;

                $primary = match ($this->summarySortBy) {
                    'country_name' => $dir * strcmp($nameA, $nameB),
                    'country_iso' => $dir * strcmp($isoA, $isoB),
                    'record_count' => $dir * ($countA <=> $countB),
                    default => $dir * strcmp($nameA, $nameB),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                return strcmp($isoA, $isoB);
            })
            ->values();
    }
}
