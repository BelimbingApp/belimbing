<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Commerce\Marketplace\Ebay\Http\Controllers\EbayOAuthCallbackController;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Index;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('commerce/marketplace/ebay', Index::class)
        ->middleware('authz:commerce.marketplace.list')
        ->name('commerce.marketplace.ebay.index');

    Route::get('commerce/marketplace/ebay/settings', Settings::class)
        ->middleware('authz:commerce.marketplace.manage')
        ->name('commerce.marketplace.ebay.settings');

    Route::get('commerce/marketplace/ebay/oauth/callback', EbayOAuthCallbackController::class)
        ->middleware('authz:commerce.marketplace.manage')
        ->name('commerce.marketplace.ebay.oauth.callback');
});
