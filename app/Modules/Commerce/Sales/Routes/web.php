<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Commerce\Sales\Http\Controllers\SalesCsvExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('commerce/sales/export.csv', SalesCsvExportController::class)
        ->middleware('authz:commerce.marketplace.list')
        ->name('commerce.sales.export.csv');
});
