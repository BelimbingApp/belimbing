<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Media\Http\Controllers\MediaAssetController;
use Illuminate\Support\Facades\Route;

Route::get('media/assets/{asset}/stream', [MediaAssetController::class, 'stream'])
    ->middleware('signed')
    ->name('media.assets.stream');
