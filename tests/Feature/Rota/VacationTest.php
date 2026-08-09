<?php

namespace Tests\Feature\Rota;

use App\Models\Institution;
use App\Models\Period;
use App\Models\Person;
use App\Models\Vacation;
use App\Support\Calendar;
use App\Support\Rota\VacationBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Munawib AR-05/MR-03, P1d Decision C. Each case here pins a DECISION, not an implementation
 * detail — see the migration's and model's own docblocks for the reasoning each assertion pins.
 */
class VacationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Calendar::flush();
    }

    /** Create (or update) the single institution row and drop Calendar's memoized settings. */
    private function institution(array $overrides = []): Institution
    {
        $existing = Institution::first();

        if ($existing !== null) {
            $existing->update($overrides);
            Calendar::flush();

            return $existing->refresh();
        }

        $institution = Institution::create(array_merge([
            'code' => 'TEST',
            'name' => 'Test Hospital',
            'active' => true,
        ], $overrides));

        Calendar::flush();

        return $institution;
    }

    public function test_a_week_booking_snaps_to_the_departments_own_week(): void
    {
        $this->institution(['weekend_days' => [5, 6]]);
        $person = Person::factory()->create();

        // 2026-08-12 is a Wednesday; the Friday+Saturday weekend puts the week Sunday..Saturday.
        $vacation = VacationBooking::book($person, '2026-08-12', '2026-08-12', Vacation::GRANULARITY_WEEK);

        $this->assertSame('2026-08-09', $vacation->starts_on->format('Y-m-d'));
        $this->assertSame('2026-08-15', $vacation->ends_on->format('Y-m-d'));

        $this->institution(['weekend_days' => [6, 7]]);
        $other = Person::factory()->create();

        $reconfigured = VacationBooking::book($other, '2026-08-12', '2026-08-12', Vacation::GRANULARITY_WEEK);

        $this->assertSame('2026-08-10', $reconfigured->starts_on->format('Y-m-d'));
        $this->assertSame('2026-08-16', $reconfigured->ends_on->format('Y-m-d'));
    }

    public function test_a_date_booking_is_stored_verbatim(): void
    {
        $person = Person::factory()->create();

        $vacation = VacationBooking::book($person, '2026-08-12', '2026-08-14', Vacation::GRANULARITY_DATE);

        $this->assertSame('2026-08-12', $vacation->starts_on->format('Y-m-d'));
        $this->assertSame('2026-08-14', $vacation->ends_on->format('Y-m-d'));
    }

    public function test_a_multi_week_booking_snaps_both_ends(): void
    {
        $this->institution(['weekend_days' => [5, 6]]);
        $person = Person::factory()->create();

        // Wednesday 2026-08-12 .. the following Tuesday 2026-08-18 covers two whole weeks.
        $vacation = VacationBooking::book($person, '2026-08-12', '2026-08-18', Vacation::GRANULARITY_WEEK);

        $this->assertSame('2026-08-09', $vacation->starts_on->format('Y-m-d'));
        $this->assertSame('2026-08-22', $vacation->ends_on->format('Y-m-d'));
    }

    public function test_a_vacation_carries_no_period_id(): void
    {
        // P1d Decision C: a vacation crosses period boundaries, overlays an assignment rather
        // than replacing it, and must outlive periods being regenerated or the department
        // switching period systems — so it is keyed on person + date range only, never a period.
        $this->assertFalse(Schema::hasColumn('vacations', 'period_id'));
    }

    public function test_a_vacation_spanning_two_periods_is_one_row(): void
    {
        Period::factory()->create([
            'academic_year' => '2026-2027',
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-14',
        ]);
        Period::factory()->create([
            'academic_year' => '2026-2027',
            'position' => 2,
            'label' => 'Block 2',
            'starts_on' => '2026-07-15',
            'ends_on' => '2026-07-28',
        ]);

        $person = Person::factory()->create();

        VacationBooking::book($person, '2026-07-10', '2026-07-20', Vacation::GRANULARITY_DATE);

        $this->assertSame(1, Vacation::query()->count());
    }

    public function test_a_vacation_survives_its_periods_being_deleted(): void
    {
        Period::factory()->create([
            'academic_year' => '2026-2027',
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $person = Person::factory()->create();
        $vacation = VacationBooking::book($person, '2026-07-10', '2026-07-14', Vacation::GRANULARITY_DATE);

        // No assignments exist against this year, so Task 4's guard permits the delete.
        Period::query()->where('academic_year', '2026-2027')->delete();

        $this->assertSame(0, Period::query()->where('academic_year', '2026-2027')->count());
        $this->assertNotNull($vacation->fresh());
        $this->assertSame('2026-07-10', $vacation->fresh()->starts_on->format('Y-m-d'));
    }

    public function test_two_overlapping_vacations_for_one_person_are_refused(): void
    {
        $person = Person::factory()->create();

        VacationBooking::book($person, '2026-08-10', '2026-08-14', Vacation::GRANULARITY_DATE);

        $this->expectException(RuntimeException::class);

        VacationBooking::book($person, '2026-08-14', '2026-08-18', Vacation::GRANULARITY_DATE);
    }

    public function test_two_people_may_be_on_leave_the_same_week(): void
    {
        $first = Person::factory()->create();
        $second = Person::factory()->create();

        VacationBooking::book($first, '2026-08-10', '2026-08-14', Vacation::GRANULARITY_DATE);
        $vacation = VacationBooking::book($second, '2026-08-10', '2026-08-14', Vacation::GRANULARITY_DATE);

        $this->assertNotNull($vacation->fresh());
        $this->assertSame(2, Vacation::query()->count());
    }

    public function test_a_vacation_ending_before_it_starts_is_refused(): void
    {
        $person = Person::factory()->create();

        $this->expectException(RuntimeException::class);

        VacationBooking::book($person, '2026-08-14', '2026-08-10', Vacation::GRANULARITY_DATE);
    }

    public function test_the_granularity_and_source_values_are_constrained_to_the_model_constants(): void
    {
        $person = Person::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        Vacation::create([
            'institution_id' => null,
            'person_id' => $person->getKey(),
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-08-14',
            'granularity' => 'fortnight',
            'source' => Vacation::SOURCE_MANUAL,
        ]);
    }
}
