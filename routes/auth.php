<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailOtpChallengeController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\InvitationAcceptController;
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

/*
 * REGISTRATION IS BY INVITATION ONLY (2026-07-27, owner decision).
 *
 * `/register` was an unauthenticated endpoint on the public internet that wrote to the
 * database and sent mail on a stranger's say-so. The approval step behind it was a real
 * control — and it is the one docs/COMPLIANCE.md leans on to justify every account seeing
 * all four units — so the answer was to make it stronger, not to keep guarding a door that
 * did not need to be open.
 *
 * The route is KEPT rather than deleted, redirecting to the sign-in page with an
 * explanation. A 404 here would read as "the system is broken" to a nurse who bookmarked
 * it, and the link is still printed in older documentation.
 */
Route::get('/register', [RegisteredUserController::class, 'closed'])->name('register');

Route::get('/invitation/{token}', [InvitationAcceptController::class, 'show'])
    ->name('invitation.show')->middleware('throttle:20,1');
Route::post('/invitation/{token}', [InvitationAcceptController::class, 'store'])
    ->middleware('throttle:10,1');

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

/*
 * Email confirmation. Both links are SIGNED URLs (HMAC + expiry, keyed by APP_KEY), so
 * nothing is stored and a tampered or stale link is rejected by the framework itself.
 * Confirming is not a login — a registration still needs an administrator's approval.
 */
Route::get('/registration/verify/{pending}/{hash}', [EmailVerificationController::class, 'verifyRegistration'])
    ->middleware(['signed', 'throttle:12,1'])
    ->name('registration.verify');

Route::get('/profile/email/verify/{user}/{hash}', [EmailVerificationController::class, 'verifyAccount'])
    ->middleware(['auth', 'signed', 'throttle:12,1'])
    ->name('profile.email.verify');

Route::post('/profile/email/verify', [EmailVerificationController::class, 'sendAccountLink'])
    ->middleware(['auth', 'throttle:4,1'])
    ->name('profile.email.send');

/*
 * The e-mail second factor challenge — the sibling of the TOTP challenge for users who
 * chose a code by email. Identity lives in the session, never in the request.
 */
Route::get('/email-code', [EmailOtpChallengeController::class, 'create'])->name('email-otp.login');
Route::post('/email-code', [EmailOtpChallengeController::class, 'store'])->middleware('throttle:10,1');
Route::post('/email-code/resend', [EmailOtpChallengeController::class, 'resend'])
    ->middleware('throttle:4,1')->name('email-otp.resend');
