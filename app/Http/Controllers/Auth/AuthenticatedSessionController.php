<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login / logout, preserving the legacy `index.php` semantics:
 *   - authenticate by `member_name` + password,
 *   - inactive accounts are rejected with a fixed message (never logged in),
 *   - an expired password (set > 3 months ago) forces a change before login,
 *   - a successful login writes a PHI-free `audit_log` row.
 */
class AuthenticatedSessionController extends Controller
{
    public const PASSWORD_EXPIRED_SESSION_KEY = 'auth.password_expired_user_id';

    /** Pending-2FA identity (id + remember flag) parked between the password step and the challenge. */
    public const TWO_FACTOR_LOGIN_ID_KEY = 'auth.two_factor.user_id';

    public const TWO_FACTOR_LOGIN_REMEMBER_KEY = 'auth.two_factor.remember';

    /** Max failed login attempts (per member_name + IP) before a temporary lockout. */
    public const MAX_ATTEMPTS = 5;

    /**
     * A real bcrypt hash used ONLY to equalize response time when the member_name does not
     * exist — running a genuine bcrypt verify prevents a timing oracle that would otherwise
     * enumerate valid usernames (bcrypt runs for real accounts but would be skipped otherwise).
     */
    private const TIMING_EQUALIZER_HASH = '$2y$12$f9fafISpfP5YNgehi3bC1uWcLqEqAjrEn1vaaNbGpjXUMXjIKqByK';

    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'member_name' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Brute-force protection: lock out after MAX_ATTEMPTS failures per member_name + IP.
        $throttleKey = Str::transliterate(Str::lower($data['member_name']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'member_name' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        // Look up WITHOUT the active filter so we can distinguish "bad credentials" (generic
        // message, no enumeration) from "correct credentials but inactive" (activation message).
        $user = User::where('member_name', $data['member_name'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // Constant-time: run a real bcrypt verify even when the user is absent so response
            // time cannot be used to enumerate valid usernames.
            if (! $user) {
                Hash::check($data['password'], self::TIMING_EQUALIZER_HASH);
            }
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'member_name' => 'Invalid Login',
            ]);
        }

        if (! $user->active) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'member_name' => 'Need for activation, contact your manager',
            ]);
        }

        // Credentials are correct — clear the failure counter for this key.
        RateLimiter::clear($throttleKey);

        // Expired password: do NOT complete the login — hand off to the forced-change flow,
        // carrying only the user id in the session (identity was just proven above).
        if ($user->passwordExpired()) {
            $request->session()->put(self::PASSWORD_EXPIRED_SESSION_KEY, $user->id);

            return redirect()->route('password.change');
        }

        // Second factor (B4): a user with confirmed 2FA is NOT logged in here — park their
        // (already proven) identity and hand off to the TOTP challenge. Users without 2FA
        // continue to log in exactly as before.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(self::TWO_FACTOR_LOGIN_ID_KEY, $user->id);
            $request->session()->put(self::TWO_FACTOR_LOGIN_REMEMBER_KEY, $request->boolean('remember'));

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        AuditLog::record('login', 'member='.$user->id, $user->id, $request->ip());

        return redirect()->intended('/endorsement');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $userId = Auth::id();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($userId !== null) {
            AuditLog::record('logout', 'member='.$userId, $userId, $request->ip());
        }

        return redirect()->route('login');
    }
}
