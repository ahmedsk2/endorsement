<?php

namespace Tests\Feature\Calendar;

use App\Models\User;
use App\Support\Calendar;
use App\Support\MissedDays;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 5 — absorbing the five independent date-conversion paths finding 2 (plan doc) named
 * into `App\Support\Calendar`: `EndorsementController::normalizeDate()`/`parseDateOrToday()`,
 * `MissedDays`, `ShiftClock`, `SendHandoverReminders`, and `Person::levelAt()`.
 *
 * This test pins BEHAVIOUR that must survive the rewrite unchanged, plus the one behaviour
 * that gets STRICTER (a syntactically-valid-but-impossible date now 404s instead of silently
 * rolling forward).
 */
class ConverterAbsorptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    /**
     * The route regex (`routes/web.php`, `where('date', '\d{4}-\d{2}-\d{2}')`) already rejects
     * this shape today — this pins that the rewrite of normalizeDate()/parseDateOrToday() does
     * not loosen it. `strtotime()` accepted "+5 years" and once created real backdated clinical
     * rows (EndorsementController.php's own docblock); Calendar::parse() throws on it too, so
     * even a route that stopped regex-pinning this param would still be safe.
     */
    public function test_a_relative_date_expression_404s(): void
    {
        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/+5%20years')
            ->assertNotFound();
    }

    /**
     * The real test of the rewrite: `2026-02-30` matches the route's `\d{4}-\d{2}-\d{2}` regex
     * (it IS that shape), so it reaches the controller. The old `strtotime()`/`date()` pair
     * would silently roll it forward to 2026-03-02 and serve that page; Calendar::parse()'s
     * round-trip check must catch it and 404 instead.
     */
    public function test_an_impossible_calendar_date_404s_rather_than_silently_rolling_forward(): void
    {
        $this->actingAs($this->admin())
            ->get('/endorsement/PICU/2026-02-30')
            ->assertNotFound();
    }

    /**
     * Owner decision 6: the missed-days denominator is UNCHANGED by this task. Every calendar
     * day still counts, including weekends — the new calendar module's weekend/holiday
     * knowledge must not leak into this figure by accident.
     */
    public function test_missed_days_denominator_still_counts_every_calendar_day_including_weekends(): void
    {
        // 2026-07-06 (Monday) .. 2026-07-12 (Sunday): a full week, including Friday 2026-07-10
        // and Saturday 2026-07-11 — the default weekend under [5, 6]. No handovers, no
        // sign-offs exist for this unit/range.
        $unit = \App\Models\Unit::where('code', 'PICU')->firstOrFail();

        $result = MissedDays::forRange($unit->id, '2026-07-06', '2026-07-12');

        $this->assertSame(7, $result['total_days']);
        $missedDates = array_column($result['missed'], 'date');
        $this->assertContains('2026-07-10', $missedDates, 'Friday must still count toward the denominator');
        $this->assertContains('2026-07-11', $missedDates, 'Saturday must still count toward the denominator');
        $this->assertCount(7, $result['missed']);
    }

    /**
     * An empty/absent submitted date defaults to TODAY, computed in the INSTANCE timezone —
     * not the server process's incidental default. Proven at the 00:00-03:00 instant where
     * UTC's calendar day and Riyadh's disagree (DayBoundaryTest's ambiguous instant), the same
     * evidence Task 3 built for Calendar::todayYmd() itself, now exercised through the
     * controller's parseDateOrToday() fallback via the newDay endpoint.
     */
    public function test_an_empty_submitted_date_defaults_to_today_in_the_instance_timezone(): void
    {
        $this->withTimezone('Asia/Riyadh', function (): void {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-08T22:30:00Z'));

            try {
                $this->assertSame('2026-08-09', Calendar::todayYmd());

                $this->actingAs($this->admin())
                    ->post('/endorsement/PICU/new-day', [])
                    ->assertRedirect('/endorsement/PICU/2026-08-09');
            } finally {
                CarbonImmutable::setTestNow();
            }
        });
    }
}
