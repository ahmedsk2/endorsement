import { describe, it, expect } from 'vitest';

import { addDays, daysFromCivil, parseYmd, type Ymd } from '../src/calendar/ymd';
import {
    DUTY_DATE_READING,
    MINUTES_PER_DAY,
    absMinute,
    anchorDate,
    dutyInterval,
    intersects,
    occupiedDates,
    onDutyMinutesOn,
    type Duty,
    type DutyDateReading,
    type Slot,
} from '../src/duty/interval';
import { orderedDutiesFor, slotIndex } from '../src/duty/order';
import { postDutyDates, postDutyWindow, startsWithin } from '../src/duty/post-duty-window';
import {
    assertHorizon,
    enumerateWindows,
    windowFor,
    windowLengthDays,
    withinHorizon,
    type Horizon,
} from '../src/duty/windows';

/**
 * The duty-time core (P2 Task 4): absolute minutes, half-open intervals, ordering, windows.
 *
 * Three conventions are decided here rather than inside twenty-two predicates, and each is
 * asserted on the case where the readings disagree — a corpus of tidy mid-morning day shifts is
 * green under every wrong version of this module.
 */

const d = (date: string): Ymd => parseYmd(date);

/** A plain day shift, 08:00–20:00. The control: one date, one interval, no midnight anywhere. */
const dayCall: Slot = {
    key: 'day',
    kind: 'day_call',
    cadence: 'daily',
    spanDays: 1,
    startMinute: 480,
    endMinute: 1200,
    crossesMidnight: false,
    countsHours: true,
};

/** Its abutting partner, 20:00–08:00. SL-02's configurable split day/night, and the case that
 * decides half-open versus closed intervals for every department that runs one. */
const nightCall: Slot = {
    key: 'night',
    kind: 'night_call',
    cadence: 'daily',
    spanDays: 1,
    startMinute: 1200,
    endMinute: 480,
    crossesMidnight: true,
    countsHours: true,
};

/** 08:00 to 08:00 — the shape the 24 h continuous cap is written against. */
const fullDayCall: Slot = {
    key: 'full24',
    kind: 'full_24h_call',
    cadence: 'daily',
    spanDays: 1,
    startMinute: 480,
    endMinute: 480,
    crossesMidnight: true,
    countsHours: true,
};

/** SL-04's weekly cadence, carrying a real extent: seven dates, ending 20:00 on the last. */
const weeklyDuty: Slot = {
    key: 'weekly',
    kind: 'weekly_duty',
    cadence: 'weekly',
    spanDays: 7,
    startMinute: 480,
    endMinute: 1200,
    crossesMidnight: false,
    countsHours: false,
};

const slots = [dayCall, nightCall, fullDayCall, weeklyDuty];

const duty = (personKey: string, date: string, slotKey: string): Duty => ({
    personKey,
    date: d(date),
    slotKey,
});

describe('absMinute', () => {
    it('is one integer line: days from the civil origin times 1440, plus the minute', () => {
        expect(absMinute(d('2026-08-19'), 480)).toBe(daysFromCivil(d('2026-08-19')) * 1440 + 480);
        expect(MINUTES_PER_DAY).toBe(1440);
    });

    it('differences across midnight are just arithmetic, with no instant in sight', () => {
        expect(absMinute(d('2026-08-20'), 480) - absMinute(d('2026-08-19'), 1200)).toBe(720);
    });

    it('refuses a minute outside the day', () => {
        expect(() => absMinute(d('2026-08-19'), -1)).toThrow();
        expect(() => absMinute(d('2026-08-19'), 1441)).toThrow();
        expect(() => absMinute(d('2026-08-19'), 90.5)).toThrow();
    });
});

