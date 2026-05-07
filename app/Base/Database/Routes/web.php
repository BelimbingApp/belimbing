<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Database\Livewire\Backups\Index as BackupsIndex;
use App\Base\Database\Livewire\DatabaseTables\Index as DatabaseTablesIndex;
use App\Base\Database\Livewire\DatabaseTables\Show as DatabaseTablesShow;
use App\Base\Database\Livewire\Queries\Index as QueriesIndex;
use App\Base\Database\Livewire\Queries\Show as QueriesShow;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('admin/system/database-tables', DatabaseTablesIndex::class)
        ->middleware('authz:admin.system.database-table.list')
        ->name('admin.system.database-tables.index');
    Route::get('admin/system/database-tables/{tableName}', DatabaseTablesShow::class)
        ->middleware('authz:admin.system.database-table.view')
        ->name('admin.system.database-tables.show');

    Route::get('admin/system/database-queries', QueriesIndex::class)
        ->middleware('authz:admin.system.database-table.list')
        ->name('admin.system.database-queries.index');
    Route::get('admin/system/database-queries/{slug}', QueriesShow::class)
        ->middleware('authz:admin.system.database-table.list')
        ->name('admin.system.database-queries.show');

    Route::get('admin/system/database-backups', BackupsIndex::class)
        ->middleware('authz:admin.system.database-backup.list')
        ->name('admin.system.database-backups.index');
});
