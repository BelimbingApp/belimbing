<?php

use App\Base\Media\Http\Controllers\MediaAssetController;
use App\Base\Media\Http\Controllers\MediaAttachmentController;
use Illuminate\Support\Facades\Route;

Route::get('media/assets/{asset}/stream', [MediaAssetController::class, 'stream'])
    ->middleware('signed')
    ->name('media.assets.stream');

Route::get('media/attachments/{attachment}', MediaAttachmentController::class)
    ->middleware('auth')
    ->whereUlid('attachment')
    ->name('media.attachments.download');
