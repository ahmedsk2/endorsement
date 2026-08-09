<?php

namespace App\Support;

use App\Models\Person;
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
 * Carries `PositionChange::isLastActiveAdministrator()`'s guard too — the exact one
 * `UserManagementController::setActive()` already applies from the account side. Deactivating
 * the sole active Administrator must be refused from EITHER console, the same way demoting them
 * from position 0 already is.
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
     * @throws ValidationException when this would deactivate the last active Administrator
     */
    public static function apply(Person $person, bool $active): void
    {
        $user = $person->user()->first();

        if (! $active && PositionChange::isLastActiveAdministrator($user)) {
            throw ValidationException::withMessages([
                'active' => 'This is the last active Administrator — grant another account the Administrator role first.',
            ]);
        }

        $person->update(['active' => $active]);
        $user?->update(['active' => $active]);
    }
}
