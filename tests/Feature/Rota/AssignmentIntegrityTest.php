<?php

namespace Tests\Feature\Rota;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * MR-02, owner decisions 2 and 3.
 *
 * Every row is a date-bounded span; a whole-period assignment is the degenerate split (one row
 * whose bounds equal the period's). There is no nullable "means the whole period" range, because
 * that would give one fact two representations and every reader would have to handle both.
 *
 * OVERLAPS ARE REFUSED. Two spans covering one day for one person is one person on two units that
 * day — which the grid cannot render and MR-04's future call roster cannot resolve. This is the
 * same reasoning `Period::booted()` refuses overlapping periods with, and the model guard is
 * modelled on it deliberately.
 *
 * GAPS ARE ALLOWED. A mid-block joiner and a half-planned year are both real; the grid renders the
 * uncovered days rather than refusing the state.
 */
class AssignmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Period $period;

    private Person $person;

    private Unit $unit;

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
        // `Unit` has NO factory — verified before writing this (`database/factories/` contains
        // Holiday, Level, Period, Person, PersonLevel and User only). Every existing test builds
        // one with `Unit::create()`; follow that rather than adding a seventh factory here.
        //
        // `'active' => true` is NOT optional: `2026_08_08_120001_add_configuration_to_units.php`
        // defaults the column to FALSE, which `UnitScopeTest.php:131` records in its own comment.
        // A unit created without it is retired on arrival, and `Unit::query()->active()` — the one
        // predicate the cell picker offers from and the FormRequest validates against — will not
        // see it.
        $this->unit = Unit::create(['code' => 'RTA', 'name' => 'Rota Test A', 'active' => true]);
    }

    public function test_a_whole_period_assignment_is_one_row_spanning_the_period(): void
    {
        $row = MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($row->exists);
    }

    public function test_a_span_starting_before_its_period_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-06-30',
            'ends_on' => '2026-07-28',
        ]);
    }

    public function test_a_span_ending_after_its_period_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-29',
        ]);
    }

    public function test_a_span_ending_before_it_starts_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-10',
            'ends_on' => '2026-07-09',
        ]);
    }

    public function test_two_spans_overlapping_by_one_day_are_refused(): void
    {
        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-14',
        ]);

        $this->expectException(RuntimeException::class);

        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => Unit::create(['code' => 'RTB', 'name' => 'Rota Test B', 'active' => true])->getKey(),
            'starts_on' => '2026-07-14',      // the shared day
            'ends_on' => '2026-07-28',
        ]);
    }

    public function test_two_adjacent_spans_are_accepted(): void
    {
        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-14',
        ]);

        $second = MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => Unit::create(['code' => 'RTB', 'name' => 'Rota Test B', 'active' => true])->getKey(),
            'starts_on' => '2026-07-15',
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($second->exists);
    }

    public function test_a_gap_between_two_spans_is_accepted(): void
    {
        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-07',
        ]);

        $second = MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-21',   // fourteen uncovered days in between
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($second->exists);
    }

    public function test_two_people_may_hold_the_same_days_in_the_same_period(): void
    {
        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $other = MasterRotaAssignment::create([
            'person_id' => Person::factory()->create()->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($other->exists);
    }

    public function test_updating_a_row_does_not_see_itself_as_an_overlap(): void
    {
        $row = MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-14',
        ]);

        $row->update(['ends_on' => '2026-07-21']);

        $this->assertSame('2026-07-21', $row->fresh()->ends_on->format('Y-m-d'));
    }
}
