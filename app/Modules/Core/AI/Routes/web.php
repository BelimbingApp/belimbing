<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['auth'])->group(function () {
    // Lara setup
    Volt::route('admin/setup/lara', 'admin.setup.lara')
        ->name('admin.setup.lara');

    Volt::route('admin/ai/playground', 'ai.playground')
        ->name('admin.ai.playground');
    Volt::route('admin/ai/providers', 'ai.providers')
        ->name('admin.ai.providers');
});
