<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\DateTime\Controllers\TimezoneController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('api/timezone/cycle', [TimezoneController::class, 'cycle'])
        ->name('timezone.cycle');
});
