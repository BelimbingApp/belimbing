<?php

use App\Base\Pdf\Http\Controllers\SignedRenderController;
use App\Base\Pdf\Http\Middleware\VerifyRenderToken;
use Illuminate\Support\Facades\Route;

Route::get('pdf/render/{token}', [SignedRenderController::class, 'show'])
    ->middleware(['signed', VerifyRenderToken::class])
    ->name('blb.pdf.render');
