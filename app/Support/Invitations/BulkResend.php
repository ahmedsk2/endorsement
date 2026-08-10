<?php

namespace App\Support\Invitations;

use App\Models\Invitation;
use App\Models\Person;
use App\Support\Rota\StatePin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LV-02's bulk resend — **the plan a cohort resend makes, before it makes it**, and then the row
 * work itself (P1c-2 Task 4, Decision D).
 *
 * THE ORDERING IS THE FEATURE, AND IT IS THE WHOLE REASON THIS CLASS DOES NOT MAIL.
 * **Commit, then mail, then audit.** `commit()` returns once its transaction has committed, and it
 * hands the caller a `deliveries` list; the controller mails from that list afterwards and appends
 * the trail after THAT. Two failures, one milder than the other:
 *
 *  - P1c-1's post-merge finding 6 is the mild one: `RosterImport::commit()` called an audit writer
 *    from inside its own transaction, serialising the hash chain for the import's duration and
 *    raising a false ops alert. That cost latency and a page.
 *  - **Mail cannot be rolled back.** Had the sends happened inside the transaction and the
 *    transaction then failed, recipients would hold live links to invitations that do not exist
 *    while the operator's screen said the whole thing was refused. There is no recovery from that
 *    and no test in this process could see it, because the evidence is in somebody else's inbox.
 *    Committing first makes the worst case the reverse — rows exist, some mail did not go — which
 *    is visible, reportable per person, and fixed by resending those people.
 *
 * TWO ENTRY POINTS, ONE ANALYSIS, the shape `RotaFill` already has. `plan()` computes the delta and
 * writes nothing; `commit()` re-runs the SAME private `analyse()` inside ONE transaction rather
 * than trusting anything the client sent back, and dispatches through `InvitationIssue` — never
 * `Invitation` directly, which `InvitationWritersAreSingularTest` fails the build for. Token
 * rotation and the kept, revoked, superseded row are that writer's, unchanged and not reimplemented
 * here.
 *
 * IT IS A RESEND, NOT A WIDER INVITE. Only a person whose latest invitation is OPEN or EXPIRED is
 * acted on, which is exactly what `InvitationController::resend()` accepts and exactly what the
 * People screen offers (D9: one predicate decides who is offered and who is accepted). The other
 * two states are refused for the reasons the single path already gives — a CLAIMED row has an owner
 * and needs no second credential, and a REVOKED one was deliberately killed by an administrator, so
 * reviving it from here would undo their act through a shorter path. A person with NO invitation at
 * all is skipped rather than invited: there is no superseded row to take a position from, and
 * `people.position` is not it — positions 0 and 5 are absent from
 * `InvitationController::OFFERABLE` on purpose, so minting from the roster row would make bulk the
 * wider door onto a bearer credential that this slice exists to prevent.
 *
 * A PERSON OUTSIDE THE ACTIONABLE SET IS SKIPPED, NOT REFUSED — with one exception. A cohort
 * selection made with "select all filtered" routinely contains people who have already claimed;
 * 422-ing the whole submission because three of fifty are done would make the feature unusable, so
 * each is reported with its own outcome from this analysis rather than from a guess the screen
 * made. The exception is AUTHORIZATION: a target outside the viewer's tier refuses the WHOLE
 * submission, because "a Chief Resident tried to act on a Consultant" is a security event and not a
 * row to skip past. `positionsToAuthorize()` is what the controller asserts over, BEFORE the
 * transaction opens — `ManagerScope::assertMayTarget()` audits its refusal and then aborts, and
 * inside a transaction that audit row would unwind with the abort and the attempt would vanish from
 * the trail (P1c-1 finding 12).
 *
 * THE CAP IS FIFTY, AND IT IS A REFUSAL RATHER THAN A TRUNCATION. Fifty covers a whole resident
 * cohort. Five hundred synchronous SMTP sends inside one HTTP request would time out halfway,
 * leaving some recipients mailed and the operator with no idea which — and a request that mails
 * five hundred people is indistinguishable from a mis-click. It is enforced in
 * `InvitationBulkResendRequest`, where it fails before anything is resolved, and it is stated on
 * screen beside the button.
 *
 * IT NAMES NOBODY. Person ids, invitation ids and counts — no name, no address, and above all **no
 * link**: `plan()` and the reportable half of `commit()` cannot carry one by construction, because
 * the links live in a separate `deliveries` list the controller consumes and never flashes. A bulk
 * path has nowhere to surface fifty one-time bearer credentials, and a payload that could carry one
 * is a payload that eventually does.
 */
