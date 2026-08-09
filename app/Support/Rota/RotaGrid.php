<?php

namespace App\Support\Rota;

use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\Calendar;
use App\Support\PersonPresenter;

/**
 * Munawib MR-02's master rota grid: rows by level (held at the academic year's start), columns
 * by period. Decision G's SEVEN-QUERY budget, constant in both people and periods — the query
 * count does not grow whether the year has one person or sixty, one period or thirteen.
 *
 * `RotaGridTest::test_the_whole_grid_is_a_bounded_number_of_queries` pins the budget with a
 * measured bound, not an exact count (a logged-in Inertia request contributes its own
 * session/auth/capability reads on top of the seven data queries below).
 *
 * Named N+1 traps this class exists to avoid — each is a real defect this codebase has already
 * paid for once elsewhere:
 *  - `Person::levelAt()` per cell → 780 queries. Solved by `Person::levelSpansBetween()` (Task 3)
 *    fetched ONCE, then `Person::levelFromSpans()` resolved in memory per (person, period).
 *  - `$assignment->unit` per cell → up to 780 queries. Units are resolved from the id-keyed map
 *    built from query 6; a cell NEVER touches the `unit()` relation.
 *  - `PersonPresenter::one()` calling `$person->hasAccount()` per row when the caller forgot
 *    `withExists()` → 60 EXISTS queries. Query 2 carries `withExists(['user as has_account'])`.
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
    public static function forYear(string $academicYear, ?User $viewer): ?array
    {
        // Query 1 — the columns.
        $periods = Period::query()->forYear($academicYear)->ordered()->get();

        if ($periods->isEmpty()) {
            return null;
        }

        $yearStart = $periods->first()->starts_on->format(Calendar::YMD);
        $yearEnd = $periods->last()->ends_on->format(Calendar::YMD);

        // Query 2 — the rows. Whole models, never select()/pluck(): a narrowed query that drops
        // person_id makes PersonPresenter's full_name/position accessors resolve to null with
        // no error. withExists() stops PersonPresenter running one EXISTS per row.
        $people = Person::query()->active()
            ->withExists(['user as has_account'])
            ->orderBy('people.full_name')
            ->get();

        // Query 3 (+ its own eager-loaded 'level' load) — every level span intersecting the
        // year, once for the whole roster. Person::levelFromSpans() resolves each cell's level
        // from this in memory; no per-cell/per-date query.
        $spans = Person::levelSpansBetween($people, $yearStart, $yearEnd);

        // Query 4 — every assignment for every period in this year, in one shot.
        $periodIds = $periods->pluck('id')->all();
        $assignments = MasterRotaAssignment::query()
            ->whereIn('period_id', $periodIds)
            ->orderBy('starts_on')
            ->get();

        $assignmentsByPersonPeriod = [];

        foreach ($assignments as $assignment) {
            $assignmentsByPersonPeriod[(int) $assignment->person_id][(int) $assignment->period_id][] = $assignment;
        }

        // Query 5 — every vacation intersecting the year for this roster, grouped in PHP.
        $personIds = $people->pluck('id')->all();
        $vacations = Vacation::query()
            ->whereIn('person_id', $personIds)
            ->intersecting($yearStart, $yearEnd)
            ->get();

        $vacationsByPerson = [];

        foreach ($vacations as $vacation) {
            $vacationsByPerson[(int) $vacation->person_id][] = $vacation;
        }

        // Query 6 — the unit picker AND the per-cell id->unit map. A cell never touches
        // $assignment->unit.
        $units = Unit::query()->active()->ordered()->get();
        $unitsById = $units->keyBy('id');

        // Query 7 — the row-group headers, in display order.
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
                'person' => PersonPresenter::one($person, $viewer),
                'group_level_id' => $groupLevel?->getKey(),
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
     * One cell: the spans covering it, the days NONE of them cover (owner decision 3 — a gap is
     * rendered and counted, never silently absent), the level held at the period's OWN start
     * (which may differ from the row's group level on a mid-year promotion), and any vacation
     * intersecting the period.
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
        $periodDays = $period->starts_on->diffInDays($period->ends_on) + 1;

        $coveredDays = 0;
        $spansOut = [];

        foreach ($assignments as $assignment) {
            $from = $assignment->starts_on->format(Calendar::YMD);
            $to = $assignment->ends_on->format(Calendar::YMD);
            $coveredDays += $assignment->starts_on->diffInDays($assignment->ends_on) + 1;

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
