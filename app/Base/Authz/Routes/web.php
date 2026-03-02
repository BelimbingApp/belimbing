<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Authz\Controllers\CapabilityController;
use App\Base\Authz\Controllers\DecisionLogController;
use App\Base\Authz\Controllers\PrincipalCapabilityController;
use App\Base\Authz\Controllers\PrincipalRoleController;
use App\Base\Authz\Controllers\RoleController;
use App\Base\Authz\Services\ImpersonationManager;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::post('admin/impersonate/leave', function (ImpersonationManager $manager) {
        $manager->stop();

        return redirect()->route('dashboard');
    })->name('admin.impersonate.stop');

    Route::post('admin/impersonate/{user}', function (User $user, ImpersonationManager $manager) {
        $manager->start(auth()->user(), $user);

        return redirect()->route('dashboard');
    })
        ->middleware('authz:admin.user.impersonate')
        ->name('admin.impersonate.start');

    Route::get('admin/roles', [RoleController::class, 'index'])
        ->middleware('authz:admin.role.list')
        ->name('admin.roles.index');
    Route::get('admin/roles/search', [RoleController::class, 'search'])
        ->middleware('authz:admin.role.list')
        ->name('admin.roles.index.search');
    Route::get('admin/roles/create', [RoleController::class, 'create'])
        ->middleware('authz:admin.role.create')
        ->name('admin.roles.create');
    Route::post('admin/roles', [RoleController::class, 'store'])
        ->middleware('authz:admin.role.create')
        ->name('admin.roles.store');
    Route::get('admin/roles/{role}', [RoleController::class, 'show'])
        ->middleware('authz:admin.role.view')
        ->name('admin.roles.show');
    Route::patch('admin/roles/{role}', [RoleController::class, 'update'])
        ->middleware('authz:admin.role.update')
        ->name('admin.roles.update');
    Route::delete('admin/roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('authz:admin.role.delete')
        ->name('admin.roles.destroy');
    Route::post('admin/roles/{role}/capabilities', [RoleController::class, 'assignCapabilities'])
        ->middleware('authz:admin.role.update')
        ->name('admin.roles.capabilities.store');
    Route::delete('admin/roles/{role}/capabilities/{roleCapability}', [RoleController::class, 'removeCapability'])
        ->middleware('authz:admin.role.update')
        ->name('admin.roles.capabilities.destroy');
    Route::post('admin/roles/{role}/users', [RoleController::class, 'assignUsers'])
        ->middleware('authz:admin.role.update')
        ->name('admin.roles.users.store');
    Route::delete('admin/roles/{role}/users/{principalRole}', [RoleController::class, 'removeUser'])
        ->middleware('authz:admin.role.update')
        ->name('admin.roles.users.destroy');

    Route::get('admin/authz/capabilities', [CapabilityController::class, 'index'])
        ->middleware('authz:admin.capability.list')
        ->name('admin.authz.capabilities.index');
    Route::get('admin/authz/capabilities/search', [CapabilityController::class, 'search'])
        ->middleware('authz:admin.capability.list')
        ->name('admin.authz.capabilities.index.search');

    Route::get('admin/authz/principal-roles', [PrincipalRoleController::class, 'index'])
        ->middleware('authz:admin.principal_role.list')
        ->name('admin.authz.principal-roles.index');
    Route::get('admin/authz/principal-roles/search', [PrincipalRoleController::class, 'search'])
        ->middleware('authz:admin.principal_role.list')
        ->name('admin.authz.principal-roles.index.search');

    Route::get('admin/authz/principal-capabilities', [PrincipalCapabilityController::class, 'index'])
        ->middleware('authz:admin.principal_capability.list')
        ->name('admin.authz.principal-capabilities.index');
    Route::get('admin/authz/principal-capabilities/search', [PrincipalCapabilityController::class, 'search'])
        ->middleware('authz:admin.principal_capability.list')
        ->name('admin.authz.principal-capabilities.index.search');

    Route::get('admin/authz/decision-logs', [DecisionLogController::class, 'index'])
        ->middleware('authz:admin.decision_log.list')
        ->name('admin.authz.decision-logs.index');
    Route::get('admin/authz/decision-logs/search', [DecisionLogController::class, 'search'])
        ->middleware('authz:admin.decision_log.list')
        ->name('admin.authz.decision-logs.index.search');
});
