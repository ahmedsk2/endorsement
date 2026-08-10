<?php

namespace App\Support\Rota;

use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\Vacation;
use App\Support\Calendar;
use App\Support\PersonPresenter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Munawib MR-02's master rota grid: rows by level (held at the academic year's start), columns
 * by period. Decision G's query budget — EIGHT since pre-merge finding 1 added the stale-row
 * union — constant in both people and periods: the count does not grow whether the year has one
 * person or sixty, one period or thirteen, nor with the number of stale rows.
 *
 * `RotaGridTest::test_the_whole_grid_is_a_bounded_number_of_queries` pins the budget with a
 * measured bound, not an exact count (a logged-in Inertia request contributes its own
 * session/auth/capability reads on top of the eight data queries below), and it measures a
 * POPULATED year — split assignments, vacations, mid-year promotions and a stale row — because a
 * budget measured on an empty grid only ever proves the empty case (pre-merge finding 3).
 *
 * Rows are the active roster UNIONED with anybody who still holds an assignment in this year but
 * is no longer active (finding 1). That union is one query, never one per stale person, and the
 * span/vacation queries below take the COMBINED set — a second round for stale people would be
 * the same N+1 in a new costume.
 *
 * NO ROTA SURFACE PROJECTS A CONTACT FIELD, FOR ANY VIEWER (P1d-2 Decision C). Rows go through
 * `PersonPresenter::contactFree()`, and `forYear()` takes NO viewer at all — the parameter was
 * removed rather than ignored, so a future caller cannot pass one and expect it to mean something.
 * This is deliberately stronger than gating on what the viewer holds. `PersonPolicy::viewContact()`
 * is `people.manage OR institutions.contact_visibility === 'members'`, so passing the real user
 * made this grid emit every colleague's email and phone whenever a department flipped that toggle —
 * and for a `people.manage` holder on the default setting too. Nothing rendered them; the leak was
 * in the Inertia props, where review does not look. Both rota surfaces (`/admin/rota` and the
 * MR-05 read view at `/rota`, which every seeded position can reach) are covered by the one call
 * below, and `RotaReadViewTest` asserts the absence for an administrator on the most permissive
 * institution setting the system can produce.
 *
 * Named N+1 traps this class exists to avoid — each is a real defect this codebase has already
 * paid for once elsewhere:
 *  - `Person::levelAt()` per cell → 780 queries. Solved by `Person::levelSpansBetween()` (Task 3)
 *    fetched ONCE, then `Person::levelFromSpans()` resolved in memory per (person, period).
 *  - `$assignment->unit` per cell → up to 780 queries. Units are resolved from the id-keyed map
 *    built from query 6; a cell NEVER touches the `unit()` relation.
 *  - `PersonPresenter::contactFree()` calling `$person->hasAccount()` per row when the caller
 *    forgot `withExists()` → 60 EXISTS queries. Query 2 carries `withExists(['user as
 *    has_account'])`. Dropping the viewer changed nothing here: the presenter's contact and notes
 *    branches never queried, and the EXISTS is the account check, which both projections do.
 *  - A narrowed `select()`/`pluck()` on the person query that drops `person_id` → `full_name`
 *    and `position` silently resolve to null (the P0c defect that broke four live sites with no
 *    test coverage). Query 2 fetches WHOLE `Person` models, never a projection.
 *  - `Calendar::label()` per DAY of a period → real CPU for no gain. The grid labels
 *    BOUNDARIES only: two labels per period, two per split span, two per vacation, two per week
 *    (`Calendar::weeksIn()`, itself query-free) — never once per day.
 */
