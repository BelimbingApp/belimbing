<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Livewire\Companies\Show;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Geonames\Models\City;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

const COMPANY_TZ_SETTINGS_KEY = 'ui.timezone.default';
const COMPANY_TZ_KL = 'Asia/Kuala_Lumpur';
const COMPANY_TZ_TOKYO = 'Asia/Tokyo';
const COMPANY_CITY_KUALA_LUMPUR = 'Kuala Lumpur';

/**
 * Seed a Malaysia country and Kuala Lumpur city for geo-based timezone tests.
 */
function seedMalaysiaGeoData(): void
{
    Country::query()->create([
        'iso' => 'MY',
        'iso3' => 'MYS',
        'iso_numeric' => '458',
        'country' => 'Malaysia',
        'continent' => 'AS',
    ]);

    City::query()->create([
        'geoname_id' => 1735161,
        'name' => COMPANY_CITY_KUALA_LUMPUR,
        'ascii_name' => COMPANY_CITY_KUALA_LUMPUR,
        'country_iso' => 'MY',
        'latitude' => 3.1412,
        'longitude' => 101.6865,
        'population' => 1768000,
        'timezone' => COMPANY_TZ_KL,
    ]);
}

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
    $this->settings = app(SettingsService::class);
    $this->company = Company::factory()->minimal()->create();
    $this->user = User::factory()->create(['company_id' => $this->company->id]);
});

it('saves timezone on property change', function (): void {
    Livewire::actingAs($this->user)
        ->test(Show::class, ['company' => $this->company])
        ->set('companyTimezone', COMPANY_TZ_KL)
        ->assertDispatched('timezone-saved', timezone: COMPANY_TZ_KL);

    $stored = $this->settings->get(
        COMPANY_TZ_SETTINGS_KEY,
        null,
        Scope::company($this->company->id),
    );

    expect($stored)->toBe(COMPANY_TZ_KL);
});

it('rejects an invalid timezone identifier', function (): void {
    Livewire::actingAs($this->user)
        ->test(Show::class, ['company' => $this->company])
        ->set('companyTimezone', 'Not/A_Real_Zone');

    $stored = $this->settings->get(
        COMPANY_TZ_SETTINGS_KEY,
        null,
        Scope::company($this->company->id),
    );

    expect($stored)->toBeNull();
});

it('clears timezone when empty string is set', function (): void {
    $scope = Scope::company($this->company->id);
    $this->settings->set(COMPANY_TZ_SETTINGS_KEY, COMPANY_TZ_KL, $scope);

    Livewire::actingAs($this->user)
        ->test(Show::class, ['company' => $this->company])
        ->set('companyTimezone', '')
        ->assertDispatched('timezone-saved', timezone: '');

    expect($this->settings->has(COMPANY_TZ_SETTINGS_KEY, $scope))->toBeFalse();
});

it('loads existing timezone on mount', function (): void {
    $this->settings->set(
        COMPANY_TZ_SETTINGS_KEY,
        COMPANY_TZ_TOKYO,
        Scope::company($this->company->id),
    );

    $component = Livewire::actingAs($this->user)
        ->test(Show::class, ['company' => $this->company]);

    expect($component->get('companyTimezone'))->toBe(COMPANY_TZ_TOKYO);
});

it('auto-saves timezone when address locality matches a city exactly', function (): void {
    seedMalaysiaGeoData();

    $component = Livewire::actingAs($this->user)
        ->test(Show::class, ['company' => $this->company]);

    // Simulate creating an address with matching locality via the modal.
    $component
        ->set('countryIso', 'MY')
        ->set('locality', COMPANY_CITY_KUALA_LUMPUR)
        ->set('kind', ['headquarters'])
        ->call('saveAddress');

    $stored = $this->settings->get(
        COMPANY_TZ_SETTINGS_KEY,
        null,
        Scope::company($this->company->id),
    );

    expect($stored)->toBe(COMPANY_TZ_KL);
    expect($component->get('companyTimezone'))->toBe(COMPANY_TZ_KL);
});

it('does not auto-save timezone when locality has no exact city match', function (): void {
    seedMalaysiaGeoData();

    $component = Livewire::actingAs($this->user)
        ->test(Show::class, ['company' => $this->company]);

    $component
        ->set('countryIso', 'MY')
        ->set('locality', 'Kampung Baru')
        ->set('kind', ['headquarters'])
        ->call('saveAddress');

    $stored = $this->settings->get(
        COMPANY_TZ_SETTINGS_KEY,
        null,
        Scope::company($this->company->id),
    );

    expect($stored)->toBeNull();
});

it('returns null suggestion when no address exists', function (): void {
    $component = Livewire::actingAs($this->user)
        ->test(Show::class, ['company' => $this->company]);

    expect($component->get('companyTimezone'))->toBe('');
});
