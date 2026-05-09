<?php
use App\Base\Media\Http\Controllers\MediaAssetController;
use Illuminate\Support\Facades\Route;

Route::get('media/assets/{asset}/stream', [MediaAssetController::class, 'stream'])
    ->middleware('signed')
    ->name('media.assets.stream');