final class RotaGrid
{
    /**
     * @return array{
     *     periods: list<array<string, mixed>>,
     *     levels: list<array<string, mixed>>,
     *     units: list<array<string, mixed>>,
     *     rows: list<array<string, mixed>>,
     * }|null null when the academic year has no periods — the rota's columns ARE periods.
     */
    public static function forYear(string $academicYear): ?array
    {
        // Query 1 — the columns.
        $periods = Period::query()->forYear($academicYear)->ordered()->get();

        if ($periods->isEmpty()) {
            return null;
        }

        $yearStart = $periods->first()->starts_on->format(Calendar::YMD);
        $yearEnd = $periods->last()->ends_on->format(Calendar::YMD);

        // Query 2 — every assignment for every period in this year, in one shot. It comes BEFORE
        // the roster because it is what says which non-active people still occupy a cell.
        $periodIds = $periods->pluck('id')->all();
        $assignments = MasterRotaAssignment::query()
            ->whereIn('period_id', $periodIds)
            ->orderBy('starts_on')
            ->get();

        $assignmentsByPersonPeriod = [];

        foreach ($assignments as $assignment) {
            $assignmentsByPersonPeriod[(int) $assignment->person_id][(int) $assignment->period_id][] = $assignment;
        }

        // Query 3 — the rows. Whole models, never select()/pluck(): a narrowed query that drops
        // person_id makes PersonPresenter's full_name/position accessors resolve to null with
        // no error. withExists() stops PersonPresenter running one EXISTS per row.
        $active = Person::query()->active()
            ->withExists(['user as has_account'])
            ->orderBy('people.full_name')
            ->get();

        // Query 4 — the STALE rows (pre-merge finding 1), and the only query this union adds:
        // anybody who still holds a span in this year but is no longer on the active roster.
        // People are deactivated, never deleted, so a resident who leaves mid-year keeps every
        // span already planned for them; without this the row simply vanished, the operator had
        // no control to clear it, and the assignment blocked PeriodController::destroy() — and so
        // Decision D's unlock — forever. `withTrashed()`: a retired person's spans wedge the year
        // exactly as an inactive person's do. Same whole-model, same withExists() as query 3, so a
        // stale row costs no EXISTS and loses no accessor.
        $activeIds = $active->modelKeys();
        $staleIds = array_values(array_diff(array_keys($assignmentsByPersonPeriod), $activeIds));

        $stale = $staleIds === []
            ? new EloquentCollection
            : Person::query()->withTrashed()
                ->whereIn('id', $staleIds)
                ->withExists(['user as has_account'])
                ->get();

        // Interleaved alphabetically, never appended: a stale row is a normal row of its level
        // group that happens to be read-only. Query 3's own ORDER BY is left untouched in the
        // common case (no stale rows at all), so the department's usual ordering is unchanged.
        $people = $stale->isEmpty()
            ? $active
            : $active->concat($stale)->sortBy(fn (Person $person): string => (string) $person->full_name)->values();

        // Query 5 (+ its own eager-loaded 'level' load) — every level span intersecting the
        // year, once for the whole roster INCLUDING the stale rows (never a second round for
        // them). Person::levelFromSpans() resolves each cell's level from this in memory; no
        // per-cell/per-date query.
        $spans = Person::levelSpansBetween($people, $yearStart, $yearEnd);

        // Query 6 — every vacation intersecting the year for this roster, grouped in PHP.
        $personIds = $people->pluck('id')->all();
        $vacations = Vacation::query()
            ->whereIn('person_id', $personIds)
            ->intersecting($yearStart, $yearEnd)
            ->get();

        $vacationsByPerson = [];

        foreach ($vacations as $vacation) {
            $vacationsByPerson[(int) $vacation->person_id][] = $vacation;
        }

        // Query 7 — the unit picker AND the per-cell id->unit map. A cell never touches
        // $assignment->unit.
        $units = Unit::query()->active()->ordered()->get();
        $unitsById = $units->keyBy('id');

        // Query 8 — the row-group headers, in display order.
        $levels = Level::query()->active()->ordered()->get();
        $levelOrder = [];

        foreach ($levels->values() as $index => $level) {
            $levelOrder[(int) $level->getKey()] = $index;
        }

        $periodProps = $periods->map(fn (Period $period): array => [
            'id' => (int) $period->getKey(),
            'position' => $period->position,
            'label' => $period->label,
            'kind' => $period->kind,
            'starts_on' => $period->starts_on->format(Calendar::YMD),
            'ends_on' => $period->ends_on->format(Calendar::YMD),
            'starts_label' => Calendar::label($period->starts_on),
            'ends_label' => Calendar::label($period->ends_on),
            'weeks' => Calendar::weeksIn(
                $period->starts_on->format(Calendar::YMD),
                $period->ends_on->format(Calendar::YMD),
            ),
        ])->values()->all();

        $levelProps = $levels->map(fn (Level $level): array => [
            'id' => (int) $level->getKey(),
            'code' => $level->code,
            'name' => $level->name,
        ])->values()->all();

        $unitProps = $units->map(fn (Unit $unit): array => [
            'id' => (int) $unit->getKey(),
            'code' => $unit->code,
            'name' => $unit->name,
            'bar_class' => $unit->bar_class ?: Unit::DEFAULT_BAR_CLASS,
        ])->values()->all();

        $rows = [];

        foreach ($people as $person) {
            $personId = (int) $person->getKey();
            $personSpans = $spans[$personId] ?? [];
            $groupLevel = Person::levelFromSpans($personSpans, $yearStart);

            $cells = [];

            foreach ($periods as $period) {
                $periodId = (int) $period->getKey();

                $cells[$periodId] = self::cellFor(
                    $period,
                    $assignmentsByPersonPeriod[$personId][$periodId] ?? [],
                    $vacationsByPerson[$personId] ?? [],
                    $unitsById,
                    Person::levelFromSpans($personSpans, $period->starts_on->format(Calendar::YMD)),
                );
            }

            $rows[] = [
                'person' => PersonPresenter::contactFree($person),
                'group_level_id' => $groupLevel?->getKey(),
                // On the ROW, not inside `person` — PersonPresenter projects a person, and "this
                // row is only here because it still holds a span" is a fact about the grid. The
                // client renders a flagged row read-only except for Clear.
                'stale' => ! $person->active || $person->trashed(),
                'cells' => $cells,
            ];
        }

        usort($rows, function (array $a, array $b) use ($levelOrder): int {
            $orderA = $levelOrder[$a['group_level_id']] ?? PHP_INT_MAX;
            $orderB = $levelOrder[$b['group_level_id']] ?? PHP_INT_MAX;

            // People::orderBy('people.full_name') has already ordered $rows alphabetically;
            // usort() is stable (PHP 8+), so ties within one level group keep that order.
            return $orderA <=> $orderB;
        });

        return [
            'periods' => $periodProps,
            'levels' => $levelProps,
            'units' => $unitProps,
            'rows' => $rows,
        ];
    }

