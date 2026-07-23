<?php

use App\Base\DateTime\Controllers\TimezoneController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::post('api/timezone/set', [TimezoneController::class, 'set'])
        ->name('timezone.set');
});
