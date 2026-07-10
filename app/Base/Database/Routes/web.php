<?php

use App\Base\Database\Http\Controllers\ReceiveBridgePackageController;
use App\Base\Database\Livewire\Backups\Index as BackupsIndex;
use App\Base\Database\Livewire\Bridge\Index as BridgeIndex;
use App\Base\Database\Livewire\Bridge\Settings as BridgeSettings;
use App\Base\Database\Livewire\DatabaseTables\Index as DatabaseTablesIndex;
use App\Base\Database\Livewire\DatabaseTables\Show as DatabaseTablesShow;
use App\Base\Database\Livewire\Queries\Index as QueriesIndex;
use App\Base\Database\Livewire\Queries\Show as QueriesShow;
use App\Base\Database\Livewire\Residue\Index as ResidueIndex;
use App\Base\Database\Livewire\SchemaIncubation\Index as SchemaIncubationIndex;
use Illuminate\Support\Facades\Route;

Route::post('data-bridge/receive/{grantId}', ReceiveBridgePackageController::class)
    ->where('grantId', '[0-9a-hjkmnp-tv-z]{26}')
    ->middleware('throttle:6,1')
    ->name('data-bridge.receive');

Route::middleware('auth')->group(function () {
    Route::get('admin/system/database', DatabaseTablesIndex::class)
        ->middleware('authz:admin.system.database-table.list')
        ->name('admin.system.database.index');
    Route::get('admin/system/database-tables', DatabaseTablesIndex::class)
        ->middleware('authz:admin.system.database-table.list')
        ->name('admin.system.database-tables.index');
    Route::get('admin/system/database-incubation', SchemaIncubationIndex::class)
        ->middleware('authz:admin.system.database-incubation.manage')
        ->name('admin.system.database-incubation.index');
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

    Route::get('admin/system/database-bridge', BridgeIndex::class)
        ->middleware('authz:admin.system.database-bridge.view')
        ->name('admin.system.database-bridge.index');
    Route::get('admin/system/database-bridge/settings', BridgeSettings::class)
        ->middleware('authz:admin.system.database-bridge-settings.manage')
        ->name('admin.system.database-bridge.settings');

    Route::get('admin/system/database-residue', ResidueIndex::class)
        ->middleware('authz:admin.system.database-residue.view')
        ->name('admin.system.database-residue.index');
});
