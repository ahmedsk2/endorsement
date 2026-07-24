<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorAuthenticationController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

/*
 * Authentication (B3) — login/logout, public self-registration (-> pending_registrations),
 * password reset, and the forced password-change flow for expired passwords.
 */

Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
// Login has its own per-account RateLimiter (member_name + IP lockout); no route throttle
// so the controller can return the precise "too many attempts" message.
Route::post('/login', [AuthenticatedSessionController::class, 'store']);
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');

Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('throttle:6,1')->name('password.email');

Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('throttle:6,1')->name('password.store');

// Forced change for an expired password (unauthenticated; identity carried in the session).
Route::get('/change-password', [ChangePasswordController::class, 'create'])->name('password.change');
Route::post('/change-password', [ChangePasswordController::class, 'store'])
    ->middleware('throttle:6,1')->name('password.change.store');

/*
 * MFA (B4). Enrollment/management lives behind `auth` (the user's own profile). The login
 * challenge is reachable mid-login (unauthenticated — identity is parked in the session by
 * the password step) and is throttled to blunt code brute-forcing.
 */
Route::middleware('auth')->group(function () {
    Route::get('/user/two-factor', [TwoFactorAuthenticationController::class, 'show'])->name('two-factor.show');
    Route::post('/user/two-factor', [TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
    Route::post('/user/two-factor/confirm', [TwoFactorAuthenticationController::class, 'confirm'])->name('two-factor.confirm');
    Route::delete('/user/two-factor', [TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
});

Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware('throttle:10,1')->name('two-factor.login.store');
