<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Holiday;
use App\Models\Institution;
use App\Models\Unit;
use App\Models\User;
use App\Support\Calendar;
use App\Support\MissedDays;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P1a built `holidays`, `Holiday::anchoredOn()` and `Calendar::holidaysOn()`/`dayType()`, and
 * P1a's own docblock says "The CRUD screen is P1b". This is it.
 *
 * `HolidayTest` already proves the MODEL-level resolution rules (Hijri-through-the-offset,
 * duration spanning a Hijri month end, holiday-beats-weekend, the MissedDays exclusion). This
 * file proves the SCREEN that writes the rows those tests read — flush-on-write (finding 1,
 * extended to the holiday memo) and the cross-screen interaction between this screen and
 * Task 10's calendar settings screen, which neither task's own tests would catch alone.
 */
class HolidayCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0]);
        Calendar::flush();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'National Day',
            'calendar' => Holiday::GREGORIAN,
            'month' => 9,
            'day' => 23,
            'year' => null,
            'duration_days' => 1,
            'equity_tracked' => true,
            'active' => true,
        ], $overrides);
    }

    // --- Index -------------------------------------------------------------------------------

    public function test_the_index_lists_active_and_inactive_rules_with_resolved_gregorian_dates(): void
    {
        Holiday::create($this->payload(['name' => 'Active Rule']));
        Holiday::create($this->payload(['name' => 'Retired Rule', 'month' => 12, 'day' => 25, 'active' => false]));
        Calendar::flush();

        $this->actingAs($this->admin)->get('/admin/structure/holidays')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Holidays')
                ->has('holidays', 2)
                ->where('holidays.0.name', 'Active Rule')
                ->has('holidays.0.occurrences')
                ->where('holidays.1.active', false)
            );
    }

    public function test_a_resident_cannot_reach_the_screen(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/holidays')->assertForbidden();
        $this->actingAs($resident)
            ->post('/admin/structure/holidays', $this->payload())
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/structure/holidays')->assertRedirect('/login');
    }

    // --- Create + the flush (finding 1, extended to the holiday memo) ----------------------

    public function test_creating_a_gregorian_rule_takes_effect_within_the_same_process(): void
    {
        $this->assertFalse(Calendar::isHoliday('2026-09-23'));

        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // NO Calendar::flush() here on purpose — the controller must have done it.
        $this->assertTrue(Calendar::isHoliday('2026-09-23'));
    }

    public function test_creating_a_hijri_rule_takes_effect_within_the_same_process(): void
    {
        Institution::current()->update(['hijri_offset_days' => 0]);
        Calendar::flush();
        $this->assertFalse(Calendar::isHoliday('2027-03-09'));

        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload([
                'name' => 'Eid al-Fitr', 'calendar' => Holiday::HIJRI, 'month' => 10, 'day' => 1, 'duration_days' => 4,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Calendar::isHoliday('2027-03-09'));
    }

    /**
     * The cross-screen interaction neither task's own tests would catch alone: save the
     * holiday here, save the offset on Task 10's calendar screen, and confirm THIS screen's
     * resolved dates moved by one day.
     */
    public function test_a_hijri_rule_moves_across_both_screens_when_the_offset_changes(): void
    {
        Institution::current()->update(['hijri_offset_days' => 0]);
        Calendar::flush();

        // duration_days = 1 here (unlike the earlier single-screen test): a 4-day span would
        // cover BOTH 03-09 and 03-10 at offset 0, which would defeat the single-day before/after
        // flip this test is pinning.
        $this->actingAs($this->admin)->post('/admin/structure/holidays', $this->payload([
            'name' => 'Eid al-Fitr', 'calendar' => Holiday::HIJRI, 'month' => 10, 'day' => 1, 'duration_days' => 1,
        ]));

        $this->assertTrue(Calendar::isHoliday('2027-03-09'));
        $this->assertFalse(Calendar::isHoliday('2027-03-10'));

        $calendarPayload = [
            'hijri_enabled' => true,
            'hijri_offset_days' => -1,
            'weekend_days' => [5, 6],
            'period_type' => Institution::PERIOD_WEEK_BLOCKS,
            'block_weeks' => [4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5],
            'academic_year_start' => null,
        ];
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $calendarPayload)
            ->assertSessionHasNoErrors();

        $this->assertTrue(Calendar::isHoliday('2027-03-10'));
        $this->assertFalse(Calendar::isHoliday('2027-03-09'));

        $this->actingAs($this->admin)->get('/admin/structure/holidays')
            ->assertInertia(fn (Assert $page) => $page
                ->where('holidays.0.occurrences.0.starts_on.date', '2027-03-10')
            );
    }

    // --- Validation ----------------------------------------------------------------------------

    public function test_month_out_of_range_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['month' => 13]))
            ->assertSessionHasErrors('month');
    }

    public function test_a_hijri_day_above_thirty_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['calendar' => Holiday::HIJRI, 'month' => 1, 'day' => 30]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['calendar' => Holiday::HIJRI, 'month' => 1, 'day' => 31]))
            ->assertSessionHasErrors('day');
    }

    public function test_a_gregorian_day_of_thirty_one_is_allowed_in_a_thirty_one_day_month(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['month' => 1, 'day' => 31]))
            ->assertSessionHasNoErrors();
    }

    /** February 30th does not exist in the Gregorian calendar, in any year. */
    public function test_a_gregorian_february_thirty_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['month' => 2, 'day' => 30]))
            ->assertSessionHasErrors('day');

        $this->assertSame(0, Holiday::count());
    }

    public function test_duration_days_accepts_one_to_sixty_and_refuses_outside_it(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['duration_days' => 60]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['duration_days' => 61, 'month' => 10]))
            ->assertSessionHasErrors('duration_days');

        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['duration_days' => 0, 'month' => 11]))
            ->assertSessionHasErrors('duration_days');
    }

    public function test_year_is_nullable_and_an_explicit_year_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['year' => 2026]))
            ->assertSessionHasNoErrors();

        $holiday = Holiday::firstOrFail();
        $this->assertSame(2026, $holiday->year);
    }

    public function test_an_unknown_calendar_value_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/holidays', $this->payload(['calendar' => 'julian']))
            ->assertSessionHasErrors('calendar');
    }

    // --- Deactivation --------------------------------------------------------------------------

    public function test_deactivating_removes_it_from_calendar_holidays_on_without_deleting_the_row(): void
    {
        $holiday = Holiday::create($this->payload());
        Calendar::flush();
        $this->assertTrue(Calendar::isHoliday('2026-09-23'));

        $this->actingAs($this->admin)
            ->patch("/admin/structure/holidays/{$holiday->id}/active", ['active' => false])
            ->assertRedirect();

        $this->assertFalse(Calendar::isHoliday('2026-09-23'));
        $this->assertDatabaseHas('holidays', ['id' => $holiday->id]);
        $this->assertFalse($holiday->fresh()->active);
    }

    public function test_a_retired_rule_can_be_reactivated(): void
    {
        $holiday = Holiday::create($this->payload(['active' => false]));
        Calendar::flush();

        $this->actingAs($this->admin)
            ->patch("/admin/structure/holidays/{$holiday->id}/active", ['active' => true])
            ->assertRedirect();

        $this->assertTrue(Calendar::isHoliday('2026-09-23'));
    }

    // --- Precedence and MissedDays, through the screen ------------------------------------------

    /** Calendar::dayType()'s documented precedence: holiday beats weekend. */
    public function test_a_holiday_landing_on_a_weekend_day_reports_holiday_not_weekend(): void
    {
        // 2026-08-07 is a Friday, a weekend day under the default [5, 6] weekend_days.
        $this->assertTrue(Calendar::isWeekend('2026-08-07'));

        $this->actingAs($this->admin)->post('/admin/structure/holidays', $this->payload([
            'name' => 'Friday Holiday', 'month' => 8, 'day' => 7,
        ]));

        $this->assertSame(Calendar::DAY_HOLIDAY, Calendar::dayType('2026-08-07'));
    }

    /**
     * Owner decision 6 is binding: the missed-days denominator is UNCHANGED. This is the FIRST
     * surface from which a user can create a holiday, so it is the first place the denominator
     * could visibly move if the exclusion were ever wired in by accident.
     */
    public function test_missed_days_denominator_is_unaffected_by_a_holiday_created_here(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();

        $this->actingAs($this->admin)->post('/admin/structure/holidays', $this->payload([
            'name' => 'Created Via Screen', 'month' => 7, 'day' => 23,
        ]));
        $this->assertTrue(Calendar::isHoliday('2026-07-23'), 'fixture sanity check');

        $result = MissedDays::forRange($unit->id, '2026-07-22', '2026-07-24');

        $this->assertSame(3, $result['total_days']);
        $this->assertCount(3, $result['missed']);
    }

    // --- Audit -----------------------------------------------------------------------------------

    public function test_writes_are_audited_by_id_and_rule_identity_never_by_name(): void
    {
        $this->actingAs($this->admin)->post('/admin/structure/holidays', $this->payload([
            'name' => 'A Sensitive Sounding Holiday Name',
            'calendar' => Holiday::HIJRI, 'month' => 10, 'day' => 1,
        ]));

        $holiday = Holiday::firstOrFail();
        $row = AuditLog::where('action', 'holiday_create')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('holiday='.$holiday->id, $row->detail);
        $this->assertStringContainsString('calendar=hijri', $row->detail);
        $this->assertStringContainsString('md=10-1', $row->detail);
        $this->assertStringNotContainsString('A Sensitive Sounding Holiday Name', $row->detail);
    }

    public function test_deactivation_is_audited_by_id_and_identity(): void
    {
        $holiday = Holiday::create($this->payload());
        Calendar::flush();

        $this->actingAs($this->admin)->patch("/admin/structure/holidays/{$holiday->id}/active", ['active' => false]);

        $row = AuditLog::where('action', 'holiday_deactivate')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('holiday='.$holiday->id, $row->detail);
        $this->assertStringNotContainsString('National Day', $row->detail);
    }
}
