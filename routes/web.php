<?php

use App\Base\Foundation\Services\LandingPageResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function (LandingPageResolver $landing) {
    return Auth::check()
        ? redirect()->to($landing->urlFor(Auth::user()))
        : redirect()->route('login');
})->name('home');
