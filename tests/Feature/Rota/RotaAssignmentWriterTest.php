<?php

namespace Tests\Feature\Rota;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Support\Rota\RotaAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `App\Support\Rota\RotaAssignment` is the ONE writer of `master_rota_assignments` (P1d Decision
 * F). Every method here proves both the outcome-string contract (ASSIGNED/UNCHANGED/REPLACED/
 * CLEARED/NOTHING_TO_CLEAR) callers use to render feedback and the refuse-before-write discipline
 * `split()` follows: a rejected split must never have destroyed the good state it started from.
 */
class RotaAssignmentWriterTest extends TestCase
{
    use RefreshDatabase;

    private Period $period;

    private Person $person;

    private Unit $unitA;

    private Unit $unitB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->period = Period::factory()->create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->person = Person::factory()->create();
        $this->unitA = Unit::create(['code' => 'RWA', 'name' => 'Rota Writer A', 'active' => true]);
        $this->unitB = Unit::create(['code' => 'RWB', 'name' => 'Rota Writer B', 'active' => true]);
    }

    public function test_set_on_an_empty_cell_returns_assigned_and_writes_one_period_wide_row(): void
    {
        $outcome = RotaAssignment::set($this->person, $this->period, $this->unitA);

        $this->assertSame(RotaAssignment::ASSIGNED, $outcome);

        $rows = MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-07-01', $rows[0]->starts_on->format('Y-m-d'));
        $this->assertSame('2026-07-28', $rows[0]->ends_on->format('Y-m-d'));
        $this->assertSame($this->unitA->getKey(), $rows[0]->unit_id);
    }

    public function test_set_with_the_same_unit_twice_returns_unchanged_and_writes_nothing(): void
    {
        RotaAssignment::set($this->person, $this->period, $this->unitA);
        $before = MasterRotaAssignment::query()->pluck('id')->all();

        $outcome = RotaAssignment::set($this->person, $this->period, $this->unitA);

        $this->assertSame(RotaAssignment::UNCHANGED, $outcome);
        $this->assertSame($before, MasterRotaAssignment::query()->pluck('id')->all());
    }

    public function test_set_over_a_split_returns_replaced_and_leaves_exactly_one_row(): void
    {
        RotaAssignment::split($this->person, $this->period, [
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $this->unitB->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
        ]);

        $outcome = RotaAssignment::set($this->person, $this->period, $this->unitA);

        $this->assertSame(RotaAssignment::REPLACED, $outcome);
        $this->assertCount(1, MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get());
    }

    public function test_split_with_two_contiguous_spans_writes_two_rows(): void
    {
        $outcome = RotaAssignment::split($this->person, $this->period, [
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $this->unitB->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
        ]);

        $this->assertSame(RotaAssignment::ASSIGNED, $outcome);
        $this->assertCount(2, MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get());
    }

    public function test_split_with_a_gap_writes_two_rows_and_leaves_the_gap(): void
    {
        RotaAssignment::split($this->person, $this->period, [
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-07'],
            ['unit_id' => $this->unitB->getKey(), 'starts_on' => '2026-07-21', 'ends_on' => '2026-07-28'],
        ]);

        $rows = MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->orderBy('starts_on')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('2026-07-07', $rows[0]->ends_on->format('Y-m-d'));
        $this->assertSame('2026-07-21', $rows[1]->starts_on->format('Y-m-d'));
    }

    public function test_split_with_mutually_overlapping_spans_throws_and_leaves_the_previous_assignment_untouched(): void
    {
        RotaAssignment::set($this->person, $this->period, $this->unitA);

        $before = MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get()
            ->toArray();

        try {
            RotaAssignment::split($this->person, $this->period, [
                ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
                ['unit_id' => $this->unitB->getKey(), 'starts_on' => '2026-07-10', 'ends_on' => '2026-07-28'],
            ]);
            $this->fail('Expected InvalidArgumentException for a self-overlapping split.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $after = MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get()
            ->toArray();

        $this->assertSame($before, $after);
    }

    public function test_split_reaching_outside_the_period_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RotaAssignment::split($this->person, $this->period, [
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-06-30', 'ends_on' => '2026-07-28'],
        ]);
    }

    public function test_split_with_an_empty_array_throws_with_the_clear_it_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('clear it');

        RotaAssignment::split($this->person, $this->period, []);
    }

    public function test_split_with_a_date_that_is_not_y_m_d_throws(): void
    {
        $this->expectException(\Exception::class);

        RotaAssignment::split($this->person, $this->period, [
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '07/01/2026', 'ends_on' => '2026-07-28'],
        ]);
    }

    public function test_clear_on_an_empty_cell_returns_nothing_to_clear_and_writes_nothing(): void
    {
        $outcome = RotaAssignment::clear($this->person, $this->period);

        $this->assertSame(RotaAssignment::NOTHING_TO_CLEAR, $outcome);
        $this->assertSame(0, MasterRotaAssignment::query()->count());
    }

    public function test_clear_removes_every_span_for_that_person_and_period_and_no_other_persons(): void
    {
        $other = Person::factory()->create();

        RotaAssignment::set($this->person, $this->period, $this->unitA);
        RotaAssignment::set($other, $this->period, $this->unitB);

        $outcome = RotaAssignment::clear($this->person, $this->period);

        $this->assertSame(RotaAssignment::CLEARED, $outcome);
        $this->assertSame(0, MasterRotaAssignment::query()->where('person_id', $this->person->getKey())->count());
        $this->assertSame(1, MasterRotaAssignment::query()->where('person_id', $other->getKey())->count());
    }
}