final class BulkResend
{
    /** Decision D property 3. A cohort, not a directory. */
    public const CAP = 50;

    /** This person's invitation would be reissued and the fresh link mailed. */
    public const RESEND = 'resend';

    /** ...and it was. Set only after the transaction committed and the send returned. */
    public const SENT = 'sent';

    /** The row was written; the send threw. Reported so the operator can chase exactly these. */
    public const MAIL_FAILED = 'mail_failed';

    /** The account exists — there is nothing to resend, and nothing to mint beside it. */
    public const SKIPPED_HAS_ACCOUNT = 'skipped_has_account';

    /** No address to deliver to. `invitations.member_email` is NOT NULL and must never be blank. */
    public const SKIPPED_NO_EMAIL = 'skipped_no_email';

    /** Off the active roster, or retired. A resend never reactivates anybody. */
    public const SKIPPED_INACTIVE = 'skipped_inactive';

    /** Never invited. A resend has no row to rotate and no position to take. */
    public const SKIPPED_NO_INVITATION = 'skipped_no_invitation';

    /** Deliberately killed by an administrator. Re-inviting is the other, named door. */
    public const SKIPPED_REVOKED = 'skipped_revoked';

    /**
     * Why each skip happened, in the words the screen shows. A skip whose reason the screen has to
     * invent is a skip the operator cannot act on.
     *
     * @var array<string, string>
     */
    private const REASONS = [
        self::SKIPPED_HAS_ACCOUNT => 'This person has already claimed an account, so there is nothing to resend.',
        self::SKIPPED_NO_EMAIL => 'This person has no email address on the roster — add one, then invite them.',
        self::SKIPPED_INACTIVE => 'This person is no longer on the active roster.',
        self::SKIPPED_NO_INVITATION => 'This person has never been invited. Send them an invitation from their own row.',
        self::SKIPPED_REVOKED => 'That invitation was revoked deliberately. Send a new invitation from their own row instead.',
    ];

    /**
     * What a bulk resend WOULD do. Writes nothing — not a row, not a revocation, not an audit entry.
     *
     * @param  list<int>  $personIds
     * @return array{rows: list<array<string, mixed>>, summary: array{selected:int, will_send:int, skipped:int}, cap: int, digest: string}
     */
    public static function plan(array $personIds): array
    {
        $analysis = self::analyse($personIds);

        // `context` carries the resolved Eloquent models `commit()` dispatches to `InvitationIssue`
        // without a second round of queries. It never reaches a screen: a props payload built from
        // whole models is how a contact field lands on a page nobody meant to put it on (P1d-2
        // Decision C, finding 3) — and here a whole `Person` would carry the address as well.
        unset($analysis['context']);

        $analysis['cap'] = self::CAP;
        $analysis['digest'] = self::digest($analysis);

        return $analysis;
    }

