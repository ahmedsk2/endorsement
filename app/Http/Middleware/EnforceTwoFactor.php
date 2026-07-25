<?php

namespace App\Http\Middleware;

use App\Support\AccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Privileged accounts must carry a second factor.
 *
 * An administrator, a Chief Resident, or anyone who can reopen a signed attestation or
 * read the compliance page holds powers whose misuse is hard to undo — a password alone
 * is not an adequate control for them (NCA ECC-1 2-2, HIPAA §164.312(d), PDPL Art. 19).
 *
 * Enforced in PRODUCTION by default and configurable everywhere (`endorsement.require_2fa`),
 * so local development and the test suite are not gated on enrolling TOTP. The user is
 * redirected to their profile to choose a method rather than being locked out.
 */
class EnforceTwoFactor
{
    /** Capabilities that make an account privileged enough to require a second factor. */
    private const PRIVILEGED = [
        'access.manage',
        'users.manage',
        'users.manage_residents',
        'settings.manage',
        'endorsement.reopen',
        'endorsement.compliance',
    ];

    /** Paths a gated user must still reach, or the redirect becomes a loop. */
    private const ALLOWED = [
        'profile*',
        'user/two-factor*',
        'logout',
        'signatures/*',
        'email-code*',
        'two-factor-challenge*',
        'change-password',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->enabled()) {
            return $next($request);
        }

        if ($user->activeTwoFactorMethod() !== null || $request->is(self::ALLOWED)) {
            return $next($request);
        }

        $privileged = false;
        foreach (self::PRIVILEGED as $capability) {
            if (AccessControl::allows($user, $capability)) {
                $privileged = true;
                break;
            }
        }

        if (! $privileged) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'This account must set up two-step sign-in before continuing.');
        }

        return redirect()->route('profile.edit')->with(
            'error',
            'Your account has administrative powers, so it needs two-step sign-in. Choose a method below to continue.',
        );
    }

    private function enabled(): bool
    {
        return (bool) config('endorsement.require_2fa', app()->environment('production'));
    }
}
