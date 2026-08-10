<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\PendingRegistration;
use App\Models\Person;
use App\Models\User;
use App\Support\Invitations\InvitationStatus;
use App\Support\ManagerScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Issue and revoke invitations — the only way an account is now created.
 *
 * The positions offered here are the ones self-registration used to offer, for the same
 * reasons: 0 (Administrator) is never granted this way, 1 (Nurse) is retired, and 5 (Chief
 * Resident) is a promotion an Administrator applies to an existing account rather than a
 * role anyone is created into. Widening this list would make an invitation a route to
 * privilege, which is exactly what it must not be.
 */
class InvitationController extends Controller
{
    public const OFFERABLE = [
        2 => 'Charge Nurse',
        3 => 'Consultant',
        4 => 'Resident',
    ];

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'member_email' => [
                'required', 'email', 'max:255',
                // NOT ALREADY AN ACCOUNT. This used to read `unique:users,member_email`, which
                // after a roster import/invite refuses to invite exactly the people it exists to
                // invite — an address already on the roster (no account yet) is the NORMAL case,
                // not a collision. `Person::accountEmailRule()` is the one definition of "already
                // an account" (P0c/D9 owner decision 2026-08-08: `people.email` is the single
                // authoritative address, so the check must resolve through the person, not the
                // no-longer-independently-written `users.member_email` column).
                Person::accountEmailRule(),
                Rule::unique('pending_registrations', 'member_email'),
            ],
            'position' => ['required', 'integer', Rule::in(array_keys(self::OFFERABLE))],
        ]);

        // The same two-tier rule that governs approving and rejecting accounts: a Chief
        // Resident may invite Residents, nobody else.
        ManagerScope::assertMayTarget($request, (int) $data['position']);

        $email = Str::lower(trim($data['member_email']));

        // Match onto the roster before creating anything (design §5.2.4). An imported/invited
        // person who is invited again must be the SAME person, not a second row with the same
        // name — and the validation above already proved nobody with this email has an account.
        $person = Person::matchByEmail($email) ?? Person::create([
            'institution_id' => $request->user()?->institution_id,
            // Blank until the invitee tells us; a person created here is a placeholder for
            // someone the roster does not yet know.
            'full_name' => '',
            'position' => (int) $data['position'],
            'email' => $email,
            'active' => true,
        ]);

        if ($person->trashed()) {
            $person->restore();
        }

        // One open invitation per address. Without this, "resend" quietly multiplies live
        // credentials for the same person and revoking one leaves the others working.
        //
        // Each superseded invitation is authorised INDIVIDUALLY and audited. A blanket
        // update was neither: a Chief Resident inviting an address that already held a
        // Consultant invitation would have cancelled it — an action they cannot perform
        // through the revoke route — and nothing would have recorded that it happened.
        $superseded = Invitation::query()
            ->where('member_email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->get();

        foreach ($superseded as $old) {
            ManagerScope::assertMayTarget($request, (int) $old->position);
        }

        foreach ($superseded as $old) {
            $old->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $request->user()?->getKey(),
            ])->save();

            AuditLog::record(
                'invitation_revoked',
                'invitation='.$old->id.' reason=superseded',
                $request->user()?->getKey(),
                $request->ip(),
            );
        }

        [$invitation, $token] = Invitation::issue($email, (int) $data['position'], $request->user(), $person);

        AuditLog::record(
            'invitation_issued',
            // An id, never an address — the person this invitation names.
            'invitation='.$invitation->id.' position='.$invitation->position.' person='.$person->id,
            $request->user()?->getKey(),
            $request->ip(),
        );

        $link = route('invitation.show', ['token' => $token]);

        // Deliver it if mail is configured; otherwise the link below is the delivery. Never
        // fail the invitation because the mailer is down — the inviter still holds a working
        // link, and losing it silently would be worse than not sending.
        $mailed = false;

        if (config('mail.default') === 'smtp') {
            try {
                Mail::to($email)->send(new \App\Mail\InvitationMail($link, $invitation->expires_at));
                $mailed = true;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Invitation email could not be delivered: '.$e->getMessage());
            }
        }

        // The token is returned to the INVITER exactly once and is never stored in
        // plaintext, never audited, and never logged. Flashed rather than redirected into
        // the URL, because a bearer credential in a query string lands in history and logs.
        return back()->with('invitation_link', $link)->with(
            'status',
            $mailed
                ? 'Invitation sent. The link below works too, and it is shown only once.'
                : 'Invitation created. Copy the link below — it is shown only once.',
        );
    }

    public function revoke(Request $request, Invitation $invitation): RedirectResponse
    {
        ManagerScope::assertMayTarget($request, (int) $invitation->position);

        // Revoking a spent or already-revoked invitation is a no-op, not an error: the
        // outcome the caller wanted is already true.
        if ($invitation->isOpen()) {
            $invitation->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $request->user()?->getKey(),
            ])->save();

            AuditLog::record(
                'invitation_revoked',
                'invitation='.$invitation->id,
                $request->user()?->getKey(),
                $request->ip(),
            );
        }

        return back()->with('status', 'Invitation revoked.');
    }

    /**
     * Every invitation, with its derived state, for the Users screen. No token, hashed or
     * otherwise, ever reaches the page.
     *
     * IT USED TO BE OPEN-ONLY, and it used to declare a `?User $viewer` it never read. Both were
     * defects of the same shape (P1c-2 finding 4). AC-02 asks for *"claim status visible"*, and a
     * list that drops a row the moment it is accepted, revoked or expired can answer "who is still
     * waiting" but not "who ever claimed theirs" — the question Munawib §35's *"residents claimed
     * accounts"* is made of. Meanwhile the scoping the unused parameter implied was being applied
     * by the CALLER (`UserManagementController::index()`'s own `->when(! $all, …)`), so a second
     * caller had to remember a rule it could not see. It is applied here now, through
     * `ManagerScope::mayTarget()` — the same rule that governs acting on the invitation, proven
     * equivalent to the assertion by `ManagerScopeParityTest`.
     *
     * NO LIMIT, deliberately. `invitations` still has no retention rule (design §14 item 7) and
     * resend makes rows accumulate faster, but truncating the list here would be a disposal policy
     * chosen by a projection — it belongs in the plan that reviews the whole retention policy, not
     * in a screen change.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function statusList(?User $viewer): array
    {
        return Invitation::query()
            // `full_name` is a read-through accessor since P0c: it resolves via the `person`
            // relation, which needs `person_id` loaded — a narrow `id,full_name` constraint
            // omits it and the accessor silently returns null even though the row was fetched.
            ->with('invitedBy:id,person_id')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Invitation $i): bool => ManagerScope::mayTarget($viewer, (int) $i->position))
            ->map(function (Invitation $i): array {
                $state = InvitationStatus::stateOf($i);

                return [
                    'id' => $i->id,
                    'member_email' => $i->member_email,
                    'position' => $i->position,
                    'position_label' => self::OFFERABLE[$i->position] ?? (string) $i->position,
                    'invited_by' => $i->invitedBy?->full_name,
                    'state' => $state,
                    'state_label' => InvitationStatus::labelFor($state, null),
                    // ONE date shape across both screens (P1c-2 Decision B): a `Calendar::label()`
                    // with the time appended, never a second `->format()` living in a controller.
                    'expires_at' => InvitationStatus::at($i->expires_at),
                    // Revoking a spent or already-revoked invitation is a no-op server-side; the
                    // control is hidden rather than offered so the screen does not suggest an act
                    // with no effect.
                    'open' => $i->isOpen(),
                ];
            })
            ->values()
            ->all();
    }

    /** Kept for the pending queue that predates invitations. */
    public static function pendingCount(): int
    {
        return PendingRegistration::count();
    }
}
