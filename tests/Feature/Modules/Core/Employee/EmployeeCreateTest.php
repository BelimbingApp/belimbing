<?php

use App\Modules\Core\Employee\Livewire\Employees\Create;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
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
