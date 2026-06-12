<?php

use App\Modules\Core\Geonames\Models\Country;

const COUNTRY_SEARCH_FILTER_LABEL = 'Any country';

function makeCountry(string $iso, string $iso3, string $isoNum, string $name): Country
{
    return Country::query()->create([
        'iso' => $iso,
        'iso3' => $iso3,
        'iso_numeric' => $isoNum,
        'country' => $name,
        'continent' => 'AS',
    ]);
}

test('country search returns case-insensitive name matches', function (): void {
    makeCountry('MY', 'MYS', '458', 'Malaysia');
    makeCountry('MV', 'MDV', '462', 'Maldives');
    makeCountry('CA', 'CAN', '124', 'Canada');

    $this->actingAs(createAdminUser());

    $results = collect($this->getJson(route('admin.addresses.countries.search', ['q' => 'mal']))
        ->assertOk()
        ->json());

    $labels = $results->pluck('label');

    expect($labels)->toContain('Malaysia')
        ->and($labels)->toContain('Maldives')
        ->and($labels)->not->toContain('Canada');

    // Shape matches the combobox option contract.
    expect($results->first())->toHaveKeys(['value', 'label']);
});

test('country search with an empty query returns the full list', function (): void {
    makeCountry('MY', 'MYS', '458', 'Malaysia');
    makeCountry('CA', 'CAN', '124', 'Canada');

    $this->actingAs(createAdminUser());

    $labels = collect($this->getJson(route('admin.addresses.countries.search'))
        ->assertOk()
        ->json())
        ->pluck('label');

    expect($labels)->toContain('Malaysia')->toContain('Canada');
});

test('country search filter mode prepends an empty All countries option', function (): void {
    makeCountry('MY', 'MYS', '458', 'Malaysia');

    $this->actingAs(createAdminUser());

    $results = collect($this->getJson(route('admin.addresses.countries.search', ['all' => COUNTRY_SEARCH_FILTER_LABEL]))
        ->assertOk()
        ->json());

    // First option is the empty-valued "All countries" sentinel used by the
    // country-combobox filter mode (e.g. the geonames admin1 page).
    expect($results->first())->toMatchArray(['value' => '', 'label' => COUNTRY_SEARCH_FILTER_LABEL]);

    // The sentinel only appears for the unfiltered list, never amid matches.
    $filtered = collect($this->getJson(route('admin.addresses.countries.search', ['all' => COUNTRY_SEARCH_FILTER_LABEL, 'q' => 'mal']))
        ->assertOk()
        ->json());

    expect($filtered->pluck('value'))->not->toContain('');
});
