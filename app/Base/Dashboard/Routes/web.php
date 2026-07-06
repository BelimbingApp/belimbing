<?php

use App\Base\Dashboard\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', Index::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
