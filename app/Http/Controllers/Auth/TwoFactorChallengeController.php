<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

/**
 * B4 — the login-time TOTP challenge. Reached only mid-login: the password step
 * (AuthenticatedSessionController) proved identity, parked the user id in the session, and
 * redirected here WITHOUT calling Auth::login. A valid TOTP code OR a single-use recovery
 * code completes the login (Auth::login + session regenerate + the same `login` audit row).
 */
class TwoFactorChallengeController extends Controller
{
    /** TOTP drift tolerance (± time-steps). */
    private const VERIFY_WINDOW = 1;

    /** Guess budget for the parked challenge before it is discarded back to the password step. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Persistent per-user failed-2FA lockout (keyed on the user id, NOT the session), so an
     * attacker who knows the password cannot reset the guess budget by looping login -> guesses
     * -> login. Higher than the session budget so single-session behaviour is unchanged.
     */
    private const MAX_USER_ATTEMPTS = 10;

    /** Lockout decay window for the per-user 2FA limiter (seconds). */
    private const LOCK_DECAY_SECONDS = 900;

    /** Session key holding this pending challenge's attempt counter. */
    private const ATTEMPTS_KEY = 'auth.two_factor.attempts';

    private function google2fa(): Google2FA
    {
        return new Google2FA;
    }

    /** Render the challenge screen — only if a login is actually pending. */
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /** Verify a TOTP or recovery code and complete the login. */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'string']]);

        // Persistent per-user lockout (survives a fresh password re-login, unlike the session
        // budget below) — the durable cap on TOTP brute-forcing.
        $lockKey = 'two-factor-challenge:'.$user->id;
        if (RateLimiter::tooManyAttempts($lockKey, self::MAX_USER_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($lockKey);
            $this->forgetPending($request);

            throw ValidationException::withMessages([
                'code' => "Too many incorrect codes. Please try again in {$seconds} seconds.",
            ]);
        }

        // Session guess budget: too many wrong codes in one pending session returns to login.
        $attempts = (int) $request->session()->increment(self::ATTEMPTS_KEY);
        if ($attempts > self::MAX_ATTEMPTS) {
            $this->forgetPending($request);

            throw ValidationException::withMessages([
                'code' => 'Too many incorrect codes. Please sign in again.',
            ]);
        }

        $code = $this->normalizeCode($request->input('code'));

        // Replay protection: a valid TOTP stays valid for the whole ~90s window, so remember the
        // last accepted code per user (fingerprinted, short TTL) and reject an exact re-use.
        $usedKey = 'two-factor-used:'.$user->id;
        $fingerprint = hash('sha256', $code);
        $totpMatches = $this->google2fa()->verifyKey($user->two_factor_secret, $code, self::VERIFY_WINDOW) !== false;
        $totpValid = $totpMatches && Cache::get($usedKey) !== $fingerprint;

        if (! $totpValid && ! $this->consumeRecoveryCode($user, $request->input('code'))) {
            RateLimiter::hit($lockKey, self::LOCK_DECAY_SECONDS);
            AuditLog::record('2fa_failed', 'member='.$user->id, $user->id, $request->ip());

            throw ValidationException::withMessages([
                'code' => 'Invalid authentication code.',
            ]);
        }

        if ($totpValid) {
            Cache::put($usedKey, $fingerprint, now()->addSeconds(120));
        }

        RateLimiter::clear($lockKey);

        $remember = (bool) $request->session()->get(AuthenticatedSessionController::TWO_FACTOR_LOGIN_REMEMBER_KEY, false);

        $this->forgetPending($request);

        // "Don't ask on this device for a week", offered only once the code has been proven.
        if ($request->boolean('trust_device')) {
            \App\Support\TrustedDevice::remember($user, $request->userAgent());
            AuditLog::record('trusted_device_added', 'member='.$user->id, $user->id, $request->ip());
        }

        \App\Support\Login::complete($user, $remember);
        $request->session()->regenerate();

        AuditLog::record('login', 'member='.$user->id, $user->id, $request->ip());

        return redirect()->intended('/endorsement');
    }

    /** Resolve the pending (not-yet-authenticated) user from the session, fail-closed. */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(AuthenticatedSessionController::TWO_FACTOR_LOGIN_ID_KEY);
        if (! $id) {
            return null;
        }

        $user = User::find($id);

        // Only an active, still-2FA-enabled user may complete the challenge.
        return ($user && $user->active && $user->hasTwoFactorEnabled()) ? $user : null;
    }

    /**
     * Redeem a single-use recovery code: on match, remove it from the stored set and persist.
     * Comparison is case-insensitive (codes are stored/displayed upper-case).
     */
    private function consumeRecoveryCode(User $user, string $input): bool
    {
        $normalized = strtoupper(trim($input));
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $code) {
            if (hash_equals(strtoupper($code), $normalized)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function forgetPending(Request $request): void
    {
        $request->session()->forget([
            AuthenticatedSessionController::TWO_FACTOR_LOGIN_ID_KEY,
            AuthenticatedSessionController::TWO_FACTOR_LOGIN_REMEMBER_KEY,
            self::ATTEMPTS_KEY,
        ]);
    }

    private function normalizeCode(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? $code;
    }
}
