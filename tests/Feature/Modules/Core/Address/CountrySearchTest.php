<?php

use App\Modules\Core\Geonames\Models\Country;

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