describe('dutyInterval', () => {
    it('a same-day slot starts and ends on its own date', () => {
        expect(dutyInterval(duty('p1', '2026-08-19', 'day'), dayCall)).toEqual({
            start: absMinute(d('2026-08-19'), 480),
            end: absMinute(d('2026-08-19'), 1200),
        });
    });

    it('a crossing slot ends on the following date, which is the whole reason for the flag', () => {
        expect(dutyInterval(duty('p1', '2026-08-19', 'night'), nightCall)).toEqual({
            start: absMinute(d('2026-08-19'), 1200),
            end: absMinute(d('2026-08-20'), 480),
        });
    });

    it('a 24 h call is exactly 1440 minutes long', () => {
        const interval = dutyInterval(duty('p1', '2026-08-19', 'full24'), fullDayCall);

        expect(interval.end - interval.start).toBe(1440);
        expect(interval.end).toBe(absMinute(d('2026-08-20'), 480));
    });

    // The plan's Decision A prose says a weekly duty ends at `daysFromCivil(date) + spanDays` days
    // plus endMinute; its own Task 4 test says spanDays 7 occupies SEVEN dates. Those differ by a
    // day, and the seven-date reading is the one that also makes a daily slot (spanDays 1) end on
    // its own date. See the module docblock: the last date the window is measured against is
    // `date + spanDays - 1`.
    it('a weekly duty ends endMinute into its LAST date, not the one after it', () => {
        expect(dutyInterval(duty('p1', '2026-08-19', 'weekly'), weeklyDuty)).toEqual({
            start: absMinute(d('2026-08-19'), 480),
            end: absMinute(d('2026-08-25'), 1200),
        });
    });

    it('accepts the two spellings of a window ending at midnight and gives them one interval', () => {
        const untilMidnight: Slot = { ...dayCall, key: 'untilMidnight', endMinute: 1440, crossesMidnight: false };
        const throughMidnight: Slot = { ...dayCall, key: 'throughMidnight', endMinute: 0, crossesMidnight: true };

        expect(dutyInterval(duty('p1', '2026-08-19', 'untilMidnight'), untilMidnight)).toEqual(
            dutyInterval(duty('p1', '2026-08-19', 'throughMidnight'), throughMidnight),
        );
    });

    it('refuses a slot whose declared shape contradicts its minutes', () => {
        // A non-crossing slot that ends before it starts would silently produce a negative interval.
        expect(() => dutyInterval(duty('p1', '2026-08-19', 'x'), { ...nightCall, crossesMidnight: false })).toThrow();
        // spanDays is 1 for a daily slot, by Decision A.
        expect(() => dutyInterval(duty('p1', '2026-08-19', 'x'), { ...dayCall, spanDays: 2 })).toThrow();
        // A weekly slot spans whole dates; `crossesMidnight` would add one of them twice.
        expect(() => dutyInterval(duty('p1', '2026-08-19', 'x'), { ...weeklyDuty, crossesMidnight: true })).toThrow();
        expect(() => dutyInterval(duty('p1', '2026-08-19', 'x'), { ...weeklyDuty, spanDays: 0 })).toThrow();
    });
});

describe('intersects — half-open [start, end)', () => {
    it('does NOT flag the abutting split day/night pair', () => {
        const day = dutyInterval(duty('p1', '2026-08-19', 'day'), dayCall);
        const night = dutyInterval(duty('p1', '2026-08-19', 'night'), nightCall);

        expect(day.end).toBe(night.start);
        expect(intersects(day, night)).toBe(false);
        expect(intersects(night, day)).toBe(false);
    });

    it('does not flag last night ending exactly as this morning begins, either', () => {
        const lastNight = dutyInterval(duty('p1', '2026-08-18', 'night'), nightCall);
        const thisMorning = dutyInterval(duty('p1', '2026-08-19', 'full24'), fullDayCall);

        expect(lastNight.end).toBe(thisMorning.start);
        expect(intersects(lastNight, thisMorning)).toBe(false);
    });

    it('does flag a genuine overlap, by one minute in either direction', () => {
        const night = dutyInterval(duty('p1', '2026-08-19', 'night'), nightCall);
        const overlapping = { start: night.end - 1, end: night.end + 600 };

        expect(intersects(night, overlapping)).toBe(true);
        expect(intersects(overlapping, night)).toBe(true);
    });

    it('is symmetric and reflexive over every fixture pair', () => {
        const intervals = slots.map((slot) => dutyInterval(duty('p1', '2026-08-19', slot.key), slot));

        for (const a of intervals) {
            expect(intersects(a, a)).toBe(true);

            for (const b of intervals) {
                expect(intersects(a, b)).toBe(intersects(b, a));
            }
        }
    });
});

