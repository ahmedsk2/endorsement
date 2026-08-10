<?php

namespace Tests\Feature\Rota;

use App\Support\Rota\AvailabilitySummary;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Munawib MR-07, and Stage 1's acceptance criterion (§35: "availability summaries match reality").
 *
 * EVERY fixture here is a hand-built grid literal — no database, no factory, no HTTP request. That
 * is the whole point of P1d-2 Decision B: `AvailabilitySummary::forGrid()` is a pure fold over the
 * array `RotaGrid::forYear()` already returns, so its cases can state exactly the shape under test
 * and nothing else. A summary that needed a seeded year to exercise a mid-year promotion would be
 * a summary with hidden inputs, and Task 5's editor/read-view parity proof rests on there being
 * none.
 *
 * The contract with `RotaGrid` this leans on: each span prop carries its own `days` count, computed
 * where the Carbon objects already are (`RotaGrid::cellFor()`), so this class handles no dates at
 * all. `RotaGridTest::test_every_span_carries_its_own_day_count` is the other half of that pairing.
 */
class AvailabilitySummaryTest extends TestCase
{
    private const PERIOD = 7;

    private const R1 = 11;

    private const R2 = 12;

    private const R3 = 13;

    private const PICU = 21;

    private const NICU = 22;

    public function test_it_counts_people_and_days_per_level_and_unit(): void
    {
        $grid = $this->grid([
            $this->row(101, self::R1, [self::PERIOD => $this->cell([$this->span(self::PICU, '2026-07-01', '2026-07-28', 28)], 0, self::R1)]),
            $this->row(102, self::R1, [self::PERIOD => $this->cell([$this->span(self::PICU, '2026-07-01', '2026-07-28', 28)], 0, self::R1)]),
            $this->row(103, self::R2, [self::PERIOD => $this->cell([$this->span(self::NICU, '2026-07-01', '2026-07-28', 28)], 0, self::R2)]),
        ]);

        $summary = AvailabilitySummary::forGrid($grid)[self::PERIOD];

        // Person-DAYS, not period days: two people each on PICU for the whole 28-day period is 56
        // days of PICU cover, and 28 would be indistinguishable from one person covering it alone.
        $this->assertSame(['people' => 2, 'days' => 56], $summary['by_level_unit'][self::R1][self::PICU]);
        $this->assertSame(['people' => 1, 'days' => 28], $summary['by_level_unit'][self::R2][self::NICU]);
        $this->assertSame(84, $summary['assigned_days']);

        // The invariant that keeps the two numbers from drifting: every day counted in a bucket is
        // a day counted in the total, and vice versa.
        $bucketed = 0;

        foreach ($summary['by_level_unit'] as $units) {
            foreach ($units as $bucket) {
                $bucketed += $bucket['days'];
            }
        }

        $this->assertSame($summary['assigned_days'], $bucketed);
    }

    public function test_a_split_counts_the_person_under_both_units_and_the_days_under_each(): void
    {
        $grid = $this->grid([
            $this->row(101, self::R1, [self::PERIOD => $this->cell([
                $this->span(self::PICU, '2026-07-01', '2026-07-09', 9),
                $this->span(self::NICU, '2026-07-10', '2026-07-28', 19),
            ], 0, self::R1)]),
        ]);

        $summary = AvailabilitySummary::forGrid($grid)[self::PERIOD];

        // One person, genuinely on both units that period — counted once in each, never halved.
        $this->assertSame(['people' => 1, 'days' => 9], $summary['by_level_unit'][self::R1][self::PICU]);
        $this->assertSame(['people' => 1, 'days' => 19], $summary['by_level_unit'][self::R1][self::NICU]);
        $this->assertSame(28, $summary['assigned_days']);
        $this->assertSame(0, $summary['people_with_a_gap']);
    }

