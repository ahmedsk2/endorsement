<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE ONE PREDICATE BEHIND EVERY DOOR THAT COULD LEAVE `access.manage` UNHELD (rulings 44 and 45).
 *
 * WHAT WAS MEASURED, TWICE. Ruling 44 closed the per-user OVERRIDE path by putting a guard in
 * `App\Support\CapabilityGrant::applyForUser()`. Ruling 45 then measured five more doors against
 * the tree on 2026-08-11 and found every one of them open — `PATCH /admin/users/{u}/active`,
 * `PATCH /admin/users/{u}/unbind`, `PATCH /admin/users/{u}/position`, `PATCH /admin/people/{p}`
 * and `POST /admin/people/bulk` with `set_active` each returned 302 and left
 * `AccessControl::holdersOf('access.manage')` answering NOBODY.
 *
 * ONE ROOT CAUSE, AND IT WAS NOT RULING 44'S. All five guarded on
 * `PositionChange::isLastActiveAdministrator()`, which asked about the Administrator ROLE — "is
 * another active `people.position = 0` account left". That stopped implying "somebody holds
 * `access.manage`" the day the capability became deniable per account. Deny it to a second
 * administrator and they become a PHANTOM: cover to the role question, no cover at all to the
 * capability. Both role-shaped predicates are gone; there is no Administrator-role check anywhere
 * in this codebase any more, because the role is not what any of those doors were protecting.
 *
 * WHY `access.manage` SPECIFICALLY, AND ONLY IT. It is the recovery root. Its holder can grant
 * every other capability back to anybody, including `users.manage` (which is what
 * `PositionChange::GRANT_ADMINISTRATOR_CAPABILITY` needs to make somebody an Administrator again).
 * Lose any other capability's last holder and `access.manage` can undo it from a screen; lose this
 * one and the only remaining path is direct database access, which CLAUDE.md reserves for the
 * owner — on a deployment where the owner may be the only administrator. There is no break-glass
 * path in this system, deliberately, and that is what makes the refusal below the whole recovery
 * story.
 *
 * IT ASKS THE ORACLE RATHER THAN DERIVING THE ANSWER. `AccessControl::holdersOf()` already knows
 * who effectively holds a capability — role default, then per-user grant, then per-user deny, in
 * `resolve()`'s own order — inner joins `people` (so an unbound account is excluded), respects
 * SoftDeletes (so a trashed one is), excludes inactive accounts, and is deliberately uncached. So
 * the write happens FIRST and the question is asked afterwards, inside the transaction, where the
 * oracle reads the world this request is proposing. Predicting the answer instead would be a
 * fourth copy of the resolution rules, and it would get the hazard wrong in both directions: on
 * the override path the danger is not the word "deny" (clearing a per-user grant that was
 * somebody's only claim strips the capability just as completely), and on the lifecycle paths it
 * is not the word "Administrator".
 *
 * PHRASED OVER THE HOLDER SET, NEVER OVER THE ACTOR. "Nobody is left" and "I am not left" are
 * different questions, and the second happens to answer the first today only because
 * `capabilitiesFor()` (which consults neither `users.active` nor the person link) and
 * `holdersOf()` (which consults both) agree, and nothing enforces that agreement.
 *
 * IT IS A POSTCONDITION, NOT A CAUSAL TEST — "is `access.manage` held after this write", not "is
 * this write what took it away". The distinction is only visible in a world where the capability
 * was ALREADY unheld, where the postcondition refuses a write that did not cause the problem.
 * Chosen deliberately, for three reasons, after `ChiefResidentTest` found the difference
 * empirically (its world is chiefs and residents alone, and nobody in it ever held
 * `access.manage`):
 *
 *  - IT COSTS ONE QUESTION INSTEAD OF TWO. A causal test needs the holder set read BEFORE the
 *    write as well as after, which doubles an uncached five-query answer on every guarded write —
 *    including `PersonController::bulk()`'s per-row loop.
 *  - IN AN ALREADY-UNHELD WORLD IT REFUSES EXACTLY THE WRITES THAT DO NOT FIX IT. Recovery still
 *    works, because a recovery makes the answer non-empty: promoting somebody to position 0 gives
 *    them `access.manage` by role default, and reactivating an account passes null `$couldLose`
 *    and is never asked about at all. What it refuses is deactivating, unbinding and demoting —
 *    which in that world are the operations that keep it broken.
 *  - THE REFUSAL IS THE RIGHT INSTRUCTION THERE ANYWAY. "Grant it to another active account
 *    first" is precisely what an instance with no holder needs doing.
 *
 * The trade it accepts is collateral: in that same broken world, deactivating an unrelated leaver
 * is refused too, and the operator must restore a holder before continuing. No deployment starts
 * in that state — `php artisan user:create-admin` is the bootstrap and it writes a position-0
 * account directly, reaching none of these doors.
 *
 * THE THROW IS THE ROLLBACK, which is why {@see guarding()} owns the transaction rather than
 * leaving it to each door. A refusal must not be able to land inside a transaction whose rollback
 * would take the refusal's own audit row with it (P1c-1 finding 12) — so nothing here audits, and
 * every caller writes its audit rows AFTER its outermost transaction commits, exactly as
 * `PositionChange::apply()`, `AccountUnbind::apply()`, `CapabilityGrant::applyForUser()` and
 * `PersonController::bulk()` already did for their own reasons.
 */
final class AccessManageGuard
{
    /**
     * The capability that must never run out of active holders.
     */
    public const CAPABILITY = 'access.manage';

    /**
     * Perform a write that could take somebody's `access.manage` away, and refuse it if it did.
     *
     * ONE BODY HOLDS THE WHOLE SHAPE — transaction, write, ask, throw-unwinds — because the four
     * parts only work together. A door that asked without a transaction would refuse a write it
     * had already committed; a door that opened a transaction and forgot to ask is precisely the
     * five doors ruling 45 measured. A sixth door inherits both by calling this.
     *
     * `$couldLose` IS THE COST STORY, and it is a structural fact rather than an optimisation
     * guess: `holdersOf()` INNER JOINS `people` on `users.person_id`, so a person with no account
     * contributes no holder and a write touching only their roster row cannot remove one. Passing
     * null says so, and skips both the oracle's queries and the transaction that exists only to
     * unwind its refusal. That is what keeps `App\Support\Roster\RosterImport`'s per-row loop at
     * exactly its old cost: a roster import can only ever reach a person WITHOUT an account
     * (`RosterImport::SKIP_HAS_ACCOUNT` refuses every row matching an account holder outright), so
     * it never asks the oracle at all — pinned by `AccessManageLockoutTest::
     * test_a_roster_import_never_asks_the_oracle_because_no_row_it_can_reach_has_an_account`.
     * Null is also how a caller says "this direction can only ADD holders" (reactivating an
     * account, for instance), which is true for the same reason it is cheap.
     *
     * @template TReturn
     *
     * @param  ?User  $couldLose  the account this write might remove from the holder set, or null
     *                            when no account can be affected by it
     * @param  string  $field  the validation key the calling surface binds its errors to
     * @param  callable():TReturn  $write
     * @return TReturn
     *
     * @throws ValidationException when the write would leave `access.manage` with no active holder
     */
    public static function guarding(?User $couldLose, string $field, callable $write): mixed
    {
        if ($couldLose === null) {
            return $write();
        }

        return DB::transaction(static function () use ($write, $field) {
            $result = $write();

            // The rows are now the rows this request is asking for, and nothing is committed —
            // the one moment the oracle can be asked about the world as it WOULD be.
            self::assertStillHeld($field);

            return $result;
        });
    }

    /**
     * The question itself: does `access.manage` still have an active holder?
     *
     * Separate from {@see guarding()} only so a caller already inside its own transaction can ask
     * it directly. Everything that WRITES goes through `guarding()`, so the transaction and the
     * question cannot be separated by accident.
     *
     * @throws ValidationException
     */
    public static function assertStillHeld(string $field): void
    {
        if (AccessControl::holdersOf(self::CAPABILITY) !== []) {
            return;
        }

        throw ValidationException::withMessages([
            // Naming the unlock rather than only saying no — an operator who cannot see why is an
            // operator who tries the next door along, which is how this became five doors.
            $field => "This would leave nobody holding '".self::CAPABILITY
                ."' — Admin → Access Control would be unreachable and access control could never be "
                .'edited again. Grant it to another active account first.',
        ]);
    }
}