describe('the three duty-to-date attribution readings', () => {
    // THE MATRIX. Every fixture duty × every reading, in one table, because the fatal failure is a
    // type picking one reading silently and the three only disagree on duties that touch midnight.
    //
    // Read the rows as: the whole duty belongs to `anchor`; it occupies `occupies`; and it puts
    // `minutes` on those dates when apportioned. A night call is ONE call on its anchor date and
    // ALSO twelve hours on each of two dates — both true, for different families of type.
    const matrix: { why: string; duty: Duty; slot: Slot; anchor: string; occupies: string[]; minutes: number[] }[] = [
        {
            why: 'a day shift: all three readings agree, which is why it proves nothing on its own',
            duty: duty('p1', '2026-08-19', 'day'),
            slot: dayCall,
            anchor: '2026-08-19',
            occupies: ['2026-08-19'],
            minutes: [720],
        },
        {
            why: 'a night call: one call on its anchor date, and twelve hours on each of two dates',
            duty: duty('p1', '2026-08-21', 'night'),
            slot: nightCall,
            anchor: '2026-08-21',
            occupies: ['2026-08-21', '2026-08-22'],
            minutes: [240, 480],
        },
        {
            why: 'a 24 h call: still ONE call, and 960 + 480 minutes across the two dates',
            duty: duty('p1', '2026-08-19', 'full24'),
            slot: fullDayCall,
            anchor: '2026-08-19',
            occupies: ['2026-08-19', '2026-08-20'],
            minutes: [960, 480],
        },
        {
            why: 'a weekly duty: one duty anchored on its first date, occupying seven',
            duty: duty('p1', '2026-08-19', 'weekly'),
            slot: weeklyDuty,
            anchor: '2026-08-19',
            occupies: [
                '2026-08-19',
                '2026-08-20',
                '2026-08-21',
                '2026-08-22',
                '2026-08-23',
                '2026-08-24',
                '2026-08-25',
            ],
            minutes: [960, 1440, 1440, 1440, 1440, 1440, 1200],
        },
    ];

    it.each(matrix)('$why', ({ duty: subject, slot, anchor, occupies, minutes }) => {
        // Reading 1 — anchor date. `Duty.date` is it; the function exists so the reading has a name.
        expect(anchorDate(subject)).toBe(anchor);

        // Reading 2 — occupied interval.
        expect(occupiedDates(subject, slot)).toEqual(occupies);

        // Reading 3 — split at midnight.
        expect(occupies.map((date) => onDutyMinutesOn(subject, slot, d(date)))).toEqual(minutes);
    });

    it.each(matrix)('the split reading sums to the interval length: $why', ({ duty: subject, slot, minutes }) => {
        const interval = dutyInterval(subject, slot);

        expect(minutes.reduce((total, m) => total + m, 0)).toBe(interval.end - interval.start);
    });

    it('puts no minutes on a date the duty does not occupy, including the day it ends at midnight on', () => {
        const untilMidnight: Slot = { ...dayCall, key: 'untilMidnight', endMinute: 1440, crossesMidnight: false };
        const subject = duty('p1', '2026-08-19', 'untilMidnight');

        expect(occupiedDates(subject, untilMidnight)).toEqual(['2026-08-19']);
        expect(onDutyMinutesOn(subject, untilMidnight, d('2026-08-20'))).toBe(0);
        expect(onDutyMinutesOn(subject, untilMidnight, d('2026-08-18'))).toBe(0);
    });
});

