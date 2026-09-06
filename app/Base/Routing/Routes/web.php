<?php

use App\Base\Routing\Livewire\TenantAudit\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/system/tenant-audit', Index::class)
        ->middleware('authz:admin.system.audit.view')
        ->name('admin.system.tenant-audit.index');
});