    /**
     * The row work, COMMITTED — and NOT the mail, which the caller sends afterwards.
     *
     * ONE transaction, the delta re-derived inside it, and the whole set already authorized by the
     * caller before this is entered. A refusal refuses the WHOLE operation: "thirty of fifty
     * resent, and the rest lost" is the failure this shape exists to prevent, and the supersede is
     * half of every resend — a committed revocation whose replacement rolled back would leave a
     * person with no live link at all and no record of why.
     *
     * IT NEVER TRUSTS THE REQUEST. The client sends person ids and a digest; every position, every
     * address and every superseded row is resolved here.
     *
     * @param  list<int>  $personIds
     * @return array{report: array{rows: list<array<string, mixed>>, summary: array<string, int>, cap: int, digest: string}, deliveries: list<array{person_id:int, invitation_id:int, to:string, link:string, expires_at:mixed}>}
     *
     * @throws StaleResendPlanException when the world moved between the preview and the confirm
     */
    public static function commit(Request $request, array $personIds, string $expectedDigest): array
    {
        return DB::transaction(function () use ($request, $personIds, $expectedDigest): array {
            $analysis = self::analyse($personIds);

            $context = $analysis['context'];
            unset($analysis['context']);

            $analysis['cap'] = self::CAP;
            $analysis['digest'] = self::digest($analysis);

            // THE PIN FIRST, and before any write.
            if (! hash_equals($analysis['digest'], $expectedDigest)) {
                throw new StaleResendPlanException(
                    'Something changed since you previewed this resend — somebody claimed an account, '
                    .'or a link was revoked. Preview it again to see what it would do now.'
                );
            }

            $deliveries = [];

            foreach ($analysis['rows'] as $index => $row) {
                if ($row['outcome'] !== self::RESEND) {
                    continue;
                }

                /** @var Person $person */
                $person = $context['people'][$row['person_id']];

                $result = InvitationIssue::issue($request, $person, (int) $row['position']);

                $analysis['rows'][$index]['invitation_id'] = (int) $result['invitation']->getKey();
                $analysis['rows'][$index]['superseded'] = $result['superseded'];

                $deliveries[] = [
                    'person_id' => (int) $row['person_id'],
                    'invitation_id' => (int) $result['invitation']->getKey(),
                    // The delivery target and the LINK travel here and nowhere else. Kept off
                    // `rows` deliberately: `rows` is what reaches an Inertia prop, and a one-time
                    // bearer credential must not be able to get there by somebody forgetting to
                    // strip it.
                    //
                    // Taken from the INVITATION rather than from the person: `member_email` is the
                    // address this credential is bound to, frozen at mint time by the one writer
                    // from the roster row's own current value (Decision G). Reading the person
                    // again here would be a second read of the same fact — one that could differ
                    // from what the credential says, and one this class would have to be
                    // allow-listed for by `ContactFieldsAreProjectedOnceTest`.
                    'to' => (string) $result['invitation']->member_email,
                    'link' => $result['link'],
                    'expires_at' => $result['invitation']->expires_at,
                ];
            }

            return ['report' => $analysis, 'deliveries' => $deliveries];
        });
    }

    /**
     * Fold each recipient's mail outcome back into the report, and count the two totals the
     * operator actually asked about.
     *
     * @param  array<string, mixed>  $report
     * @param  array<int, string>  $mailOutcomes  person id => `SENT` or `MAIL_FAILED`
     * @return array<string, mixed>
     */
    public static function withMailOutcomes(array $report, array $mailOutcomes): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($report['rows'] as $index => $row) {
            $outcome = $mailOutcomes[(int) $row['person_id']] ?? null;

            if ($outcome === null) {
                continue;
            }

            $report['rows'][$index]['outcome'] = $outcome;
            $outcome === self::SENT ? $sent++ : $failed++;
        }

        $report['summary']['resent'] = $sent + $failed;
        $report['summary']['sent'] = $sent;
        $report['summary']['failed'] = $failed;

