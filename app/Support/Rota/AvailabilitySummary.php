<?php

namespace App\Support\Rota;

/**
 * Munawib MR-07 — "per-period availability summary per level and unit, including who is on
 * vacation each week" — and with it Stage 1's acceptance criterion (§35: "availability summaries
 * match reality").
 *
 * ONE COMPUTATION, TWO SURFACES. The editor (`/admin/rota`) and the resident read view (`/rota`)
 * both render the output of this function; neither computes a figure of its own. Two definitions of
 * "how many R2s are on PICU in Block 11" is the failure class `AuditChain::canonical()` and
 * `Person::levelAt()` already carry docblocks against, and a department reading two different
 * answers off two screens has no way to know which one is the rota.
 *
 * PURE, AND QUERY-FREE BY CONSTRUCTION (P1d-2 Decision B). It takes the array
 * `RotaGrid::forYear()` already returns and folds it. It touches no model, issues no query, holds
 * no state and reads no configuration — `AvailabilitySummaryTest::test_it_issues_no_query` pins
 * that, because "just fetch the unit name here" would turn one fold into an N+1 on two screens at
 * once, and because a function with no hidden inputs is the only kind whose two callers can be
 * PROVED to agree.
 *
 * IT HANDLES NO DATES (ST-06). Not one. Each span arrives carrying its own `days` count, computed
 * in `RotaGrid::cellFor()` where the Carbon objects already are; the only date logic left here is
 * asking whether a vacation intersects a week, which is four comparisons between `Y-m-d` STRINGS —
 * a format that sorts correctly as text. The weeks themselves came from `Calendar::weeksIn()` when
 * the grid was built, which is the one converter.
 *
 * THE LEVEL IS THE CELL'S, NEVER THE ROW'S. `by_level_unit` is keyed on `cell['level_id']` — the
 * level held at THAT period's start — not on `row['group_level_id']`, which is the level held at
 * the academic year's start. A person promoted mid-year is an R2 in Block 4 and an R3 in Block 9;
 * a summary keyed on the row group reports the whole of that year under R2 and quietly ages a
 * cohort down.
 *
 * A GAP IS A LEGAL STATE, AND IT IS COUNTED TWICE OVER. Owner decision 3 (P1d-1) permits a person
 * to be unassigned for part of a period, so a summary that counted only assignments could not tell
 * a finished period from a half-planned one. `uncovered_days` is the total; `people_with_a_gap` is
 * how many people carry it. Twenty-six uncovered days is one person's whole block OR twenty-six
 * people missing a day each, and reporting only the sum rounds that difference away — which is
 * precisely what "match reality" forbids.
 *
 * A STALE ROW IS EXCLUDED FROM COVERAGE AND COUNTED SEPARATELY (Decision D). People are
 * deactivated, never deleted, so "assigned in March, left in April" is a state the system reaches
 * on its own. Counting a departed person's block as cover OVERSTATES availability, which is the
 * exact failure §35 names; zeroing them silently would be dishonest in the other direction,
 * because those cells really are occupied and somebody has to clear them. So they contribute
 * nothing to `by_level_unit`, `assigned_days`, `uncovered_days`, `people_with_a_gap`,
 * `unassigned_people` or the vacation weeks, and every period-cell they still occupy is counted in
 * `stale_people` — an administrator's to-do, not a coverage figure.
 *
 * `stale_people`, NOT `stale_assignments` (adversarial review, finding 5). The fold below counts
 * CELLS, and within one period a cell is exactly one person — but the field was called
 * `stale_assignments` and rendered as "N assignment(s)", and "assignment" already means a
 * `master_rota_assignments` ROW everywhere else here (`PeriodController::destroy()` refuses a year
 * while N "master rota assignment(s)" reference it, and that N is rows). One departed person
 * holding a three-way split in one block was one cell and three assignments, so the number on
 * screen was wrong for its own label. The COUNT is the figure worth having and it stayed: it is a
 * headcount beside two other headcounts, and it is the administrator's unit of work, because
 * `MasterRota.vue`'s Clear control empties a whole cell, splits and all — a span count would
 * over-report the job by the number of splits. Only the name moved.
 *
 * THE ORDERING TRAP: this must be handed the FULL grid, stale rows included. A caller that filters
 * rows for display first and summarises afterwards loses `stale_people` entirely, silently, and the
 * summary still looks plausible.
 *
 * IT NAMES NOBODY. Ids and counts only — no name, no email, no phone, not even a person projection.
 * `rota.view` is seeded for every authenticated position, so both surfaces rendering this are read
 * by the whole department, and a field folded in "for convenience" would be a disclosure in the
 * props that no screen had to render in order to leak.
 *
 * THIS IS NOT, AND MUST NEVER BECOME, AN ON-CALL ELIGIBILITY COMPUTATION. MR-04 is Stage 2. This is
 * exactly the shape a future implementer would reach for to answer "who is available for call in
 * Block 11?" — and answering it here would mean the rota had quietly begun inferring eligibility,
 * which nothing in P1d does or may do. Availability is a description of the rota as planned; it is
 * not a permission, a roster or a candidate list.
 */
final class AvailabilitySummary
{
    /**
     * The `by_level_unit` bucket for a person whose level history says nothing about this period.
     * Zero is safe: it is never a real `levels.id`. Dropping such a person instead would break the
     * property that the buckets sum to `assigned_days`, and their days would vanish from the one
     * screen that exists to show where everybody is.
     */
    public const NO_LEVEL = 0;

