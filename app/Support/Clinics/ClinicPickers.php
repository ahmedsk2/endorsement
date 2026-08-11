<?php

namespace App\Support\Clinics;

use App\Models\Level;
use App\Models\Person;
use App\Models\Unit;
use App\Support\PersonPresenter;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * WHAT THE CLINICS SCREEN MAY OFFER — one definition per field, used for BOTH the offered list and
 * the write-side rule (D9, the `App\Support\SignoffPickers` discipline applied to three smaller
 * questions).
 *
 * The failure this shape exists to remove is not hypothetical here. A bare `exists:units,id` on the
 * owning unit is green against the one unit a happy-path test uses and wrong about every other row
 * in the table: it admits a unit that does not own clinics and a unit that has been retired, both of
 * which `ClinicWriter::assertOwns()` then refuses by throwing — turning an administrator's typo into
 * a 500 instead of a message under the field. And `Rule::exists` runs on the RAW query builder,
 * which never sees Eloquent's SoftDeletes global scope, so `exists:people,id` cheerfully accepts a
 * retired person the offered list has already dropped. A predicate written once as Eloquent and once
 * as raw SQL is two predicates that drift; each one below is written ONCE, as a closure over a query
 * builder, and applied to the rule directly and to the offer query through `getQuery()`.
 *
 * THE PEOPLE OFFER IS CONTACT-FREE, whatever the viewer holds. `PersonPresenter::contactFree()`,
 * never `one($person, $viewer)` — the administrator on this screen holds `people.manage`, which is
 * one branch of `PersonPolicy::viewContact()`, and the department may be switched to the permissive
 * setting, which is the other; passing the real user therefore emits every colleague's reachable
 * details into the props of a screen that renders none of them. That is the payload disclosure P1d-2
 * Decision C closed on the rota grid, arriving here through the same door.
 *
 * IT OFFERS AND VALIDATES ONLY. Nothing here writes: `ClinicWriter` is the one writer of both clinic
 * tables, and it re-checks every id it is given anyway — a rule that passes and a writer that
 * refuses is a message the operator can read, which is the whole point of validating first.
 */
final class ClinicPickers
{
    /**
     * A clinic's owning unit is a `units` ROW and the question is asked of the COLUMN (D2) — never
     * a code list, never a `match` on a code string. A fifth department that ticks "owns clinics"
     * appears here with no change to this file.
     *
     * `units` carries no soft deletes and no `institution_id` at all (deliberate; see
     * `2026_08_09_120001`'s docblock), so this predicate is the whole question.
     *
     * @return \Closure(QueryBuilder): void
     */
    public static function unitPredicate(): \Closure
    {
        return function (QueryBuilder $query): void {
            $query->where('units.clinic_owner', true)->where('units.active', true);
        };
    }

    /** @return \Closure(QueryBuilder): void */
    public static function levelPredicate(): \Closure
    {
        return function (QueryBuilder $query): void {
            $query->where('levels.active', true);
        };
    }

    /**
     * `whereNull('people.deleted_at')` is written EXPLICITLY, because this same closure is handed to
     * `Rule::exists`, which bypasses the global scope that would otherwise apply it.
     *
     * Position is deliberately NOT part of it: CL-02's `named` mode exists for the external
     * consultant who attends a clinic and rotates nowhere (PE-03's `people.external`), and narrowing
     * to the training positions would refuse exactly the person the mode was built for.
     *
     * @return \Closure(QueryBuilder): void
     */
    public static function personPredicate(): \Closure
    {
        return function (QueryBuilder $query): void {
            $query->where('people.active', true)->whereNull('people.deleted_at');
        };
    }

    /**
     * The units a clinic may be created on or moved to, in the department's own display order.
     *
     * @return list<array{id:int, code:string, name:string, bar_class:string}>
     */
    public static function units(): array
    {
        $query = Unit::query()->ordered();
        (self::unitPredicate())($query->getQuery());

        return $query->get()->map(fn (Unit $unit): array => [
            'id' => (int) $unit->getKey(),
            'code' => (string) $unit->code,
            'name' => (string) $unit->name,
            'bar_class' => (string) ($unit->bar_class ?? Unit::DEFAULT_BAR_CLASS),
        ])->values()->all();
    }

    /**
     * @return list<array{id:int, code:string, name:string}>
     */
    public static function levels(): array
    {
        $query = Level::query()->ordered();
        (self::levelPredicate())($query->getQuery());

        return $query->get()->map(fn (Level $level): array => [
            'id' => (int) $level->getKey(),
            'code' => (string) $level->code,
            'name' => (string) $level->name,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function people(): array
    {
        $query = Person::query()->orderBy('people.full_name');
        (self::personPredicate())($query->getQuery());

        return $query->withExists(['user as has_account'])
            ->get()
            ->map(fn (Person $person): array => PersonPresenter::contactFree($person))
            ->values()
            ->all();
    }

    public static function unitRule(): Exists
    {
        return Rule::exists('units', 'id')->where(self::unitPredicate());
    }

    public static function levelRule(): Exists
    {
        return Rule::exists('levels', 'id')->where(self::levelPredicate());
    }

    public static function personRule(): Exists
    {
        return Rule::exists('people', 'id')->where(self::personPredicate());
    }
}
