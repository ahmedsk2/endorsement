<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\HandoverSignoff;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * AC-03 — the ONE definition of "retire an ACCOUNT" (P1c-2 Decision E, owner decision 3).
 *
 * The account is never deleted. `users.id` is the actor foreign key on `audit_log` and the
 * signer foreign key on a day's attestation, so removing one degrades the trail that exists to
 * say who did what to a child's record. Unbinding clears the person link, deactivates the
 * account so nobody can log in, and keeps the row as history.
 *
 * DEACTIVATE AND UNBIND ARE ONE ATOMIC ACT, never one without the other. `$user->full_name` and
 * `$user->position` are read-through accessors onto the linked `Person` (P0c), so an
 * active-but-unbound account is nameless and positionless on every screen with no error
 * anywhere, and `AccessControl::resolve()` joins the role defaults on a null position.
 * `UserManagementController::setActive()` refuses to reactivate one for the same reason.
 *
 * DISTINCT FROM `PersonStatus::apply()`, WHICH IS THE OTHER HALF OF THE VOCABULARY. That one
 * deactivates a PERSON: it writes `people.active` and the linked `users.active` together, and is
 * guarded by `PersonActiveHasOneWriterTest`. This one retires an ACCOUNT: it writes
 * `users.active` and the person link, and touches `people` NOT AT ALL. They are not the same act
 * — a colleague can turn over to a new account (a hospital address change, a returning locum)
 * while remaining a perfectly active member of the roster the department still schedules — so
 * conflating them would take that person off the rota. Being distinct, it gets its own
 * source-level guard in the same house style (`AccountLinkHasOneWriterTest`), and
 * `PersonActiveHasOneWriterTest` needs no entry for this file, which is what proves the two
 * definitions are really disjoint rather than merely differently named.
 *
 * CAPABILITY GRANTS GO DORMANT WITH THE ACCOUNT AND ARE NOT RESTORED. Owner decision 2 keeps
 * grants keyed to `user_capabilities.user_id`. The override rows are kept — they are history,
 * and deleting them would be "auto-restore on re-bind" inverted — but they cannot be exercised,
 * because the account cannot log in and cannot be reactivated. A colleague who leaves and later
 * returns does so on a NEW account and an administrator grants their roles again, deliberately.
 * There is no rebind action anywhere in this codebase, and adding one would silently reattach a
 * departed administrator's grants to whoever claims that identity next.
 */
final class AccountUnbind
{
    /**
     * Snapshot, clear, deactivate — one transaction — then flush the capability cache and audit.
     *
     * The audit row is written HERE rather than by the caller, which is what the `$actor`/`$ip`
     * parameters are for: this is `PositionChange::apply()`'s shape (write, guard, flush, audit),
     * and the alternative — a caller-written row — would leave both parameters declared and never
     * read, which is precisely the defect P1c-2 finding 4 recorded against
     * `openInvitations()`'s unused `?User $viewer`. It is NOT `PersonStatus::apply()`'s shape,
     * which deliberately audits nothing because it runs inside a per-person bulk loop; unbinding
     * is one act per request. The count is still returned, because a caller that wants to say
     * what happened must get it from the writer's own answer rather than re-derive it after the
     * rows have already changed.
     *
     * Auditing happens AFTER the commit: `AuditLog::record()` opens its own transaction and locks
     * the chain tail, so a row nested inside this one would serialise the chain and, on a
     * rollback, erase the record of the attempt along with the attempt.
     *
     * @return int the number of signoffs whose signer name was snapshotted
     *
     * @throws ValidationException when the account is already unbound, or when unbinding it would
     *                             leave `access.manage` with no active holder
     */
    public static function apply(User $user, User $actor, ?string $ip): int
    {
        $personId = $user->person_id;

        // `AccessManageGuard::guarding()` owns the transaction (ruling 45). The refusal it may
        // raise has to unwind the snapshot and the link-clearing below, and a guard that asked
        // BEFORE the write could not see the world the unbind is leaving — an unbound account
        // drops out of `holdersOf()`'s inner join on `people`, which is precisely what makes this
        // door a lockout. Its predecessor here asked whether another active Administrator existed,
        // which a second administrator holding a per-user DENY satisfied while holding nothing.
        $snapshotted = AccessManageGuard::guarding($user, 'unbind', function () use ($user): int {
            $person = $user->person;

            if ($person === null) {
                throw ValidationException::withMessages([
                    'unbind' => 'This account is already unbound from its person.',
                ]);
            }

            // THE PART THAT MAKES THIS DANGEROUS RATHER THAN ROUTINE, and it must happen BEFORE
            // the link is cleared, inside this same transaction.
            //
            // `handover_signoffs.signed_off_by_name` was added additive-and-nullable on
            // 2026-07-27 and deliberately NOT backfilled — its own migration docblock says so.
            // The sheet reads the snapshot first and falls back to the relation, and that
            // fallback resolves the signer's name through this very link. So for every handover
            // signed before that date, clearing the link blanks "Signed off … by …" — which is
            // exactly the failure the freeze migration exists to prevent, reached through a
            // different door, and under the 2026-07-27 signature ruling that line is the whole
            // attestation wherever a signature was withheld.
            //
            // This writes a currently-null column with THE VALUE THE SHEET ALREADY RENDERS ON
            // THOSE ROWS TODAY. It preserves evidence; it does not alter it. `whereNull` is what
            // keeps it a snapshot rather than a rewrite: a row that already carries a frozen name
            // keeps the name it was signed with, whatever the person has since been renamed to.
            //
            // Both alternatives were worse. Refusing to unbind an account with un-snapshotted
            // signoffs blocks turnover permanently — the thing AC-03 exists to enable — and
            // unbinding without this silently destroys attribution on medico-legal evidence.
            //
            // `withTrashed()`: nothing in this codebase soft-deletes a signoff today, but a
            // snapshot that quietly skipped a row because of a global scope would be a snapshot
            // that missed evidence, and the scope is not what should be deciding that.
            //
            // On a deployment younger than 2026-07-27 this matches zero rows and is a no-op —
            // which is why its test CONSTRUCTS an un-snapshotted row rather than assuming one.
            $snapshotted = HandoverSignoff::withTrashed()
                ->where('signed_off_by_user_id', $user->getKey())
                ->whereNull('signed_off_by_name')
                ->update(['signed_off_by_name' => $person->full_name]);

            $user->forceFill(['person_id' => null, 'active' => false])->save();

            return $snapshotted;
        });

        AccessControl::flush((int) $user->getKey());

        AuditLog::record(
            'account_unbound',
            'user='.$user->getKey().';person='.$personId.';signoffs_snapshotted='.$snapshotted,
            $actor->getKey(),
            $ip,
        );

        return $snapshotted;
    }
}
