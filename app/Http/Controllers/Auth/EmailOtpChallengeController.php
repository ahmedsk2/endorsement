<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\EmailOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The e-mail second factor challenge — the sibling of TwoFactorChallengeController for
 * users who chose 'email' instead of an authenticator app.
 *
 * Same shape as the TOTP challenge, and the same defences: the identity is parked in the
 * SESSION (never in a URL or a form field a client could edit), the session's own attempt
 * budget is finite, and a persistent per-user rate limiter survives cookie-clearing.
 */
class EmailOtpChallengeController extends Controller
{
    public const SESSION_USER = 'auth.email_otp.user_id';

    public const SESSION_REMEMBER = 'auth.email_otp.remember';

    private const SESSION_BUDGET = 5;

    private const LIMIT_ATTEMPTS = 10;

    private const LIMIT_DECAY = 900;

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $this->challengedUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/EmailOtpChallenge', [
            // Enough to reassure the user WHICH inbox to check, without printing the
            // address in full to whoever is looking at the screen.
            'hint' => $this->maskEmail((string) $user->member_email),
            'lifetimeMinutes' => EmailOtp::LIFETIME_MINUTES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->challengedUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $limiterKey = 'email-otp:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, self::LIMIT_ATTEMPTS)) {
            $this->forget($request);

            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Sign in again in a few minutes.',
            ]);
        }

        RateLimiter::hit($limiterKey, self::LIMIT_DECAY);

        if (! EmailOtp::verify($user, $data['code'])) {
            $left = $request->session()->decrement('auth.email_otp.tries');

            AuditLog::record('two_factor_email_failed', 'user='.$user->getKey(), null, $request->ip());

            if ($left <= 0) {
                $this->forget($request);

                throw ValidationException::withMessages([
                    'code' => 'Too many incorrect codes. Sign in again.',
                ]);
            }

            throw ValidationException::withMessages([
                'code' => 'That code is not valid or has expired.',
            ]);
        }

        RateLimiter::clear($limiterKey);

        $remember = (bool) $request->session()->get(self::SESSION_REMEMBER, false);
        $this->forget($request);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        AuditLog::record('login_two_factor_email', 'user='.$user->getKey(), $user->getKey(), $request->ip());

        return redirect()->intended('/endorsement');
    }

    /** Re-send the code (rate limited separately from the guessing limiter). */
    public function resend(Request $request): RedirectResponse
    {
        $user = $this->challengedUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $key = 'email-otp-resend:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'code' => 'A code was just sent. Wait a minute before asking for another.',
            ]);
        }

        RateLimiter::hit($key, 300);
        EmailOtp::issue($user);

        return back()->with('status', 'A new code is on its way.');
    }

    /** Start (or restart) the challenge for a user who has passed the password stage. */
    public static function begin(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::SESSION_USER, $user->getKey());
        $request->session()->put(self::SESSION_REMEMBER, $remember);
        $request->session()->put('auth.email_otp.tries', self::SESSION_BUDGET);

        EmailOtp::issue($user);
    }

    private function challengedUser(Request $request): ?User
    {
        $id = $request->session()->get(self::SESSION_USER);

        if ($id === null) {
            return null;
        }

        $user = User::find($id);

        // An account deactivated between the password and the code must not complete login.
        return ($user !== null && $user->active) ? $user : null;
    }

    private function forget(Request $request): void
    {
        $request->session()->forget([self::SESSION_USER, self::SESSION_REMEMBER, 'auth.email_otp.tries']);
    }

    /** j***@example.org — enough to identify the inbox, not enough to harvest it. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($local === '' || $domain === '') {
            return 'your email address';
        }

        return mb_substr($local, 0, 1).str_repeat('*', max(mb_strlen($local) - 1, 1)).'@'.$domain;
    }
}
