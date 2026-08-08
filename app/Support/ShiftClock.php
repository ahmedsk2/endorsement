<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Which handover the visitor is arriving for.
 *
 * The signed-out page knows the time of day and says so. That is not decoration: this
 * system exists for two moments — 07:30 and 15:30 — and a clinician opening it at 07:20 is
 * almost certainly there for the morning handover. Greeting them with the shift they are
 * about to do is the difference between a login screen and a tool that knows its job.
 *
 * Computed SERVER-SIDE, in the application's timezone (Asia/Riyadh). The browser clock is
 * whatever the device says — a laptop on the wrong timezone, a phone still on holiday
 * time — and a ward system should agree with the ward, not the device.
 *
 * Carries no patient data and nothing an anonymous visitor should not see: the handover
 * times are printed on the sheets and pinned to the wall.
 */
final class ShiftClock
{
    /** @return array{greeting: string, phase: string, next: string, label: string} */
    public static function now(?Carbon $at = null): array
    {
        // Calendar::timezone() is the single source of truth for "what timezone is the
        // instance in" (owner decision 3); Carbon::now() still supplies the actual clock time,
        // which Calendar's own now()/today() intentionally do not expose as a Carbon (mutable).
        $at = $at ?? Carbon::now(Calendar::timezone());

        $times = config('endorsement.handover_times', ['07:30', '15:30']);
        [$morning, $afternoon] = [$times[0] ?? '07:30', $times[1] ?? '15:30'];

        $minutes = $at->hour * 60 + $at->minute;
        $toMinutes = static function (string $hhmm): int {
            [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

            return ((int) $h) * 60 + (int) $m;
        };

        $morningAt = $toMinutes($morning);
        $afternoonAt = $toMinutes($afternoon);

        // The next handover, wrapping to tomorrow morning after the afternoon one.
        $next = $minutes < $morningAt ? $morning
            : ($minutes < $afternoonAt ? $afternoon : $morning);

        // Phases drive the hero tint — and ONLY the tint. Boundaries are deliberately
        // generous: the point is "roughly when in the day", not a precise astronomical
        // dawn, and an illustration that tracks the sun wants clock hours rather than shift
        // boundaries.
        //
        // There is no greeting. The line rendered here is 10px uppercase monospace — the
        // register the design system uses for labelling measured values — and a warm
        // second-person greeting shouted in that type reads oddly. It also told the reader
        // nothing: "Good morning" to someone who is demonstrably standing in the morning is
        // a word doing no work. What is left, "Next handover 07:30", is a measured value in
        // the class built for measured values.
        $phase = match (true) {
            $minutes < 5 * 60 => 'night',
            $minutes < 11 * 60 => 'dawn',
            $minutes < 16 * 60 => 'day',
            $minutes < 20 * 60 => 'dusk',
            default => 'night',
        };

        return [
            'phase' => $phase,
            'next' => $next,
            'label' => 'Next handover '.$next,
        ];
    }
}
