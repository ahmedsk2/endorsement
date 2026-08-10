<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvitationBulkResendRequest;
use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\PendingRegistration;
use App\Models\Person;
use App\Models\User;
use App\Support\Invitations\BulkResend;
use App\Support\Invitations\InvitationIssue;
use App\Support\Invitations\InvitationStatus;
use App\Support\Invitations\StaleResendPlanException;
use App\Support\ManagerScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Issue, resend and revoke invitations — the only way an account is now created.
 *
 * The positions offered here are the ones self-registration used to offer, for the same
 * reasons: 0 (Administrator) is never granted this way, 1 (Nurse) is retired, and 5 (Chief
 * Resident) is a promotion an Administrator applies to an existing account rather than a
 * role anyone is created into. Widening this list would make an invitation a route to
 * privilege, which is exactly what it must not be.
 *
 * INVITE AND RESEND ARE ONE ACT REACHED BY TWO PATHS, and they share a writer for that reason:
 * `App\Support\Invitations\InvitationIssue` mints the token and kills every live link the person
 * holds, in one transaction, with the whole superseded set authorized first. What differs between
 * the two endpoints is only where the person and the position come from — a validated address and
 * a chosen role on one, a bound invitation row on the other. Nothing else, deliberately: a resend
 * that could reach a person, an address or a role the invite path would refuse is a second, wider
 * door onto a bearer credential.
 */
class InvitationController extends Controller
{
    public const OFFERABLE = [
        2 => 'Charge Nurse',
        3 => 'Consultant',
        4 => 'Resident',
    ];

    /** Why an invitation was minted — audited, never stored in a column of its own. */
    private const INVITED = 'invited';

    private const RESENT = 'resent';

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

        // ONE definition of normalising an address (`Person::normalizeEmail()`), not a third
        // inline copy of the same expression — case and whitespace differ between a hospital
        // spreadsheet, an invitation form and a self-registration, and matching must not.
        $email = (string) Person::normalizeEmail($data['member_email']);

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

        // Re-inviting someone who left is a reactivation, never a second human. This is the ONE
        // path that restores a roster row, and it is deliberately not on the resend path.
        if ($person->trashed()) {
            $person->restore();
        }

        $result = InvitationIssue::issue($request, $person, (int) $data['position']);

        $this->auditIssue($request, $result, $person, self::INVITED);

