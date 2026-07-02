<?php

namespace App\Modules\Core\Geonames\Livewire\Admin1;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Geonames\Database\Seeders\Admin1Seeder;
use App\Modules\Core\Geonames\Models\Admin1;
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

    public string $filterCountryIso = '';

    public string $sortBy = 'country_name';

    public string $sortDir = 'asc';

    /**
     * Preserve the historical default page size for the admin1 list.
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

    private const SORTABLE = [
        'country_name' => 'country_name',
        'code' => 'geonames_admin1.code',
        'name' => 'geonames_admin1.name',
        'alt_name' => 'geonames_admin1.alt_name',
        'updated_at' => 'geonames_admin1.updated_at',
    ];

    public function updatedFilterCountryIso(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'country_name' => 'asc',
                'code' => 'asc',
                'name' => 'asc',
                'alt_name' => 'asc',
                'updated_at' => 'desc',
            ],
        );
    }

    public function with(): array
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'country_name';

        $query = Admin1::query()
            ->withCountryName()
            ->orderBy($sortColumn, $this->sortDir)
            ->orderByDesc('geonames_admin1.id');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('geonames_admin1.name', 'like', '%'.$this->search.'%')
                    ->orWhere('geonames_admin1.code', 'like', '%'.$this->search.'%')
                    ->orWhere('geonames_countries.country', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->filterCountryIso) {
            $query->forCountry($this->filterCountryIso);
        }

        $importedCountries = Admin1::query()
            ->selectRaw(Admin1::countryIsoSql('code').' as iso')
            ->distinct()
            ->pluck('iso')
            ->sort()
            ->values();

        $countryNames = Country::query()
            ->whereIn('iso', $importedCountries)
            ->orderBy('country')
            ->pluck('country', 'iso');

        return [
            'admin1s' => $query->paginate($this->clampedPerPage()),
            'importedCountries' => $countryNames,
        ];
    }

    public function saveName(int $id, string $name): void
    {
        $admin1 = Admin1::query()->findOrFail($id);
        $admin1->name = trim($name);
        $admin1->save();
        $this->notify(__('Admin1 name saved.'));
    }

    public function update(): void
    {
        app(Admin1Seeder::class)->run();

        $this->notify(__('Admin1 divisions updated from Geonames.'));
    }

    public function render(): View
    {
        return view('livewire.admin.geonames.admin1.index', $this->with());
    }
}
