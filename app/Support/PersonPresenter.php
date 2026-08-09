<?php

namespace App\Support;

use App\Models\Person;
use App\Models\PersonLevel;
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
            'joined_at' => $person->joined_at === null ? null : Calendar::ymd($person->joined_at),
        ];

        // `email` and `phone` are both contact fields and are governed by ONE decision
        // (`PersonPolicy::viewContact()`), not one governed and one forgotten. This was not
        // always so: `email` shipped ungated in P1c because every caller then held
        // `people.manage`, which is also what `viewContact()`'s first branch grants — a no-op
        // distinction. P1d's rota grid is the first consumer with a narrower capability
        // (`rota.view`, held by every resident, owner decision 2), which is exactly the case this
        // docblock predicted, so the gate is now real.
        if ($viewer !== null && $viewer->can('viewContact', $person)) {
            $base['phone'] = $person->phone;
            $base['email'] = $person->email;
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

    /**
     * LV-04's per-span history shape — newest first, the level HELD AT THE TIME (the joined row,
     * never a re-lookup of "current"), dual-dated. Every date is a `Calendar::label()` shape
     * (Gregorian + Hijri): the client performs no date arithmetic at all.
     *
     * `with('createdBy:id,person_id')`, never `with('createdBy:id,full_name')` — `full_name` is
     * a read-through accessor onto the linked Person (P0c), not a real column on `users`, so a
     * narrowed eager load that omits `person_id` makes it resolve to null with no error (the
     * defect that broke four live sites with zero test coverage before P0c's audit).
     *
     * @return list<array<string, mixed>>
     */
    public static function history(Person $person): array
    {
        return $person->levels()
            ->with(['level:id,code,name', 'createdBy:id,person_id'])
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (PersonLevel $span): array => [
                'level' => [
                    'id' => (int) $span->level_id,
                    'code' => (string) $span->level->code,
                    'name' => (string) $span->level->name,
                ],
                'from' => Calendar::label($span->effective_from),
                'to' => $span->effective_to === null ? null : Calendar::label($span->effective_to),
                'reason' => $span->reason,
                'batch' => $span->promotion_batch_id,
                'by' => $span->createdBy?->full_name,
            ])
            ->values()
            ->all();
    }
}
