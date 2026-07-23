<?php

use App\Modules\Core\User\Actions\Logout;
use App\Modules\Core\User\Controllers\Auth\VerifyEmailController;
use App\Modules\Core\User\Controllers\PinController;
use App\Modules\Core\User\Controllers\ThemeController;
use App\Modules\Core\User\Livewire\Auth\ConfirmPassword;
use App\Modules\Core\User\Livewire\Auth\ForgotPassword;
use App\Modules\Core\User\Livewire\Auth\Login;
use App\Modules\Core\User\Livewire\Auth\ResetPassword;
use App\Modules\Core\User\Livewire\Auth\VerifyEmail;
use App\Modules\Core\User\Livewire\Settings\Appearance;
use App\Modules\Core\User\Livewire\Settings\Password;
use App\Modules\Core\User\Livewire\Settings\Profile;
use App\Modules\Core\User\Livewire\Users\Create;
use App\Modules\Core\User\Livewire\Users\Index;
use App\Modules\Core\User\Livewire\Users\Show;
use Illuminate\Support\Facades\Route;

// Auth routes (guest)
Route::middleware('guest')->group(function () {
    Route::get('login', Login::class)
        ->name('login');

    Route::get('forgot-password', ForgotPassword::class)
        ->name('password.request');

    Route::get('reset-password/{token}', ResetPassword::class)
        ->name('password.reset');
});

// Auth routes (authenticated)
Route::middleware('auth')->group(function () {
    Route::get('verify-email', VerifyEmail::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::get('confirm-password', ConfirmPassword::class)
        ->name('password.confirm');

    // User admin
    Route::get('admin/users', Index::class)
        ->middleware('authz:admin.user.list')
        ->name('admin.users.index');
    Route::get('admin/users/create', Create::class)
        ->middleware('authz:admin.user.create')
        ->name('admin.users.create');
    Route::get('admin/users/{user}', Show::class)
        ->middleware('authz:admin.user.view')
        ->name('admin.users.show');

    // User settings
    Route::get('settings/profile', Profile::class)->name('profile.edit');
    Route::get('settings/password', Password::class)->name('password.edit');
    Route::get('settings/appearance', Appearance::class)->name('appearance.edit');

    // Pinned items (JSON API for sidebar Alpine component)
    Route::post('api/pins/toggle', [PinController::class, 'toggle'])
        ->name('pins.toggle');
    Route::post('api/pins/reorder', [PinController::class, 'reorder'])
        ->name('pins.reorder');
    Route::post('api/theme', ThemeController::class)
        ->name('theme.set');
});

Route::post('logout', Logout::class)
    ->name('logout');
