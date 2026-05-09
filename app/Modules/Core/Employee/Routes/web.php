<?php
use App\Modules\Core\Employee\Livewire\Employees\Create as EmployeesCreate;
use App\Modules\Core\Employee\Livewire\Employees\Index as EmployeesIndex;
use App\Modules\Core\Employee\Livewire\Employees\Show as EmployeesShow;
use App\Modules\Core\Employee\Livewire\EmployeeTypes\Create as EmployeeTypesCreate;
use App\Modules\Core\Employee\Livewire\EmployeeTypes\Edit as EmployeeTypesEdit;
use App\Modules\Core\Employee\Livewire\EmployeeTypes\Index as EmployeeTypesIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/employees', EmployeesIndex::class)->name('admin.employees.index');
    Route::get('admin/employees/create', EmployeesCreate::class)->name('admin.employees.create');
    Route::get('admin/employees/{employee}', EmployeesShow::class)->name('admin.employees.show');

    Route::get('admin/employee-types', EmployeeTypesIndex::class)
        ->middleware('authz:admin.employee-type.list')
        ->name('admin.employee-types.index');
    Route::get('admin/employee-types/create', EmployeeTypesCreate::class)
        ->middleware('authz:admin.employee-type.create')
        ->name('admin.employee-types.create');
    Route::get('admin/employee-types/{employeeType}/edit', EmployeeTypesEdit::class)
        ->middleware('authz:admin.employee-type.update')
        ->name('admin.employee-types.edit');
});
