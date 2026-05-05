<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Integration\Livewire\OutboundExchanges\Index;
use App\Base\Integration\Livewire\OutboundExchanges\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('admin/integration/outbound-exchanges', Index::class)
        ->middleware('authz:admin.integration_exchange.list')
        ->name('admin.integration.outbound-exchanges.index');

    Route::get('admin/integration/outbound-exchanges/{exchange}', Show::class)
        ->middleware('authz:admin.integration_exchange.list')
        ->name('admin.integration.outbound-exchanges.show');
});
