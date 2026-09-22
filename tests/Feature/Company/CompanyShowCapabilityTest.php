<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Address\Models\Address;
use App\Core\Company\Livewire\Companies\Show;
use App\Core\Company\Models\Company;
use App\Core\Geonames\Jobs\ImportPostcodes;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function companyShowViewOnlyUser(Company $company): User
{
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.company.view',
        'is_allowed' => true,
    ]);

    app(TenantContext::class)->set((int) $user->tenant_id);

    return $user;
}

test('view-only user is denied every company detail write', function (): void {
    Queue::fake();

    $parent = Company::factory()->create(['name' => 'Original Parent']);
    $company = Company::factory()->create([
        'tenant_id' => $parent->tenant_id,
        'parent_id' => $parent->id,
        'name' => 'Original Company',
        'status' => 'active',
        'scope_activities' => ['manufacturing'],
        'metadata' => ['source' => 'original'],
    ]);
    $viewer = companyShowViewOnlyUser($company);
    $attachedAddress = Address::factory()->create([
        'tenant_id' => (int) $company->tenant_id,
        'label' => 'Original address',
        'line1' => 'Original line',
    ]);
    $unattachedAddress = Address::factory()->create([
        'tenant_id' => (int) $company->tenant_id,
    ]);
    $company->addresses()->attach($attachedAddress->id, [
        'kind' => ['billing'],
        'is_primary' => false,
        'priority' => 5,
        'valid_from' => now()->toDateString(),
    ]);
    $timezoneSettings = app(TimezoneSettings::class);
    $timezoneSettings->setCompanyTimezone($company->id, 'UTC');
    $addressCount = Address::query()->count();
    $denied = __('You do not have permission to perform this action.');

    $this->actingAs($viewer);

    $actions = [
        fn () => Livewire::test(Show::class, ['company' => $company])->call('saveField', 'name', 'Forged Company'),
        fn () => Livewire::test(Show::class, ['company' => $company])->call('saveStatus', 'archived'),
        fn () => Livewire::test(Show::class, ['company' => $company])->call('saveParent', null),
        fn () => Livewire::test(Show::class, ['company' => $company])->call('addActivity', 'forged-activity'),
        fn () => Livewire::test(Show::class, ['company' => $company])->call('removeActivity', 0),
        fn () => Livewire::test(Show::class, ['company' => $company])->call('saveMetadata', '{"source":"forged"}'),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->call('updateAddressPivot', $attachedAddress->id, 'priority', 9),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->call('saveAddressKinds', $attachedAddress->id, ['shipping']),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->call('unlinkAddress', $attachedAddress->id),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('attachAddressId', $unattachedAddress->id)
            ->call('attachAddress'),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('kind', ['headquarters'])
            ->set('line1', 'Forged new address')
            ->call('saveAddress'),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('addressFormId', $attachedAddress->id)
            ->set('label', 'Forged address')
            ->set('line1', 'Forged address line')
            ->call('saveAddress'),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('companyTimezone', 'Asia/Kuala_Lumpur'),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('suggestedTimezone', 'Asia/Tokyo')
            ->call('acceptSuggestedTimezone'),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->call('openAddressModal', $attachedAddress->id)
            ->assertSet('showAddressModal', false)
            ->assertSet('addressFormId', null),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('postcode', 'KEEP-ME')
            ->set('countryIso', 'ZZ')
            ->assertSet('postcode', 'KEEP-ME'),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('localityOptions', [['value' => 'Keep', 'label' => 'Keep']])
            ->set('postcode', '12345')
            ->assertSet('localityOptions', [['value' => 'Keep', 'label' => 'Keep']]),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('admin1IsAuto', true)
            ->set('admin1Code', 'ZZ.01')
            ->assertSet('admin1IsAuto', true),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('localityIsAuto', true)
            ->set('locality', 'Keep Locality')
            ->assertSet('localityIsAuto', true),
        fn () => Livewire::test(Show::class, ['company' => $company])
            ->set('suggestedTimezone', 'Asia/Tokyo')
            ->set('suggestedTimezoneOld', 'UTC')
            ->call('dismissSuggestedTimezone')
            ->assertSet('suggestedTimezone', 'Asia/Tokyo')
            ->assertSet('suggestedTimezoneOld', 'UTC'),
    ];

    foreach ($actions as $action) {
        $action()->assertDispatched('notify', variant: 'error', message: $denied);
    }

    expect($company->refresh())
        ->name->toBe('Original Company')
        ->status->toBe('active')
        ->parent_id->toBe($parent->id)
        ->scope_activities->toBe(['manufacturing'])
        ->metadata->toBe(['source' => 'original'])
        ->and(Address::query()->count())->toBe($addressCount)
        ->and($attachedAddress->refresh()->label)->toBe('Original address')
        ->and($attachedAddress->line1)->toBe('Original line')
        ->and($company->addresses()->whereKey($attachedAddress)->exists())->toBeTrue()
        ->and($company->addresses()->whereKey($unattachedAddress)->exists())->toBeFalse()
        ->and($timezoneSettings->explicitCompanyTimezone($company->id))->toBe('UTC');

    $pivot = $company->addresses()->whereKey($attachedAddress)->firstOrFail()->pivot;

    expect($pivot->priority)->toBe(5)
        ->and($pivot->kind)->toBe(['billing']);

    Queue::assertNotPushed(ImportPostcodes::class);
});

test('company editor can use guarded company address and timezone mutations', function (): void {
    $company = Company::factory()->create([
        'name' => 'Before Update',
        'scope_activities' => [],
    ]);
    $editor = createTenantOwnerUser($company->id);
    $address = Address::factory()->create([
        'tenant_id' => (int) $company->tenant_id,
        'label' => 'Before Address',
        'line1' => 'Before Line',
    ]);
    $company->addresses()->attach($address->id, [
        'kind' => ['billing'],
        'is_primary' => false,
        'priority' => 1,
        'valid_from' => now()->toDateString(),
    ]);

    Livewire::actingAs($editor)
        ->test(Show::class, ['company' => $company])
        ->call('saveField', 'name', 'After Update')
        ->call('addActivity', 'distribution')
        ->call('openAddressModal', $address->id)
        ->set('label', 'After Address')
        ->set('line1', 'After Line')
        ->set('admin1Code', null)
        ->set('locality', 'After Locality')
        ->call('saveAddress')
        ->call('updateAddressPivot', $address->id, 'priority', 9)
        ->set('companyTimezone', 'Asia/Kuala_Lumpur')
        ->assertDispatched('timezone-saved', timezone: 'Asia/Kuala_Lumpur');

    expect($company->refresh()->name)->toBe('After Update')
        ->and($company->scope_activities)->toBe(['distribution'])
        ->and($address->refresh()->label)->toBe('After Address')
        ->and($address->line1)->toBe('After Line')
        ->and($company->addresses()->whereKey($address)->firstOrFail()->pivot->priority)->toBe(9)
        ->and(app(TimezoneSettings::class)->explicitCompanyTimezone($company->id))
        ->toBe('Asia/Kuala_Lumpur');
});
