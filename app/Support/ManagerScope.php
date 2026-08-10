<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * WHO may act on an account of a given role — one definition, two call sites.
 *
 * Two tiers:
 *   - `users.manage` (Administrator) may act on any account.
 *   - `users.manage_residents` (Chief Resident) may act on RESIDENT accounts only, and a
 *     refusal is audited with the attempted target position.
 *
 * This lived privately inside UserManagementController until invitations needed the same
 * rule. Copying it would have been the cheaper edit and the wrong one: a duplicated
 * authorization rule that drifts is how a Chief Resident ends up able to mint an
 * Administrator through the newer of two doors. The audit-chain false alarm earlier the
 * same day came from exactly that shape of duplication.
 */
final class ManagerScope
{
    public const RESIDENT = 4;

    public static function canManageAll(?User $user): bool
    {
        return $user !== null && AccessControl::allows($user, 'users.manage');
    }

    /** The scoped (Chief Resident) tier — must hold users.manage_residents. 403 + audit otherwise. */
    public static function assertScopedManager(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && AccessControl::allows($user, 'users.manage_residents')) {
            return;
        }

        AuditLog::record('access_denied', 'cap=users.manage_residents', $user?->getKey(), $request->ip());

        abort(403);
    }

    /**
     * The same rule as `assertMayTarget()`, as a PREDICATE — for a read, not an act.
     *
     * P1c-2 Decision B: AC-02's claim-status projection has to answer "may this viewer know
     * anything about this person's account?" once per row across a whole roster. An assertion
     * that throws, audits and 403s is the wrong shape for that — sixty refusals on one page load
     * would be sixty audit rows and one aborted request.
     *
     * WHY NOT COLLAPSE THE TWO. `assertMayTarget()` keeps its own body deliberately: it writes
     * TWO DISTINCT refusal audits — `access_denied` when the capability is missing at all,
     * `user_scope_denied` when the capability is held but the target is out of tier — and that
     * distinction is the difference between "someone poked at a screen they cannot reach" and "a
     * Chief Resident tried to act on a Consultant". Expressing the assertion in terms of this
     * boolean would lose it.
     *
     * The deal is that the duplication is PROVEN rather than trusted:
     * `tests/Feature/Admin/ManagerScopeParityTest.php` runs the two over the whole matrix of
     * (capability set × position) and fails if they ever disagree. This class's own docblock
     * records what an unproven copy of this rule cost once already.
     */
    public static function mayTarget(?User $user, int $targetPosition): bool
    {
        if (self::canManageAll($user)) {
            return true;
        }

        return $user !== null
            && AccessControl::allows($user, 'users.manage_residents')
            && $targetPosition === self::RESIDENT;
    }

    /**
     * Authorize an action against ONE account/registration/invitation of $targetPosition.
     *
     * The throwing, auditing half of the pair — see `mayTarget()` above for why there are two and
     * what keeps them honest.
     */
    public static function assertMayTarget(Request $request, int $targetPosition): void
    {
        if (self::canManageAll($request->user())) {
            return;
        }

        self::assertScopedManager($request);

        if ($targetPosition !== self::RESIDENT) {
            AuditLog::record(
                'user_scope_denied',
                'target_position='.$targetPosition,
                $request->user()?->getKey(),
                $request->ip(),
            );

            abort(403, 'A Chief Resident manages resident accounts only.');
        }
    }
}
