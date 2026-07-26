<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The one place a session actually begins — password-only, after TOTP, or after an email
 * code all end up here.
 */
final class Login
{
    /**
     * How long "Remember me" lasts.
     *
     * Laravel's default recaller cookie runs to about 400 days. The session idle lifetime
     * is 60 minutes precisely because these are SHARED WARD WORKSTATIONS — and the recaller
     * quietly defeats that, silently re-authenticating whoever sits down next, for the best
     * part of a year. Twelve hours covers a long shift and expires before the next one.
     */
    public const REMEMBER_MINUTES = 60 * 12;

    public static function complete(User $user, bool $remember): void
    {
        $guard = Auth::guard('web');

        if (method_exists($guard, 'setRememberDuration')) {
            $guard->setRememberDuration(self::REMEMBER_MINUTES);
        }

        Auth::login($user, $remember);

        // saveQuietly: a sign-in is not a change to the clinical record and must not fire
        // model events or bump updated_at on the account.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
