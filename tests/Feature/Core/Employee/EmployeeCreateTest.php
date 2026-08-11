<?php

use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Livewire\Employees\Create;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Livewire\Livewire;

it('links the selected user account when creating an employee', function (): void {
    $admin = createAdminUser();
    $user = User::factory()->create([
        'company_id' => $admin->company_id,
        'employee_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(Create::class)
        ->set('companyId', $admin->company_id)
        ->set('employeeNumber', 'EMP-CREATE-001')
        ->set('fullName', 'Created Employee Link')
        ->set('employeeType', 'full_time')
        ->set('status', 'active')
        ->set('userId', $user->id)
        ->call('store')
        ->assertRedirect(route('admin.employees.index'));

    $employee = Employee::query()
        ->where('employee_number', 'EMP-CREATE-001')
        ->firstOrFail();

    expect($user->refresh()->employee_id)->toBe($employee->id);
});
it('rejects cross-tenant department supervisor and user ids when creating an employee', function (): void {
    $tenantCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $tenantOwner = createTenantOwnerUser($tenantCompany->id);

    $departmentType = DepartmentType::query()->create([
        'code' => 'qa-cross-tenant',
        'name' => 'QA Cross Tenant',
        'category' => 'test',
        'is_active' => true,
    ]);

    $otherDepartment = Department::query()->create([
        'company_id' => $otherCompany->id,
        'department_type_id' => $departmentType->id,
        'status' => 'active',
    ]);

    $otherSupervisor = Employee::factory()->create(['company_id' => $otherCompany->id]);
    $otherUser = User::factory()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => null,
    ]);

    Livewire::actingAs($tenantOwner)
        ->test(Create::class)
        ->set('companyId', $tenantCompany->id)
        ->set('employeeNumber', 'EMP-CREATE-XTENANT')
        ->set('fullName', 'Cross Tenant Attempt')
        ->set('employeeType', 'full_time')
        ->set('status', 'active')
        ->set('departmentId', $otherDepartment->id)
        ->set('supervisorId', $otherSupervisor->id)
        ->set('userId', $otherUser->id)
        ->call('store')
        ->assertHasErrors(['departmentId', 'supervisorId', 'userId']);

    expect($otherUser->refresh()->employee_id)->toBeNull();
});

it('does not expose another tenant employee options to platform administrators', function (): void {
    $admin = createAdminUser();
    [, $otherCompany] = createTenantWithCompany(['name' => 'Hidden Employee Tenant']);
    $departmentType = DepartmentType::query()->create([
        'code' => 'hidden-cross-tenant',
        'name' => 'Hidden Cross Tenant',
        'category' => 'test',
        'is_active' => true,
    ]);
    $otherDepartment = Department::query()->create([
        'company_id' => $otherCompany->id,
        'department_type_id' => $departmentType->id,
        'status' => 'active',
    ]);
    $otherSupervisor = Employee::factory()->create(['company_id' => $otherCompany->id]);
    $otherUser = User::factory()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => null,
    ]);

    $component = Livewire::actingAs($admin)->test(Create::class);

    expect($component->viewData('companies')->pluck('id'))->not->toContain($otherCompany->id)
        ->and($component->viewData('departments')->pluck('id'))->not->toContain($otherDepartment->id)
        ->and($component->viewData('supervisors')->pluck('id'))->not->toContain($otherSupervisor->id)
        ->and($component->viewData('users')->pluck('id'))->not->toContain($otherUser->id);
});
