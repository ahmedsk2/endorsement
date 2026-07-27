<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A new account finishes setting itself up before it reaches the wards.
 *
 * Two things this system must not guess for a clinician: how they prove who they are at
 * sign-in, and whether they want to be reminded about a handover. Both were previously
 * optional settings buried on a profile page, so the realistic outcome was that neither was
 * ever chosen — an account with no second factor and no reminders, indefinitely.
 *
 * Asking once, at the only moment the person is definitely paying attention, is the whole
 * idea. It is not a security control on its own — EnforceTwoFactor is that, for privileged
 * accounts — it is the difference between a default nobody chose and a decision somebody made.
 */
class RequireSetup
{
    /**
     * Paths the unfinished account must still reach, or the redirect is a trap.
     *
     * This list is load-bearing. Everything the setup page itself needs is here — enrolling
     * an authenticator, choosing the method, confirming an email, saving reminders,
     * subscribing to push — because those requests come FROM the page being redirected to.
     * `logout` is here so nobody can be locked into their own onboarding, and `up` because
     * the container healthcheck must never depend on any user's state.
     *
     * Deliberately NOT here: `profile*` as a whole. Exempting it would let the setup step
     * be walked around by typing a URL, which makes this advisory rather than a step.
     */
    private const ALLOWED = [
        'setup*',
        'user/two-factor*',
        'profile/two-factor-method',
        'profile/email/verify',
        'profile/reminders',
        'push/subscriptions*',
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

        if ($user === null || $user->setup_completed_at !== null || $request->is(self::ALLOWED)) {
            return $next($request);
        }

        // A half-finished XHR should not be answered with a redirect to an HTML page.
        if ($request->expectsJson()) {
            abort(403, 'Finish setting up your account before continuing.');
        }

        return redirect()->route('setup.show');
    }
}
