<?php

use App\Base\Session\Livewire\Sessions\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('admin/system/sessions', Index::class)
        ->middleware('authz:admin.system.session.list')
        ->name('admin.system.sessions.index');
});
