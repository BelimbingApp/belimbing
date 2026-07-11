<?php

use App\Base\Database\Http\Controllers\DownloadDataShareTransferOfferController;
use App\Base\Database\Livewire\Backups\Index as BackupsIndex;
use App\Base\Database\Livewire\DatabaseTables\Index as DatabaseTablesIndex;
use App\Base\Database\Livewire\DatabaseTables\Show as DatabaseTablesShow;
use App\Base\Database\Livewire\DataShare\Index as DataShareIndex;
use App\Base\Database\Livewire\DataShare\Settings as DataShareSettings;
use App\Base\Database\Livewire\Queries\Index as QueriesIndex;
use App\Base\Database\Livewire\Queries\Show as QueriesShow;
use App\Base\Database\Livewire\Residue\Index as ResidueIndex;
use App\Base\Database\Livewire\SchemaIncubation\Index as SchemaIncubationIndex;
use Illuminate\Support\Facades\Route;

Route::get('data-share/offers/{offerId}', DownloadDataShareTransferOfferController::class)
    ->where('offerId', '[0-9a-hjkmnp-tv-z]{26}')
    ->middleware('throttle:6,1')
    ->name('data-share.offers.show');

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

    Route::get('admin/system/data-share', DataShareIndex::class)
        ->middleware('authz:admin.system.data-share.view')
        ->name('admin.system.data-share.index');
    Route::get('admin/system/data-share/settings', DataShareSettings::class)
        ->middleware('authz:admin.system.data-share-settings.manage')
        ->name('admin.system.data-share.settings');

    Route::get('admin/system/database-residue', ResidueIndex::class)
        ->middleware('authz:admin.system.database-residue.view')
        ->name('admin.system.database-residue.index');
});
