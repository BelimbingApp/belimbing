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

test('view-only user is denied every employee detail write', function (): void {
    $company = Company::factory()->create();
    $viewer = employeeShowViewOnlyUser($company);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'full_name' => 'Original Name',
        'employee_type' => 'staff',
        'status' => 'active',
    ]);
    $linkable = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => null,
    ]);
    $unassignedSubordinate = Employee::factory()->create([
        'company_id' => $company->id,
        'supervisor_id' => null,
    ]);
    $assignedSubordinate = Employee::factory()->create([
        'company_id' => $company->id,
        'supervisor_id' => $employee->id,
    ]);
    $unattachedAddress = Address::factory()->create([
        'tenant_id' => (int) $company->tenant_id,
    ]);
    $attachedAddress = Address::factory()->create([
        'tenant_id' => (int) $company->tenant_id,
    ]);
    $employee->addresses()->attach($attachedAddress->id, [
        'kind' => ['billing'],
        'is_primary' => false,
        'priority' => 5,
        'valid_from' => now()->toDateString(),
    ]);
    $denied = __('You do not have permission to perform this action.');

    $this->actingAs($viewer);

    $actions = [
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('saveField', 'full_name', 'Forged Name'),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('saveStatus', 'terminated'),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('saveEmployeeType', 'agent'),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('saveDepartment', null),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('saveSupervisor', $unassignedSubordinate->id),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('saveUser', $linkable->id),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('addSubordinate', $unassignedSubordinate->id),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('removeSubordinate', $assignedSubordinate->id),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->set('attachAddressId', $unattachedAddress->id)->call('attachAddress'),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('unlinkAddress', $attachedAddress->id),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('updateAddressPivot', $attachedAddress->id, 'priority', 9),
        fn () => Livewire::test(Show::class, ['employee' => $employee])->call('saveAddressKinds', $attachedAddress->id, ['shipping']),
    ];

    foreach ($actions as $action) {
        $action()->assertDispatched('notify', variant: 'error', message: $denied);
    }

    expect($employee->refresh())
        ->full_name->toBe('Original Name')
        ->employee_type->toBe('staff')
        ->status->toBe('active')
        ->department_id->toBeNull()
        ->supervisor_id->toBeNull()
        ->and($linkable->refresh()->employee_id)->toBeNull()
        ->and($unassignedSubordinate->refresh()->supervisor_id)->toBeNull()
        ->and($assignedSubordinate->refresh()->supervisor_id)->toBe($employee->id)
        ->and($employee->addresses()->whereKey($unattachedAddress)->exists())->toBeFalse()
        ->and($employee->addresses()->whereKey($attachedAddress)->exists())->toBeTrue();

    $pivot = $employee->addresses()->whereKey($attachedAddress)->firstOrFail()->pivot;

    expect($pivot->priority)->toBe(5)
        ->and($pivot->kind)->toBe(['billing']);
});

test('employee editor can update an employee field', function (): void {
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
