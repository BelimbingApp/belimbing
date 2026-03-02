<?php

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\User\Models\User;

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
        'verification_status' => 'unverified',
    ]);

    $this->actingAs($user);

    $this->get(route('admin.addresses.index'))->assertOk();
    $this->get(route('admin.addresses.create'))->assertOk();
    $this->get(route('admin.addresses.show', $address))->assertOk();
});

test('address can be created from create page component', function (): void {
    Country::query()->create([
        'iso' => 'US',
        'iso3' => 'USA',
        'iso_numeric' => '840',
        'country' => 'United States',
        'continent' => 'NA',
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('admin.addresses.store'), [
        'label' => 'Warehouse',
        'line1' => '88 River Road',
        'locality' => 'Boston',
        'postcode' => '02110',
        'country_iso' => 'US',
        'verification_status' => 'verified',
    ]);

    $response->assertRedirect(route('admin.addresses.index'));

    $address = Address::query()->where('label', 'Warehouse')->first();

    expect($address)
        ->not()->toBeNull()
        ->and($address->country_iso)
        ->toBe('US')
        ->and($address->verification_status)
        ->toBe('verified');
});
