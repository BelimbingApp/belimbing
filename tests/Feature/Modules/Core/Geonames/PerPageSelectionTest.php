<?php

use App\Modules\Core\Geonames\Livewire\Admin1\Index as Admin1Index;
use App\Modules\Core\Geonames\Livewire\Countries\Index as CountriesIndex;
use App\Modules\Core\Geonames\Livewire\Postcodes\Index as PostcodesIndex;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Livewire;
use Tests\Support\GeonamesSeeder;

beforeEach(function (): void {
    $this->actingAs(createAdminUser());
});

describe('countries list', function (): void {
    it('falls back to the default page size when the URL does not supply perPage', function (): void {
        GeonamesSeeder::countries(5);

        Livewire::test(CountriesIndex::class)
            ->assertSet('perPage', 20)
            ->assertViewHas('countries', fn (LengthAwarePaginator $p): bool => $p->perPage() === 20);
    });

    it('honors a URL-supplied perPage over the default', function (): void {
        GeonamesSeeder::countries(5);

        Livewire::withQueryParams(['perPage' => 50])
            ->test(CountriesIndex::class)
            ->assertSet('perPage', 50)
            ->assertViewHas('countries', fn (LengthAwarePaginator $p): bool => $p->perPage() === 50);
    });

    it('clamps a stale out-of-range URL perPage to the largest option', function (): void {
        GeonamesSeeder::countries(5);

        Livewire::withQueryParams(['perPage' => 9999])
            ->test(CountriesIndex::class)
            ->assertSet('perPage', 300)
            ->assertViewHas('countries', fn (LengthAwarePaginator $p): bool => $p->perPage() === 300);
    });

    it('reloads with the new page size when the per-page selector changes', function (): void {
        GeonamesSeeder::countries(5);

        Livewire::test(CountriesIndex::class)
            ->set('perPage', 300)
            ->assertSet('perPage', 300)
            ->assertViewHas('countries', fn (LengthAwarePaginator $p): bool => $p->perPage() === 300);
    });
});

describe('admin1 list', function (): void {
    it('falls back to the default page size when the URL does not supply perPage', function (): void {
        GeonamesSeeder::admin1(5);

        Livewire::test(Admin1Index::class)
            ->assertSet('perPage', 20)
            ->assertViewHas('admin1s', fn (LengthAwarePaginator $p): bool => $p->perPage() === 20);
    });

    it('honors a URL-supplied perPage over the default', function (): void {
        GeonamesSeeder::admin1(5);

        Livewire::withQueryParams(['perPage' => 50])
            ->test(Admin1Index::class)
            ->assertSet('perPage', 50)
            ->assertViewHas('admin1s', fn (LengthAwarePaginator $p): bool => $p->perPage() === 50);
    });

    it('clamps a stale out-of-range URL perPage to the largest option', function (): void {
        GeonamesSeeder::admin1(5);

        Livewire::withQueryParams(['perPage' => 9999])
            ->test(Admin1Index::class)
            ->assertSet('perPage', 300)
            ->assertViewHas('admin1s', fn (LengthAwarePaginator $p): bool => $p->perPage() === 300);
    });
});

describe('postcodes list', function (): void {
    it('falls back to the default page size when the URL does not supply perPage', function (): void {
        GeonamesSeeder::postcodes(5);

        Livewire::test(PostcodesIndex::class)
            ->assertSet('perPage', 20)
            ->assertViewHas('postcodes', fn (LengthAwarePaginator $p): bool => $p->perPage() === 20);
    });

    it('honors a URL-supplied perPage over the default', function (): void {
        GeonamesSeeder::postcodes(5);

        Livewire::withQueryParams(['perPage' => 50])
            ->test(PostcodesIndex::class)
            ->assertSet('perPage', 50)
            ->assertViewHas('postcodes', fn (LengthAwarePaginator $p): bool => $p->perPage() === 50);
    });

    it('clamps a stale out-of-range URL perPage to the largest option', function (): void {
        GeonamesSeeder::postcodes(5);

        Livewire::withQueryParams(['perPage' => 9999])
            ->test(PostcodesIndex::class)
            ->assertSet('perPage', 300)
            ->assertViewHas('postcodes', fn (LengthAwarePaginator $p): bool => $p->perPage() === 300);
    });
});
