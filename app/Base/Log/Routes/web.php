<?php
use App\Base\Log\Livewire\Logs\Index;
use App\Base\Log\Livewire\Logs\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/logs', Index::class)
        ->name('admin.system.logs.index');
    Route::get('admin/system/logs/{filename}', Show::class)
        ->name('admin.system.logs.show')
        ->where('filename', '.+');
});
