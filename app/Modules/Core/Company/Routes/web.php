<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Company\Controllers\CompanyController;
use App\Modules\Core\Company\Controllers\DepartmentController;
use App\Modules\Core\Company\Controllers\DepartmentTypeController;
use App\Modules\Core\Company\Controllers\LegalEntityTypeController;
use App\Modules\Core\Company\Controllers\RelationshipController;
use App\Modules\Core\Company\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/setup/licensee', [SetupController::class, 'licensee'])->name('admin.setup.licensee');
    Route::post('admin/setup/licensee', [SetupController::class, 'updateLicensee'])->name('admin.setup.licensee.update');

    Route::get('admin/companies', [CompanyController::class, 'index'])->name('admin.companies.index');
    Route::get('admin/companies/search', [CompanyController::class, 'search'])->name('admin.companies.index.search');
    Route::get('admin/companies/create', [CompanyController::class, 'create'])->name('admin.companies.create');
    Route::post('admin/companies', [CompanyController::class, 'store'])->name('admin.companies.store');
    Route::get('admin/companies/legal-entity-types', [LegalEntityTypeController::class, 'index'])->name('admin.companies.legal-entity-types');
    Route::post('admin/companies/legal-entity-types', [LegalEntityTypeController::class, 'store'])->name('admin.companies.legal-entity-types.store');
    Route::patch('admin/companies/legal-entity-types/{legalEntityType}', [LegalEntityTypeController::class, 'update'])->name('admin.companies.legal-entity-types.update');
    Route::delete('admin/companies/legal-entity-types/{legalEntityType}', [LegalEntityTypeController::class, 'destroy'])->name('admin.companies.legal-entity-types.destroy');
    Route::get('admin/companies/department-types', [DepartmentTypeController::class, 'index'])->name('admin.companies.department-types');
    Route::post('admin/companies/department-types', [DepartmentTypeController::class, 'store'])->name('admin.companies.department-types.store');
    Route::patch('admin/companies/department-types/{departmentType}', [DepartmentTypeController::class, 'update'])->name('admin.companies.department-types.update');
    Route::delete('admin/companies/department-types/{departmentType}', [DepartmentTypeController::class, 'destroy'])->name('admin.companies.department-types.destroy');
    Route::patch('admin/companies/{company}/field', [CompanyController::class, 'updateField'])->name('admin.companies.field');
    Route::get('admin/companies/{company}', [CompanyController::class, 'show'])->name('admin.companies.show');
    Route::delete('admin/companies/{company}', [CompanyController::class, 'destroy'])->name('admin.companies.destroy');

    Route::get('admin/companies/{company}/relationships', [RelationshipController::class, 'index'])->name('admin.companies.relationships');
    Route::post('admin/companies/{company}/relationships', [RelationshipController::class, 'store'])->name('admin.companies.relationships.store');
    Route::delete('admin/companies/{company}/relationships/{relationship}', [RelationshipController::class, 'destroy'])->name('admin.companies.relationships.destroy');

    Route::get('admin/companies/{company}/departments', [DepartmentController::class, 'index'])->name('admin.companies.departments');
    Route::post('admin/companies/{company}/departments', [DepartmentController::class, 'store'])->name('admin.companies.departments.store');
    Route::delete('admin/companies/{company}/departments/{department}', [DepartmentController::class, 'destroy'])->name('admin.companies.departments.destroy');
});
