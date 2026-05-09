<?php
use App\Modules\Core\Company\Livewire\Companies\Create;
use App\Modules\Core\Company\Livewire\Companies\Departments;
use App\Modules\Core\Company\Livewire\Companies\DepartmentTypes;
use App\Modules\Core\Company\Livewire\Companies\Index;
use App\Modules\Core\Company\Livewire\Companies\LegalEntityTypes;
use App\Modules\Core\Company\Livewire\Companies\Relationships;
use App\Modules\Core\Company\Livewire\Companies\Show;
use App\Modules\Core\Company\Livewire\Setup\Licensee;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // Setup
    Route::get('admin/setup/licensee', Licensee::class)->name('admin.setup.licensee');

    Route::get('admin/companies', Index::class)->name('admin.companies.index');
    Route::get('admin/companies/create', Create::class)->name('admin.companies.create');
    Route::get('admin/companies/legal-entity-types', LegalEntityTypes::class)->name('admin.companies.legal-entity-types');
    Route::get('admin/companies/department-types', DepartmentTypes::class)->name('admin.companies.department-types');
    Route::get('admin/companies/{company}', Show::class)->name('admin.companies.show');
    Route::get('admin/companies/{company}/relationships', Relationships::class)->name('admin.companies.relationships');
    Route::get('admin/companies/{company}/departments', Departments::class)->name('admin.companies.departments');
});
