<?php

namespace App\Support\Clinics;

use App\Models\Clinic;
use App\Models\ClinicAttendee;
use App\Models\MasterRotaAssignment;
use App\Models\Person;
use App\Support\Calendar;
use App\Support\PersonPresenter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * THE ONE RESOLVER of "who does this clinic come down to on this date" (Munawib CL-02, P1e
 * Decision B).
 *
 * ATTENDANCE IS DERIVED, NEVER STORED. `clinic_attendees` holds the RULE; this class turns that
 * rule plus the master rota into people, on a date, at read time. Nothing writes a roster
 * anywhere, so moving the rota moves the clinic with no write at all — and there is no hook into
 * `RotaAssignment`, `RotaFill` or `RotaImport`, which would each have been a second writer of
 * `clinic_attendees` (a single-writer guard that passes while the writer of table A writes table B
 * has stopped meaning anything).
 *
 * A snapshot was the alternative and it has no honest answer to one question: a clinic is a WEEKLY
 * RECURRENCE WITH NO DATE OF ITS OWN, so a stored roster would be a snapshot as of *what day*?
 * Every wrong answer to that is invisible. The same call was already made once here — `vacations`
 * carries no `period_id`, because "which period(s) it touches is a range intersection computed at
 * read time, never a foreign key" — and a clinic outlives a rota year the same way.
 *
 * THE LEVEL IS THE ONE HELD ON THAT DATE, never the current one. A resident promoted in January is
 * an R2 at October's clinic and an R3 at March's; resolving `levelAt()` with no date reports the
 * whole year under the later level and quietly ages a cohort. This is `AvailabilitySummary`'s "the
 * level is the cell's, never the row's" property, asked of a day instead of a period.
 *
 * IT SUBTRACTS NO LEAVE AND MUST NEVER BECOME AN ELIGIBILITY COMPUTATION. A person booked off that
 * day is returned exactly like anybody else, with no extra field and no flag — this class does not
 * query the leave table at all. CL-04 (personal schedules, the on-now board, "who can cover
 * Tuesday") is P3 and MR-04 (the rota driving on-call) is Stage 2; NOTHING in the clinic module
 * infers either, and `ClinicHooksTest` asserts that at source level rather than trusting this
 * paragraph. It is stated here because this class is precisely what a future implementer would
 * reach into to answer those questions, and answering them here would mean clinics had quietly
 * begun deciding who may work.
 *
 * RESOLUTION IS NOT AUTHORIZATION. A deactivated clinic, and a clinic whose owning unit has since
 * been retired, both still resolve: the map decides what to show, this answers what was asked.
 *
 * NO CONTACT DETAIL REACHES THE OUTPUT, FOR ANY VIEWER — `PersonPresenter::contactFree()`, and
 * this function takes NO viewer at all, so a later caller cannot pass one and expect it to mean
 * something. That is deliberately stronger than gating on what the viewer holds:
 * `PersonPolicy::viewContact()` is true for a `people.manage` holder outright and for EVERY
 * signed-in member on a department that has opted in, so passing the real user made the rota grid
 * ship every colleague's contact detail in its Inertia props with nothing rendering it (P1d-2
 * Decision C, a live disclosure). `clinics.view` will be seeded to every position, so this output
 * reaches the whole department; it is the same shape and it gets the same answer.
 *
 * THE QUERY COUNT DOES NOT GROW WITH THE ROSTER. `ClinicRosterTest::test_the_query_count_is_
 * bounded` pins it on a POPULATED unit, because a bound measured on an empty one only ever proves
 * the empty case. Named traps, each a defect this codebase has already paid for once:
 *  - `Person::levelAt()` per person → one query per row. Solved by `Person::levelSpansBetween()`
 *    fetched ONCE for the whole set, then `Person::levelFromSpans()` resolved in memory.
 *  - `$assignment->unit` per span → one query per span. Nothing here touches that relation: the
 *    unit is already known, because it is what the span query filtered on.
 *  - `PersonPresenter` calling `hasAccount()` per row when the caller forgot `withExists()` → one
 *    EXISTS per row. The person query below carries `withExists(['user as has_account'])`.
 *  - A narrowed `select()`/`pluck()` on the person query → `full_name` and `position` are
 *    read-through accessors onto the linked roster row (P0c), so a projection that drops the
 *    linking column makes them resolve to NULL with no error and no exception. The query below
 *    fetches WHOLE `Person` models for exactly that reason. (The `pluck()` on the assignment
 *    query is a different thing entirely: it reads a foreign key off a rota row, not a person.)
 */
final class ClinicRoster
{
    /** Resolved from the master rota — `rotators` and `levels` mode both. */
    public const VIA_ROTATION = 'rotation';

    /** Named on the clinic itself; the rota was never consulted. */
    public const VIA_NAMED = 'named';