    public function test_the_level_is_the_one_held_at_this_periods_start_not_the_row_group(): void
    {
        // The mid-year promotion: the row is grouped under the level held at the academic year's
        // start (R2), but by this period the person is an R3. A summary keyed on the row group
        // reports a whole cohort a year junior than it is.
        $grid = $this->grid([
            $this->row(101, self::R2, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-28', 28)], 0, self::R3
            )]),
        ]);

        $summary = AvailabilitySummary::forGrid($grid)[self::PERIOD];

        $this->assertSame(['people' => 1, 'days' => 28], $summary['by_level_unit'][self::R3][self::PICU]);
        $this->assertArrayNotHasKey(self::R2, $summary['by_level_unit']);
    }

    public function test_uncovered_days_and_people_with_a_gap_are_separate_numbers(): void
    {
        // One person missing 26 days of their block.
        $one = $this->grid([
            $this->row(101, self::R1, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-02', 2)], 26, self::R1
            )]),
        ]);

        // Twenty-six people each missing one day of theirs.
        $rows = [];

        for ($i = 0; $i < 26; $i++) {
            $rows[] = $this->row(200 + $i, self::R1, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-27', 27)], 1, self::R1
            )]);
        }

        $many = $this->grid($rows);

        $first = AvailabilitySummary::forGrid($one)[self::PERIOD];
        $second = AvailabilitySummary::forGrid($many)[self::PERIOD];

        // Same total. Completely different facts about the department — which is exactly why the
        // sum alone cannot be the whole answer.
        $this->assertSame(26, $first['uncovered_days']);
        $this->assertSame(26, $second['uncovered_days']);

        $this->assertSame(1, $first['people_with_a_gap']);
        $this->assertSame(26, $second['people_with_a_gap']);
    }

    public function test_a_person_with_no_span_at_all_is_unassigned_not_a_gap(): void
    {
        $grid = $this->grid([
            $this->row(101, self::R1, [self::PERIOD => $this->cell([], 28, self::R1)]),
            $this->row(102, self::R1, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-27', 27)], 1, self::R1
            )]),
        ]);

        $summary = AvailabilitySummary::forGrid($grid)[self::PERIOD];

        // Nothing was planned for 101, versus something planned incompletely for 102. The two
        // counts are disjoint, so an operator reading them can add them up.
        $this->assertSame(1, $summary['unassigned_people']);
        $this->assertSame(1, $summary['people_with_a_gap']);

        // The unassigned person still contributes their whole period to the uncovered total —
        // 28 unstaffed days are 28 unstaffed days however they came about.
        $this->assertSame(29, $summary['uncovered_days']);
    }

    public function test_who_is_on_vacation_is_reported_per_week_from_the_periods_own_weeks(): void
    {
        $grid = $this->grid([
            $this->row(101, self::R1, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-28', 28)],
                0,
                self::R1,
                [['id' => 1, 'starts_on' => '2026-07-09', 'ends_on' => '2026-07-14', 'granularity' => 'date']],
            )]),
        ], $this->fiveWeekPeriod());

        $weeks = AvailabilitySummary::forGrid($grid)[self::PERIOD]['weeks'];

        $this->assertCount(5, $weeks);
        $this->assertSame([0, 1, 1, 0, 0], array_column($weeks, 'on_vacation'));
        $this->assertSame([], $weeks[0]['person_ids']);
        $this->assertSame([101], $weeks[1]['person_ids']);
        $this->assertSame([101], $weeks[2]['person_ids']);
        $this->assertSame([], $weeks[3]['person_ids']);

        // The strip is the period's own weeks, clipped bounds included, so the screen renders the
        // same weeks the grid does rather than a second enumeration of them.
        $this->assertSame('2026-07-01', $weeks[0]['clipped_starts_on']);
        $this->assertSame('2026-07-28', $weeks[4]['clipped_ends_on']);
    }

    public function test_a_stale_row_is_excluded_from_coverage_and_counted_separately(): void
    {
        $grid = $this->grid([
            $this->row(101, self::R1, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-28', 28)], 0, self::R1
            )]),
            $this->row(102, self::R1, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-28', 28)], 0, self::R1
            )], stale: true),
            // A stale row holding no span in this period is not an occupied cell.
            $this->row(103, self::R1, [self::PERIOD => $this->cell([], 28, self::R1)], stale: true),
        ]);

        $summary = AvailabilitySummary::forGrid($grid)[self::PERIOD];

        // Counting a departed person's block as cover OVERSTATES availability, which is the exact
        // failure "summaries match reality" names (Decision D).
        $this->assertSame(['people' => 1, 'days' => 28], $summary['by_level_unit'][self::R1][self::PICU]);
        $this->assertSame(28, $summary['assigned_days']);
        $this->assertSame(0, $summary['uncovered_days']);
        $this->assertSame(0, $summary['unassigned_people']);

        // Zeroing them silently would be dishonest the other way — those cells really are
        // occupied, and clearing them is an administrator's to-do.
        $this->assertSame(1, $summary['stale_people']);
    }

    /**
     * THE FIGURE IS PEOPLE, AND THE NAME NOW SAYS SO (adversarial review, finding 5).
     *
     * It was called `stale_assignments` and rendered as "N assignment(s) here belong to someone no
     * longer on the roster", while the fold counted period-CELLS: one person holding a split with
     * three spans in one block was one cell and three assignments, so the sentence on screen was
     * wrong about its own number. "Assignment" already means a `master_rota_assignments` ROW
     * everywhere else in this codebase — `PeriodController::destroy()` refuses a year while N
     * "master rota assignment(s)" reference it, and that N is rows — so the old name was not merely
     * vague, it collided with a term in use.
     *
     * PEOPLE IS THE FIGURE WORTH HAVING, so the count stayed and the name moved. It is a headcount
     * beside two other headcounts (`people_with_a_gap`, `unassigned_people`); it is the
     * administrator's unit of work, because `MasterRota.vue`'s Clear control empties a whole cell,
     * splits and all, so a span count would over-report the job by the number of splits; and within
     * ONE period a cell IS a person, which is what makes the rename exact rather than approximate.
     *
     * This case is the one that fails if the two ever disagree again: one departed person, one
     * period, THREE spans.
     */
    public function test_a_departed_person_with_a_split_is_one_person_not_three_assignments(): void
    {
        $grid = $this->grid([
            $this->row(102, self::R1, [self::PERIOD => $this->cell([
                $this->span(self::PICU, '2026-07-01', '2026-07-09', 9),
                $this->span(self::NICU, '2026-07-10', '2026-07-19', 10),
                $this->span(self::PICU, '2026-07-20', '2026-07-28', 9),
            ], 0, self::R1)], stale: true),
        ]);

        $summary = AvailabilitySummary::forGrid($grid)[self::PERIOD];

        $this->assertSame(1, $summary['stale_people'],
            'three spans held by ONE departed person in ONE period were reported as three — the '
            .'number and the sentence beside it are about different things again');
        $this->assertArrayNotHasKey('stale_assignments', $summary,
            'the old key is still shipped, so a consumer can still render a cell count under a '
            .'label that says assignments');
    }

    /**
     * The summary reaches a person by id and by nothing else. `rota.view` is seeded for every
     * authenticated position, so both surfaces that render this are read by the whole department;
     * a name or a contact field folded in "for convenience" would be a disclosure in the props
     * that no screen had to render to leak.
     */
    public function test_the_summary_names_nobody(): void
    {
        $grid = $this->grid([
            $this->row(101, self::R1, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-28', 28)],
                0,
                self::R1,
                [['id' => 1, 'starts_on' => '2026-07-09', 'ends_on' => '2026-07-14', 'granularity' => 'date']],
            )]),
        ], $this->fiveWeekPeriod());

        $offenders = [];
        $this->assertNoHumanFields(AvailabilitySummary::forGrid($grid), '', $offenders);

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /**
     * The test that stops a later "just fetch the unit name here" turning a pure fold into an N+1
     * on two screens at once. `RotaGrid`'s own budget stays pinned at its measured bound because
     * this class adds nothing to it.
     */
    public function test_it_issues_no_query(): void
    {
        $grid = $this->grid([
            $this->row(101, self::R1, [self::PERIOD => $this->cell(
                [$this->span(self::PICU, '2026-07-01', '2026-07-28', 28)],
                0,
                self::R1,
                [['id' => 1, 'starts_on' => '2026-07-09', 'ends_on' => '2026-07-14', 'granularity' => 'date']],
            )]),
            $this->row(102, self::R2, [self::PERIOD => $this->cell([], 28, self::R2)], stale: true),
        ], $this->fiveWeekPeriod());

        DB::enableQueryLog();
        DB::flushQueryLog();

        AvailabilitySummary::forGrid($grid);

        $this->assertCount(0, DB::getQueryLog(), 'AvailabilitySummary must be a pure fold (Decision B).');
    }

    /** @param list<array<string, mixed>> $rows */
    private function grid(array $rows, ?array $periods = null): array
    {
        return [
            'periods' => $periods ?? [$this->period()],
            'levels' => [
                ['id' => self::R1, 'code' => 'R1', 'name' => 'R1'],
                ['id' => self::R2, 'code' => 'R2', 'name' => 'R2'],
                ['id' => self::R3, 'code' => 'R3', 'name' => 'R3'],
            ],
            'units' => [
                ['id' => self::PICU, 'code' => 'PICU', 'name' => 'PICU', 'bar_class' => 'channel-bar-slate'],
                ['id' => self::NICU, 'code' => 'NICU', 'name' => 'NICU', 'bar_class' => 'channel-bar-slate'],
            ],
            'rows' => $rows,
        ];
    }

    /** A 28-day period carrying no week strip — the cases that do not look at one. */
    private function period(array $weeks = []): array
    {
        return [
            'id' => self::PERIOD,
            'position' => 1,
            'label' => 'Block 1',
            'kind' => 'week_block',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
            'starts_label' => ['date' => '2026-07-01'],
            'ends_label' => ['date' => '2026-07-28'],
            'weeks' => $weeks,
        ];
    }

    /**
     * The shape `Calendar::weeksIn()` returns for 2026-07-01 → 2026-07-28: five weeks, the first
     * and last clipped to the period, written out literally rather than computed so that this file
     * genuinely touches no calendar.
     */
    private function fiveWeekPeriod(): array
    {
        $week = fn (string $from, string $to, string $clippedFrom, string $clippedTo): array => [
            'starts_on' => $from,
            'ends_on' => $to,
            'clipped_starts_on' => $clippedFrom,
            'clipped_ends_on' => $clippedTo,
            'starts_label' => ['date' => $from],
            'ends_label' => ['date' => $to],
        ];

        return [$this->period([
            $week('2026-06-28', '2026-07-04', '2026-07-01', '2026-07-04'),
            $week('2026-07-05', '2026-07-11', '2026-07-05', '2026-07-11'),
            $week('2026-07-12', '2026-07-18', '2026-07-12', '2026-07-18'),
            $week('2026-07-19', '2026-07-25', '2026-07-19', '2026-07-25'),
            $week('2026-07-26', '2026-08-01', '2026-07-26', '2026-07-28'),
        ])];
    }

    /** @param array<int|string, array<string, mixed>> $cells */
    private function row(int $personId, ?int $groupLevelId, array $cells, bool $stale = false): array
    {
        return [
            'person' => ['id' => $personId, 'full_name' => 'Person '.$personId, 'short_name' => 'P'.$personId],
            'group_level_id' => $groupLevelId,
            'stale' => $stale,
            'cells' => $cells,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $spans
     * @param  list<array<string, mixed>>  $vacations
     */
    private function cell(array $spans, int $uncoveredDays, ?int $levelId, array $vacations = []): array
    {
        return [
            'spans' => $spans,
            'uncovered_days' => $uncoveredDays,
            'level_id' => $levelId,
            'vacations' => $vacations,
        ];
    }

    private function span(int $unitId, string $from, string $to, int $days): array
    {
        return [
            'id' => $unitId * 1000 + $days,
            'unit_id' => $unitId,
            'unit_code' => $unitId === self::PICU ? 'PICU' : 'NICU',
            'starts_on' => $from,
            'ends_on' => $to,
            'days' => $days,
            'starts_label' => ['date' => $from],
            'ends_label' => ['date' => $to],
        ];
    }

    /** Walks the whole tree — a key the assertion did not look for is a key that can leak. */
    private function assertNoHumanFields(array $node, string $path, array &$offenders): void
    {
        $forbidden = ['email', 'phone', 'full_name', 'short_name', 'name', 'notes', 'person'];

        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && in_array($key, $forbidden, true)) {
                $offenders[] = "the summary carries a \"{$key}\" at {$here}";
            }

            // A Y-m-d bound is the only string this computation has any business carrying; any
            // other free text arrived from a person, a unit or a period label.
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                $offenders[] = "the summary carries free text at {$here}: \"{$value}\"";
            }

            if (is_array($value)) {
                $this->assertNoHumanFields($value, $here, $offenders);
            }
        }
    }
}
