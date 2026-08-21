/**
 * The duty-time core: one integer line, half-open intervals, and the three attribution readings.
 *
 * Built before any condition type exists, so that all twenty-two consume ONE definition of what a
 * duty occupies. The precedent this repository already carries is `AuditChain::canonical()`: two
 * copies of one canonical string drifted the day `APP_TIMEZONE` was set, and the live system
 * announced its entire audit trail as tampered when nothing had been. A catalog of twenty-two
 * types each deciding for itself when a night call ends is the same defect with twenty-two copies.
 *
 * ## One absolute-minute line
 *
 * `absMinute(date, minute) = daysFromCivil(date) * 1440 + minute`. No instant, no timezone, no
 * `Date` (P2 Decision B) — a duty is an integer pair and every comparison below is arithmetic.
 *
 * ## Intervals are HALF-OPEN, `[start, end)`
 *
 * Under SL-02's configurable split day/night the night window begins exactly when the day window
 * ends. Closed intervals would flag every legal split-call department on every single day, and the
 * difference is one comparison operator — invisible in review, which is why {@link intersects} is
 * fixtured on the abutting pair and the plant that proves it is swapping `<` for `<=`.
 *
 * ## THREE readings of "which date does a duty belong to", and each type declares which it uses
 *
 * This is the single largest source of silent divergence between two implementations of one
 * catalog, and §4.3's eventual cross-validation surfaces it as a mismatch rather than as a bug.
 *
 *  - **Anchor date** — the whole duty belongs to the calendar date its slot STARTS on.
 *    {@link anchorDate}; `Duty.date` is already it, and the function exists so the reading has a
 *    name a type can cite.
 *  - **Occupied interval** — the half-open absolute-minute interval. {@link dutyInterval},
 *    {@link intersects}, {@link occupiedDates}.
 *  - **Split at midnight** — minutes apportioned to each civil date they fall on.
 *    {@link onDutyMinutesOn}, used by `rolling_hours_max` and by nothing else.
 *
 * A night call is ONE call on its anchor date, and it is ALSO twelve hours on each of two dates in
 * the one type that sums minutes into a day-bounded window. Both are right for their family; what
 * is fatal is a type picking one silently. {@link DUTY_DATE_READING} is the declaration, and the
 * matrix in `test/duty-core.test.ts` asserts every fixture against all three readings at once —
 * including the property that the split reading always sums back to the interval's own length.
 *
 * ## `spanDays` — one formula for both cadences, and a correction to the plan's prose
 *
 * A duty's window is measured against `date + spanDays - 1`, so a daily slot (`spanDays: 1`) ends
 * on its own date and SL-04's weekly slot with `spanDays: 7` occupies seven dates. P2's Decision A
 * prose says a weekly duty ends at `daysFromCivil(date) + spanDays` days plus `endMinute`, which
 * is one day later than its own Task 4 acceptance case ("a weekly-cadence duty with `spanDays: 7`
 * occupies seven dates") and, applied to `spanDays: 1`, would end every daily duty on the
 * FOLLOWING date — making every abutting split day/night pair overlap. The seven-date reading is
 * the one implemented here; the divergence is recorded rather than quietly resolved.
 */

import { addDays, civilFromDays, datesBetween, daysFromCivil, type Ymd } from '../calendar/ymd';

/** Minutes in a civil day. Not seconds, not a duration — the line's unit. */
export const MINUTES_PER_DAY = 1440;

/**
 * A duty slot, as P2 authors it ahead of SL-01..SL-07 (Decision A, owner decision C).
 *
 * `key` and `kind` are **opaque strings and are not validated against any enum**: SL-01's slot
 * vocabulary is stored nowhere in this repository — not in `app/`, not in `database/`, not in
 * `resources/js` — so an enum here would be this codebase's first and only definition of it. P3
 * owns that vocabulary and maps its own primary keys onto `key`; nothing here is a foreign key.
 */
export interface Slot {
    key: string;
    kind: string;
    unitKey?: string;
    cadence: 'daily' | 'weekly';
    /** Calendar dates the window is anchored across: 1 for a daily slot, SL-04's real extent otherwise. */
    spanDays: number;
    /** Minutes from local midnight, 0..1439. */
    startMinute: number;
    /** Minutes from midnight of the window's LAST date, 0..1440 — 1440 spells "ends at midnight". */
    endMinute: number;
    crossesMidnight: boolean;
    /** SL-01's counts-toward-hours flag. Read by the duty-hours types, never by this module. */
    countsHours: boolean;
    /** SL-01's tally key; `fairness_distribution`'s quantity keys on it. */
    tallyKey?: string;
}