    /**
     * @param  array{periods: list<array<string, mixed>>, levels: list<array<string, mixed>>, units: list<array<string, mixed>>, rows: list<array<string, mixed>>}  $grid
     *                                                                                                                                                             the FULL grid, stale rows included
     * @return array<int, array{
     *     by_level_unit: array<int, array<int, array{people: int, days: int}>>,
     *     assigned_days: int,
     *     uncovered_days: int,
     *     people_with_a_gap: int,
     *     unassigned_people: int,
     *     weeks: list<array{starts_on: string, ends_on: string, clipped_starts_on: string, clipped_ends_on: string, on_vacation: int, person_ids: list<int>}>,
     *     stale_people: int,
     * }> keyed by period id, in the grid's own period order
     */
    public static function forGrid(array $grid): array
    {
        $summary = [];

        foreach ($grid['periods'] as $period) {
            $summary[(int) $period['id']] = self::forPeriod($period, $grid['rows']);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function forPeriod(array $period, array $rows): array
    {
        $periodId = (int) $period['id'];

        $byLevelUnit = [];
        $assignedDays = 0;
        $uncoveredDays = 0;
        $peopleWithAGap = 0;
        $unassignedPeople = 0;
        $stalePeople = 0;
        $weeks = self::weeksOf($period);

        foreach ($rows as $row) {
            $cell = $row['cells'][$periodId] ?? null;

            if (! is_array($cell)) {
                continue;
            }

            $spans = $cell['spans'] ?? [];

            // Decision D — a departed person's occupied cells are their own number, and nothing
            // else. Everything below this line is coverage, and they are not part of it.
            //
            // ONE PER PERSON, however many spans they hold here: this is a headcount of people to
            // clear, not a tally of rows (finding 5). The `!== []` is what makes it a count of
            // OCCUPIED cells — a departed person whose cell in this period is already empty has
            // nothing left for anybody to do about.
            if ($row['stale'] ?? false) {
                if ($spans !== []) {
                    $stalePeople++;
                }

                continue;
            }

            $personId = (int) $row['person']['id'];
            $levelId = isset($cell['level_id']) ? (int) $cell['level_id'] : self::NO_LEVEL;
            $unitsThisPerson = [];

            foreach ($spans as $span) {
                $unitId = (int) $span['unit_id'];
                $days = (int) $span['days'];

                $assignedDays += $days;
                $bucket = $byLevelUnit[$levelId][$unitId] ?? ['people' => 0, 'days' => 0];
                $bucket['days'] += $days;

                // Counted once per unit however many spans they hold on it — and a split person
                // IS counted under both units, because they are genuinely on both that period.
                if (! isset($unitsThisPerson[$unitId])) {
                    $unitsThisPerson[$unitId] = true;
                    $bucket['people']++;
                }

                $byLevelUnit[$levelId][$unitId] = $bucket;
            }

            $gap = (int) ($cell['uncovered_days'] ?? 0);
            $uncoveredDays += $gap;

            // Disjoint, deliberately: nothing planned at all is a different fact from something
            // planned incompletely, and an operator reading both numbers can add them up.
            if ($spans === []) {
                $unassignedPeople++;
            } elseif ($gap > 0) {
                $peopleWithAGap++;
            }

            foreach ($cell['vacations'] ?? [] as $vacation) {
                foreach ($weeks as $index => $week) {
                    // Y-m-d sorts correctly as text, so intersection is four string comparisons —
                    // the same idiom `RotaGrid::cellFor()` uses to pick a cell's vacations. The
                    // CLIPPED bounds are the ones the week strip renders.
                    $intersects = $vacation['starts_on'] <= $week['clipped_ends_on']
                        && $vacation['ends_on'] >= $week['clipped_starts_on'];

                    if ($intersects && ! in_array($personId, $week['person_ids'], true)) {
                        $weeks[$index]['person_ids'][] = $personId;
                        $weeks[$index]['on_vacation']++;
                    }
                }
            }
        }

        // Deterministic order for a prop two screens render: by level, then by unit. Nothing
        // downstream may depend on the order rows happened to arrive in.
        ksort($byLevelUnit);

        foreach ($byLevelUnit as $levelId => $units) {
            ksort($units);
            $byLevelUnit[$levelId] = $units;
        }

        return [
            'by_level_unit' => $byLevelUnit,
            'assigned_days' => $assignedDays,
            'uncovered_days' => $uncoveredDays,
            'people_with_a_gap' => $peopleWithAGap,
            'unassigned_people' => $unassignedPeople,
            'weeks' => $weeks,
            'stale_people' => $stalePeople,
        ];
    }

    /**
     * The period's own week strip, carried through with the two counters this class fills in.
     * Bounds only — the labels stay on the period prop beside it rather than being copied into a
     * second place they could drift from.
     *
     * @param  array<string, mixed>  $period
     * @return list<array{starts_on: string, ends_on: string, clipped_starts_on: string, clipped_ends_on: string, on_vacation: int, person_ids: list<int>}>
     */
    private static function weeksOf(array $period): array
    {
        $weeks = [];

        foreach ($period['weeks'] ?? [] as $week) {
            $weeks[] = [
                'starts_on' => $week['starts_on'],
                'ends_on' => $week['ends_on'],
                'clipped_starts_on' => $week['clipped_starts_on'],
                'clipped_ends_on' => $week['clipped_ends_on'],
                'on_vacation' => 0,
                'person_ids' => [],
            ];
        }

        return $weeks;
    }
}
