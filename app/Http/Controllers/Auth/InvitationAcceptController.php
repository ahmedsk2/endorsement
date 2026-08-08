<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Person;
use App\Support\AccessControl;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Redeem an invitation: the only route by which an account now comes into existence.
 *
 * What the invitee supplies is their name, their username and their password. What they do
 * NOT supply — and what therefore cannot be tampered with — is their email address and
 * their role: both travel with the invitation, decided by whoever issued it. There is no
 * request in this flow carrying a self-declared position.
 *
 * The account is created ACTIVE and email-verified, with no second approval step, and that
 * is not a weakening of the old flow. Under self-registration, approval was where a human
 * first decided this person should exist and what they should be; here that decision was
 * already made, by a named manager, before the link was sent. Redeeming a link delivered to
 * one fixed address is also exactly what the verification email proved, so requiring a
 * further confirmation would be asking the same question twice.
 *
 * Task 8 (P0c): redemption CLAIMS the person this invitation was issued to — it never inserts
 * a second one alongside them. `InvitationController::store()` already matched-or-created that
 * person at issue time; this is where the account gets linked to them.
 */
class InvitationAcceptController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = Invitation::redeemable($token);

        if ($invitation === null) {
            // One message for expired, revoked, already-used and never-existed. Telling a
            // stranger which of those it was turns this into an oracle for valid addresses.
            return redirect()->route('login')->with(
                'error',
                'That invitation link is no longer valid. Ask the person who invited you to send a new one.',
            );
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'member_email' => $invitation->member_email,
            'position_label' => \App\Http\Controllers\Admin\InvitationController::OFFERABLE[$invitation->position]
                ?? (string) $invitation->position,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = Invitation::redeemable($token);

        if ($invitation === null) {
            return redirect()->route('login')->with(
                'error',
                'That invitation link is no longer valid. Ask the person who invited you to send a new one.',
            );
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'member_name' => [
                'required', 'string', 'max:255',
                // Soft-deleted accounts still hold the unique index, so they count.
                Rule::unique('users', 'member_name'),
                Rule::unique('pending_registrations', 'member_name'),
            ],
            'password' => PasswordPolicy::rules(),
        ]);

        $userId = DB::transaction(function () use ($data, $invitation): int {
            // Re-check inside the transaction with a lock. Two people opening the same link
            // at once would otherwise both pass the check above and create two accounts from
            // one invitation.
            $locked = Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isOpen()) {
                throw new \RuntimeException('invitation-consumed');
            }

            $now = now();

            // CLAIM the person this invitation was issued to — never insert alongside them. The
            // old code did an unconditional INSERT into `people`, which forked the identity into
            // two humans the moment `InvitationController::store()` had already matched-or-created
            // one at issue time (Task 8 / design §5.2.4). A null person_id only happens for a row
            // minted before P0c (or by a direct `Invitation::issue()` caller with no $person) —
            // for those, and only those, a person is created here, exactly as before.
            $person = $locked->person_id === null
                ? Person::create([
                    'institution_id' => $locked->institution_id,
                    'full_name' => $data['full_name'],
                    'position' => $locked->position,   // FROM THE INVITATION, never from the request
                    'email' => Person::normalizeEmail($locked->member_email),
                    'active' => true,
                ])
                : Person::withTrashed()->lockForUpdate()->findOrFail($locked->person_id);

            if ($person->trashed()) {
                $person->restore();
            }

            // The ROSTER is the name of record. A person the department already knows keeps the
            // name the department gave them — an invitee must not be able to rename themselves
            // onto a signed sheet. A placeholder created at issue time (or above, for a legacy
            // person-less invitation) has no name yet, so the one they supply here is taken.
            if (trim((string) $person->full_name) === '') {
                $person->full_name = $data['full_name'];
            }

            $person->active = true;
            $person->save();

            // Guard against the address having been claimed by someone ELSE between issue and
            // redemption — checked against the LOCKED invitation row, not a read from seconds
            // earlier. `people.email` is unique, so this is the same collision the DB would
            // otherwise throw as an opaque 23000; catching it here keeps the failure a normal
            // validation error.
            $collision = Person::withTrashed()
                ->where('email', Person::normalizeEmail($locked->member_email))
                ->whereKeyNot($person->getKey())
                ->exists();

            if ($collision) {
                throw ValidationException::withMessages([
                    'member_name' => 'This invitation can no longer be completed. Ask the person who invited you to send a new one.',
                ]);
            }

            // Guard against THIS invitation's OWN person having claimed an account through some
            // other route since it was issued (e.g. a second invitation to the same address,
            // redeemed first). The collision check above only catches a DIFFERENT person holding
            // the address — without this, the insert below would hit `users.person_id`'s UNIQUE
            // index directly, a raw 23000 surfaced as a 500. `UserManagementController::
            // assertStillUnique()` guards the pending-registration path the same way, via the
            // same `hasAccount()` call. Reachability is very low (two links to the same person,
            // the earlier one redeemed in the gap), so this is defence in depth, not a race fix —
            // note `hasAccount()` excludes soft-deleted accounts while the UNIQUE index does not,
            // so it cannot catch every case the database itself would refuse.
            if ($person->hasAccount()) {
                throw ValidationException::withMessages([
                    'member_name' => 'This invitation can no longer be completed. Ask the person who invited you to send a new one.',
                ]);
            }

            $userId = DB::table('users')->insertGetId([
                'person_id' => $person->getKey(),
                'institution_id' => $locked->institution_id,
                // No 'member_email' key: `people.email` is the single authoritative address
                // (owner decision 2026-08-08). `users.member_email` physically survives — dropping
                // it is its own migration, not this one — but a UNIQUE index still sits on it,
                // and nothing keeps it in step with `people.email` after a later profile edit. If
                // this insert wrote it, redeeming an invitation to an address someone ELSE used to
                // hold (and has since changed away from — a freed, later-reassigned address, a
                // realistic case) would 500 on that stale column's own unique index despite
                // `people.email` having no live collision at all.
                'member_name' => $data['member_name'],
                'password' => Hash::make($data['password']),
                'active' => true,
                // Redeeming a link delivered to this address IS the proof of the address.
                'email_verified_at' => $now,
                // Arm the 90-day rotation, as the approval path does. Left null it never fires.
                'pass_exp_date' => $now->copy()->addDays(90)->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $locked->forceFill([
                'accepted_at' => $now,
                'accepted_user_id' => $userId,
            ])->save();

            return $userId;
        });

        // Capabilities resolve from the person's position and are cached for 600 seconds per
        // user id. A brand-new id has no entry, but the flush is written explicitly so that a
        // future re-claim of a recycled account can never serve a stale set.
        AccessControl::flush($userId);

        AuditLog::record(
            'invitation_accepted',
            'invitation='.$invitation->id.' member='.$userId.' position='.$invitation->position,
            // No session yet — the actor is recorded as the new account itself.
            $userId,
            $request->ip(),
        );

        return redirect()->route('login')->with(
            'status',
            'Your account is ready. Sign in with the username you just chose.',
        );
    }
}