    /**
     * One cell: the spans covering it (each carrying its OWN day count), the days NONE of them
     * cover (owner decision 3 — a gap is rendered and counted, never silently absent), the level
     * held at the period's OWN start (which may differ from the row's group level on a mid-year
     * promotion), and any vacation intersecting the period.
     *
     * @param  list<MasterRotaAssignment>  $assignments
     * @param  list<Vacation>  $vacations
     * @param  \Illuminate\Support\Collection<int, Unit>  $unitsById
     */
    private static function cellFor(
        Period $period,
        array $assignments,
        array $vacations,
        $unitsById,
        ?Level $levelAtPeriodStart,
    ): array {
        $periodStart = $period->starts_on->format(Calendar::YMD);
        $periodEnd = $period->ends_on->format(Calendar::YMD);
        // (int) — Carbon 3's diffInDays() returns a float, and a whole number of days that reaches
        // the client as 28.0 is one somebody eventually compares against 28 and loses.
        $periodDays = (int) $period->starts_on->diffInDays($period->ends_on) + 1;

        $coveredDays = 0;
        $spansOut = [];

        foreach ($assignments as $assignment) {
            $from = $assignment->starts_on->format(Calendar::YMD);
            $to = $assignment->ends_on->format(Calendar::YMD);
            $days = (int) $assignment->starts_on->diffInDays($assignment->ends_on) + 1;
            $coveredDays += $days;

            /** @var Unit|null $unit */
            $unit = $unitsById->get((int) $assignment->unit_id);

            $spansOut[] = [
                'id' => (int) $assignment->getKey(),
                'unit_id' => (int) $assignment->unit_id,
                // A retired unit stays visible on the historical span it was assigned under —
                // the units list (query 6) offers only active ones for a NEW pick, but a span
                // written while a unit was active must not go blank the day it is retired.
                'unit_code' => $unit?->code,
                'starts_on' => $from,
                'ends_on' => $to,
                // Counted HERE, where the Carbon objects are already in hand for `uncovered_days`,
                // so `AvailabilitySummary` can fold this grid into MR-07's figures while handling
                // no date at all (P1d-2 Decision B). One fewer place a converter could appear.
                'days' => $days,
                'starts_label' => Calendar::label($assignment->starts_on),
                'ends_label' => Calendar::label($assignment->ends_on),
            ];
        }

        $cellVacations = array_values(array_filter(
            $vacations,
            fn (Vacation $vacation): bool => $vacation->starts_on->format(Calendar::YMD) <= $periodEnd
                && $vacation->ends_on->format(Calendar::YMD) >= $periodStart,
        ));

        return [
            'spans' => $spansOut,
            'uncovered_days' => max(0, $periodDays - $coveredDays),
            'level_id' => $levelAtPeriodStart?->getKey(),
            'vacations' => array_map(fn (Vacation $vacation): array => [
                'id' => (int) $vacation->getKey(),
                'starts_on' => $vacation->starts_on->format(Calendar::YMD),
                'ends_on' => $vacation->ends_on->format(Calendar::YMD),
                'granularity' => $vacation->granularity,
                'starts_label' => Calendar::label($vacation->starts_on),
                'ends_label' => Calendar::label($vacation->ends_on),
            ], $cellVacations),
        ];
    }
}
