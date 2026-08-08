<?php

namespace Tests\Feature\Calendar;

use App\Models\Holiday;
use App\Models\Institution;
use App\Models\Unit;
use App\Support\Calendar;
use App\Support\MissedDays;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 7 — Munawib §30 `holidays/{holId} { name, rule:{greg?|hijri?}, equityTracked }`. The
 * model and the day-type resolver land here; the CRUD screen is P1b.
 *
 * The whole point of a Hijri-ruled holiday is that it resolves THROUGH the department's
 * `hijri_offset_days` calibration, not around it (owner decision 3 / Task 2's `Calendar::hijri()`
 * docblock) — a department that recalibrates moves its Eids with every other Hijri date rather
 * than acquiring a second, silently divergent calendar. And a holiday beats a weekend when both
 * apply, because SL-03's coverage templates (P3) key on that precedence.
 */
class HolidayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seeded (not a bare `institution()` fixture) so the denominator test below has a real
        // PICU unit to attach handovers to, matching ConverterAbsorptionTest's convention.
        $this->seed(ReferenceSeeder::class);
        Calendar::flush();
    }

    /** Create (or update) the single institution row and drop Calendar's memoized settings —
     * same helper shape as CalendarTest, so the two suites read the same either way. */
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function holiday(array $overrides = []): Holiday
    {
        $holiday = Holiday::create(array_merge([
            'name' => 'Test Holiday',
            'calendar' => Holiday::GREGORIAN,
            'month' => 1,
            'day' => 1,
            'year' => null,
            'duration_days' => 1,
            'equity_tracked' => true,
            'active' => true,
        ], $overrides));

        Calendar::flush();

        return $holiday;
    }

    public function test_a_gregorian_rule_recurs_every_year_and_matches_nothing_else(): void
    {
        $this->holiday(['name' => 'National Day', 'calendar' => Holiday::GREGORIAN, 'month' => 9, 'day' => 23]);

        $this->assertTrue(Calendar::isHoliday('2026-09-23'));
        $this->assertTrue(Calendar::isHoliday('2027-09-23'));
        $this->assertFalse(Calendar::isHoliday('2026-09-22'));
        $this->assertFalse(Calendar::isHoliday('2026-09-24'));
    }

    public function test_a_gregorian_rule_with_an_explicit_year_matches_that_year_only(): void
    {
        $this->holiday(['name' => 'One-off Holiday', 'calendar' => Holiday::GREGORIAN, 'month' => 12, 'day' => 25, 'year' => 2026]);

        $this->assertTrue(Calendar::isHoliday('2026-12-25'));
        $this->assertFalse(Calendar::isHoliday('2027-12-25'));
    }

    /**
     * 2027-03-09 is the Gregorian date whose offset-0 Hijri date is 1448-10-01 (confirmed via
     * IntlCalendar directly, not assumed). The rule is defined in HIJRI terms — Eid al-Fitr,
     * Shawwal 1 — so it must move to a DIFFERENT Gregorian date, not disappear, when the
     * department's calibration changes. That is the assertion that proves it resolves THROUGH
     * the offset rather than around it.
     */
    public function test_a_hijri_rule_resolves_through_the_departments_offset(): void
    {
        $this->institution(['hijri_offset_days' => 0]);
        $this->holiday(['name' => 'Eid al-Fitr', 'calendar' => Holiday::HIJRI, 'month' => 10, 'day' => 1]);

        $this->assertTrue(Calendar::isHoliday('2027-03-09'));
        $this->assertFalse(Calendar::isHoliday('2027-03-10'));

        // Recalibrate. Calendar::hijri() applies the offset to the GREGORIAN instant before
        // conversion (Task 2), so the same Hijri anchor now falls a day later on the Gregorian
        // calendar — the holiday must move WITH it, not stay pinned to the old Gregorian date.
        $this->institution(['hijri_offset_days' => -1]);

        $this->assertTrue(Calendar::isHoliday('2027-03-10'));
        $this->assertFalse(Calendar::isHoliday('2027-03-09'));
    }

    /**
     * Ramadan 1448 has 29 days at offset 0 (confirmed via IntlCalendar): 1448-09-29 falls on
     * 2027-03-08, and 1448-10-01 (Shawwal, i.e. Eid al-Fitr) falls the very next Gregorian day,
     * 2027-03-09. An anchor of "Ramadan 29" with duration_days=4 therefore spans a Gregorian
     * run that crosses a HIJRI month name boundary one day in — the case that would break an
     * implementation that tried to add duration in HIJRI days instead of Gregorian ones.
     */
    public function test_duration_days_spans_forward_across_a_hijri_month_end(): void
    {
        $this->institution(['hijri_offset_days' => 0]);
        $this->holiday([
            'name' => 'Ramadan tail (test fixture)',
            'calendar' => Holiday::HIJRI,
            'month' => 9,
            'day' => 29,
            'duration_days' => 4,
        ]);

        $this->assertFalse(Calendar::isHoliday('2027-03-07'), 'the day before the anchor');
        $this->assertTrue(Calendar::isHoliday('2027-03-08'), 'the anchor day itself (1448-09-29)');
        $this->assertTrue(Calendar::isHoliday('2027-03-09'), 'crosses into Shawwal (1448-10-01)');
        $this->assertTrue(Calendar::isHoliday('2027-03-10'), '1448-10-02');
        $this->assertTrue(Calendar::isHoliday('2027-03-11'), 'the fourth and last covered day (1448-10-03)');
        $this->assertFalse(Calendar::isHoliday('2027-03-12'), 'one day past the span');
    }

    /**
     * SL-03's day type: a holiday landing on a weekend day is still reported as a holiday, not
     * a weekend — the precedence a coverage template keys on.
     */
    public function test_a_holiday_beats_a_weekend_in_day_type(): void
    {
        // 2026-08-07 is a Friday — a weekend day under the default [5, 6] weekend_days.
        $this->assertTrue(Calendar::isWeekend('2026-08-07'));

        $this->holiday(['name' => 'Friday Holiday (test fixture)', 'calendar' => Holiday::GREGORIAN, 'month' => 8, 'day' => 7]);

        $this->assertSame(Calendar::DAY_HOLIDAY, Calendar::dayType('2026-08-07'));
    }

    public function test_day_type_is_weekend_or_weekday_absent_a_holiday(): void
    {
        $this->assertSame(Calendar::DAY_WEEKEND, Calendar::dayType('2026-08-08')); // Saturday
        $this->assertSame(Calendar::DAY_WEEKDAY, Calendar::dayType('2026-08-04')); // Tuesday
    }

    public function test_an_inactive_holiday_matches_nothing(): void
    {
        $this->holiday(['calendar' => Holiday::GREGORIAN, 'month' => 9, 'day' => 23, 'active' => false]);

        $this->assertFalse(Calendar::isHoliday('2026-09-23'));
        $this->assertSame([], Calendar::holidaysOn('2026-09-23'));
    }

    /** The one shape every screen renders a date as (Task 2/UX-04) now carries day-type data too. */
    public function test_label_carries_the_holiday_name_and_day_type(): void
    {
        $this->holiday(['name' => 'Friday Holiday (test fixture)', 'calendar' => Holiday::GREGORIAN, 'month' => 8, 'day' => 7]);

        $label = Calendar::label('2026-08-07');

        $this->assertSame('Friday Holiday (test fixture)', $label['holiday']);
        $this->assertSame(Calendar::DAY_HOLIDAY, $label['day_type']);

        $plainWeekday = Calendar::label('2026-08-04');
        $this->assertNull($plainWeekday['holiday']);
        $this->assertSame(Calendar::DAY_WEEKDAY, $plainWeekday['day_type']);
    }

    /**
     * OWNER DECISION 6 (hard constraint): the missed-days compliance counter is UNCHANGED.
     * Task 7 gives the system its first weekend AND holiday knowledge — exactly the moment that
     * knowledge could leak into the compliance denominator by accident. A holiday must count
     * toward `total_days` exactly like any other day, and MissedDays must never consult
     * Calendar::dayType()/isHoliday() at all.
     */
    public function test_missed_days_denominator_is_unaffected_by_a_holiday(): void
    {
        // A holiday that lands on 2026-07-23 (day-of-week irrelevant here — MissedDays is
        // being pinned against the calendar's HOLIDAY knowledge, not its weekend knowledge;
        // ConverterAbsorptionTest already pins the weekend side separately).
        $this->holiday(['name' => 'National Day', 'calendar' => Holiday::GREGORIAN, 'month' => 7, 'day' => 23]);
        $unit = Unit::where('code', 'PICU')->firstOrFail();
        $this->assertTrue(Calendar::isHoliday('2026-07-23'), 'fixture sanity check');

        // 2026-07-22 .. 2026-07-24: three days (all in the past relative to any test run date
        // this plan is executed on), the middle one a holiday. No sign-offs exist.
        $result = MissedDays::forRange($unit->id, '2026-07-22', '2026-07-24');

        $this->assertSame(3, $result['total_days']);
        $this->assertCount(3, $result['missed']);
        $this->assertContains('2026-07-23', array_column($result['missed'], 'date'));
    }
}
