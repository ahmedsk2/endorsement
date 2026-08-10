<?php

namespace App\Support\Invitations;

use App\Models\Invitation;
use App\Models\Person;
use App\Support\ManagerScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONE writer of `invitations` (P1c-2 Decision C). `InvitationWritersAreSingularTest` proves it,
 * the same way `RotaWritersAreSingularTest` proves it for `master_rota_assignments`.
 *
 * WHY A RESEND ROTATES THE TOKEN. An invitation is a BEARER CREDENTIAL: whoever holds the URL
 * creates the account it names, with the role the row carries. A resend exists precisely because
 * the first link was lost, aged out, or went somewhere it should not have — a forwarded email, a
 * shared inbox, a mailbox somebody else now reads. Re-mailing the same token would extend the life
 * of a credential that may already be in the wrong hands, and it would make revoking the first link
 * meaningless, because the "revoked" one and the "new" one would be the same secret. So issuing and
 * reissuing are the SAME act down to the last line: mint a new token, kill every live one.
 *
 * WHY THE SUPERSEDED ROW IS KEPT. `invitations` records revocation rather than deleting, and this
 * follows it. Who was invited, by whom, and what became of it is the history AC-03 exists to
 * preserve; it is also the only evidence available if a link is later found somewhere it should not
 * be, and "there used to be a row here" is not evidence of anything. Rows therefore accumulate
 * slightly faster than before — `invitations` still has no retention rule (design §14 item 7), and
 * this class does not invent one: a disposal policy chosen by a writer is a disposal policy nobody
 * reviewed.
 *
 * IT DOES NOT MAIL AND IT DOES NOT AUDIT. Both belong to the caller, because the bulk path
 * (LV-02) needs a different ordering for each — one summary audit row rather than a pair per
 * person, and every send AFTER the transaction commits, since mail cannot be rolled back. A writer
 * that decided either could not serve both callers, and the second caller would quietly grow its
 * own copy of this one.
 *
 * THE WHOLE SUPERSEDED SET IS AUTHORIZED BEFORE ANYTHING IS MUTATED. That ordering is the point,
 * not a detail: a blanket update was neither authorized nor audited once, and a Chief Resident
 * inviting an address that already held a Consultant's invitation would have cancelled it — an act
 * they cannot perform through the revoke route at all.
 */