describe('DUTY_DATE_READING — which type uses which reading', () => {
    // Transcribed from Decision A's table, deliberately: the module DECLARES the reading beside the
    // functions that implement it, and this test is the plan's table written out. A divergence
    // between the two is exactly what it exists to catch, and Task 8's registry cross-checks the
    // key set against SPEC.md's catalog from the other side.
    const anchorDateTypes = [
        'call_frequency_max',
        'clinic_conflict',
        'composition',
        'consecutive_max',
        'count_max',
        'count_min',
        'dow_restriction',
        'eligibility',
        'fairness_distribution',
        'holiday_equity',
        'max_gap',
        'min_gap',
        'onboarding_grace',
        'same_unit_conflict',
        'target_per_period',
        'unwanted_day_block',
        'vacation_block',
        'we_pairing',
    ];
    const occupiedIntervalTypes = [
        'consecutive_max',
        'free_day_min',
        'min_gap',
        'overlap_block',
        'post_duty_exclusion',
    ];
    const splitAtMidnightTypes = ['rolling_hours_max'];

    const withReading = (reading: DutyDateReading): string[] =>
        Object.entries(DUTY_DATE_READING)
            .filter(([, readings]) => (readings as readonly DutyDateReading[]).includes(reading))
            .map(([key]) => key)
            .sort();

    it('declares a reading for all 22 implemented type keys and nothing else', () => {
        expect(Object.keys(DUTY_DATE_READING)).toHaveLength(22);
        expect(Object.keys(DUTY_DATE_READING)).not.toContain('forbidden_transition');
    });

    it('matches Decision A: anchor date', () => {
        expect(withReading('anchor-date')).toEqual([...anchorDateTypes].sort());
    });

    it('matches Decision A: occupied interval', () => {
        expect(withReading('occupied-interval')).toEqual([...occupiedIntervalTypes].sort());
    });

    it('matches Decision A: split at midnight, which is rolling_hours_max ALONE', () => {
        expect(withReading('split-at-midnight')).toEqual(splitAtMidnightTypes);
    });

    /**
     * TWO types carry two readings, and both for the same reason: a PARAMETER of their own picks
     * which one applies. `min_gap`'s `unit` is owner decision H's; `consecutive_max`'s is owner
     * decision V's, added after Decision A's table was written — `days` and `nights` count the dates
     * duties start on, and `hours` measures a contiguous chain on the absolute-minute line, which
     * anchor dates cannot express. Declaring one reading for a type that has two is precisely the
     * silent divergence this table exists to prevent, so the list is asserted rather than the count.
     */
    it('names every type whose own parameter picks between two readings', () => {
        const twoReadings = Object.entries(DUTY_DATE_READING)
            .filter(([, readings]) => readings.length > 1)
            .map(([key]) => key)
            .sort();

        expect(twoReadings).toEqual(['consecutive_max', 'min_gap']);
        expect(DUTY_DATE_READING['min_gap']).toEqual(['occupied-interval', 'anchor-date']);
        expect(DUTY_DATE_READING['consecutive_max']).toEqual(['anchor-date', 'occupied-interval']);
    });
});

describe('orderedDutiesFor', () => {
    const index = slotIndex(slots);

    const streams = {
        priorDuties: [duty('p1', '2026-07-31', 'night'), duty('p2', '2026-07-30', 'day')],
        duties: [
            duty('p1', '2026-08-03', 'day'),
            duty('p1', '2026-08-01', 'night'),
            duty('p1', '2026-08-01', 'day'),
            duty('p2', '2026-08-02', 'day'),
        ],
        followingDuties: [duty('p1', '2026-09-01', 'day')],
    };

    it('is one chronological line per person across all three streams', () => {
        const ordered = orderedDutiesFor('p1', streams, index);

        expect(ordered.map((entry) => [entry.duty.date, entry.duty.slotKey, entry.origin])).toEqual([
            ['2026-07-31', 'night', 'prior'],
            ['2026-08-01', 'day', 'horizon'],
            ['2026-08-01', 'night', 'horizon'],
            ['2026-08-03', 'day', 'horizon'],
            ['2026-09-01', 'day', 'following'],
        ]);
    });

    it('carries the origin, which is how a type knows a neighbour is read-only context (CG-03)', () => {
        const ordered = orderedDutiesFor('p1', streams, index);

        expect(ordered.filter((entry) => entry.origin !== 'horizon')).toHaveLength(2);
    });

    it('excludes every other person, and returns an empty line for a person with no duties', () => {
        expect(orderedDutiesFor('p2', streams, index).map((entry) => entry.duty.date)).toEqual([
            '2026-07-30',
            '2026-08-02',
        ]);
        expect(orderedDutiesFor('p3', streams, index)).toEqual([]);
    });

    it('carries the resolved slot and interval, so a consumer never re-derives either', () => {
        const first = orderedDutiesFor('p1', streams, index)[0];

        expect(first?.slot.key).toBe('night');
        expect(first?.interval).toEqual(dutyInterval(duty('p1', '2026-07-31', 'night'), nightCall));
    });

    it('throws on a duty naming a slot nobody supplied, rather than dropping it', () => {
        const orphan = { priorDuties: [], duties: [duty('p1', '2026-08-01', 'ghost')], followingDuties: [] };

        expect(() => orderedDutiesFor('p1', orphan, index)).toThrow(/ghost/);
    });

    it('refuses a slot list with a duplicate key, which would make the lookup arbitrary', () => {
        expect(() => slotIndex([dayCall, { ...nightCall, key: 'day' }])).toThrow(/day/);
    });
});

