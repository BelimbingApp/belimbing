<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Authz\Livewire\Capabilities\Index as CapabilitiesIndex;
use App\Base\Authz\Livewire\DecisionLogs\Index as DecisionLogsIndex;
use App\Base\Authz\Livewire\PrincipalCapabilities\Index as PrincipalCapabilitiesIndex;
use App\Base\Authz\Livewire\PrincipalRoles\Index as PrincipalRolesIndex;
use App\Base\Authz\Livewire\Roles\Create as RolesCreate;
use App\Base\Authz\Livewire\Roles\Index as RolesIndex;
use App\Base\Authz\Livewire\Roles\Show as RolesShow;
use App\Base\Authz\Services\ImpersonationManager;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
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

    // Role management
    Route::get('admin/roles', RolesIndex::class)
        ->middleware('authz:admin.authz.role.list')
        ->name('admin.roles.index');
    Route::get('admin/roles/create', RolesCreate::class)
        ->middleware('authz:admin.authz.role.create')
        ->name('admin.roles.create');
    Route::get('admin/roles/{role}', RolesShow::class)
        ->middleware('authz:admin.authz.role.view')
        ->name('admin.roles.show');

    // Authz administration
    Route::get('admin/authz/capabilities', CapabilitiesIndex::class)
        ->middleware('authz:admin.authz.capability.list')
        ->name('admin.authz.capabilities.index');
    Route::get('admin/authz/principal-roles', PrincipalRolesIndex::class)
        ->middleware('authz:admin.authz.principal-role.list')
        ->name('admin.authz.principal-roles.index');
    Route::get('admin/authz/principal-capabilities', PrincipalCapabilitiesIndex::class)
        ->middleware('authz:admin.authz.principal-capability.list')
        ->name('admin.authz.principal-capabilities.index');
    Route::get('admin/authz/decision-logs', DecisionLogsIndex::class)
        ->middleware('authz:admin.authz.decision-log.list')
        ->name('admin.authz.decision-logs.index');
});