    /**
     * Everyone this clinic comes down to on `$date`, ordered by name.
     *
     * `$date` is Y-m-d and is normalised through the one date module before anything compares it.
     * Leniency here would be silent rather than loud: '2026-7-3' sorts below every stored bound,
     * so an unnormalised string resolves to an empty clinic that reads as a quiet Tuesday. The
     * COMPARISONS themselves are then plain bounds on the stored dates — lexicographic order on
     * Y-m-d is chronological order, so no second conversion happens anywhere below.
     *
     * BOTH BOUNDS INCLUSIVE, the idiom `Period::contains()` and `Person::levelAt()` already share:
     * a span that runs to 14 July still puts its person on the 14 July clinic.
     *
     * A person no longer on the active roster is RETURNED AND FLAGGED `stale`, not dropped. People
     * are deactivated (and soft-deleted), never erased, so "assigned in March, left in April" is a
     * state the system reaches on its own — and their span really is still on the rota. Dropping
     * them silently hides a cell somebody has to clear; counting them as attending reads as cover
     * that is not there. Flagging them is the same answer P1d-2 Decision D gave the rota grid.
     *
     * @return list<array<string, mixed>> each `PersonPresenter::contactFree()` plus `via` and
     *                                    `stale`
     *
     * @throws \InvalidArgumentException on a date that is not a plain Y-m-d
     */
    public static function forDate(Clinic $clinic, string $date): array
    {
        $on = Calendar::ymd($date);
        $mode = (string) $clinic->attendee_mode;

        // `rotators` needs no rule rows and has none by construction (the writer refuses them), so
        // the default mode — CL-02's own default, and the one the map will ask for most — pays for
        // no query here at all.
        $rules = $mode === Clinic::MODE_ROTATORS
            ? new EloquentCollection
            : $clinic->attendees()->get();

        if ($mode === Clinic::MODE_NAMED) {
            return self::project(
                self::peopleByIds(self::idsFrom($rules, 'person_id')),
                self::VIA_NAMED,
            );
        }

        $people = self::peopleByIds(self::rotatingOn((int) $clinic->unit_id, $on));

        if ($mode === Clinic::MODE_LEVELS) {
            $people = self::atLevels($people, self::idsFrom($rules, 'level_id'), $on);
        }

        return self::project($people, self::VIA_ROTATION);
    }

    /**
     * The ids the master rota has on one unit on one day.
     *
     * `whereDate()` rather than a raw string comparison, and that is not decoration: the model
     * casts both bounds to `date`, and MySQL round-trips such a column as `'Y-m-d 00:00:00'`, so a
     * plain equality against `'Y-m-d'` never matches on the engine production runs. It is the same
     * caveat `MasterRotaAssignment::booted()` and `Vacation::scopeIntersecting()` both already
     * carry.
     *
     * `pluck()` on a rota row is not the narrowed-projection trap the class docblock names — that
     * one is about narrowing a PERSON query past the column its accessors resolve through. This
     * reads one foreign key off `master_rota_assignments` and nothing else, and the whole models
     * are fetched a query later.
     *
     * @return list<int>
     */
    private static function rotatingOn(int $unitId, string $on): array
    {
        return MasterRotaAssignment::query()
            ->where('unit_id', $unitId)
            ->whereDate('starts_on', '<=', $on)
            ->whereDate('ends_on', '>=', $on)
            ->pluck('person_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * WHOLE MODELS, never a projection, and `withTrashed()` so a soft-deleted person still holding
     * a span arrives flagged rather than vanishing between the span query and this one.
     *
     * @param  list<int>  $ids
     * @return EloquentCollection<int, Person>
     */
    private static function peopleByIds(array $ids): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        return Person::query()->withTrashed()
            ->whereIn('id', $ids)
            ->withExists(['user as has_account'])
            ->orderBy('people.full_name')
            ->get();
    }

    /**
     * CL-02's `levels` refinement: keep the rotators whose training level ON THAT DATE is in the
     * attached set.
     *
     * ONE query for the whole set (plus its eager level load), resolved in memory per person —
     * never `Person::levelAt()` per row, which is one query each and the shape the rota grid's
     * docblock already names as costing 780. Measured rather than argued: replacing the two calls
     * below with a per-row `levelAt()` took `test_the_query_count_is_bounded` from 5 queries to 83.
     *
     * NO EMPTY-SET SHORT-CIRCUIT, deliberately. `levelSpansBetween()` already issues no query for
     * an empty collection, and an empty rule set filters everybody out through `in_array()` on its
     * own — so a guard clause here would buy nothing except a second branch to keep in step, and
     * one of its two halves would describe a state `ClinicWriter` refuses to create and no test
     * could ever exercise.
     *
     * @param  EloquentCollection<int, Person>  $people
     * @param  list<int>  $levelIds
     * @return EloquentCollection<int, Person>
     */
    private static function atLevels(EloquentCollection $people, array $levelIds, string $on): EloquentCollection
    {
        $spans = Person::levelSpansBetween($people, $on, $on);

        return $people->filter(function (Person $person) use ($spans, $levelIds, $on): bool {
            $level = Person::levelFromSpans($spans[(int) $person->getKey()] ?? [], $on);

            return $level !== null && in_array((int) $level->getKey(), $levelIds, true);
        })->values();
    }

    /**
     * @param  EloquentCollection<int, ClinicAttendee>  $rules
     * @return list<int>
     */
    private static function idsFrom(EloquentCollection $rules, string $column): array
    {
        return $rules->pluck($column)
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * `contactFree()`, NOT `one($person, $viewer)`. A clinic surface picks and shows NAMES; leaking
     * a way to reach somebody as a side effect of naming them is the payload disclosure P1d-2
     * Decision C closed. Passing `null` as the viewer would work identically and would lie — the
     * caller has a viewer; `contactFree()` is the named intent, which is the only spelling that
     * survives a later reader "fixing" the null.
     *
     * `via` and `stale` ride as `$extra` on the ROW, not inside the person: the presenter projects
     * a PERSON, and "this one is here because they are named on the clinic" and "this one is only
     * here because a departed colleague still holds a span" are facts about the resolution.
     *
     * @param  EloquentCollection<int, Person>  $people
     * @return list<array<string, mixed>>
     */
    private static function project(EloquentCollection $people, string $via): array
    {
        return $people->map(fn (Person $person): array => PersonPresenter::contactFree($person, [
            'via' => $via,
            'stale' => ! $person->active || $person->trashed(),
        ]))->values()->all();
    }
}
