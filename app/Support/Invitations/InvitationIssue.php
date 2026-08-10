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

        $superseded = self::liveFor($person, $email);

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
     * Every invitation that would still redeem for this person — the set a new one must kill.
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
     * @return Collection<int, Invitation>
     */
    private static function liveFor(Person $person, string $email): Collection
    {
        return Invitation::query()
            ->where(fn ($q) => $q
                ->where('person_id', $person->getKey())
                ->orWhere('member_email', $email))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();
    }
}
