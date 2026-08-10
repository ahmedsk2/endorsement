<?php

namespace Tests\Feature\Rota;

use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Munawib MR-02. Decision G's bounded-query grid — seven queries as Task 8 shipped it, eight since
 * pre-merge finding 1 added the stale-row union: rows by level (held at the academic year's
 * start), columns by period, one cell per (person, period) carrying its spans, its uncovered
 * days, the level held AT THAT PERIOD, and any vacation overlay.
 *
 * `Level::factory()->create(['code' => 'R1'])` collides with `ReferenceSeeder`'s seeded ladder
 * (P1c Task 3/Task 7's recorded trap) — every level created here uses an `XG`-prefixed code.
 */
class RotaGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_the_grid_renders_a_row_per_active_person_and_a_column_per_period(): void
    {
        [, $people] = $this->seedYear(periods: 13, people: 6);

        $grid = $this->fetchGrid();

        $this->assertCount(13, $grid['periods']);

        // Not an exact row count: fetchGrid()'s own admin actor carries a linked Person row too
        // (UserFactory always creates one, P0c) — asserting membership rather than a total is
        // what stays honest about that rather than special-casing it away.
        $rowIds = array_map(fn (array $r) => $r['person']['id'], $grid['rows']);

        foreach ($people as $person) {
            $this->assertContains($person->getKey(), $rowIds);
        }
    }

    public function test_rows_are_grouped_by_the_level_held_on_the_academic_years_start_date(): void
    {
        [$periods, $people, $unit, $level] = $this->seedYear(periods: 3, people: 1);
        $person = $people[0];

        $promoted = Level::factory()->create(['code' => 'XG2', 'display_order' => 20]);
        // Promoted partway through period 2 — the row group must still reflect the level held
        // at the ACADEMIC YEAR's start, not the promoted level.
        LevelAssignment::assign($person, $promoted, $periods[1]->starts_on->format('Y-m-d'));

        $grid = $this->fetchGrid();
        $row = $this->rowFor($grid, $person);

        $this->assertSame($level->getKey(), $row['group_level_id']);

        // The person appears in exactly ONE row, i.e. one group — never split across two.
        $matches = array_filter($grid['rows'], fn (array $r): bool => $r['person']['id'] === $person->getKey());
        $this->assertCount(1, $matches);
    }

    public function test_a_cell_carries_the_level_held_at_its_own_period_when_it_differs_from_the_row_group(): void
    {
        [$periods, $people, $unit, $level] = $this->seedYear(periods: 3, people: 1);
        $person = $people[0];

        $promoted = Level::factory()->create(['code' => 'XG3', 'display_order' => 20]);
        LevelAssignment::assign($person, $promoted, $periods[1]->starts_on->format('Y-m-d'));

        $grid = $this->fetchGrid();
        $row = $this->rowFor($grid, $person);

        $this->assertSame($level->getKey(), $row['cells'][$periods[0]->getKey()]['level_id']);
        $this->assertSame($promoted->getKey(), $row['cells'][$periods[1]->getKey()]['level_id']);
        $this->assertNotSame($row['group_level_id'], $row['cells'][$periods[1]->getKey()]['level_id']);
    }

    public function test_a_retired_person_is_not_offered_a_row(): void
    {
        $this->seedYear(periods: 2, people: 2);
        $retired = Person::factory()->inactive()->create();

        $grid = $this->fetchGrid();

        $ids = array_map(fn (array $r) => $r['person']['id'], $grid['rows']);
        $this->assertNotContains($retired->getKey(), $ids);
    }

    public function test_every_date_reaching_the_client_is_server_formatted_and_dual_dated(): void
    {
        $this->seedYear(periods: 2, people: 1);

        $grid = $this->fetchGrid();

        foreach ($grid['periods'] as $period) {
            $this->assertArrayHasKey('date', $period['starts_label']);
            $this->assertArrayHasKey('hijri', $period['starts_label']);
            $this->assertArrayHasKey('date', $period['ends_label']);
            $this->assertArrayHasKey('hijri', $period['ends_label']);
        }
    }

    public function test_a_cell_with_two_spans_reports_both_and_the_uncovered_days(): void
    {
        [$periods, $people, $unitA] = $this->seedYear(periods: 1, people: 1);
        $person = $people[0];
        $period = $periods[0];

        $unitB = Unit::create(['code' => 'XGB', 'name' => 'Rota Grid Unit B', 'active' => true]);

        $from = $period->starts_on->format('Y-m-d');
        $mid1 = CarbonImmutable::parse($from)->addDays(9)->format('Y-m-d');
        $mid2 = CarbonImmutable::parse($from)->addDays(18)->format('Y-m-d');
        $to = $period->ends_on->format('Y-m-d');

        RotaAssignment::split($person, $period, [
            ['unit_id' => $unitA->getKey(), 'starts_on' => $from, 'ends_on' => $mid1],
            ['unit_id' => $unitB->getKey(), 'starts_on' => $mid2, 'ends_on' => $to],
        ]);

        $grid = $this->fetchGrid();
        $cell = $this->rowFor($grid, $person)['cells'][$period->getKey()];

        $this->assertCount(2, $cell['spans']);
        $this->assertGreaterThan(0, $cell['uncovered_days']);
    }

    /**
     * The other half of `AvailabilitySummary`'s contract (P1d-2 Task 2, Decision B): the summary
     * is a pure fold that touches no date at all, which is only possible because each span arrives
     * carrying its own day count. It is computed HERE, where the Carbon objects are already in hand
     * for `uncovered_days`, rather than re-derived downstream — one fewer place a date converter
     * could ever appear (ST-06).
     *
     * The counts are asserted with assertSame, so they are also the assertion that these are whole
     * INTEGERS: Carbon 3's diffInDays() returns a float, and a day count that reaches the client as
     * 8.0 is a day count somebody will eventually compare against 8 and lose.
     */
    public function test_every_span_carries_its_own_day_count(): void
    {
        [$periods, $people, $unitA] = $this->seedYear(periods: 1, people: 1);
        $person = $people[0];
        $period = $periods[0];

        $unitB = Unit::create(['code' => 'XGD', 'name' => 'Rota Grid Unit D', 'active' => true]);

        $from = $period->starts_on->format('Y-m-d');
        $mid1 = $period->starts_on->addDays(9)->format('Y-m-d');   // 10 days
        $mid2 = $period->starts_on->addDays(18)->format('Y-m-d');  // an 8-day gap between them
        $to = $period->ends_on->format('Y-m-d');                   // 10 days, 28 in the period

        RotaAssignment::split($person, $period, [
            ['unit_id' => $unitA->getKey(), 'starts_on' => $from, 'ends_on' => $mid1],
            ['unit_id' => $unitB->getKey(), 'starts_on' => $mid2, 'ends_on' => $to],
        ]);

        $cell = $this->rowFor($this->fetchGrid(), $person)['cells'][$period->getKey()];

        $this->assertSame(10, $cell['spans'][0]['days']);
        $this->assertSame(10, $cell['spans'][1]['days']);
        $this->assertSame(8, $cell['uncovered_days']);
    }

    public function test_a_vacation_intersecting_a_period_appears_on_that_periods_cell(): void
    {
        [$periods, $people] = $this->seedYear(periods: 2, people: 1);
        $person = $people[0];

        // Straddles the boundary between period 1 and period 2.
        $boundary = $periods[0]->ends_on->format('Y-m-d');
        $after = $periods[1]->starts_on->addDays(2)->format('Y-m-d');

        VacationBooking::book($person, $boundary, $after, Vacation::GRANULARITY_DATE);

        $grid = $this->fetchGrid();
        $row = $this->rowFor($grid, $person);

        $this->assertCount(1, $row['cells'][$periods[0]->getKey()]['vacations']);
        $this->assertCount(1, $row['cells'][$periods[1]->getKey()]['vacations']);
    }

    /**
     * Pre-merge finding 3: this test used to seed 60 people and 13 periods and then measure an
     * ENTIRELY EMPTY grid — no assignment, no vacation, no promotion, no stale row — so
     * `assertLessThan(20)` only ever proved the empty case. Every N+1 the class docblock names is
     * per-SPAN, per-VACATION or per-CELL-WITH-DATA; none of them can fire on a grid with nothing
     * in it. The year is now populated before the measurement: 1170 assignment rows (30 people
     * whole-period, 30 people split in two, across all 13 periods), 120 vacations, 30 mid-year
     * promotions, and finding 1's deactivated person, whose row arrives through the stale-row
     * union rather than the active roster.
     */
    public function test_the_whole_grid_is_a_bounded_number_of_queries(): void
    {
        [$periods, $people, $unitA, $level] = $this->seedYear(periods: 13, people: 60);

        $unitB = Unit::create(['code' => 'XGQ', 'name' => 'Rota Grid Unit Q', 'active' => true]);
        $promoted = Level::factory()->create(['code' => 'XGQ2', 'display_order' => 20]);

        foreach ($people as $index => $person) {
            foreach ($periods as $period) {
                $from = $period->starts_on->format('Y-m-d');
                $to = $period->ends_on->format('Y-m-d');

                if ($index % 2 === 0) {
                    RotaAssignment::set($person, $period, $unitA);

                    continue;
                }

                // A split cell with a deliberate gap — the shape a per-span N+1 would feed on.
                $mid = $period->starts_on->addDays(9)->format('Y-m-d');
                $resume = $period->starts_on->addDays(13)->format('Y-m-d');

                RotaAssignment::split($person, $period, [
                    ['unit_id' => $unitA->getKey(), 'starts_on' => $from, 'ends_on' => $mid],
                    ['unit_id' => $unitB->getKey(), 'starts_on' => $resume, 'ends_on' => $to],
                ]);
            }

            // Two vacations each, in different periods — 120 rows across the year.
            VacationBooking::book(
                $person,
                $periods[2]->starts_on->addDay()->format('Y-m-d'),
                $periods[2]->starts_on->addDays(3)->format('Y-m-d'),
                Vacation::GRANULARITY_DATE,
            );
            VacationBooking::book(
                $person,
                $periods[8]->starts_on->addDay()->format('Y-m-d'),
                $periods[8]->starts_on->addDays(3)->format('Y-m-d'),
                Vacation::GRANULARITY_DATE,
            );

            // Half the roster is promoted mid-year, so half the rows carry a second level span
            // and every cell's level must be resolved from a MULTI-span history in memory.
            if ($index % 2 === 0) {
                LevelAssignment::assign($person, $promoted, $periods[6]->starts_on->format('Y-m-d'));
            }
        }

        // Finding 1's stale rows: assigned, then deactivated. They reach the grid through the
        // union, not the active roster. TEN of them, not one — a union written as one query per
        // stale person costs exactly one query when there is exactly one stale person, so a
        // single fixture could never tell the two implementations apart. At ten, the N+1 shape
        // lands on 25 queries against this bound of 20 (verified by writing that N+1 and watching
        // this test go red before restoring the union).
        for ($i = 0; $i < 10; $i++) {
            $departed = Person::factory()->create();
            LevelAssignment::assign($departed, $level, $periods[0]->starts_on->format('Y-m-d'));
            RotaAssignment::set($departed, $periods[0], $unitA);
            $departed->forceFill(['active' => false])->save();
        }

        $admin = User::factory()->create(['position' => 0]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($admin)->get('/admin/rota?year=2026-2027')->assertOk();

        $count = count(DB::getQueryLog());

        // Measured, not assumed (this plan's own "evidence before arithmetic" instruction):
        // Decision G's eight data queries (seven, plus finding 1's stale-row union) plus the
        // framework's own session/auth/capability reads and Person::levelSpansBetween()'s
        // eager-loaded 'level' relation currently total 16 for this exact request — the same
        // figure this request produced with an EMPTY year before the seeding above was added,
        // which is the whole point: the count does not grow with 60 people, 13 periods, 1170
        // spans, 120 vacations, 30 promotions or a stale row. A per-cell Person::levelAt() would
        // be 780 queries on its own, a per-cell $assignment->unit another 1170, and a per-stale-
        // person union one more each; all would blow well past this bound.
        $this->assertLessThan(20, $count, "the grid ran {$count} queries for 780 cells");
    }

    /**
     * @return array{0: list<Period>, 1: list<Person>, 2: Unit, 3: Level}
     */
    private function seedYear(int $periods, int $people, string $academicYear = '2026-2027'): array
    {
        $level = Level::factory()->create(['code' => 'XG1', 'display_order' => 10]);
        $unit = Unit::create(['code' => 'XGU', 'name' => 'Rota Grid Unit', 'active' => true]);

        $periodModels = [];
        $start = CarbonImmutable::parse('2026-07-01');

        for ($i = 1; $i <= $periods; $i++) {
            $periodStart = $start->addWeeks(($i - 1) * 4);
            $periodEnd = $periodStart->addWeeks(4)->subDay();

            $periodModels[] = Period::create([
                'academic_year' => $academicYear,
                'kind' => Period::WEEK_BLOCK,
                'position' => $i,
                'label' => "Block {$i}",
                'starts_on' => $periodStart->format('Y-m-d'),
                'ends_on' => $periodEnd->format('Y-m-d'),
            ]);
        }

        $peopleModels = [];

        for ($i = 1; $i <= $people; $i++) {
            $person = Person::factory()->create();
            LevelAssignment::assign($person, $level, $periodModels[0]->starts_on->format('Y-m-d'));
            $peopleModels[] = $person;
        }

        return [$periodModels, $peopleModels, $unit, $level];
    }

    /** @return array<string, mixed> */
    private function fetchGrid(): array
    {
        $admin = User::factory()->create(['position' => 0]);

        $captured = null;

        $this->actingAs($admin)->get('/admin/rota?year=2026-2027')->assertOk()
            ->assertInertia(function (Assert $page) use (&$captured): void {
                $captured = $page->toArray()['props']['grid'];
            });

        $this->assertIsArray($captured, 'the grid prop was not an array — is the year query string wrong?');

        return $captured;
    }

    /** @return array<string, mixed> */
    private function rowFor(array $grid, Person $person): array
    {
        foreach ($grid['rows'] as $row) {
            if ($row['person']['id'] === $person->getKey()) {
                return $row;
            }
        }

        $this->fail("No row found for person {$person->getKey()}.");
    }
}
