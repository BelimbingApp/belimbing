<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Livewire\Countries;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\Geonames\Database\Seeders\CountrySeeder;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'country';

    public string $sortDir = 'asc';

    /** Allowed sort columns mapped to their DB column names. */
    private const SORTABLE = [
        'country' => 'country',
        'population' => 'population',
    ];

    /** Default sort direction per column (omitted = 'asc'). */
    private const SORT_DEFAULT_DIR = [
        'population' => 'desc',
    ];

    public function sort(string $column): void
    {
        if (! array_key_exists($column, self::SORTABLE)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = self::SORT_DEFAULT_DIR[$column] ?? 'asc';
        }

        $this->resetPage();
    }

    public function with(): array
    {
        $dbColumn = self::SORTABLE[$this->sortBy] ?? 'country';

        return [
            'countries' => Country::query()
                ->when($this->search, function ($query, $search) {
                    $query->where('country', 'ilike', '%'.$search.'%')
                        ->orWhere('iso', 'ilike', '%'.$search.'%');
                })
                ->orderBy($dbColumn, $this->sortDir)
                ->paginate(20),
        ];
    }

    public function saveName(int $id, string $name): void
    {
        $country = Country::query()->findOrFail($id);
        $country->country = trim($name);
        $country->save();
    }

    public function update(): void
    {
        app(CountrySeeder::class)->run();
        Session::flash('success', __('Countries updated from Geonames.'));
    }

    public function render(): View
    {
        return view('livewire.admin.geonames.countries.index', $this->with());
    }
}