/**
 * The post-duty window — the ONE definition of *"after this duty"*, and until now it had no test.
 *
 * It shipped at Task 13 with `clinic_conflict` and `post_duty_exclusion` as its only exercise, and
 * a shared definition asserted only through its two consumers is asserted at whatever resolution
 * those two happen to need: `post_duty_exclusion`'s corpus fires on a duty starting hours inside
 * the exclusion, so neither of the half-open bounds, nor the window's LENGTH, could be moved by
 * an hour without the suite staying green. This is the file the two types agree through, and the
 * disagreement it exists to prevent is `AuditChain::canonical()`'s, which this repository has
 * already paid for once — so the rule is asserted where it is written rather than downstream of it.
 *
 * PLANTED, one at a time, each reverted: `end + windowMinutes` → `+ 60` more; `>= window.start` →
 * `>`; `< window.end` → `<=`; `Math.max(window.start, window.end - 1)` → `window.end - 1`; and
 * `window.end - 1` → `window.end`. Every one of the five went red here and green before.
 */
describe('the post-duty window', () => {
    /** 08:00 to midnight exactly — the shape the zero-length reading is decided on. */
    const toMidnight: Slot = { ...dayCall, key: 'tomidnight', endMinute: MINUTES_PER_DAY };

    const nightEnd = absMinute(d('2026-08-20'), 480);

    it('opens where the duty ENDS and runs exactly as long as it was asked to', () => {
        const window = postDutyWindow(duty('p1', '2026-08-19', 'night'), nightCall, 600);

        expect(window.start).toBe(nightEnd);
        expect(window.end - window.start).toBe(600);
    });

    it('is zero length by default, which is the day-granular question rather than a degenerate one', () => {
        const window = postDutyWindow(duty('p1', '2026-08-19', 'night'), nightCall);

        expect(window).toEqual({ start: nightEnd, end: nightEnd });
    });

    it('refuses a negative or fractional length rather than reversing the interval', () => {
        expect(() => postDutyWindow(duty('p1', '2026-08-19', 'night'), nightCall, -1)).toThrow();
        expect(() => postDutyWindow(duty('p1', '2026-08-19', 'night'), nightCall, 90.5)).toThrow();
    });

    /**
     * Owner decision H's other half, at BOTH bounds. A duty beginning the minute the exclusion
     * opens is inside it; one beginning the minute it closes is clean — `intersects()`'s rule for
     * abutting windows, stated a second time here because this is a different question about it
     * (start-in-window, not overlap) and the two must not drift apart.
     */
    it('is half-open at both ends: the opening minute is in, the closing minute is out', () => {
        const window = postDutyWindow(duty('p1', '2026-08-19', 'night'), nightCall, 600);
        const at = (start: number): { start: number; end: number } => ({ start, end: start + 720 });

        expect(startsWithin(window, at(window.start - 1))).toBe(false);
        expect(startsWithin(window, at(window.start))).toBe(true);
        expect(startsWithin(window, at(window.end - 1))).toBe(true);
        expect(startsWithin(window, at(window.end))).toBe(false);
    });

    /**
     * A long duty beginning one minute before the exclusion closes is INSIDE it, however far past
     * the close its own window reaches; overlap would answer the same question differently, and
     * `post_duty_exclusion` would then disagree with the sentence its preview prints.
     */
    it('reads the START of the later duty, not its extent', () => {
        const window = postDutyWindow(duty('p1', '2026-08-19', 'night'), nightCall, 600);

        expect(startsWithin(window, { start: window.end - 1, end: window.end + 10_000 })).toBe(true);
        expect(startsWithin(window, { start: window.end, end: window.end + 1 })).toBe(false);
    });

    it('answers a zero-length window with the date its end instant falls on, midnight included', () => {
        expect(postDutyDates(postDutyWindow(duty('p1', '2026-08-19', 'night'), nightCall))).toEqual(['2026-08-20']);
        expect(postDutyDates(postDutyWindow(duty('p1', '2026-08-19', 'tomidnight'), toMidnight))).toEqual([
            '2026-08-20',
        ]);
    });

    it('keeps a window closing exactly at midnight off the following date', () => {
        expect(postDutyDates(postDutyWindow(duty('p1', '2026-08-19', 'day'), dayCall, 240))).toEqual(['2026-08-19']);
        expect(postDutyDates(postDutyWindow(duty('p1', '2026-08-19', 'day'), dayCall, 241))).toEqual([
            '2026-08-19',
            '2026-08-20',
        ]);
    });
});

