<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The ONE definition of "change a person's job position" (P1c Decision C).
 *
 * `people.position` drives the capability set: `AccessControl::resolve()` reads
 * `$user->position`, a read-through accessor onto this column, and `capabilitiesFor()` caches
 * the result for CACHE_TTL (600s). A position change can therefore take `access.manage` away from
 * its last holder, which is why `write()` runs through `App\Support\AccessManageGuard` (ruling 45).
 *
 * Before P1c there was one writer (`UserManagementController::setPosition()`) and it handled
 * both. P1c adds a second surface — the People screen — and a second writer would be a
 * ten-minute privilege-retention window plus a route around the last-admin guard, with no test
 * anywhere that would notice. Both surfaces call `apply()`, which writes, guards, flushes AND
 * audits. `App\Support\Roster\RosterImport` (a third writer, review finding 6) calls
 * `applyWithoutAudit()` instead — the SAME write, guard and flush, but leaves auditing to the
 * caller, because that one runs inside a per-row loop rather than once per request.
 *
 * The last-administrator guard is a PERSON-level question asked through the account: only a
 * claimed, active account can hold `access.manage` in the first place (a roster-only person has
 * no `users` row for `AccessControl::resolve()` to key off — design §5.2 mitigation 6).
 */
final class PositionChange
{
    /** The Administrator role. Placing anybody here is the ACCOUNT console's act, not the roster's. */
    public const ADMINISTRATOR = 0;

    /**
     * The capability that may place somebody at {@see ADMINISTRATOR} — `users.manage`, the account
     * console's own, NOT the roster's `people.manage`.
     *
     * WHY THIS EXISTS (adversarial review F2). P1c-2 Decision F gated the People screen's roles
     * panel on `access.manage` rather than on that route's `people.manage`, reasoning that hanging
     * a role control off the roster gate would create a privilege-escalation path. The gate is
     * right; the premise was already false. `PersonRequest::POSITIONS` offers 0, position 0 holds
     * `access.manage` by seeded role default, and `AccessControl::resolve()` reads
     * `people.position` — so a `people.manage` holder promoted their own roster row, had their
     * capability cache flushed for them by the write below, and held the security console. The
     * path was one field to the left of the panel the decision was about.
     */
    public const GRANT_ADMINISTRATOR_CAPABILITY = 'users.manage';

    /** May this actor place somebody AT `ADMINISTRATOR` who is not already there? */
    public static function mayGrantAdministrator(?User $actor): bool
    {
        return $actor !== null && AccessControl::allows($actor, self::GRANT_ADMINISTRATOR_CAPABILITY);
    }

    /**
     * The positions this actor may PLACE somebody at — the OFFER half of the gate in `write()`,
     * so a screen never presents a role the endpoint would refuse (D9 applied to a select).
     *
     * It narrows a CATALOG rather than owning one: the caller passes whatever list of positions it
     * was going to render, and gets back the subset that is assignable. The full catalog is still
     * needed alongside it — it is what renders an existing Administrator's role NAME, and a screen
     * that dropped position 0 outright would show "Role 0" on every administrator's row.
     *
     * @param  list<int>  $catalog
     * @return list<int>
     */
    public static function grantableBy(?User $actor, array $catalog): array
    {
        if (self::mayGrantAdministrator($actor)) {
            return array_values($catalog);
        }

        return array_values(array_filter(
            $catalog,
            static fn (int $position): bool => $position !== self::ADMINISTRATOR,
        ));
    }

    /**
     * @throws ValidationException when this would demote the last active Administrator, or when
     *                             the actor may not place somebody at Administrator
     */
    public static function apply(Person $person, int $position, Request $request, string $field = 'position'): void
    {
        $user = self::write($person, $position, $request->user(), $field);

        AuditLog::record(
            'user_role_change',
            'person='.$person->getKey().';user='.($user?->getKey() ?? 'none').';position='.$position,
            $request->user()?->getKey(),
            $request->ip(),
        );
    }

    /**
     * The write, guard and capability-cache flush ALONE — no audit row. For a bulk caller
     * (review finding 6, `App\Support\Roster\RosterImport`) that must audit AFTER its own
     * transaction commits rather than nested inside a per-row loop: `AuditLog::record()` opens
     * its own transaction and locks the chain tail, so N of them inside a loop serialises the
     * whole chain for the batch's duration — the exact reason `LevelAssignment::assign()` and
     * `Promotion::commit()` already write no audit row themselves (Decision H).
     *
     * `$actor` is REQUIRED here even though nothing is audited: the Administrator gate below is an
     * authorization question, and a bulk caller is not exempt from it — `App\Support\Roster\
     * RosterImport` runs from a `cap:people.manage` route and resolves its position column by NAME
     * against the `positions` table, so a CSV cell reading "Administrator" is valid input that
     * never passes a FormRequest rule about positions at all. Positional and non-optional so a
     * future caller cannot omit it and silently skip the gate.
     *
     * @throws ValidationException when this would demote the last active Administrator, or when
     *                             the actor may not place somebody at Administrator
     */
    public static function applyWithoutAudit(Person $person, int $position, ?User $actor, string $field = 'position'): void
    {
        self::write($person, $position, $actor, $field);
    }

