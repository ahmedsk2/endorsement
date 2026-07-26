<?php

namespace Tests\Feature\Auth;

use App\Support\ShiftClock;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The signed-out page tells the visitor which handover they are arriving for.
 *
 * Computed server-side in the ward's timezone rather than from the browser clock: a laptop
 * on the wrong timezone would otherwise greet a night resident with "Good afternoon", and
 * a clinical tool should agree with the ward, not the device.
 */
class ShiftClockTest extends TestCase
{
    private function at(string $time): array
    {
        return ShiftClock::now(Carbon::parse("2026-07-27 {$time}", config('app.timezone')));
    }

    public function test_before_the_morning_handover_it_points_at_the_morning(): void
    {
        $shift = $this->at('06:15');

        $this->assertSame('07:30', $shift['next']);
        $this->assertSame('Good morning', $shift['greeting']);
    }

    public function test_between_the_two_handovers_it_points_at_the_afternoon(): void
    {
        $shift = $this->at('09:00');

        $this->assertSame('15:30', $shift['next']);
    }

    public function test_after_the_afternoon_handover_it_wraps_to_tomorrow_morning(): void
    {
        // The wrap is the bit that is easy to get wrong: at 22:00 the next handover is not
        // "none", it is 07:30 the next day.
        $shift = $this->at('22:00');

        $this->assertSame('07:30', $shift['next']);
    }

    public function test_the_small_hours_are_recognised_as_a_night_shift(): void
    {
        $shift = $this->at('03:00');

        $this->assertSame('night', $shift['phase']);
        $this->assertSame('Still on nights', $shift['greeting']);
        $this->assertSame('07:30', $shift['next']);
    }

    public function test_each_part_of_the_day_gets_its_own_phase(): void
    {
        $this->assertSame('dawn', $this->at('08:00')['phase']);
        $this->assertSame('day', $this->at('13:00')['phase']);
        $this->assertSame('dusk', $this->at('18:30')['phase']);
        $this->assertSame('night', $this->at('23:30')['phase']);
    }

    public function test_it_follows_the_configured_handover_times(): void
    {
        // The times are owner-configurable at Admin → Settings; the greeting must follow
        // them rather than hard-coding 07:30 in a second place.
        config(['endorsement.handover_times' => ['06:00', '18:00']]);

        $this->assertSame('06:00', $this->at('05:00')['next']);
        $this->assertSame('18:00', $this->at('12:00')['next']);
        $this->assertSame('06:00', $this->at('19:00')['next']);
    }

    public function test_the_login_page_carries_the_shift_without_leaking_anything(): void
    {
        $response = $this->get('/login');

        $response->assertOk();

        $shift = $response->viewData('page')['props']['shift'] ?? null;

        $this->assertIsArray($shift);
        $this->assertArrayHasKey('greeting', $shift);
        $this->assertArrayHasKey('phase', $shift);

        // A greeting and a wall-pinned time. Nothing about any patient or any account.
        $this->assertSame(
            ['greeting', 'phase', 'next', 'label'],
            array_keys($shift),
            'the shared shift payload must stay minimal',
        );
    }
}
