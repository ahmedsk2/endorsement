<?php

namespace Tests\Feature\Rota;

use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\RotaFill;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Munawib MR-06's bulk moves, as a PLANNER (P1d-2 Task 7). `RotaFill::plan()` computes a delta and
 * writes nothing; Task 8's `apply()` re-runs the same private `analyse()` inside a transaction.
 *
 * The periods this fixture builds are DELIBERATELY OF DIFFERENT LENGTHS (28, 35, 28, 21, 28 days).
 * MR-01 permits that, and it is the whole reason Decision E exists: a split cannot be mapped onto a
 * period of another length without inventing dates or truncating a unit. A fixture of equal-length
 * periods would let a "just reuse the source dates" implementation pass every cross-period case
 * here, which is exactly the bug these tests are for.
 *
 * `Level::factory()->create(['code' => 'R1'])` collides with `ReferenceSeeder`'s seeded ladder
 * (P1c Task 3/Task 7's recorded trap) — every level created here uses an `XF`-prefixed code, and
 * so does every unit.
 */
class RotaFillPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    public function test_fill_down_a_level_group_targets_only_that_group(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior, $senior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);
        $other = $this->person($senior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_LEVEL, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $this->assertSame([], $plan['errors']);
        $this->assertSame([$peer->getKey()], $this->targetPeopleIn($plan));
        $this->assertNotContains($other->getKey(), $this->targetPeopleIn($plan));
        $this->assertNotContains($source->getKey(), $this->targetPeopleIn($plan));
    }

    public function test_fill_down_a_column_targets_every_person_in_the_period(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior, $senior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);
        $other = $this->person($senior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $ids = $this->targetPeopleIn($plan);
        sort($ids);
        $expected = [$peer->getKey(), $other->getKey()];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains($source->getKey(), $ids);
    }

    public function test_fill_down_copies_a_split_verbatim(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        $spans = $this->splitSpans($periods[0], $unitA, $unitB);
        RotaAssignment::split($source, $periods[0], $spans);

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $target = $this->targetFor($plan, $peer, $periods[0]);

        $this->assertSame(RotaFill::ASSIGN, $target['outcome']);
        // Verbatim: the same period, so the same dates are already inside it by construction.
        $this->assertSame($spans, $target['proposed']);
    }

    public function test_fill_across_goes_forwards_only(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        RotaAssignment::set($source, $periods[2], $unit);

        $plan = RotaFill::plan(RotaFill::FILL_ACROSS, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[2]->getKey(),
        ], []);

        $this->assertSame([], $plan['errors']);
        $this->assertSame(
            [$periods[3]->getKey(), $periods[4]->getKey()],
            array_map(fn (array $t): int => $t['period_id'], $plan['targets']),
        );
    }

    public function test_fill_across_from_a_split_source_is_refused_per_cell(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        RotaAssignment::split($source, $periods[0], $this->splitSpans($periods[0], $unitA, $unitB));

        $plan = RotaFill::plan(RotaFill::FILL_ACROSS, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $this->assertNotSame([], $plan['targets']);

        foreach ($plan['targets'] as $target) {
            $this->assertSame(RotaFill::SKIP_SPLIT_SOURCE, $target['outcome']);
            $this->assertStringContainsString('length', (string) $target['reason']);
            $this->assertSame([], $target['proposed']);
        }

        $this->assertSame(count($plan['targets']), $plan['summary']['skipped']);
    }

    public function test_copy_period_maps_whole_period_assignments_onto_the_targets_own_bounds(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $person = $this->person($junior, $periods[0]);
        RotaAssignment::set($person, $periods[0], $unit);

        // Period 2 is 35 days long; period 1 is 28. A copy that reused the source's dates would
        // leave the target seven days short, and one that rescaled would invent dates.
        $plan = RotaFill::plan(RotaFill::COPY_PERIOD, [
            'period_id' => $periods[0]->getKey(),
            'target_period_id' => $periods[1]->getKey(),
        ], []);

        $target = $this->targetFor($plan, $person, $periods[1]);

        $this->assertSame(RotaFill::ASSIGN, $target['outcome']);
        $this->assertSame([[
            'unit_id' => $unit->getKey(),
            'starts_on' => $periods[1]->starts_on->format('Y-m-d'),
            'ends_on' => $periods[1]->ends_on->format('Y-m-d'),
        ]], $target['proposed']);
    }

    public function test_a_target_carrying_a_split_is_skipped_unless_confirmed(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unitA);
        RotaAssignment::split($peer, $periods[0], $this->splitSpans($periods[0], $unitA, $unitB));

        $args = [RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ]];

        $unconfirmed = RotaFill::plan($args[0], $args[1], []);
        $target = $this->targetFor($unconfirmed, $peer, $periods[0]);
        $this->assertSame(RotaFill::SKIP_SPLIT_TARGET, $target['outcome']);
        $this->assertNotNull($target['reason']);
        $this->assertSame(1, $unconfirmed['summary']['skipped']);
        // A confirmation an operator cannot see the consequence of is not a confirmation: this is
        // the ONE skip that still carries its proposal, because the operator is being asked to
        // choose between the two span sets in front of them.
        $this->assertNotSame([], $target['proposed']);
        $this->assertNotSame($target['current'], $target['proposed']);

        $key = $peer->getKey().':'.$periods[0]->getKey();
        $this->assertSame($key, $target['key']);

        $confirmed = RotaFill::plan($args[0], $args[1], [$key => true]);
        $target = $this->targetFor($confirmed, $peer, $periods[0]);
        $this->assertSame(RotaFill::REPLACE, $target['outcome']);
        $this->assertNull($target['reason']);
        $this->assertSame(0, $confirmed['summary']['skipped']);
        $this->assertSame(1, $confirmed['summary']['replace']);
    }

    public function test_an_inactive_target_person_is_skipped(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $departed = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);
        // Assigned, THEN deactivated — the state the system reaches on its own, and the row the
        // editor shows read-only. It is a target the operator can see, so it is named and
        // skipped, never silently dropped.
        RotaAssignment::set($departed, $periods[1], $unit);
        $departed->forceFill(['active' => false])->save();

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $target = $this->targetFor($plan, $departed, $periods[0]);

        $this->assertSame(RotaFill::SKIP_STALE_PERSON, $target['outcome']);
        $this->assertStringContainsString('roster', (string) $target['reason']);
        $this->assertSame([], $target['proposed']);
    }

    public function test_a_retired_source_unit_is_skipped(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);
        $unit->forceFill(['active' => false])->save();

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $target = $this->targetFor($plan, $peer, $periods[0]);

        $this->assertSame(RotaFill::SKIP_RETIRED_UNIT, $target['outcome']);
        $this->assertStringContainsString('retired', (string) $target['reason']);
        $this->assertSame([], $target['proposed']);
    }

    public function test_an_identical_target_is_unchanged_not_replaced(): void
    {
        [$periods, $unit] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        RotaAssignment::set($source, $periods[0], $unit);
        RotaAssignment::set($peer, $periods[0], $unit);

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $target = $this->targetFor($plan, $peer, $periods[0]);

        $this->assertSame(RotaFill::UNCHANGED, $target['outcome']);
        $this->assertSame(0, $plan['summary']['replace']);
        $this->assertSame(0, $plan['summary']['assign']);
        $this->assertSame(0, $plan['summary']['skipped']);
        $this->assertSame(1, $plan['summary']['unchanged']);
    }

    public function test_an_identical_split_target_is_unchanged_and_needs_no_confirmation(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $peer = $this->person($junior, $periods[0]);

        $spans = $this->splitSpans($periods[0], $unitA, $unitB);
        RotaAssignment::split($source, $periods[0], $spans);
        RotaAssignment::split($peer, $periods[0], $spans);

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        // Nothing would be destroyed, so demanding a tick would be a confirmation for a no-op.
        $this->assertSame(RotaFill::UNCHANGED, $this->targetFor($plan, $peer, $periods[0])['outcome']);
    }

    public function test_an_empty_source_cell_is_an_error_and_plans_nothing(): void
    {
        [$periods] = $this->seedYear();
        [$junior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        $this->person($junior, $periods[0]);

        $plan = RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []);

        $this->assertNotSame([], $plan['errors']);
        $this->assertSame([], $plan['targets']);
    }

    public function test_an_unknown_operation_is_an_error_not_an_empty_plan(): void
    {
        [$periods] = $this->seedYear();

        $plan = RotaFill::plan('fill_sideways', ['period_id' => $periods[0]->getKey()], []);

        $this->assertNotSame([], $plan['errors']);
        $this->assertSame([], $plan['targets']);
    }

    public function test_the_same_inputs_produce_the_same_plan(): void
    {
        [$periods, $unitA] = $this->seedYear();
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior, $senior] = $this->twoLevels();

        $source = $this->person($junior, $periods[0]);
        RotaAssignment::set($source, $periods[0], $unitA);

        for ($i = 0; $i < 5; $i++) {
            $peer = $this->person($i % 2 === 0 ? $junior : $senior, $periods[0]);

            if ($i === 1) {
                RotaAssignment::split($peer, $periods[0], $this->splitSpans($periods[0], $unitA, $unitB));
            }
        }

        $args = [RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $source->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], []];

        $first = RotaFill::plan(...$args);

        // Non-vacuity: two EMPTY plans are trivially identical, so the claim is worthless without
        // this. Five peers, one of them holding a split, is a mixed-outcome plan.
        $this->assertCount(5, $first['targets']);

        $this->assertSame(
            $first,
            RotaFill::plan(...$args),
            'The same inputs must produce the same delta — a preview an operator confirms has to be the plan that ran.',
        );
    }

    /**
     * THE TEST THAT MAKES THE SEPARATION REAL. A plan over a fully populated year names hundreds of
     * target cells and leaves the table byte-identical: same count, same rows, same dates.
     */
    public function test_plan_writes_nothing(): void
    {
        [$periods, $unitA] = $this->seedYear(13);
        $unitB = Unit::create(['code' => 'XFB', 'name' => 'Fill Unit B', 'active' => true]);
        [$junior, $senior] = $this->twoLevels();

        $people = [];

        for ($i = 0; $i < 30; $i++) {
            $person = $this->person($i % 2 === 0 ? $junior : $senior, $periods[0]);
            $people[] = $person;

            foreach ($periods as $index => $period) {
                if ($i % 3 === 0 && $index % 4 === 0) {
                    RotaAssignment::split($person, $period, $this->splitSpans($period, $unitA, $unitB));

                    continue;
                }

                RotaAssignment::set($person, $period, $unitA);
            }
        }

        $before = $this->assignmentDigest();
        $this->assertGreaterThan(300, MasterRotaAssignment::query()->count());

        $planned = 0;

        foreach ($people as $person) {
            $plan = RotaFill::plan(RotaFill::FILL_ACROSS, [
                'person_id' => $person->getKey(),
                'period_id' => $periods[0]->getKey(),
            ], []);
            $planned += count($plan['targets']);
        }

        $planned += count(RotaFill::plan(RotaFill::FILL_DOWN_COLUMN, [
            'person_id' => $people[0]->getKey(),
            'period_id' => $periods[0]->getKey(),
        ], [])['targets']);

        $planned += count(RotaFill::plan(RotaFill::COPY_PERIOD, [
            'period_id' => $periods[0]->getKey(),
            'target_period_id' => $periods[5]->getKey(),
        ], [])['targets']);

        $this->assertGreaterThan(300, $planned, 'the fixture must plan hundreds of cells for this to prove anything');

        $after = $this->assignmentDigest();

        // The count first, because it is the readable half of the failure. Then a HASH of the whole
        // row set, dates included — a count alone would pass a plan that swapped one row for
        // another, and printing 390 serialised rows into a failure message is the "never dump a
        // failing suite into context" rule broken by the test that was supposed to help.
        $this->assertSame(substr_count($before, '"id":'), substr_count($after, '"id":'), 'plan() changed the number of assignment rows');
        $this->assertSame(md5($before), md5($after), 'plan() changed the assignment rows themselves — it must write nothing at all.');
    }

    /**
     * Measured on this fixture (40 people, 13 periods), by reading a deliberately unreachable
     * `assertLessThan(1, …)` and restoring it: `fill_down_level` **6**, `fill_down_column` **4**,
     * `fill_across` **5**, `copy_period` **5**. Constant in the number of targets — the bound is
     * what is asserted, not the exact figure, because a plan is issued behind an authenticated
     * request whose own reads this test does not model.
     */
    public function test_the_plan_is_a_bounded_number_of_queries(): void
    {
        [$periods, $unitA] = $this->seedYear(13);
        [$junior, $senior] = $this->twoLevels();

        $people = [];

        for ($i = 0; $i < 40; $i++) {
            $person = $this->person($i % 2 === 0 ? $junior : $senior, $periods[0]);
            $people[] = $person;
            RotaAssignment::set($person, $periods[0], $unitA);
        }

        RotaAssignment::set($people[0], $periods[1], $unitA);

        foreach ([
            [RotaFill::FILL_DOWN_LEVEL, ['person_id' => $people[0]->getKey(), 'period_id' => $periods[0]->getKey()]],
            [RotaFill::FILL_DOWN_COLUMN, ['person_id' => $people[0]->getKey(), 'period_id' => $periods[0]->getKey()]],
            [RotaFill::FILL_ACROSS, ['person_id' => $people[0]->getKey(), 'period_id' => $periods[0]->getKey()]],
            [RotaFill::COPY_PERIOD, ['period_id' => $periods[0]->getKey(), 'target_period_id' => $periods[7]->getKey()]],
        ] as [$op, $source]) {
            DB::enableQueryLog();
            DB::flushQueryLog();

            $plan = RotaFill::plan($op, $source, []);

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            // Non-vacuity: a budget is trivially met by a plan that resolved nothing, and a
            // measured "0 queries" would look identical to a correct bounded one.
            $this->assertNotSame([], $plan['targets'], "{$op} planned no target — the budget below would prove nothing.");
            $this->assertGreaterThan(0, $count);

            $this->assertLessThan(10, $count, "{$op} planned in {$count} queries — a per-target lookup is the 780-query preview this class exists to avoid.");
        }
    }

    // ---------------------------------------------------------------- fixture

    /**
     * Periods of DIFFERENT lengths, deliberately (MR-01, Decision E).
     *
     * @return array{0: list<Period>, 1: Unit}
     */
    private function seedYear(int $count = 5, string $academicYear = '2026-2027'): array
    {
        $lengths = [28, 35, 28, 21, 28, 28, 35, 28, 28, 21, 28, 28, 35];

        $periods = [];
        $start = CarbonImmutable::parse('2026-07-01');

        for ($i = 1; $i <= $count; $i++) {
            $days = $lengths[($i - 1) % count($lengths)];
            $end = $start->addDays($days - 1);

            $periods[] = Period::create([
                'academic_year' => $academicYear,
                'kind' => Period::WEEK_BLOCK,
                'position' => $i,
                'label' => "Block {$i}",
                'starts_on' => $start->format('Y-m-d'),
                'ends_on' => $end->format('Y-m-d'),
            ]);

            $start = $end->addDay();
        }

        return [$periods, Unit::create(['code' => 'XFA', 'name' => 'Fill Unit A', 'active' => true])];
    }

    /** @return array{0: Level, 1: Level} */
    private function twoLevels(): array
    {
        return [
            Level::factory()->create(['code' => 'XF1', 'display_order' => 10]),
            Level::factory()->create(['code' => 'XF2', 'display_order' => 20]),
        ];
    }

    private function person(Level $level, Period $from): Person
    {
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $level, $from->starts_on->format('Y-m-d'));

        return $person;
    }

    /**
     * A two-unit split with a deliberate gap in the middle — a shape whose dates cannot be
     * reproduced on a period of another length.
     *
     * @return list<array{unit_id:int, starts_on:string, ends_on:string}>
     */
    private function splitSpans(Period $period, Unit $a, Unit $b): array
    {
        return [
            [
                'unit_id' => $a->getKey(),
                'starts_on' => $period->starts_on->format('Y-m-d'),
                'ends_on' => $period->starts_on->addDays(9)->format('Y-m-d'),
            ],
            [
                'unit_id' => $b->getKey(),
                'starts_on' => $period->starts_on->addDays(13)->format('Y-m-d'),
                'ends_on' => $period->ends_on->format('Y-m-d'),
            ],
        ];
    }

    /** @return list<int> */
    private function targetPeopleIn(array $plan): array
    {
        return array_map(fn (array $target): int => $target['person_id'], $plan['targets']);
    }

    /** @return array<string, mixed> */
    private function targetFor(array $plan, Person $person, Period $period): array
    {
        foreach ($plan['targets'] as $target) {
            if ($target['person_id'] === $person->getKey() && $target['period_id'] === $period->getKey()) {
                return $target;
            }
        }

        $this->fail("No target planned for person {$person->getKey()} in period {$period->getKey()}.");
    }

    private function assignmentDigest(): string
    {
        return MasterRotaAssignment::query()
            ->orderBy('id')
            ->get(['id', 'person_id', 'period_id', 'unit_id', 'starts_on', 'ends_on'])
            ->toJson();
    }
}
