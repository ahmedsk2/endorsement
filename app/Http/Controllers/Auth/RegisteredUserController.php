<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Public self-registration, CLOSED on 2026-07-27 (owner decision).
 *
 * `/register` was an unauthenticated endpoint on the public internet that wrote to the
 * database and sent mail on a stranger's say-so. An administrator still had to approve the
 * resulting row — a real control, and the one docs/COMPLIANCE.md leans on to justify every
 * clinical account being able to read all four units — so the answer was to make that
 * control stronger rather than keep guarding a door that did not need to be open.
 *
 * Accounts are now created only by invitation:
 * App\Http\Controllers\Admin\InvitationController.
 *
 * What survives, and why this is not simply deleted: the `pending_registrations` queue and
 * its email-confirmation link are still reachable, because an administrator can still
 * approve rows that were registered before invitations existed. Nothing creates new ones.
 * Once that queue is confirmed empty in production, the queue, its approval path and this
 * file can all go together.
 */
class RegisteredUserController extends Controller
{
    /**
     * A redirect rather than a 404: the URL is bookmarked and printed in older
     * documentation, and "page not found" reads as a broken system to a nurse at 03:00 —
     * which produces a phone call rather than the intended understanding.
     */
    public function closed(): RedirectResponse
    {
        return redirect()->route('login')->with(
            'status',
            'Accounts are created by invitation. Ask an administrator or your chief resident to send you one.',
        );
    }
}