        return $this->deliver($result, $person);
    }

    /**
     * AC-02's *"resendable singly"* — the same mint, reached from an existing row.
     *
     * The order below is authorize, then refuse, then write. Authorization comes FIRST because the
     * invitation is route-bound: everything after it discloses something about a specific row
     * (whether it was claimed, whether the person holds an account), and a viewer outside that
     * person's tier learns none of it.
     */
    public function resend(Request $request, Invitation $invitation): RedirectResponse
    {
        // The invitation's OWN position — never a request-supplied one. A resend cannot promote.
        ManagerScope::assertMayTarget($request, (int) $invitation->position);

        $state = InvitationStatus::stateOf($invitation);

        // Resend acts on a link that is live or has aged out. Anything else names the endpoint
        // that does own it, rather than quietly doing something adjacent:
        //
        //  - CLAIMED: the account exists. There is nothing to resend, and minting another link to
        //    a claimed address is a second credential for an account that already has an owner.
        //  - REVOKED: revoking is a deliberate administrator act meaning "this link must not
        //    work". Resending from that row would undo it through a shorter path with a different
        //    name. Re-inviting is still available and is a different endpoint entirely
        //    (`admin.invitations.store`), which is what the People screen offers for this state.
        if (! in_array($state, [InvitationStatus::OPEN, InvitationStatus::EXPIRED], true)) {
            throw ValidationException::withMessages([
                'invitation' => $state === InvitationStatus::CLAIMED
                    ? 'That invitation has already been claimed — there is nothing to resend.'
                    : 'That invitation was revoked. Send a new invitation instead.',
            ]);
        }

        // `member_email` is frozen at send time and `people.email` can be corrected afterwards
        // (Decision G), so the ROSTER row is what a resend is addressed to. The relation is tried
        // first and the address second: a row minted before P0c carries no `person_id` at all.
        $person = $invitation->person ?? Person::matchByEmail($invitation->member_email);

        if ($person === null || $person->trashed() || Person::normalizeEmail($person->email) === null) {
            throw ValidationException::withMessages([
                'invitation' => 'That invitation is not linked to a current roster entry with an email address. Send a new invitation instead.',
            ]);
        }

        // The ONE predicate for "already an account" (`Person::accountEmailRule()`), applied here
        // exactly as the invite path applies it — not a second `hasAccount()` check written out
        // again beside it. A person who claimed an account through some other door since this row
        // was minted is refused with the same message and the same key, and nothing is written.
        Validator::make(
            ['member_email' => $person->email],
            ['member_email' => [Person::accountEmailRule()]],
        )->validate();

        $result = InvitationIssue::issue($request, $person, (int) $invitation->position);

        $this->auditIssue($request, $result, $person, self::RESENT);

        return $this->deliver($result, $person);
    }

    /**
     * LV-02's bulk resend, PREVIEWED. Writes nothing — not a row, not a revocation, not an audit
     * entry — and the flash shape is `RosterImport`'s (`back()->with(...)`), so the screen never
     * holds a stale plan across a navigation.
     *
     * The plan carries its own `digest`, which the confirm posts back. That is the whole
     * preview-then-confirm contract in one field.
     *
     * THE AUTHORIZATION PASS RUNS HERE TOO, over the same set, for the same reason it runs on the
     * confirm: everything below it discloses something about specific people — whether they have
     * claimed, whether they were ever invited — and a viewer outside their tier learns none of it.
     */
    public function bulkPreview(InvitationBulkResendRequest $request): RedirectResponse
    {
        $ids = array_values(array_unique(array_map('intval', $request->validated('person_ids'))));

        foreach (BulkResend::positionsToAuthorize($ids) as $position) {
            ManagerScope::assertMayTarget($request, $position);
        }

        return back()->with('invitation_bulk_preview', BulkResend::plan($ids));
    }

    /**
     * The confirm. **COMMIT, THEN MAIL, THEN AUDIT** — stated here because the ordering is the
     * whole feature and each step is where it is for a named reason.
     *
     *  1. **The WHOLE selection is authorized before any mutation**, over every LIVE invitation
     *     position it will touch. `AccessControlController::updateRoles()` is the model: authorize
     *     the entire matrix, then mutate. It runs BEFORE the transaction because
     *     `ManagerScope::assertMayTarget()` audits its refusal and then `abort(403)`s — inside a
     *     transaction that audit row would unwind with the abort and the attempt would vanish from
     *     the trail (P1c-1 finding 12).
     *  2. **One transaction** for the row work (`BulkResend::commit()`), pinned to the plan the
     *     operator saw and re-derived inside itself rather than trusted from the request.
     *  3. **Then the mail** — after `commit()` has returned, which IS the commit, so the ordering is
     *     structural rather than a comment somebody has to keep honouring. **Mail cannot be rolled
     *     back**: had a send happened inside a transaction that then failed, its recipient would
     *     hold a live link to an invitation that does not exist while this screen said the whole
     *     thing was refused, and no test in this process could ever see it. Each send is wrapped
     *     individually, because a partial send is the EXPECTED outcome of talking to an SMTP relay
     *     about fifty addresses, not an error state — the operator is shown exactly who did not get
     *     one so they can resend just those.
     *  4. **Then the trail**, after the sends, so the summary can carry the counts that actually
     *     happened. `AuditLog::record()` opens its own transaction and takes `lockForUpdate` on the
     *     chain tail, so appending from inside step 2 would hold that lock for the operation's whole
     *     duration for no benefit (P1c-1's post-merge finding 6 — a false ops alert).
     *
     * ONE SUMMARY ROW PLUS ONE ROW PER PERSON ACTED ON — never the single path's PAIR
     * (`invitation_issued` + `invitation_revoked`), which on a cohort of fifty would be a hundred
     * chain appends carrying the same information at twice the contention. Ids and counts only: no
     * name, no address, and no link.
     */
    public function bulkResend(InvitationBulkResendRequest $request): RedirectResponse
    {
        $ids = array_values(array_unique(array_map('intval', $request->validated('person_ids'))));

        foreach (BulkResend::positionsToAuthorize($ids) as $position) {
            ManagerScope::assertMayTarget($request, $position);
        }

        try {
            ['report' => $report, 'deliveries' => $deliveries] = BulkResend::commit(
                $request,
                $ids,
                (string) $request->validated('digest'),
            );
        } catch (StaleResendPlanException $e) {
            throw ValidationException::withMessages(['digest' => $e->getMessage()]);
        }

        // --- the rows are safe now; only now does anything leave the building.
        $report = BulkResend::withMailOutcomes($report, $this->mailAll($deliveries));

        $actorId = $request->user()?->getKey();
        $ip = $request->ip();

        AuditLog::record(
            'invitation_bulk_resend',
            'selected='.$report['summary']['selected']
                .';n='.$report['summary']['resent']
                .';sent='.$report['summary']['sent']
                .';failed='.$report['summary']['failed']
                .';skipped='.$report['summary']['skipped'],
            $actorId,
            $ip,
        );

        foreach ($report['rows'] as $row) {
            if (! in_array($row['outcome'], [BulkResend::SENT, BulkResend::MAIL_FAILED], true)) {
                continue;
            }

            AuditLog::record(
                'invitation_resent',
                'person='.$row['person_id']
                    .';invitation='.$row['invitation_id']
                    .';superseded='.($row['superseded'] === [] ? 'none' : implode(',', $row['superseded']))
                    .';mailed='.($row['outcome'] === BulkResend::SENT ? 'yes' : 'no'),
                $actorId,
                $ip,
            );
        }

        return back()->with('invitation_bulk_report', $report);
    }

    /**
     * Send one email per recipient, each in its own `try`, and report the failures rather than
     * swallowing them.
     *
     * THE EXCEPTION MESSAGE IS NEVER LOGGED. SMTP transport errors routinely quote the envelope
     * recipient back ("... 550 5.1.1 <someone@hospital.example>"), and staff personal data is
     * covered by the same rule as PHI — the person id, the invitation id and the exception CLASS
     * are what a diagnosis actually needs, and SMTP itself is proved by Settings → Send test email.
     *
     * @param  list<array{person_id:int, invitation_id:int, to:string, link:string, expires_at:mixed}>  $deliveries
     * @return array<int, string> person id => `BulkResend::SENT` or `BulkResend::MAIL_FAILED`
     */
    private function mailAll(array $deliveries): array
    {
        $outcomes = [];

        foreach ($deliveries as $delivery) {
            try {
                Mail::to($delivery['to'])
                    ->send(new InvitationMail($delivery['link'], $delivery['expires_at']));

                $outcomes[$delivery['person_id']] = BulkResend::SENT;
            } catch (\Throwable $e) {
                Log::warning('A bulk invitation email could not be delivered.', [
                    'person' => $delivery['person_id'],
                    'invitation' => $delivery['invitation_id'],
                    'exception' => $e::class,
                ]);

                $outcomes[$delivery['person_id']] = BulkResend::MAIL_FAILED;
            }
        }

        return $outcomes;
    }

    /**
     * The audit pair for one mint: what died, and what replaced it. Ids and counts only.
     *
     * AFTER the write, never inside it — `AuditLog::record()` takes `lockForUpdate` on the chain
     * tail, and holding that inside a row transaction serialises the whole chain for its duration
     * (P1c-1's post-merge finding 6, which cost a false ops alert).
     *
     * @param  array{invitation: Invitation, link: string, superseded: list<int>}  $result
     */
    private function auditIssue(Request $request, array $result, Person $person, string $reason): void
    {
        foreach ($result['superseded'] as $id) {
            AuditLog::record(
                'invitation_revoked',
                // Each row's `reason` explains that row's own end: `superseded` when a fresh
                // invitation replaced it, `resent` when the same invitation was sent again.
                'invitation='.$id.' reason='.($reason === self::RESENT ? self::RESENT : 'superseded'),
                $request->user()?->getKey(),
                $request->ip(),
            );
        }

        AuditLog::record(
            'invitation_issued',
            // Ids, never an address — the person this invitation names.
            'invitation='.$result['invitation']->getKey()
                .' position='.$result['invitation']->position
                .' person='.$person->getKey()
                .' reason='.$reason,
            $request->user()?->getKey(),
            $request->ip(),
        );
    }

    /**
     * Mail it if mail is configured; either way, hand the link back exactly once.
     *
     * NEVER FAIL THE INVITATION BECAUSE THE MAILER IS DOWN. The inviter still holds a working
     * link, and losing it silently would be worse than not sending. That swallow is safe here and
     * ONLY here: the single path has somewhere to surface the credential, which a bulk path
     * mailing N people does not.
     *
     * The exception MESSAGE is deliberately not logged. Transport errors routinely quote the
     * envelope recipient back ("... 550 5.1.1 <someone@hospital.example>"), and staff personal
     * data is covered by the same rule as PHI — ids and field names in a log line, never an
     * address. The class name plus the two ids is what a diagnosis actually needs; SMTP itself is
     * proved by Settings → Send test email.
     *
     * @param  array{invitation: Invitation, link: string, superseded: list<int>}  $result
     */
    private function deliver(array $result, Person $person): RedirectResponse
    {
        $mailed = false;

        if (config('mail.default') === 'smtp') {
            try {
                Mail::to((string) $person->email)
                    ->send(new InvitationMail($result['link'], $result['invitation']->expires_at));
                $mailed = true;
            } catch (\Throwable $e) {
                Log::warning('Invitation email could not be delivered.', [
                    'person' => $person->getKey(),
                    'invitation' => $result['invitation']->getKey(),
                    'exception' => $e::class,
                ]);
            }
        }

        // The token is returned to the INVITER exactly once and is never stored in
        // plaintext, never audited, and never logged. Flashed rather than redirected into
        // the URL, because a bearer credential in a query string lands in history and logs.
        return back()->with('invitation_link', $result['link'])->with(
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