        return $report;
    }

    /**
     * Every distinct position the operation will ask `ManagerScope` to approve.
     *
     * A UNION OF TWO SETS, and the first half is this method's whole baseline (review finding F3).
     * It used to return the positions of LIVE INVITATIONS ONLY — and these two endpoints sit in an
     * `auth`-only route group, because the invitation rule is two-tier and position-dependent and
     * is therefore applied in-controller rather than by a `cap:` middleware. That exception is only
     * sound if the in-controller pass asserts something. Select people who have all claimed, or who
     * were never invited, and the old answer was `[]`: the controller's `foreach` had nothing to
     * iterate, and ANY authenticated account received the plan — per person, their invitation state,
     * their invitation id and whether they hold an account. `people.position` is now always in the
     * set, so a non-empty selection can never authorize nothing.
     *
     * `people.position` is also the RIGHT half rather than a defensive one: it is the position
     * `InvitationIssue::issue()` itself authorizes against (F1), because a redeemed account resolves
     * its capabilities from the roster row and not from the invitation.
     *
     * The second half is the SUPERSEDE set — every live invitation the operation will revoke on its
     * way to minting a replacement, not merely the latest one per person.
     *
     * WIDER THAN THE ACTED-ON SET ON PURPOSE. `issue()` asserts over every row it is about to
     * supersede, and if one of those asserts fired from inside `commit()`'s transaction the
     * `user_scope_denied` row it wrote would roll back with the abort — the attempt would vanish
     * from the trail, which is the exact defect P1c-1 finding 12 records. Authorizing the whole set
     * up front means the inner asserts are a belt already proven by the braces.
     *
     * @param  list<int>  $personIds
     * @return list<int>
     */
    public static function positionsToAuthorize(array $personIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $personIds)));

        if ($ids === []) {
            return [];
        }

        // `withTrashed()`, matching `analyse()`: a retired person is skipped by the operation but is
        // still somebody this viewer either may or may not know about, and `person_ids.*` validates
        // with a bare `exists` that sees soft-deleted rows too.
        $people = Person::withTrashed()->whereIn('id', $ids)->get();

        $positions = $people->map(static fn (Person $p): int => (int) $p->position)->all();

        $superseded = Invitation::query()
            ->whereIn('person_id', $ids)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->pluck('position');

        foreach ($superseded as $position) {
            $positions[] = (int) $position;
        }

        $positions = array_values(array_unique($positions));
        sort($positions);

        return $positions;
    }

    /**
     * WHAT THE COMMIT IS PINNED TO.
     *
     * {@see StatePin} is the one definition of that rule (P1d-2 built it after an importer was
     * found pinned to the wrong thing), and this operation genuinely has its shape: a set of cells,
     * each with an identity, what it CURRENTLY holds, and what would be WRITTEN. Here a "cell" is a
     * PERSON — the second identity slot is the one `StatePin` documents as "null where the concept
     * does not apply", because a resend has no period — what it currently holds is the account and
     * invitation state the outcome is derived from, and what would be written is the position the
     * fresh invitation would carry.
     *
     * `outcome` and `reason` are deliberately out of it, exactly as `RotaFill::digest()` leaves them
     * out: both are a pure function of the current state above, so a change invisible to this hash
     * is a change that writes nothing different. Re-deriving inside the transaction is necessary
     * and NOT sufficient — it makes the commit compute a FRESH answer, so without this the operator
     * would confirm "47 emails" and send whatever the roster now says.
     *
     * @param  array<string, mixed>  $analysis
     */
    public static function digest(array $analysis): string
    {
        return StatePin::of(
            'invitation_bulk_resend',
            // No inputs beyond the people themselves — unlike a fill, which has a source cell.
            [],
            [],
            array_map(static fn (array $row): array => [
                (int) $row['person_id'],
                null,
                [[
                    'state' => $row['state'],
                    'invitation_id' => $row['invitation_id'],
                    'has_account' => $row['has_account'],
                    'has_email' => $row['has_email'],
                    'nameable' => $row['nameable'],
                ]],
                $row['position'] === null ? [] : [['position' => (int) $row['position']]],
            ], $analysis['rows']),
        );
    }

    /**
     * The ONE analysis. `plan()` is this with nothing written; `commit()` calls it again inside its
     * own transaction and acts on what it says. Two implementations of "what would this resend do"
     * is two answers, and the operator confirmed one of them.
     *
     * TWO QUERIES, whatever the selection size. The people (with their account existence as a
     * `withExists` alias, never a per-row `hasAccount()` — that N+1 is what P1c-2 Task 2's budget
     * case caught from an unexpected direction), and the latest invitation per person through
     * `InvitationStatus::latestPerPerson()`, which is the one definition of that precedence rule
     * and is already what the People screen renders from.
     *
     * @param  list<int>  $personIds
     * @return array<string, mixed>
     */
    private static function analyse(array $personIds): array
    {
        // Sorted and de-duplicated, so the digest is a property of the SELECTION rather than of the
        // order the browser happened to serialise a Set in. A re-ordered confirm of the same fifty
        // people is the same operation and must not be refused as a stale one.
        $ids = array_values(array_unique(array_map('intval', $personIds)));
        sort($ids);

        $people = $ids === []
            ? collect()
            : Person::withTrashed()
                ->withExists(['user as has_account'])
                ->whereIn('id', $ids)
                ->get()
                ->keyBy(fn (Person $p): int => (int) $p->getKey());

        $latest = InvitationStatus::latestPerPerson($ids);

        $rows = [];
        $context = ['people' => []];
        $willSend = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            /** @var Person|null $person */
            $person = $people->get($id);
            $invitation = $latest[$id] ?? null;

            [$outcome, $position] = self::outcomeFor($person, $invitation);

            $outcome === self::RESEND ? $willSend++ : $skipped++;

            if ($person !== null && $outcome === self::RESEND) {
                $context['people'][$id] = $person;
            }

            $rows[] = [
                'person_id' => $id,
                'outcome' => $outcome,
                'reason' => self::REASONS[$outcome] ?? null,
                // The four facts the outcome is derived from, carried so the pin covers the WORLD
                // rather than the conclusion drawn from it.
                'state' => $invitation === null ? InvitationStatus::NONE : InvitationStatus::stateOf($invitation),
                'invitation_id' => $invitation === null ? null : (int) $invitation->getKey(),
                'has_account' => $person !== null && (bool) $person->has_account,
                'has_email' => $person !== null && $person->hasEmail(),
                'nameable' => $person !== null && (bool) $person->active && ! $person->trashed(),
                'position' => $position,
                // Filled in by `commit()`; present here so the preview and the report are one shape
                // and the screen does not branch on a missing key.
                'superseded' => [],
            ];
        }

        return [
            'rows' => $rows,
            'summary' => ['selected' => count($ids), 'will_send' => $willSend, 'skipped' => $skipped],
            'context' => $context,
        ];
    }

    /**
     * One person's outcome, in precedence order, and the position a resend would carry.
     *
     * ORDER MATTERS. Being off the roster comes first because it is the fact that makes every other
     * question moot; the account comes next because "they already have one" is the answer the
     * operator most often wants; the address next, because an invitation with no address is a
     * credential delivered to nobody that still redeems; and only then the invitation's own state.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function outcomeFor(?Person $person, ?Invitation $invitation): array
    {
        if ($person === null || ! $person->active || $person->trashed()) {
            return [self::SKIPPED_INACTIVE, null];
        }

        if ((bool) $person->has_account) {
            return [self::SKIPPED_HAS_ACCOUNT, null];
        }

        if (! $person->hasEmail()) {
            return [self::SKIPPED_NO_EMAIL, null];
        }

        if ($invitation === null) {
            return [self::SKIPPED_NO_INVITATION, null];
        }

        $state = InvitationStatus::stateOf($invitation);

        if ($state === InvitationStatus::CLAIMED) {
            return [self::SKIPPED_HAS_ACCOUNT, null];
        }

        if ($state === InvitationStatus::REVOKED) {
            return [self::SKIPPED_REVOKED, null];
        }

        // OPEN or EXPIRED — the two states `InvitationController::resend()` accepts, and the two
        // the People screen offers a Resend for. The position comes from the invitation row, never
        // from the request and never from `people.position`: a resend cannot promote.
        return [self::RESEND, (int) $invitation->position];
    }
}
