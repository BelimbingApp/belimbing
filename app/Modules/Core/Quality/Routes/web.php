<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Quality\Livewire\Ncr\Create as NcrCreate;
use App\Modules\Core\Quality\Livewire\Ncr\Index as NcrIndex;
use App\Modules\Core\Quality\Livewire\Ncr\Show as NcrShow;
use App\Modules\Core\Quality\Livewire\Scar\Create as ScarCreate;
use App\Modules\Core\Quality\Livewire\Scar\Index as ScarIndex;
use App\Modules\Core\Quality\Livewire\Scar\Show as ScarShow;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('quality/ncr', NcrIndex::class)
        ->middleware('authz:quality.ncr.view')
        ->name('quality.ncr.index');

    Route::get('quality/ncr/create', NcrCreate::class)
        ->middleware('authz:quality.ncr.create')
        ->name('quality.ncr.create');

    Route::get('quality/ncr/{ncr}', NcrShow::class)
        ->middleware('authz:quality.ncr.view')
        ->name('quality.ncr.show');

    Route::get('quality/scar', ScarIndex::class)
        ->middleware('authz:quality.scar.view')
        ->name('quality.scar.index');

    Route::get('quality/scar/create', ScarCreate::class)
        ->middleware('authz:quality.scar.create')
        ->name('quality.scar.create');

    Route::get('quality/scar/{scar}', ScarShow::class)
        ->middleware('authz:quality.scar.view')
        ->name('quality.scar.show');
});