/** One person, one date, one slot. The whole of it — P3 serialises assignments into this shape. */
export interface Duty {
    personKey: string;
    date: Ymd;
    slotKey: string;
}

/** Half-open `[start, end)` on the absolute-minute line. */
export interface AbsInterval {
    start: number;
    end: number;
}

/** The three readings of Decision A, as values a type can declare. */
export type DutyDateReading = 'anchor-date' | 'occupied-interval' | 'split-at-midnight';

/**
 * Which reading each of the 22 implemented catalog keys uses — Decision A's table, in code, beside
 * the functions that implement it. `forbidden_transition` is absent because it is registered
 * unimplemented (CG-07 marks it Stage 5 in its own parameters cell, §35 lists it there, and §36
 * makes shift features before Stage 5 a named non-goal).
 *
 * The value is a LIST because `min_gap` is the one type whose reading depends on its own
 * parameter: owner decision H gives it `hours`, measured END-to-START on the occupied interval,
 * and `days`, measured between START DATES — which is the anchor-date reading. Encoding that as a
 * single value would have forced one half of the type to be wrong or undeclared.
 */
export const DUTY_DATE_READING = {
    call_frequency_max: ['anchor-date'],
    clinic_conflict: ['anchor-date'],
    composition: ['anchor-date'],
    // TWO readings, and the second entry in this table to carry them after `min_gap` — a
    // CORRECTION to Decision A's table rather than a departure from it. That table was written
    // before owner decision V added `unit: 'hours'`, and a contiguous chain joined by the GAP
    // between two duties cannot be measured from anchor dates. `days` and `nights` count the dates
    // duties start on; `hours` measures the absolute-minute line.
    consecutive_max: ['anchor-date', 'occupied-interval'],
    count_max: ['anchor-date'],
    count_min: ['anchor-date'],
    dow_restriction: ['anchor-date'],
    eligibility: ['anchor-date'],
    fairness_distribution: ['anchor-date'],
    free_day_min: ['occupied-interval'],
    holiday_equity: ['anchor-date'],
    max_gap: ['anchor-date'],
    min_gap: ['occupied-interval', 'anchor-date'],
    onboarding_grace: ['anchor-date'],
    overlap_block: ['occupied-interval'],
    post_duty_exclusion: ['occupied-interval'],
    rolling_hours_max: ['split-at-midnight'],
    same_unit_conflict: ['anchor-date'],
    target_per_period: ['anchor-date'],
    unwanted_day_block: ['anchor-date'],
    vacation_block: ['anchor-date'],
    we_pairing: ['anchor-date'],
} as const satisfies Record<string, readonly DutyDateReading[]>;

/**
 * A minute of the absolute line: whole days from the civil origin, times 1440, plus the minute.
 *
 * 1440 is accepted as an end-of-day marker, so a window closing at midnight can be spelled either
 * as `endMinute: 1440` or as `endMinute: 0, crossesMidnight: true`. Both produce the same interval,
 * asserted, because a department's configuration will contain both spellings and neither is wrong.
 */
export function absMinute(date: Ymd, minute: number): number {
    if (!Number.isInteger(minute) || minute < 0 || minute > MINUTES_PER_DAY) {
        throw new RangeError(`A minute must be an integer 0..${MINUTES_PER_DAY}; got ${minute}.`);
    }

    return daysFromCivil(date) * MINUTES_PER_DAY + minute;
}

/**
 * Refuse a slot whose declared shape contradicts its own minutes, LOUDLY.
 *
 * Each of these would otherwise produce a plausible wrong number rather than a crash, which is the
 * defect shape `strict`/`noUncheckedIndexedAccess` are on for. In particular a weekly slot may not
 * declare `crossesMidnight`: `spanDays` already carries every midnight the window crosses, so the
 * flag would mean choosing between ignoring a declared field and adding a day twice — both silent.
 */
