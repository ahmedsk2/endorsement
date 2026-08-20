/**
 * The post-duty window: ONE definition of *"after this duty"*, shared by the two types that need it.
 *
 * ## Why it is a module and not two implementations
 *
 * `post_duty_exclusion` tests DUTIES against an hour-granular window opened at the end of an earlier
 * duty. `clinic_conflict` tests CLINIC SESSIONS against the set of calendar dates that window
 * touches, because CL-03's rule is day-granular and `clinics.session` is a two-character code with
 * no minutes anywhere in the schema (finding 3, open item 32). Those are two questions about one
 * fact, and on a real configuration two implementations of it disagree: a 24 h call ending Tue 08:00
 * with a Tue PM clinic is a violation under the day reading and clean under an `H = 4` hour reading,
 * and a scheduler shown one warning and not the other cannot tell which is right.
 *
 * SL-02 already states the intent — *"post-duty semantics follow slot windows automatically"* — so
 * the window is derived from the duty's own end rather than from *"the day after a call"* written
 * down a second time. `AuditChain::canonical()` is the precedent this repository already paid for.
 *
 * ## The window opens where the duty ENDS, and it is half-open
 *
 * `[end, end + windowMinutes)` on Task 4's absolute-minute line. Owner decision H anchors
 * `post_duty_exclusion` on the END of the earlier duty and tests the later one by START-in-window,
 * and a weekly-cadence duty's end is `spanDays`' business (owner decision K) — `dutyInterval()`
 * already answers both, so neither is re-derived here.
 *
 * ## A ZERO-LENGTH window is the day-granular question, and it is not degenerate
 *
 * `clinic_conflict` needs no hours at all: it asks which calendar dates a person is post-duty on,
 * and the answer is the date the duty's end INSTANT falls on. So `postDutyDates()` reports that one
 * date for a zero-length window rather than the empty list a half-open reading would give — a night
 * ending Tue 08:00 puts the person post-call on Tuesday, and a day duty ending Mon 17:00 puts them
 * post-duty on Monday, which is their own anchor date and therefore the SAME-DAY question rather
 * than the post-call one. That split is what lets `clinic_conflict` keep the two variants apart
 * without inventing a second notion of "the day after".
 *
 * **This file lands at Task 13 rather than Task 14**, where the plan's file list puts it, because
 * Task 13's `clinic_conflict` is its first consumer and a shared definition that arrives after its
 * first caller has already been written is a shared definition in name only.
 */

import { civilFromDays, datesBetween, type Ymd } from '../calendar/ymd';
import { dutyInterval, MINUTES_PER_DAY, type AbsInterval, type Duty, type Slot } from './interval';

/**
 * The window after `duty` ends, `[end, end + windowMinutes)`.
 *
 * `windowMinutes` defaults to zero — the day-granular question — and a negative one is refused
 * rather than quietly reversing the interval into a window that ends before it starts.
 */
export function postDutyWindow(duty: Duty, slot: Slot, windowMinutes = 0): AbsInterval {
    if (!Number.isInteger(windowMinutes) || windowMinutes < 0) {
        throw new RangeError(
            `A post-duty window is a whole number of minutes, zero or more; got ${windowMinutes}.`,
        );
    }

    const { end } = dutyInterval(duty, slot);

    return { start: end, end: end + windowMinutes };
}

/**
 * Every civil date the post-duty window touches, in order.
 *
 * `end - 1` is what keeps a window closing exactly at midnight off the following date, exactly as
 * `occupiedDates()` does it — and `Math.max(start, …)` is what makes a ZERO-length window answer
 * with the date its instant falls on rather than with nothing. `Math.floor` rather than
 * `Math.trunc`, because the absolute line is negative before the civil origin.
 */
export function postDutyDates(window: AbsInterval): Ymd[] {
    const lastMinute = Math.max(window.start, window.end - 1);

    return datesBetween(dateOfAbsolute(window.start), dateOfAbsolute(lastMinute));
}

function dateOfAbsolute(absolute: number): Ymd {
    return civilFromDays(Math.floor(absolute / MINUTES_PER_DAY));
}

/**
 * Owner decision H's other half: does `interval` START inside this window?
 *
 * `post_duty_exclusion` anchors on the END of the earlier duty and tests the later one by
 * START-IN-WINDOW rather than by overlap, and the difference is a real configuration: a long duty
 * beginning one minute before the exclusion closes is inside it, and one beginning one minute after
 * it closes is not, however far back its own window would reach. Half-open at both ends, so a duty
 * starting exactly when the exclusion closes is clean — the same rule `intersects()` states for
 * abutting windows, and for the same reason.
 */
export function startsWithin(window: AbsInterval, interval: AbsInterval): boolean {
    return interval.start >= window.start && interval.start < window.end;
}
