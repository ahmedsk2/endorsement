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
 * the result for CACHE_TTL (600s). It is also what `isLastActiveAdministrator()` guards.
 *
 * Before P1c there was one writer (`UserManagementController::setPosition()`) and it handled
 * both. P1c adds a second surface — the People screen — and a second writer would be a
 * ten-minute privilege-retention window plus a route around the last-admin guard, with no test
 * anywhere that would notice. Both surfaces call this.
 *
 * The last-administrator guard is a PERSON-level question asked through the account: only a
 * claimed, active account can hold `access.manage` in the first place (a roster-only person has
 * no `users` row for `AccessControl::resolve()` to key off — design §5.2 mitigation 6).
 */
final class PositionChange
{
    /**
     * @throws ValidationException when this would demote the last active Administrator
     */
    public static function apply(Person $person, int $position, Request $request, string $field = 'position'): void
    {
        $user = $person->user()->first();

        if ($position !== 0 && self::isLastActiveAdministrator($user)) {
            throw ValidationException::withMessages([
                $field => 'This is the last active Administrator — grant another account the Administrator role first.',
            ]);
        }

        $person->update(['position' => $position]);

        // Only a claimed account has a cached capability set to bust.
        if ($user !== null) {
            AccessControl::flush((int) $user->getKey());
        }

        AuditLog::record(
            'user_role_change',
            'person='.$person->getKey().';user='.($user?->getKey() ?? 'none').';position='.$position,
            $request->user()?->getKey(),
            $request->ip(),
        );
    }

    /**
     * True when this account is an ACTIVE Administrator and no OTHER active Administrator exists.
     *
     * `whereKeyNot` is avoided: it filters the unqualified `id`, ambiguous once `people` is
     * joined (both tables have one). Moved here verbatim from UserManagementController, which now
     * calls through — see PositionChangeTest's delegation assertion.
     */
    public static function isLastActiveAdministrator(?User $user): bool
    {
        if ($user === null || (int) $user->position !== 0 || ! $user->active) {
            return false;
        }

        return ! User::query()
            ->join('people', 'people.id', '=', 'users.person_id')
            ->where('users.id', '!=', $user->getKey())
            ->where('people.position', 0)
            ->where('users.active', true)
            ->exists();
    }

    /**
     * The SET-AWARE sibling of {@see isLastActiveAdministrator()} (P1c finding 13) — extending
     * the one definition rather than writing a second, per Decision B.
     *
     * The per-row check asks "is there ANOTHER active Administrator besides THIS ONE" — true for
     * every one of the last two Administrators checked individually, so a loop that applies
     * `isLastActiveAdministrator()` row by row lets a bulk deactivation of the last N accounts
     * through one row at a time, each seeing the others as cover, and empties the Administrator
     * set permanently. This asks the question for the WHOLE batch at once, in one query, called
     * BEFORE any row in the batch is written: excluding every account this one batch is about to
     * deactivate, does any active Administrator remain?
     *
     * @param  list<int>  $userIds  every account this one batch is about to deactivate — accounts
     *                              only; a person with no linked account has none to exclude and
     *                              carries no capability set to lose
     */
    public static function wouldLeaveNoActiveAdministrator(array $userIds): bool
    {
        if ($userIds === []) {
            return false;
        }

        // `position` was moved off `users` onto `people` (2026_08_10_120003) — `$user->position`
        // is a read-through accessor, not a real column, so a raw query predicate must join
        // `people` the same way `isLastActiveAdministrator()` above does.
        return ! User::query()
            ->join('people', 'people.id', '=', 'users.person_id')
            ->whereNotIn('users.id', $userIds)
            ->where('people.position', 0)
            ->where('users.active', true)
            ->exists();
    }
}
