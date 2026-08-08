<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot password" — mails a reset link via Laravel's password broker. The response is a
 * single generic status in every case so the endpoint can't be used to enumerate accounts.
 *
 * The broker resolves the account by JOINING through `person_id` to `people.email` (owner
 * decision 2026-08-08: there is one email column now, on `people`; `users.member_email` is a
 * legacy artifact no write path here trusts). `retrieveByCredentials()` supports a Closure
 * credential value — it hands the Eloquent query builder straight to it — which is the
 * officially-supported way to do this WITHOUT a custom user provider (the design this app
 * deliberately avoids; see the P0c plan's finding 1 and the corrected design doc §5.2.2). A
 * person with no `users` row has nothing for this query to find, by construction: the closure
 * only ever constrains a `users` query, so B8 stays refused exactly as
 * `RosterOnlyCannotAuthenticateTest` proves.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Person::normalizeEmail($request->input('email'));

        // Ignore the broker's status so an unknown address returns the same generic message as
        // a known one.
        Password::sendResetLink([
            'member_email' => function ($query) use ($email): void {
                // A null normalized address must match NOTHING, not every person with a null
                // `people.email` — `where('col', null)` degrades to `whereNull` in Eloquent.
                if ($email === null) {
                    $query->whereRaw('0 = 1');

                    return;
                }

                $query->whereHas('person', fn ($q) => $q->where('people.email', $email));
            },
        ]);

        return back()->with('status', 'If that account exists, a password reset link has been sent.');
    }
}
