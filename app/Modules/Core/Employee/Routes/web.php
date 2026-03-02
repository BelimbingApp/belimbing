<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Employee\Controllers\EmployeeController;
use App\Modules\Core\Employee\Controllers\EmployeeTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/employees', [EmployeeController::class, 'index'])
        ->name('admin.employees.index');
    Route::get('admin/employees/search', [EmployeeController::class, 'search'])
        ->name('admin.employees.index.search');
    Route::get('admin/employees/create', [EmployeeController::class, 'create'])
        ->name('admin.employees.create');
    Route::post('admin/employees', [EmployeeController::class, 'store'])
        ->name('admin.employees.store');
    Route::get('admin/employees/{employee}', [EmployeeController::class, 'show'])
        ->name('admin.employees.show');
    Route::patch('admin/employees/{employee}/field', [EmployeeController::class, 'updateField'])
        ->name('admin.employees.update-field');
    Route::delete('admin/employees/{employee}', [EmployeeController::class, 'destroy'])
        ->name('admin.employees.destroy');
    Route::post('admin/employees/{employee}/addresses', [EmployeeController::class, 'attachAddress'])
        ->name('admin.employees.addresses.attach');
    Route::delete('admin/employees/{employee}/addresses/{address}', [EmployeeController::class, 'unlinkAddress'])
        ->name('admin.employees.addresses.unlink');

    Route::get('admin/employee-types', [EmployeeTypeController::class, 'index'])
        ->middleware('authz:core.employee_type.list')
        ->name('admin.employee-types.index');
    Route::get('admin/employee-types/create', [EmployeeTypeController::class, 'create'])
        ->middleware('authz:core.employee_type.create')
        ->name('admin.employee-types.create');
    Route::post('admin/employee-types', [EmployeeTypeController::class, 'store'])
        ->middleware('authz:core.employee_type.create')
        ->name('admin.employee-types.store');
    Route::get('admin/employee-types/{employeeType}/edit', [EmployeeTypeController::class, 'edit'])
        ->middleware('authz:core.employee_type.update')
        ->name('admin.employee-types.edit');
    Route::patch('admin/employee-types/{employeeType}', [EmployeeTypeController::class, 'update'])
        ->middleware('authz:core.employee_type.update')
        ->name('admin.employee-types.update');
    Route::delete('admin/employee-types/{employeeType}', [EmployeeTypeController::class, 'destroy'])
        ->middleware('authz:core.employee_type.delete')
        ->name('admin.employee-types.destroy');
});
