<?php

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

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
        ->set('line1', '88 River Road')
        ->set('countryIso', 'us')
        ->set('locality', 'Boston')
        ->set('postcode', '02110')
        ->set('verificationStatus', 'verified')
        ->call('store')
        ->assertRedirect(route('admin.addresses.index'));

    $address = Address::query()
        ->where('label', 'Warehouse')
        ->where('line1', '88 River Road')
        ->latest('id')
        ->first();

    expect($address)
        ->not()->toBeNull()
        ->and($address->label)
        ->toBe('Warehouse')
        ->and($address->line1)
        ->toBe('88 River Road')
        ->and($address->locality)
        ->toBe('Boston')
        ->and($address->postcode)
        ->toBe('02110')
        ->and($address->country_iso)
        ->toBe('US')
        ->and($address->verificationStatus)
        ->toBe('verified');
});
