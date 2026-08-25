<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Geonames\Livewire\Admin1\Index as Admin1Index;
use App\Core\Geonames\Livewire\Countries\Index as CountriesIndex;
use App\Core\Geonames\Livewire\Postcodes\Index as PostcodesIndex;
use App\Core\Geonames\Models\Admin1;
use App\Core\Geonames\Models\Country;
use App\Core\User\Models\User;
use Livewire\Livewire;
use Tests\Support\GeonamesSeeder;

/**
 * Create a user holding only the given Geonames capabilities.
 *
 * @param  list<string>  $capabilities
 */
function geonamesUser(array $capabilities = []): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    foreach ($capabilities as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

    app(TenantContext::class)->set((int) $user->tenant_id);

    return $user;
}

test('authenticated users without list capability cannot open Geonames pages', function (): void {
    $this->actingAs(geonamesUser());

    $this->get(route('admin.geonames.countries.index'))->assertForbidden();
    $this->get(route('admin.geonames.admin1.index'))->assertForbidden();
    $this->get(route('admin.geonames.postcodes.index'))->assertForbidden();
});

test('list-only users can read but cannot invoke Geonames writes', function (): void {
    $this->actingAs(geonamesUser(['admin.geonames.list']));
    GeonamesSeeder::countries(1);
    GeonamesSeeder::admin1(1);

    $country = Country::query()->firstOrFail();
    $admin1 = Admin1::query()->firstOrFail();
    $denied = __('You do not have permission to perform this action.');

    Livewire::test(CountriesIndex::class)
        ->call('saveName', PHP_INT_MAX, '')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(CountriesIndex::class)
        ->call('update')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Admin1Index::class)
        ->call('saveName', PHP_INT_MAX, '')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Admin1Index::class)
        ->call('update')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(PostcodesIndex::class)
        ->call('import')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(PostcodesIndex::class)
        ->call('update')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    expect($country->refresh()->country)->toBe('Country 0')
        ->and($admin1->refresh()->name)->toBe('Division 0');
});

test('Geonames managers can mutate country names', function (): void {
    $this->actingAs(createAdminUser());
    GeonamesSeeder::countries(1);
    $country = Country::query()->firstOrFail();

    Livewire::test(CountriesIndex::class)
        ->call('saveName', $country->id, 'Renamed Country')
        ->assertDispatched('notify', variant: 'success', message: __('Country name saved.'));

    expect($country->refresh()->country)->toBe('Renamed Country');
});
