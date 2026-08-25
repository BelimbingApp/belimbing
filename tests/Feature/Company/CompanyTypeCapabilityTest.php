<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Livewire\Companies\DepartmentTypes;
use App\Core\Company\Livewire\Companies\LegalEntityTypes;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\DepartmentType;
use App\Core\Company\Models\LegalEntityType;
use App\Core\User\Models\User;
use Livewire\Livewire;

/**
 * Create a user holding only the admin.company.list capability.
 */
function companyTypeListOnlyUser(): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.company.list',
        'is_allowed' => true,
    ]);

    app(TenantContext::class)->set((int) $user->tenant_id);

    return $user;
}

function companyTypeLegalEntityFixture(): LegalEntityType
{
    return LegalEntityType::query()->create([
        'code' => 'llc',
        'name' => 'Limited Liability Company',
        'is_active' => true,
    ]);
}

function companyTypeDepartmentFixture(): DepartmentType
{
    return DepartmentType::query()->create([
        'code' => 'ops',
        'name' => 'Operations',
        'category' => 'operational',
        'is_active' => true,
    ]);
}

test('list-only user is denied legal entity type writes', function (): void {
    $this->actingAs(companyTypeListOnlyUser());
    $type = companyTypeLegalEntityFixture();
    $denied = __('You do not have permission to perform this action.');

    Livewire::test(LegalEntityTypes::class)
        ->set('createCode', 'plc')
        ->set('createName', 'Public Limited Company')
        ->call('createType')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(LegalEntityTypes::class)
        ->call('saveField', $type->id, 'name', 'Renamed')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(LegalEntityTypes::class)
        ->call('toggleActive', $type->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(LegalEntityTypes::class)
        ->call('deleteType', $type->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    expect(LegalEntityType::query()->where('code', 'plc')->exists())->toBeFalse()
        ->and($type->refresh())
        ->name->toBe('Limited Liability Company')
        ->is_active->toBeTrue();
});

test('list-only user is denied department type writes', function (): void {
    $this->actingAs(companyTypeListOnlyUser());
    $type = companyTypeDepartmentFixture();
    $denied = __('You do not have permission to perform this action.');

    Livewire::test(DepartmentTypes::class)
        ->set('createCode', 'fin')
        ->set('createName', 'Finance')
        ->call('createType')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(DepartmentTypes::class)
        ->call('saveField', $type->id, 'name', 'Renamed')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(DepartmentTypes::class)
        ->call('toggleActive', $type->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(DepartmentTypes::class)
        ->call('deleteType', $type->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    expect(DepartmentType::query()->where('code', 'fin')->exists())->toBeFalse()
        ->and($type->refresh())
        ->name->toBe('Operations')
        ->is_active->toBeTrue();
});

test('admin user can create a legal entity type', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(LegalEntityTypes::class)
        ->set('createCode', 'plc')
        ->set('createName', 'Public Limited Company')
        ->call('createType')
        ->assertDispatched('notify', variant: 'success', message: __('Legal entity type created.'));

    expect(LegalEntityType::query()->where('code', 'plc')->exists())->toBeTrue();
});

test('admin user can toggle a department type', function (): void {
    $this->actingAs(createAdminUser());
    $type = companyTypeDepartmentFixture();

    Livewire::test(DepartmentTypes::class)
        ->call('toggleActive', $type->id)
        ->assertDispatched('notify', variant: 'success', message: __('Department type status updated.'));

    expect($type->refresh()->is_active)->toBeFalse();
});
