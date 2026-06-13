<?php

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Geonames\Models\Admin1;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\Geonames\Models\Postcode;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

const ADDRESS_UI_WAREHOUSE_LINE = '88 River Road';
const ADDRESS_UI_BOSTON_POSTCODE = '02110';
const ADDRESS_UI_KUALA_LUMPUR = 'Kuala Lumpur';
const ADDRESS_UI_KUALA_LUMPUR_POSTCODE = '50450';

test('guests are redirected to login from addresses pages', function (): void {
    $this->get(route('admin.addresses.index'))->assertRedirect(route('login'));
    $this->get(route('admin.addresses.create'))->assertRedirect(route('login'));
});

test('authenticated users can view address pages', function (): void {
    $user = User::factory()->create();
    $address = Address::query()->create([
        'label' => 'HQ',
        'line1' => '123 Main Street',
        'locality' => 'Springfield',
        'verificationStatus' => 'unverified',
    ]);

    $this->actingAs($user);

    $this->get(route('admin.addresses.index'))->assertOk();
    $this->get(route('admin.addresses.create'))->assertOk();
    $this->get(route('admin.addresses.show', $address))->assertOk();
});

test('address can be created from create page component', function (): void {
    Country::query()->updateOrCreate(
        ['iso' => 'US'],
        [
            'iso3' => 'USA',
            'iso_numeric' => '840',
            'country' => 'United States',
            'continent' => 'NA',
        ]
    );

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('admin.addresses.create')
        ->set('label', 'Warehouse')
        ->set('line1', ADDRESS_UI_WAREHOUSE_LINE)
        ->set('countryIso', 'us')
        ->set('locality', 'Boston')
        ->set('postcode', ADDRESS_UI_BOSTON_POSTCODE)
        ->set('verificationStatus', 'verified')
        ->call('store')
        ->assertRedirect(route('admin.addresses.index'));

    $address = Address::query()
        ->where('label', 'Warehouse')
        ->where('line1', ADDRESS_UI_WAREHOUSE_LINE)
        ->latest('id')
        ->first();

    expect($address)
        ->not()->toBeNull()
        ->and($address->label)
        ->toBe('Warehouse')
        ->and($address->line1)
        ->toBe(ADDRESS_UI_WAREHOUSE_LINE)
        ->and($address->locality)
        ->toBe('Boston')
        ->and($address->postcode)
        ->toBe(ADDRESS_UI_BOSTON_POSTCODE)
        ->and($address->country_iso)
        ->toBe('US')
        ->and($address->verificationStatus)
        ->toBe('verified');
});

test('address detail saves location as a grouped edit', function (): void {
    Country::query()->create([
        'iso' => 'US',
        'iso3' => 'USA',
        'iso_numeric' => '840',
        'country' => 'United States',
        'continent' => 'NA',
    ]);
    Country::query()->create([
        'iso' => 'MY',
        'iso3' => 'MYS',
        'iso_numeric' => '458',
        'country' => 'Malaysia',
        'continent' => 'AS',
    ]);
    Admin1::query()->create(['code' => 'MY.14', 'name' => ADDRESS_UI_KUALA_LUMPUR]);
    Postcode::query()->create([
        'country_iso' => 'MY',
        'postcode' => ADDRESS_UI_KUALA_LUMPUR_POSTCODE,
        'place_name' => ADDRESS_UI_KUALA_LUMPUR,
        'admin1Code' => '14',
        'admin_name1' => ADDRESS_UI_KUALA_LUMPUR,
    ]);

    $user = User::factory()->create();
    $address = Address::query()->create([
        'label' => 'HQ',
        'line1' => '1 Old Road',
        'country_iso' => 'US',
        'postcode' => ADDRESS_UI_BOSTON_POSTCODE,
        'locality' => 'Boston',
        'verificationStatus' => 'unverified',
    ]);

    $this->actingAs($user);

    Livewire::test('admin.addresses.show', ['address' => $address])
        ->call('openLocationEditor')
        ->set('countryIso', 'MY')
        ->set('postcode', ADDRESS_UI_KUALA_LUMPUR_POSTCODE)
        ->assertSet('admin1Code', 'MY.14')
        ->assertSet('locality', ADDRESS_UI_KUALA_LUMPUR);

    expect($address->fresh()->country_iso)->toBe('US');

    Livewire::test('admin.addresses.show', ['address' => $address])
        ->call('openLocationEditor')
        ->set('countryIso', 'MY')
        ->set('postcode', ADDRESS_UI_KUALA_LUMPUR_POSTCODE)
        ->call('saveLocation')
        ->assertSet('editingLocation', false)
        ->assertSee('Malaysia');

    $address->refresh();

    expect($address->country_iso)
        ->toBe('MY')
        ->and($address->admin1Code)
        ->toBe('MY.14')
        ->and($address->postcode)
        ->toBe(ADDRESS_UI_KUALA_LUMPUR_POSTCODE)
        ->and($address->locality)
        ->toBe(ADDRESS_UI_KUALA_LUMPUR);
});
