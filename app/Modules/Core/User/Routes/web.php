<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\User\Actions\Logout;
use App\Modules\Core\User\Controllers\Auth\ConfirmPasswordController;
use App\Modules\Core\User\Controllers\Auth\ForgotPasswordController;
use App\Modules\Core\User\Controllers\Auth\LoginController;
use App\Modules\Core\User\Controllers\Auth\RegisterController;
use App\Modules\Core\User\Controllers\Auth\ResetPasswordController;
use App\Modules\Core\User\Controllers\Auth\VerifyEmailController;
use App\Modules\Core\User\Controllers\Auth\VerifyEmailNoticeController;
use App\Modules\Core\User\Controllers\Settings\AppearanceController;
use App\Modules\Core\User\Controllers\Settings\DeleteAccountController;
use App\Modules\Core\User\Controllers\Settings\PasswordController;
use App\Modules\Core\User\Controllers\Settings\ProfileController;
use App\Modules\Core\User\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Auth routes (guest)
Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store']);

    Route::get('register', [RegisterController::class, 'show'])->name('register');
    Route::post('register', [RegisterController::class, 'store']);

    Route::get('forgot-password', [ForgotPasswordController::class, 'show'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [ResetPasswordController::class, 'show'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'store'])->name('password.update');
});

// Dashboard
Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Auth routes (authenticated)
Route::middleware('auth')->group(function (): void {
    Route::get('verify-email', [VerifyEmailNoticeController::class, 'show'])->name('verification.notice');
    Route::post('verify-email/notification', [VerifyEmailNoticeController::class, 'store'])->name('verification.send');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::get('confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmPasswordController::class, 'store']);

    // User admin
    Route::get('admin/users', [UserController::class, 'index'])
        ->middleware('authz:core.user.list')
        ->name('admin.users.index');
    Route::get('admin/users/search', [UserController::class, 'search'])
        ->middleware('authz:core.user.list')
        ->name('admin.users.index.search');
    Route::get('admin/users/create', [UserController::class, 'create'])
        ->middleware('authz:core.user.create')
        ->name('admin.users.create');
    Route::post('admin/users', [UserController::class, 'store'])
        ->middleware('authz:core.user.create')
        ->name('admin.users.store');
    Route::get('admin/users/{user}', [UserController::class, 'show'])
        ->middleware('authz:core.user.view')
        ->name('admin.users.show');
    Route::patch('admin/users/{user}/field', [UserController::class, 'updateField'])
        ->middleware('authz:core.user.update')
        ->name('admin.users.update-field');
    Route::patch('admin/users/{user}/company', [UserController::class, 'updateCompany'])
        ->middleware('authz:core.user.update')
        ->name('admin.users.update-company');
    Route::patch('admin/users/{user}/password', [UserController::class, 'updatePassword'])
        ->middleware('authz:core.user.update')
        ->name('admin.users.update-password');
    Route::post('admin/users/{user}/capabilities', [UserController::class, 'storeCapabilities'])
        ->middleware('authz:core.user.update')
        ->name('admin.users.capabilities.store');
    Route::delete('admin/users/{user}/capabilities/{principalCapability}', [UserController::class, 'destroyCapability'])
        ->middleware('authz:core.user.update')
        ->name('admin.users.capabilities.destroy');
    Route::post('admin/users/{user}/capabilities/deny', [UserController::class, 'denyCapability'])
        ->middleware('authz:core.user.update')
        ->name('admin.users.capabilities.deny');
    Route::delete('admin/users/{user}', [UserController::class, 'destroy'])
        ->middleware('authz:core.user.delete')
        ->name('admin.users.destroy');

    // User settings
    Route::redirect('settings', 'settings/profile');
    Route::get('settings/profile', [ProfileController::class, 'show'])->name('profile.edit');
    Route::post('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('settings/profile/resend-verification', [ProfileController::class, 'resendVerification'])->name('profile.resend-verification');
    Route::get('settings/password', [PasswordController::class, 'show'])->name('password.edit');
    Route::post('settings/password', [PasswordController::class, 'update'])->name('password.update.settings');
    Route::get('settings/appearance', [AppearanceController::class, 'show'])->name('appearance.edit');
    Route::delete('settings/account', [DeleteAccountController::class, 'destroy'])->name('account.destroy');
});

Route::post('logout', Logout::class)->name('logout');