    /**
     * @throws ValidationException when this would leave `access.manage` with no active holder, or
     *                             when the actor may not place somebody at Administrator
     */
    private static function write(Person $person, int $position, ?User $actor, string $field): ?User
    {
        self::assertMayPlaceAtAdministrator($person, $position, $actor, $field);

        $user = $person->user()->first();

        // THE GUARD IS ASKED ON EVERY POSITION CHANGE, in either direction, and that is
        // deliberate (ruling 45). Its predecessor `isLastActiveAdministrator()` skipped the check
        // whenever the target position was 0, reasoning that arriving at Administrator cannot cost
        // anybody the role. True of the role, and not the question: what must survive is
        // `access.manage`, whose holders are computed from role defaults AND per-user overrides,
        // so "position 0 always holds it" is a property of `role_capabilities` rows that a seeder
        // or a future migration could differ on. A guard that fires only on some writes is the
        // shape this whole fix exists to remove, and it costs the same either way.
        //
        // `$user` is null for a roster-only person, which the guard reads as "this write cannot
        // remove a holder" — see AccessManageGuard::guarding(). That is what keeps RosterImport's
        // per-row loop free of the oracle entirely.
        AccessManageGuard::guarding($user, $field, static function () use ($person, $position): void {
            $person->update(['position' => $position]);
        });

        // Only a claimed account has a cached capability set to bust.
        if ($user !== null) {
            AccessControl::flush((int) $user->getKey());
        }

        return $user;
    }

    /**
     * Refuse a write that would PLACE somebody at Administrator when the actor may not.
     *
     * IT IS THE TRANSITION THAT IS GATED, NOT THE VALUE, and that distinction is deliberate. The
     * People screen's edit form submits the position it was rendered with, so refusing every write
     * carrying 0 would leave every administrator's roster row unsaveable by the roster console
     * that exists to edit rows — a `people.manage` holder could no longer correct an
     * administrator's phone number or level. What they may not do is place somebody at 0 who is not
     * already there.
     *
     * A CREATE IS A PLACEMENT. `PersonController::store()` and `RosterImport::applyCreate()` both
     * `Person::create()` with the position already in the insert and then call through here, so
     * `getOriginal('position')` would report 0 for a row that was 0 for the first time a
     * millisecond ago. `wasRecentlyCreated` is what tells those two cases apart.
     *
     * NO AUDIT ROW. It throws, and a throw from inside `PersonController`'s transaction (or
     * `RosterImport`'s) rolls back — an audit row written here would unwind with it and the attempt
     * would vanish from the trail, which is the exact defect P1c-1 finding 12 records.
     * `App\Support\AccessManageGuard` refuses the same way and for the same reason.
     *
     * @throws ValidationException
     */
    private static function assertMayPlaceAtAdministrator(
        Person $person,
        int $position,
        ?User $actor,
        string $field,
    ): void {
        if ($position !== self::ADMINISTRATOR) {
            return;
        }

        $alreadyThere = ! $person->wasRecentlyCreated
            && (int) $person->getOriginal('position') === self::ADMINISTRATOR;

        if ($alreadyThere || self::mayGrantAdministrator($actor)) {
            return;
        }

        throw ValidationException::withMessages([
            // The refusal names the unlock rather than only saying no — an operator who cannot see
            // why is an operator who tries the import next.
            $field => 'Only an account holding “'.self::GRANT_ADMINISTRATOR_CAPABILITY
                .'” may make somebody an Administrator. Ask one to do it from Admin → Users.',
        ]);
    }

    /*
     * `isLastActiveAdministrator()` AND `wouldLeaveNoActiveAdministrator()` WERE REMOVED HERE
     * (ruling 45, 2026-08-11) — do not reintroduce either, nor a third predicate shaped like them.
     *
     * Both asked about the Administrator ROLE: "is another active `people.position = 0` account
     * left", per-row and per-batch respectively. That question implied "somebody holds
     * `access.manage`" only until the capability became deniable per account (P1c-2, owner
     * decision 2), after which a second administrator denied the capability was cover to the role
     * question and no cover at all to the real one. Five doors guarded on them and all five were
     * measured emptying `holdersOf('access.manage')` with a 302.
     *
     * They are gone rather than kept beside `App\Support\AccessManageGuard` because there was no
     * remaining consumer that wanted the role question for its own sake — every caller was a
     * lockout guard asking the wrong one — and two predicates answering NEARLY the same question
     * is the drift this codebase keeps paying for (the audit canonical string, the picker
     * predicates). A role-shaped check left lying about is also an invitation for the next door to
     * ask it again, which is exactly how this became five.
     *
     * `assertMayPlaceAtAdministrator()` above is NOT that check and stays: it is an authorization
     * question about the ACTOR (who may hand out position 0), not a survival question about the
     * set. `AccessControlController::assertNoSelfLockout()` also stays, guarding a third thing
     * again — that the position-0 role DEFAULT keeps `access.manage` — on the role matrix.
     */
}
