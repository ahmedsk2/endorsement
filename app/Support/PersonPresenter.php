<?php

namespace App\Support;

use App\Models\Person;
use App\Models\User;

/**
 * The ONE place a Person becomes Inertia props (PE-02).
 *
 * `Person::$hidden = ['phone', 'notes']` is NOT the control and never was: it applies to
 * toArray()/toJson(), and every admin screen in this codebase builds its props with an explicit
 * map that bypasses it. $hidden stays as defence in depth against an accidental whole-model
 * serialisation; THIS class is what decides what a viewer may see.
 * `tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php` stops a second one appearing.
 *
 * A withheld field is ABSENT, never null. A null phone and a withheld phone are different facts,
 * and a consumer given the same shape for both will eventually render one as the other.
 *
 * Never carries `institution_id` (provenance, D11 — not a client concern) and never carries a
 * password, signature path or any `users` column: this projects a PERSON, not an account.
 */
final class PersonPresenter
{
    /**
     * @param  array<string, mixed>  $extra  task-specific keys (level, history) merged verbatim
     * @return array<string, mixed>
     */
    public static function one(Person $person, ?User $viewer, array $extra = []): array
    {
        $base = [
            'id' => (int) $person->getKey(),
            'full_name' => (string) $person->full_name,
            'short_name' => $person->short_name,
            'position' => (int) $person->position,
            'external' => (bool) $person->external,
            'active' => (bool) $person->active,
            'retired' => $person->trashed(),
            // `has_account` is set by the caller's withExists() alias; a per-row hasAccount()
            // call would be one EXISTS query per person.
            'has_account' => (bool) ($person->has_account ?? $person->hasAccount()),
            'email' => $person->email,
            'joined_at' => $person->joined_at === null ? null : Calendar::ymd($person->joined_at),
        ];

        if ($viewer !== null && $viewer->can('viewContact', $person)) {
            $base['phone'] = $person->phone;
        }

        if ($viewer !== null && $viewer->can('viewNotes', $person)) {
            $base['notes'] = $person->notes;
            $base['constraints'] = $person->constraints;
        }

        return $base + $extra;
    }

    /**
     * @param  iterable<Person>  $people
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $people, ?User $viewer): array
    {
        $out = [];

        foreach ($people as $person) {
            $out[] = self::one($person, $viewer);
        }

        return $out;
    }
}