describe('windows', () => {
    const horizon: Horizon = {
        from: d('2026-08-01'),
        to: d('2026-08-31'),
        evaluableFrom: d('2026-07-25'),
        evaluableTo: d('2026-09-07'),
    };

    it('asserts the carry-in tail actually surrounds the horizon', () => {
        expect(() => assertHorizon(horizon)).not.toThrow();
        expect(() => assertHorizon({ ...horizon, evaluableFrom: d('2026-08-02') })).toThrow();
        expect(() => assertHorizon({ ...horizon, evaluableTo: d('2026-08-30') })).toThrow();
        expect(() => assertHorizon({ ...horizon, from: d('2026-09-01') })).toThrow();
    });

    it('a rolling window is enumerated for every start that can still touch the horizon', () => {
        const windows = enumerateWindows('rolling', 7, horizon);

        // 31 horizon dates plus the six earlier starts whose window reaches the 1st.
        expect(windows).toHaveLength(37);
        expect(windows[0]).toEqual({ from: d('2026-07-26'), to: d('2026-08-01'), fullyEvaluable: true });
        expect(windows.at(-1)).toEqual({ from: d('2026-08-31'), to: d('2026-09-06'), fullyEvaluable: true });
    });

    it('marks a window the carry-in tail does not cover as NOT fully evaluable', () => {
        // A 28-day window over the same horizon reaches back to 5 July, and the tail starts on the
        // 25th — so the earliest windows are partial and a FLOOR must not fire on them.
        const windows = enumerateWindows('rolling', 28, horizon);
        const partial = windows.filter((window) => !window.fullyEvaluable);

        expect(partial.length).toBeGreaterThan(0);
        expect(partial[0]?.from).toBe(d('2026-07-05'));
        expect(windows.filter((window) => window.fullyEvaluable).length).toBeGreaterThan(0);
        expect(windows.at(-1)?.fullyEvaluable).toBe(false);
    });

    it('a one-day rolling window is one window per horizon date', () => {
        expect(enumerateWindows('rolling', 1, horizon)).toHaveLength(31);
    });

    it('refuses a length that is not a positive integer, and a span past the enumeration cap', () => {
        expect(() => enumerateWindows('rolling', 0, horizon)).toThrow();
        expect(() => enumerateWindows('rolling', 2.5, horizon)).toThrow();
        expect(() =>
            enumerateWindows('rolling', 400, { ...horizon, from: d('2026-01-01'), evaluableFrom: d('2026-01-01') }),
        ).toThrow(/550/);
    });

    it('wraps a context-supplied range — a week or a period — with the same evaluability rule', () => {
        expect(windowFor(d('2026-08-02'), d('2026-08-08'), horizon)).toEqual({
            from: d('2026-08-02'),
            to: d('2026-08-08'),
            fullyEvaluable: true,
        });
        expect(windowFor(d('2026-07-01'), d('2026-07-31'), horizon).fullyEvaluable).toBe(false);
        expect(() => windowFor(d('2026-08-08'), d('2026-08-02'), horizon)).toThrow();
    });

    it('every rolling window is exactly the requested length', () => {
        for (const window of enumerateWindows('rolling', 4, horizon)) {
            expect(addDays(window.from, 3)).toBe(window.to);
            expect(windowLengthDays(window)).toBe(4);
        }
    });

    // The emission rule (Decision A, CG-03): a violation is reported only when its location falls
    // inside the horizon. The tail is read, never re-evaluated — so a date in it answers false
    // here while still being fully evaluable above, and the two questions must not collapse.
    it('withinHorizon is the emission rule, and is not the same question as evaluability', () => {
        expect(withinHorizon(horizon, d('2026-08-01'))).toBe(true);
        expect(withinHorizon(horizon, d('2026-08-31'))).toBe(true);
        expect(withinHorizon(horizon, d('2026-07-31'))).toBe(false);
        expect(withinHorizon(horizon, d('2026-09-01'))).toBe(false);

        const inTheTail = windowFor(d('2026-07-26'), d('2026-07-31'), horizon);

        expect(inTheTail.fullyEvaluable).toBe(true);
        expect(withinHorizon(horizon, inTheTail.to)).toBe(false);
    });
});