final class InvitationIssue
{
    /**
     * Mint one invitation for this person, killing every live one they already hold.
     *
     * `$position` is the role the invitation authorizes — validated against
     * `InvitationController::OFFERABLE` on the invite path and taken from the superseded row on the
     * resend path. It is never read from a resend request: a resend that could re-position would be
     * a promotion with none of `users.position`'s gate and no trace of having happened.
     *
     * It is NOT, however, the position this act is authorized against — see the assertion below.
     *
     * @return array{invitation: Invitation, link: string, superseded: list<int>}
     */
    public static function issue(Request $request, Person $person, int $position): array
    {
        $email = Person::normalizeEmail($person->email);

        if ($email === null) {
            // A programming error, not a user path: every caller validates an address first. It
            // throws rather than inventing one, because `invitations.member_email` is NOT NULL and
            // a blank row would be a credential addressed to nobody that still redeems.
            throw new InvalidArgumentException('An invitation needs an address to be delivered to.');
        }

        // THE BOUND PERSON'S POSITION IS THE ONE THIS IS AUTHORIZED AGAINST — not the invitation's.
        //
        // Every endpoint above this used to authorize `invitations.position` and nothing else, and
        // that is the wrong number. Redemption takes `InvitationAcceptController`'s
        // `person_id !== null` branch for every row this system mints, and that branch does not
        // write `position` — deliberately: `people.position` has ONE writer (`PositionChange`), and
        // an invitee must not be able to re-rank the roster row they are claiming. So the account
        // that comes out resolves its capabilities from the PERSON (`$user->position` is a
        // read-through accessor onto `people`, and `AccessControl::resolve()` joins
        // `role_capabilities` on it) while the check that approved it read a column nothing
        // downstream consults.
        //
        // The two disagree without anybody misusing anything: an administrator invites a new joiner
        // at 4, later corrects that roster row to 0 on the People screen — a correction that must
        // not reach into `invitations`, and does not — and the live invitation still reads 4. A
        // Chief Resident may target 4, so resend, bulk resend and (through `Person::matchByEmail()`)
        // invite would all have handed them a link that mints an Administrator.
        //
        // ASSERTED HERE, AT THE ONE WRITER, rather than at the three endpoints: three copies of an
        // authorization rule that drift is what `ManagerScope`'s own docblock records having cost
        // once already. On `InvitationController::store()`'s create branch the person is opened at
        // exactly `$position`, so this is a no-op there — which is what lets one check cover all
        // three doors without narrowing any of them. `$position` itself stays authorized by the
        // callers (a request-supplied one on invite, the row's own on resend and bulk).
        //
        // BEFORE THE TRANSACTION, like the supersede loop below and for the same reason:
        // `assertMayTarget()` audits its refusal and then aborts, and inside a transaction that
        // `user_scope_denied` row would unwind with the abort — the attempt would vanish from the
        // trail (P1c-1 finding 12).
        ManagerScope::assertMayTarget($request, (int) $person->position);

        $superseded = self::supersededBy([$person]);

        foreach ($superseded as $old) {
            ManagerScope::assertMayTarget($request, (int) $old->position);
        }

        $actor = $request->user();

        // One transaction: a supersede that committed without its replacement would leave the
        // person with no live link at all and no record of why.
        [$invitation, $token] = DB::transaction(function () use ($superseded, $person, $position, $actor, $email): array {
            foreach ($superseded as $old) {
                $old->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor?->getKey(),
                ])->save();
            }

            return Invitation::issue($email, $position, $actor, $person);
        });

        return [
            'invitation' => $invitation,
            // The plaintext token exists for exactly one moment — this return value — and is never
            // stored, logged or audited. It is handed back inside the LINK rather than on its own,
            // so no caller is offered a bare secret it might be tempted to put somewhere.
            'link' => route('invitation.show', ['token' => $token]),
            'superseded' => $superseded->map(fn (Invitation $old): int => (int) $old->getKey())->values()->all(),
        ];
    }

    /**
     * Every invitation that would still redeem for these people — **the set this operation will
     * supersede**, and therefore the set an authorization pass must approve before any of it is
     * written.
     *
     * ONE DEFINITION, TWO CALLERS (review finding F4). `issue()` below uses it to know what to
     * revoke; `BulkResend::positionsToAuthorize()` uses it to know what the controller must
     * authorize BEFORE `commit()` opens its transaction. Those two used to be separate queries and
     * they diverged in both directions, each way with its own failure:
     *
     *  - The pre-authorization pass matched `whereIn('person_id', …)` only, so an invitation
     *    reachable along the ADDRESS axis was never authorized up front. The assertion inside this
     *    class then fired from INSIDE the transaction, and the `user_scope_denied` row it wrote
     *    rolled back with the abort — the refusal happened and the trail did not record it, which
     *    is P1c-1 finding 12 reached through a different door.
     *  - The pre-authorization pass had no expiry filter, so a row that had merely aged out — one
     *    this method will not touch, will not revoke and does not need approving — refused a batch
     *    the operator was entitled to run.
     *
     * MATCHED BY PERSON **OR** ADDRESS, and both halves are load-bearing.
     *
     * By address, because that is the credential's delivery target and two rows for one address are
     * two live links. By person, because `invitations.member_email` is frozen at send time while
     * `people.email` can be corrected afterwards (Decision G) — so an address-only match would
     * leave a live link addressed to the OLD mailbox every time somebody's address is fixed and
     * their invitation resent, which is exactly the case a resend is most often reaching for.
     *
     * EXPIRY IS PART OF THE PREDICATE. The pass this replaced tested `accepted_at` and `revoked_at`
     * and not the clock, so re-inviting somebody also stamped `revoked_at` and
     * `revoked_by_user_id` on an invitation that had simply aged out — rewriting "this expired" as
     * "a person killed this" in the very projection AC-02 renders, and disagreeing with
     * `InvitationController::revoke()`, which has always treated a spent or expired row as a no-op.
     * The three conditions here are `Invitation::isOpen()`, which is what "one live link" means.
     *
     * SET-WISE, IN ONE QUERY, whatever the selection size — a bulk caller asking this per person
     * would pay fifty queries for a cohort of fifty, and `BulkResend` is built around a query cost
     * that does not move with the size of the department.
     *
     * @param  iterable<Person>  $people
     * @return Collection<int, Invitation>
     */
    public static function supersededBy(iterable $people): Collection
    {
        $ids = [];
        $emails = [];

        foreach ($people as $person) {
            $ids[] = (int) $person->getKey();

            // Normalised the same way `issue()` normalises before minting, because that is the form
            // the address was frozen into. A person with no address contributes no address axis —
            // never a `null` in the IN list, which would match nothing and cost a bound parameter.
            $email = Person::normalizeEmail($person->email);

            if ($email !== null) {
                $emails[] = $email;
            }
        }

        if ($ids === []) {
            return new Collection;
        }

        return Invitation::query()
            ->where(function ($q) use ($ids, $emails) {
                $q->whereIn('person_id', $ids);

                if ($emails !== []) {
                    $q->orWhereIn('member_email', array_values(array_unique($emails)));
                }
            })
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();
    }
}
