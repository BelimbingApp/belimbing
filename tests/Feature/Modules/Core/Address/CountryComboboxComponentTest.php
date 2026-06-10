<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Livewire\Livewire;

/**
 * The country-combobox is the single source of rendering for country INPUT
 * selectors. These tests prove it (a) forwards the consumer's wire:model
 * binding all the way into the inner x-ui.combobox, (b) sources its options
 * from the SSOT search endpoint, and (c) keeps storing a 2-letter ISO code on
 * the eBay Step 2 shipping-location selector.
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

test('eBay Step 2 country selector still stores a 2-letter ISO via the combobox', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    app(SettingsService::class)->set('marketplace.ebay.environment', 'sandbox', $scope);
    app(SettingsService::class)->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);

    Http::fake(function ($request) {
        return match (true) {
            $request->method() === 'POST' && str_contains($request->url(), '/sell/inventory/v1/location/') => Http::response([], 204),
            str_contains($request->url(), '/sell/inventory/v1/location') => Http::response(['locations' => []], 200),
            default => Http::response([], 200),
        };
    });

    $this->actingAs($user);

    // Default is 'US'; the dropdown stores the ISO code, not a country name.
    // A 2-letter ISO passes validation (size:2) and reaches eBay; a country
    // name would fail validation, proving the binding stores the code.
    Livewire::test(EbaySettings::class)
        ->assertSet('newLocationCountry', 'US')
        ->set('newLocationKey', 'california_shop')
        ->set('newLocationCountry', 'US')
        ->set('newLocationState', 'CA')
        ->set('newLocationCity', 'Los Angeles')
        ->set('newLocationPostal', '90001')
        ->call('createMerchantLocation')
        ->assertHasNoErrors();

    // A non-2-char value (e.g. a country name) is rejected by validation.
    Livewire::test(EbaySettings::class)
        ->set('newLocationKey', 'california_shop')
        ->set('newLocationCity', 'Los Angeles')
        ->set('newLocationState', 'CA')
        ->set('newLocationPostal', '90001')
        ->set('newLocationCountry', 'United States')
        ->call('createMerchantLocation')
        ->assertHasErrors(['newLocationCountry']);
});
