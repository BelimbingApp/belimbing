<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Address\Models\Address;
use App\Core\Company\Models\Company;
use App\Core\Employee\Livewire\Employees\Show;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Livewire\Livewire;

/**
 * Create a user holding only the admin.employee.view capability.
 */
function employeeShowViewOnlyUser(Company $company): User
{
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.employee.view',
        'is_allowed' => true,
    ]);

    app(TenantContext::class)->set((int) $user->tenant_id);

    return $user;
}

test('view-only user is denied employee writes', function (): void {
    $company = Company::factory()->create();
    $viewer = employeeShowViewOnlyUser($company);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'full_name' => 'Original Name',
        'status' => 'active',
    ]);
    $linkable = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => null,
    ]);
    $subordinate = Employee::factory()->create([
        'company_id' => $company->id,
        'supervisor_id' => null,
    ]);
    $address = Address::factory()->create([
        'tenant_id' => (int) $company->tenant_id,
    ]);
    $denied = __('You do not have permission to perform this action.');

    $this->actingAs($viewer);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('saveField', 'full_name', 'Hacked Name')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('saveStatus', 'terminated')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('saveUser', $linkable->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('addSubordinate', $subordinate->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->set('attachAddressId', $address->id)
        ->call('attachAddress')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    expect($employee->refresh())
        ->full_name->toBe('Original Name')
        ->status->toBe('active')
        ->and($linkable->refresh()->employee_id)->toBeNull()
        ->and($subordinate->refresh()->supervisor_id)->toBeNull()
        ->and($employee->addresses()->count())->toBe(0);
});

test('view-only user is denied remaining employee writes', function (): void {
    $company = Company::factory()->create();
    $viewer = employeeShowViewOnlyUser($company);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'staff',
        'department_id' => null,
        'supervisor_id' => null,
    ]);
    $subordinate = Employee::factory()->create([
        'company_id' => $company->id,
        'supervisor_id' => $employee->id,
    ]);
    $address = Address::factory()->create([
        'tenant_id' => (int) $company->tenant_id,
    ]);
    $employee->addresses()->attach($address->id, [
        'kind' => ['billing'],
        'is_primary' => false,
        'priority' => 5,
        'valid_from' => now()->toDateString(),
    ]);
    $denied = __('You do not have permission to perform this action.');

    $this->actingAs($viewer);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('saveEmployeeType', 'agent')
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('saveDepartment', null)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('saveSupervisor', $subordinate->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('removeSubordinate', $subordinate->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('unlinkAddress', $address->id)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('updateAddressPivot', $address->id, 'priority', 9)
        ->assertDispatched('notify', variant: 'error', message: $denied);

    Livewire::test(Show::class, ['employee' => $employee])
        ->call('saveAddressKinds', $address->id, ['shipping'])
        ->assertDispatched('notify', variant: 'error', message: $denied);

    expect($employee->refresh())
        ->employee_type->toBe('staff')
        ->supervisor_id->toBeNull()
        ->and($subordinate->refresh()->supervisor_id)->toBe($employee->id);

    $pivot = $employee->addresses()->first()->pivot;

    expect($pivot->priority)->toBe(5)
        ->and($pivot->kind)->toBe(['billing']);
});

test('capable user can update an employee field', function (): void {
    $admin = createAdminUser();
    $employee = Employee::factory()->create([
        'company_id' => $admin->company_id,
        'full_name' => 'Before Update',
    ]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['employee' => $employee])
        ->call('saveField', 'full_name', 'After Update')
        ->assertDispatched('notify', variant: 'success');

    expect($employee->refresh()->full_name)->toBe('After Update');
});
