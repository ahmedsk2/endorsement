<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-login setup: choose how you sign in, then choose what you get told about.
 *
 * Both questions already had answers on the profile page and both were skippable, so the
 * realistic outcome was an account that never chose either. This asks once, at the only
 * moment the person is definitely paying attention.
 */
class SetupController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Someone who has finished has no business here — a bookmark should not reopen it.
        if ($user->setup_completed_at !== null) {
            return redirect()->route('endorsement.root');
        }

        return Inertia::render('Setup', [
            'security' => [
                'email' => $user->member_email,
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_method' => $user->two_factor_method,
                'totp_confirmed' => $user->hasTwoFactorEnabled(),
                'active_method' => $user->activeTwoFactorMethod(),
            ],
            'reminders' => [
                'units' => Unit::orderBy('id')->get(['id', 'code', 'name']),
                'selected' => $user->reminderUnits()->pluck('units.id'),
                'vapid_public_key' => (string) config('endorsement.vapid.public_key'),
            ],
        ]);
    }

    /**
     * Finish. Refuses while the first step is genuinely outstanding.
     *
     * The check is `activeTwoFactorMethod()`, not "did they click something": that is the
     * predicate the rest of the application consults, so anything else would let a user
     * leave setup in a state the app still considers unconfigured — which is the exact
     * contradiction the enrolment flow used to produce.
     */
    public function complete(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->activeTwoFactorMethod() === null) {
            throw ValidationException::withMessages([
                'setup' => 'Choose how you will sign in before continuing.',
            ]);
        }

        $user->forceFill(['setup_completed_at' => now()])->save();

        AuditLog::record(
            'account_setup_completed',
            'member='.$user->getKey().' method='.$user->activeTwoFactorMethod(),
            $user->getKey(),
            $request->ip(),
        );

        return redirect()->route('endorsement.root')
            ->with('status', 'You are all set. Welcome.');
    }
}
