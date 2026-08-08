<?php

use App\Base\Tenancy\Livewire\Admin\Tenants;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('admin/tenancy/tenants', Tenants::class)
        ->middleware('authz:admin.tenancy.tenant.list')
        ->name('admin.tenancy.tenants');
});