function assertSlot(slot: Slot): void {
    if (!Number.isInteger(slot.startMinute) || slot.startMinute < 0 || slot.startMinute >= MINUTES_PER_DAY) {
        throw new RangeError(`Slot "${slot.key}" starts at minute ${slot.startMinute}, outside 0..1439.`);
    }

    if (!Number.isInteger(slot.endMinute) || slot.endMinute < 0 || slot.endMinute > MINUTES_PER_DAY) {
        throw new RangeError(`Slot "${slot.key}" ends at minute ${slot.endMinute}, outside 0..1440.`);
    }

    if (!Number.isInteger(slot.spanDays) || slot.spanDays < 1) {
        throw new RangeError(`Slot "${slot.key}" spans ${slot.spanDays} days; a slot spans at least one.`);
    }

    if (slot.cadence === 'daily' && slot.spanDays !== 1) {
        throw new RangeError(
            `Slot "${slot.key}" is daily but spans ${slot.spanDays} days. A daily slot spans exactly one ` +
                'date and reaches the next only by crossing midnight (Decision A).',
        );
    }

    if (slot.cadence === 'weekly' && slot.crossesMidnight) {
        throw new RangeError(
            `Slot "${slot.key}" is weekly and declares crossesMidnight. spanDays already carries every ` +
                'midnight a weekly window crosses; the flag is a daily-slot statement.',
        );
    }
}

/**
 * The half-open interval a duty occupies, `[start, end)`.
 *
 * The window's last date is `date + spanDays - 1`; a crossing slot then adds one more day. See the
 * module docblock for why that is `- 1` and not the plan's `+ spanDays`.
 */
export function dutyInterval(duty: Duty, slot: Slot): AbsInterval {
    assertSlot(slot);

    const start = absMinute(duty.date, slot.startMinute);
    const end =
        absMinute(addDays(duty.date, slot.spanDays - 1), slot.endMinute) +
        (slot.crossesMidnight ? MINUTES_PER_DAY : 0);

    if (end <= start) {
        throw new RangeError(
            `Slot "${slot.key}" produces an empty or negative window on ${duty.date} ` +
                `(${slot.startMinute} to ${slot.endMinute}). A window that ends before it starts needs ` +
                'crossesMidnight, and one that ends exactly when it starts occupies nothing.',
        );
    }

    return { start, end };
}

/**
 * Do two intervals overlap? HALF-OPEN, so abutting windows do NOT — see the module docblock.
 *
 * The strict `<` in both directions is the whole rule. `<=` would report every split-call
 * department as overlapping itself on every day it runs.
 */
export function intersects(a: AbsInterval, b: AbsInterval): boolean {
    return a.start < b.end && b.start < a.end;
}

/** Reading 1 — the anchor date. The whole duty belongs to the date its slot starts on. */
export function anchorDate(duty: Duty): Ymd {
    return duty.date;
}

/**
 * Reading 2 — every civil date the occupied interval touches, in order.
 *
 * `end - 1` is what keeps a window closing exactly at midnight off the following date: the last
 * occupied minute is the one before `end`, because the interval is half-open. `Math.floor` rather
 * than `Math.trunc` because the absolute line is negative for dates before the civil origin.
 */
export function occupiedDates(duty: Duty, slot: Slot): Ymd[] {
    const { start, end } = dutyInterval(duty, slot);

    return datesBetween(
        civilFromDays(Math.floor(start / MINUTES_PER_DAY)),
        civilFromDays(Math.floor((end - 1) / MINUTES_PER_DAY)),
    );
}

/**
 * Reading 3 — minutes of this duty falling on ONE civil date. Zero for a date it does not touch.
 *
 * Used by `rolling_hours_max` and by nothing else (Decision A). Summed over
 * {@link occupiedDates} it always equals the interval's own length, which is the property that
 * ties readings 2 and 3 together and is asserted for every fixture in the matrix.
 */
export function onDutyMinutesOn(duty: Duty, slot: Slot, date: Ymd): number {
    return minutesOfIntervalOn(dutyInterval(duty, slot), date);
}

/**
 * The same reading, over an interval the caller has ALREADY resolved — and the one definition of it.
 *
 * {@link onDutyMinutesOn} delegates here rather than repeating the arithmetic, so there is exactly
 * one expression in the package deciding how many minutes of a span fall on a civil date. Two
 * copies would be `AuditChain::canonical()`'s defect in the function whose whole content is a
 * clamp.
 *
 * It exists because `dutyInterval` re-runs `assertSlot` on every call, and `rolling_hours_max` asks
 * this question `windows x people x duties x days` times over one evaluation — the interval was
 * already computed and already validated by `orderedDutiesFor`, which is what `PositionedDuty`
 * carries it for. This is a REUSE, not a shortcut: nothing here decides anything the slower path
 * decides differently, which is the line Task 10's pruning defect crossed and this does not.
 */
export function minutesOfIntervalOn(interval: AbsInterval, date: Ymd): number {
    const dayStart = absMinute(date, 0);
    const dayEnd = dayStart + MINUTES_PER_DAY;

    return Math.max(0, Math.min(interval.end, dayEnd) - Math.max(interval.start, dayStart));
}
