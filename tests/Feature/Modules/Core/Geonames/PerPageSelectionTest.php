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
        assertGeonamesPerPage(CountriesIndex::class, 'countries', GeonamesSeeder::countries(...), null, 20);
    });

    it('honors a URL-supplied perPage over the default', function (): void {
        assertGeonamesPerPage(CountriesIndex::class, 'countries', GeonamesSeeder::countries(...), 50, 50);
    });

    it('clamps a stale out-of-range URL perPage to the largest option', function (): void {
        assertGeonamesPerPage(CountriesIndex::class, 'countries', GeonamesSeeder::countries(...), 9999, 300);
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
        assertGeonamesPerPage(Admin1Index::class, 'admin1s', GeonamesSeeder::admin1(...), null, 20);
    });

    it('honors a URL-supplied perPage over the default', function (): void {
        assertGeonamesPerPage(Admin1Index::class, 'admin1s', GeonamesSeeder::admin1(...), 50, 50);
    });

    it('clamps a stale out-of-range URL perPage to the largest option', function (): void {
        assertGeonamesPerPage(Admin1Index::class, 'admin1s', GeonamesSeeder::admin1(...), 9999, 300);
    });
});

describe('postcodes list', function (): void {
    it('falls back to the default page size when the URL does not supply perPage', function (): void {
        assertGeonamesPerPage(PostcodesIndex::class, 'postcodes', GeonamesSeeder::postcodes(...), null, 20);
    });

    it('honors a URL-supplied perPage over the default', function (): void {
        assertGeonamesPerPage(PostcodesIndex::class, 'postcodes', GeonamesSeeder::postcodes(...), 50, 50);
    });

    it('clamps a stale out-of-range URL perPage to the largest option', function (): void {
        assertGeonamesPerPage(PostcodesIndex::class, 'postcodes', GeonamesSeeder::postcodes(...), 9999, 300);
    });
});

/**
 * @param  class-string  $component
 * @param  callable(int): void  $seed
 */
function assertGeonamesPerPage(string $component, string $viewDataKey, callable $seed, ?int $queryPerPage, int $expectedPerPage): void
{
    $seed(5);

    $test = $queryPerPage === null
        ? Livewire::test($component)
        : Livewire::withQueryParams(['perPage' => $queryPerPage])->test($component);

    $test
        ->assertSet('perPage', $expectedPerPage)
        ->assertViewHas($viewDataKey, fn (LengthAwarePaginator $p): bool => $p->perPage() === $expectedPerPage);
}
