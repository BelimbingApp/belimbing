<?php
use App\Core\Company\Livewire\Companies\Create;
use App\Core\Company\Livewire\Companies\Departments;
use App\Core\Company\Livewire\Companies\DepartmentTypes;
use App\Core\Company\Livewire\Companies\Index;
use App\Core\Company\Livewire\Companies\LegalEntityTypes;
use App\Core\Company\Livewire\Companies\Relationships;
use App\Core\Company\Livewire\Companies\Show;
use App\Core\Company\Livewire\Setup\Licensee;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // Setup
    Route::get('admin/setup/licensee', Licensee::class)->name('admin.setup.licensee');

    Route::get('admin/companies', Index::class)
        ->middleware('authz:admin.company.list')
        ->name('admin.companies.index');
    Route::get('admin/companies/create', Create::class)
        ->middleware('authz:admin.company.create')
        ->name('admin.companies.create');
    Route::get('admin/companies/legal-entity-types', LegalEntityTypes::class)
        ->middleware('authz:admin.company.list')
        ->name('admin.companies.legal-entity-types');
    Route::get('admin/companies/department-types', DepartmentTypes::class)
        ->middleware('authz:admin.company.list')
        ->name('admin.companies.department-types');
    Route::get('admin/companies/{company}', Show::class)
        ->middleware('authz:admin.company.view')
        ->name('admin.companies.show');
    Route::get('admin/companies/{company}/relationships', Relationships::class)
        ->middleware('authz:admin.company.view')
        ->name('admin.companies.relationships');
    Route::get('admin/companies/{company}/departments', Departments::class)
        ->middleware('authz:admin.company.view')
        ->name('admin.companies.departments');
});
