<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Period;
use App\Models\User;
use App\Support\Calendar;
use App\Support\PeriodGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Munawib ST-02, the calendar settings screen. Three of P1b's findings land here:
 *
 *  - finding 1: Calendar::flush() had no production caller, so the redirect that follows a
 *    save would have rendered from the pre-save memo;
 *  - finding 2: Institution::HIJRI_OFFSET_BOUNDS was enforced only in ReferenceSeeder, never
 *    in a request;
 *  - finding 3 / Decision C: PeriodGenerator::months() mislabels a run that does not start on
 *    the first of a month — resolved by VALIDATING the start date, not relabelling.
 *
 * Decision D's hard-lock (period_type/academic_year_start freeze once any `periods` row
 * exists) is covered here too; the unlock itself (deleting a year) is Task 11's job.
 */
class CalendarSettingsTest extends TestCase
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
            'hijri_enabled' => true,
            'hijri_offset_days' => 0,
            'weekend_days' => [5, 6],
            'period_type' => Institution::PERIOD_WEEK_BLOCKS,
            'block_weeks' => [4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5],
            'academic_year_start' => '2026-07-01',
        ], $overrides);
    }

    // --- The flush (finding 1) ----------------------------------------------------------

    /**
     * Finding 1: Calendar::settings() is memoised in a static for the life of the process and
     * Calendar::flush() had NO production caller before this screen. Without a flush on save,
     * the redirect that follows the save renders from the pre-save memo — the admin presses
     * Save, the row changes, and the page shows the old value. Under a long-lived worker the
     * stale value outlives the request entirely.
     *
     * Asserted through Calendar's own API, not by reading the column, because the column was
     * never the thing that was wrong.
     */
    public function test_saving_the_offset_takes_effect_within_the_same_process(): void
    {
        // Warm the memo the way a real request would.
        $this->assertSame(0, Calendar::hijriOffsetDays());
        $before = Calendar::hijri('2026-07-15');

        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['hijri_offset_days' => -1]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // NO Calendar::flush() here on purpose — the controller must have done it.
        $this->assertSame(-1, Calendar::hijriOffsetDays());
        $this->assertNotEquals($before, Calendar::hijri('2026-07-15'));
    }

    /** The same trap on the holiday memo: weekend days feed dayType(), which is memoised too. */
    public function test_saving_weekend_days_takes_effect_within_the_same_process(): void
    {
        $this->assertSame([5, 6], Calendar::weekendDays());

        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['weekend_days' => [6, 7]]))
            ->assertRedirect();

        $this->assertSame([6, 7], Calendar::weekendDays());
    }

    // --- The bounds (finding 2) ------------------------------------------------------------

    public function test_an_offset_below_the_bound_is_refused(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->put('/admin/structure/calendar', $this->payload(['hijri_offset_days' => -3]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('hijri_offset_days');
        $this->assertStringContainsString(
            'wrong timezone',
            $response->json('errors.hijri_offset_days.0')
        );
        $this->assertSame(0, Institution::current()->hijri_offset_days);
    }

    public function test_an_offset_above_the_bound_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['hijri_offset_days' => 3]))
            ->assertSessionHasErrors('hijri_offset_days');

        $this->assertSame(0, Institution::current()->hijri_offset_days);
    }

    public function test_every_offset_within_the_bound_saves(): void
    {
        foreach ([-2, -1, 0, 1, 2] as $offset) {
            $this->actingAs($this->admin)
                ->put('/admin/structure/calendar', $this->payload(['hijri_offset_days' => $offset]))
                ->assertSessionHasNoErrors();

            $this->assertSame($offset, Institution::current()->hijri_offset_days);
        }
    }

    public function test_a_non_integer_offset_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['hijri_offset_days' => 'minus one']))
            ->assertSessionHasErrors('hijri_offset_days');

        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['hijri_offset_days' => '-1.5']))
            ->assertSessionHasErrors('hijri_offset_days');
    }

    // --- Month alignment (finding 3 / Decision C) -------------------------------------------

    public function test_a_mid_month_start_is_refused_when_the_period_type_is_months(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload([
                'period_type' => Institution::PERIOD_MONTHS,
                'academic_year_start' => '2026-01-31',
            ]))
            ->assertSessionHasErrors('academic_year_start');

        $this->assertSame(Institution::PERIOD_WEEK_BLOCKS, Institution::current()->period_type);
    }

    public function test_the_same_mid_month_start_saves_under_week_blocks(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload([
                'period_type' => Institution::PERIOD_WEEK_BLOCKS,
                'academic_year_start' => '2026-01-31',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-01-31', Institution::current()->academic_year_start->format('Y-m-d'));
    }

    public function test_period_generator_months_throws_directly_on_a_mid_month_start(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PeriodGenerator::months(CarbonImmutable::parse('2026-01-31'));
    }

    /** The guard's blast radius, visible in this file too — unchanged from PeriodGenerationTest. */
    public function test_period_generator_months_is_unchanged_for_a_first_of_month_start(): void
    {
        $periods = PeriodGenerator::months(CarbonImmutable::parse('2026-07-01'));

        $this->assertCount(12, $periods);
        $this->assertSame('July 2026', $periods[0]['label']);
        $this->assertSame('2026-07-01', $periods[0]['starts_on']);
        $this->assertSame('June 2027', $periods[11]['label']);
        $this->assertSame('2027-06-30', $periods[11]['ends_on']);
    }

    // --- The hard-lock (Decision D) ---------------------------------------------------------

    private function generateOnePeriod(): void
    {
        Period::create([
            'institution_id' => null,
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);
    }

    public function test_period_type_and_academic_year_start_save_when_no_periods_exist(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload([
                'period_type' => Institution::PERIOD_MONTHS,
                'academic_year_start' => '2026-08-01',
            ]))
            ->assertSessionHasNoErrors();

        $institution = Institution::current();
        $this->assertSame(Institution::PERIOD_MONTHS, $institution->period_type);
        $this->assertSame('2026-08-01', $institution->academic_year_start->format('Y-m-d'));
    }

    public function test_changing_period_type_is_refused_once_a_period_exists(): void
    {
        $this->generateOnePeriod();

        $response = $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['period_type' => Institution::PERIOD_MONTHS]));

        $response->assertSessionHasErrors('period_type');
        $this->assertStringContainsString(
            'delete this academic year',
            strtolower(session('errors')->first('period_type'))
        );
        $this->assertSame(Institution::PERIOD_WEEK_BLOCKS, Institution::current()->period_type);
    }

    public function test_changing_academic_year_start_is_refused_once_a_period_exists(): void
    {
        $this->generateOnePeriod();
        Institution::current()->update(['academic_year_start' => '2026-07-01']);
        Calendar::flush();

        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['academic_year_start' => '2026-08-01']))
            ->assertSessionHasErrors('academic_year_start');

        $this->assertSame('2026-07-01', Institution::current()->academic_year_start->format('Y-m-d'));
    }

    public function test_weekend_and_hijri_fields_still_save_once_a_period_exists(): void
    {
        $this->generateOnePeriod();
        Institution::current()->update(['academic_year_start' => '2026-07-01']);
        Calendar::flush();

        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload([
                'academic_year_start' => '2026-07-01',
                'hijri_offset_days' => -1,
                'weekend_days' => [6, 7],
                'hijri_enabled' => false,
            ]))
            ->assertSessionHasNoErrors();

        $institution = Institution::current();
        $this->assertSame(-1, $institution->hijri_offset_days);
        $this->assertSame([6, 7], $institution->weekend_days);
        $this->assertFalse($institution->hijri_enabled);
    }

    public function test_the_index_response_carries_a_locked_flag(): void
    {
        $this->actingAs($this->admin)->get('/admin/structure/calendar')
            ->assertInertia(fn (Assert $page) => $page->where('locked', false));

        $this->generateOnePeriod();

        $this->actingAs($this->admin)->get('/admin/structure/calendar')
            ->assertInertia(fn (Assert $page) => $page->where('locked', true));
    }

    // --- The rest ----------------------------------------------------------------------------

    public function test_block_weeks_rejects_an_empty_list(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['block_weeks' => []]))
            ->assertSessionHasErrors('block_weeks');
    }

    public function test_block_weeks_rejects_a_zero_length_block(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['block_weeks' => [0]]))
            ->assertSessionHasErrors('block_weeks.0');
    }

    public function test_block_weeks_rejects_a_nine_week_block(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['block_weeks' => [9]]))
            ->assertSessionHasErrors('block_weeks.0');
    }

    public function test_block_weeks_rejects_twenty_seven_entries(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['block_weeks' => array_fill(0, 27, 4)]))
            ->assertSessionHasErrors('block_weeks');
    }

    public function test_weekend_days_rejects_an_empty_list(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['weekend_days' => []]))
            ->assertSessionHasErrors('weekend_days');
    }

    public function test_weekend_days_rejects_a_zero(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['weekend_days' => [0]]))
            ->assertSessionHasErrors('weekend_days.0');
    }

    public function test_weekend_days_rejects_an_eight(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['weekend_days' => [8]]))
            ->assertSessionHasErrors('weekend_days.0');
    }

    public function test_weekend_days_rejects_a_duplicate(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['weekend_days' => [5, 5]]))
            ->assertSessionHasErrors('weekend_days.0');
    }

    public function test_every_save_is_audited_by_key_never_by_value(): void
    {
        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['hijri_offset_days' => -1]));

        $row = AuditLog::where('action', 'calendar_settings_update')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('keys=', $row->detail);
        $this->assertStringContainsString('hijri_offset_days', $row->detail);
        $this->assertStringNotContainsString('-1', $row->detail);
    }

    public function test_a_resident_cannot_reach_the_screen(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/calendar')->assertForbidden();
        $this->actingAs($resident)
            ->put('/admin/structure/calendar', $this->payload())
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/structure/calendar')->assertRedirect('/login');
    }

    /** Owner decision 3 / P1 finding 5: the screen shows no timezone field. */
    public function test_the_screen_shows_no_timezone_field_only_a_readonly_instance_value(): void
    {
        $this->actingAs($this->admin)->get('/admin/structure/calendar')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CalendarSettings')
                ->missing('form.timezone')
                ->where('instance_timezone', config('app.timezone'))
            );
    }
}
