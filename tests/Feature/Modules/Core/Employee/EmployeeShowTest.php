<?php

use App\Modules\Core\Employee\Livewire\Employees\Show;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
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
