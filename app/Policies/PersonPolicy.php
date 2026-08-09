<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\ContactVisibility;

/**
 * Munawib PE-02. The first policy in this codebase — `app/Policies/` did not exist before P1c.
 *
 * ABILITY NAMES ARE camelCase ON PURPOSE. `Gate::before` (AppServiceProvider.php:56) bridges the
 * capability resolver into the Gate and returns TRUE for any ability string that is a capability
 * key the user holds, returning null only on a miss. An ability named `people.manage` here would
 * therefore be short-circuited to true for every holder and this class would never run — a
 * silent authorization bypass. `ContactVisibilityTest` asserts the separation rather than trusting
 * it.
 *
 * No policy is registered in a provider: Laravel 13 discovers `App\Policies\{Model}Policy`
 * conventionally, and adding a registration array would create a second place for this to be
 * true.
 */
class PersonPolicy
{
    /**
     * A phone number. Roster managers always; any signed-in account holder only when the
     * department has opted in.
     */
    public function viewContact(User $user, Person $person): bool
    {
        return AccessControl::allows($user, 'people.manage') || ContactVisibility::membersMaySeePhone();
    }

    /**
     * Free text a supervisor wrote ABOUT this person. No department setting reveals it — see the
     * migration's own docblock and docs/COMPLIANCE.md.
     */
    public function viewNotes(User $user, Person $person): bool
    {
        return AccessControl::allows($user, 'people.manage');
    }
}
