<?php
use App\Base\Audit\Livewire\AuditLog\Actions as AuditActions;
use App\Base\Audit\Livewire\AuditLog\Mutations as AuditMutations;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('admin/audit/mutations', AuditMutations::class)
        ->middleware('authz:admin.audit.log.list')
        ->name('admin.audit.mutations');

    Route::get('admin/audit/actions', AuditActions::class)
        ->middleware('authz:admin.audit.log.list')
        ->name('admin.audit.actions');
});
