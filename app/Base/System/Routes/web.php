<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\System\Enums\UiReferenceSection;
use App\Base\System\Http\Controllers\TestTransportStreamController;
use App\Base\System\Livewire\Info\Index;
use App\Base\System\Livewire\Localization\Index as LocalizationIndex;
use App\Base\System\Livewire\MenuInspector\Index as MenuInspectorIndex;
use App\Base\System\Livewire\TestTransport\Index as TestTransportIndex;
use App\Base\System\Livewire\UiReference\Index as UiReferenceIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/info', Index::class)
        ->name('admin.system.info.index');

    Route::get('admin/system/localization', LocalizationIndex::class)
        ->middleware('authz:admin.system.localization.manage')
        ->name('admin.system.localization.index');

    Route::get('admin/system/test-transport', TestTransportIndex::class)
        ->middleware('authz:admin.system.test-transport.view')
        ->name('admin.system.test-transport.index');

    Route::get('admin/system/test-transport/stream', TestTransportStreamController::class)
        ->middleware('authz:admin.system.test-transport.view')
        ->name('admin.system.test-transport.stream');

    Route::get('admin/system/ui-reference', UiReferenceIndex::class)
        ->middleware('authz:admin.system.ui-reference.view')
        ->name('admin.system.ui-reference.index');

    Route::get('admin/system/ui-reference/{section}', UiReferenceIndex::class)
        ->middleware('authz:admin.system.ui-reference.view')
        ->whereIn('section', UiReferenceSection::slugs())
        ->name('admin.system.ui-reference.show');

    Route::get('admin/system/menu-inspector', MenuInspectorIndex::class)
        ->middleware('authz:admin.system.menu-inspector.view')
        ->name('admin.system.menu-inspector.index');
});
