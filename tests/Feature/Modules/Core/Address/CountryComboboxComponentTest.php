<?php

use App\Modules\Core\Geonames\Models\Country;
use Livewire\Component;
use Livewire\Livewire;

/**
 * The country-combobox is the single source of rendering for country INPUT
 * selectors. These tests prove it (a) forwards the consumer's wire:model
 * binding all the way into the inner x-ui.combobox, and (b) sources its
 * options from the SSOT search endpoint. The eBay Step 2 regression for this
 * component lives in Commerce/Marketplace's EbayCountryComboboxTest.
 */
test('country-combobox forwards wire:model into the inner combobox entangle', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::component('country-combobox-forward-probe', new class extends Component
    {
        public ?string $countryIso = null;

        public function render(): string
        {
            return '<div><x-ui.country-combobox wire:model.live="countryIso" :label="__(\'Country\')" /></div>';
        }
    });

    $html = Livewire::test('country-combobox-forward-probe')->html();

    // The inner combobox entangles against the forwarded property name, with
    // the .live modifier preserved (address pages depend on .live to load
    // admin1/postcode on country change).
    expect($html)->toContain("entangle('countryIso')")
        ->and($html)->toContain('.live')
        // Options come from the SSOT search endpoint, not inlined markup.
        ->and($html)->toContain('searchUrl:')
        ->and($html)->toContain('admin/addresses/countries/search');
});

test('country-combobox filter mode requests the All countries option', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::component('country-combobox-filter-probe', new class extends Component
    {
        public string $filterCountryIso = '';

        public function render(): string
        {
            return '<div><x-ui.country-combobox wire:model.live="filterCountryIso" :include-all="true" /></div>';
        }
    });

    $html = Livewire::test('country-combobox-filter-probe')->html();

    expect($html)->toContain('admin/addresses/countries/search')
        ->and($html)->toContain('all=');
});

test('country-combobox binds end to end in a Livewire component', function (): void {
    Country::query()->create([
        'iso' => 'US',
        'iso3' => 'USA',
        'iso_numeric' => '840',
        'country' => 'United States',
        'continent' => 'NA',
    ]);

    $this->actingAs(createAdminUser());

    Livewire::component('country-combobox-binding-probe', new class extends Component
    {
        public ?string $country = null;

        public function render(): string
        {
            return '<div><x-ui.country-combobox wire:model.live="country" :label="__(\'Country\')" /></div>';
        }
    });

    // Selection -> property updated: setting the entangled value through the
    // component round-trips back to the host Livewire property.
    Livewire::test('country-combobox-binding-probe')
        ->assertSet('country', null)
        ->set('country', 'US')
        ->assertSet('country', 'US');
});
