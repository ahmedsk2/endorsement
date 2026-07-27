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
     * Authorize an action against ONE account/registration/invitation of $targetPosition.
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
