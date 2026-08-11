<?php

namespace App\Support;

use App\Models\Person;
// Thrown from `AccessManageGuard::guarding()` below rather than constructed here — imported so
// `apply()`'s `@throws` resolves to the right class for a caller reading it.
use Illuminate\Validation\ValidationException;

/**
 * The ONE definition of "deactivate/reactivate a person" (P1c review finding 4), in
 * `App\Support\PositionChange`'s shape (Decision C).
 *
 * `people.active` governs whether a person may be NAMED; the linked account's `users.active`
 * separately governs whether they may AUTHENTICATE (D3's reversal). Before this existed there
 * were three writers of `people.active` and only `PersonController::applySetActive()` (LV-02's
 * bulk tool) kept the two in step: `PersonController::update()` wrote `active` straight through
 * `$person->update($data)`, and `Promotion::commit()`'s retire path wrote
 * `$person->update(['active' => false])` directly — neither touched the account, so a resident
 * retired through either surface stopped being named on sheets but kept the ability to log in
 * and read handover sheets carrying patient PHI.
 *
 * Carries `App\Support\AccessManageGuard`'s refusal too — the exact one
 * `UserManagementController::setActive()` applies from the account side. Deactivating the last
 * active holder of `access.manage` must be refused from EITHER console, the same way demoting them
 * already is. Until ruling 45 both guards asked about the Administrator ROLE instead, which a
 * second administrator with the capability DENIED satisfied while holding nothing.
 *
 * THE BULK PATH GETS ITS SET-AWARENESS FROM THE ORACLE, not from a second, batch-shaped predicate
 * (which is what `PositionChange::wouldLeaveNoActiveAdministrator()` was, and why it is gone).
 * `PersonController::bulk()` runs its whole loop inside ONE transaction, so each row's write is
 * visible to the next row's question: deactivating the last two holders together is refused at the
 * second one, and the throw unwinds the entire batch. The per-row check that finding 13 had to
 * work around asked "is another Administrator left BESIDES THIS ONE" and was blind to the rest of
 * the selection; this one asks about the world as the batch is actually leaving it.
 *
 * Deliberately writes NO audit row itself, unlike `PositionChange::apply()`: that class's three
 * real call sites each run once per request, but this one is also called from inside
 * `PersonController::bulk()`'s per-person loop (LV-02) — auditing here would be the same
 * per-write-inside-a-transaction problem `Promotion::commit()`'s own docblock and review finding
 * 6 both name (`AuditLog::record()` opens its own transaction and locks the chain tail, so N of
 * them nested in a loop serialises the whole chain and unwinds the batch's own trail on
 * rollback). Every caller already writes its own audit row(s) after its transaction commits
 * (`PersonController::update()`'s `person_update`, `PersonController::bulk()`'s
 * `person_bulk`/`person_bulk_item`, `PromotionController::commit()`'s
 * `person_promotion`/`person_level_change`) — Decision H's ordering, not repeated here.
 */
final class PersonStatus
{
    /**
     * `$field` is the validation key the CALLING SURFACE binds its errors to, in
     * `PositionChange::apply()`'s shape and for the same reason: the People screen's edit form
     * shows a refusal against `active`, and `PersonController::bulk()`'s selection shows one
     * against `ids`. One writer, one refusal, named where each screen is already listening —
     * rather than a second guard in the controller so the message can land in the right place.
     *
     * @throws ValidationException when this would leave `access.manage` with no active holder
     */
    public static function apply(Person $person, bool $active, string $field = 'active'): void
    {
        $user = $person->user()->first();

        AccessManageGuard::guarding(
            // Only the DEACTIVATING direction can take a holder away; reactivating can only add
            // one, and a roster-only person (no account) is never a holder at all. Both are null
            // here, which is how `guarding()` is told this write cannot cost anybody the
            // capability — no transaction and no oracle queries for either.
            $active ? null : $user,
            $field,
            static function () use ($person, $user, $active): void {
                $person->update(['active' => $active]);
                $user?->update(['active' => $active]);
            },
        );
    }
}
