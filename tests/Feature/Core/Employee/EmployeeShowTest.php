<?php

use App\Core\Company\Models\Company;
use App\Core\Employee\Livewire\Employees\Show;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Livewire\Livewire;

it('renders the employee detail page for admins', function (): void {
    $admin = createAdminUser();
    $employee = Employee::factory()->create([
        'company_id' => $admin->company_id,
        'employee_number' => 'EMP-SHOW-001',
        'full_name' => 'Nadia Employee Render',
        'short_name' => 'Nadia',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.employees.show', $employee))
        ->assertOk()
        ->assertSee('Nadia Employee Render');
});

it('links user accounts from the employee detail page through users.employee_id', function (): void {
    $admin = createAdminUser();
    $employee = Employee::factory()->create([
        'company_id' => $admin->company_id,
    ]);
    $user = User::factory()->create([
        'company_id' => $admin->company_id,
        'employee_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['employee' => $employee])
        ->call('saveUser', $user->id);

    expect($user->refresh()->employee_id)->toBe($employee->id);

    Livewire::actingAs($admin)
        ->test(Show::class, ['employee' => $employee])
        ->call('saveUser', null);

    expect($user->refresh()->employee_id)->toBeNull();
});
it('does not link a user account from another tenant on the employee detail page', function (): void {
    $tenantCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $tenantOwner = createTenantOwnerUser($tenantCompany->id);

    $employee = Employee::factory()->create([
        'company_id' => $tenantCompany->id,
    ]);
    $otherUser = User::factory()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => null,
    ]);

    Livewire::actingAs($tenantOwner)
        ->test(Show::class, ['employee' => $employee])
        ->call('saveUser', $otherUser->id);

    expect($otherUser->refresh()->employee_id)->toBeNull();
});

it('does not expose users from another tenant in the employee account picker', function (): void {
    $admin = createAdminUser();
    $employee = Employee::factory()->create(['company_id' => $admin->company_id]);
    $localUser = User::factory()->create([
        'company_id' => $admin->company_id,
        'name' => 'Visible Local Account',
        'employee_id' => null,
    ]);
    [, $foreignCompany] = createTenantWithCompany(['name' => 'Picker Foreign Tenant']);
    User::factory()->create([
        'company_id' => $foreignCompany->id,
        'name' => 'Hidden Foreign Account',
        'employee_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['employee' => $employee])
        ->assertSee($localUser->name)
        ->assertDontSee('Hidden Foreign Account');
});
