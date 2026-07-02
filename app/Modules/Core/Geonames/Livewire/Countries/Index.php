<?php

namespace App\Modules\Core\Geonames\Livewire\Countries;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Geonames\Database\Seeders\CountrySeeder;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithNotifications;
    use ResetsPaginationOnSearch;
    use SelectsPerPage;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'country';

    public string $sortDir = 'asc';

    /**
     * Preserve the historical default page size for the countries list.
     * Applied by the {@see SelectsPerPage} mount hook only when the URL does
     * not supply `?perPage=`.
     */
    protected function defaultPerPage(): int
    {
        return 20;
    }

    /**
     * @return list<int>
     */
    public function perPageOptions(): array
    {
        return [20, 50, 100, 300];
    }

    /** Allowed sort columns mapped to their DB column names. */
    private const SORTABLE = [
        'iso' => 'geonames_countries.iso',
        'country' => 'geonames_countries.country',
        'capital' => 'geonames_countries.capital',
        'phone' => 'geonames_countries.phone',
        'currency_code' => 'geonames_countries.currency_code',
        'population' => 'geonames_countries.population',
        'updated_at' => 'geonames_countries.updated_at',
    ];

    /** Default sort direction per column (omitted = 'asc'). */
    private const SORT_DEFAULT_DIR = [
        'population' => 'desc',
        'updated_at' => 'desc',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: self::SORT_DEFAULT_DIR,
        );
    }

    public function with(): array
    {
        $dbColumn = self::SORTABLE[$this->sortBy] ?? 'geonames_countries.country';

        return [
            'countries' => Country::query()
                ->when($this->search, function ($query, $search) {
                    $query->where(function ($q) use ($search): void {
                        $q->where('geonames_countries.country', 'ilike', '%'.$search.'%')
                            ->orWhere('geonames_countries.iso', 'ilike', '%'.$search.'%');
                    });
                })
                ->orderBy($dbColumn, $this->sortDir)
                ->orderBy('geonames_countries.iso')
                ->paginate($this->clampedPerPage()),
        ];
    }

    public function saveName(int $id, string $name): void
    {
        $country = Country::query()->findOrFail($id);
        $country->country = trim($name);
        $country->save();
        $this->notify(__('Country name saved.'));
    }

    public function update(): void
    {
        app(CountrySeeder::class)->run();
        $this->notify(__('Countries updated from Geonames.'));
    }

    public function render(): View
    {
        return view('livewire.admin.geonames.countries.index', $this->with());
    }
}
