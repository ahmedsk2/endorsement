import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, it, expect } from 'vitest';

import { dayType, isWeekend, weekOf, weekdayColumns } from '../src/calendar';
import { datesBetween, isoWeekday, parseYmd, type Ymd } from '../src/calendar/ymd';

/**
 * The calendar mirror against its contract (P2 Task 5).
 *
 * `tests/fixtures/calendar/golden.json` is an INPUT to this phase, not something it authors: its
 * own `_purpose` names this package, and every value in it was produced by RUNNING
 * `App\Support\Calendar`. `GoldenFixtureTest` asserts the same file from PHP, in eleven methods.
 * This suite is the other half — and the two halves together are the whole reason a second
 * implementation of the calendar was allowed to exist at all (§7 Decision A, which killed exactly
 * this shape once: two definitions of one fact is the failure class `AuditChain::canonical()`
 * carries a docblock against, after two copies drifted and the live system announced its whole
 * audit trail as tampered).
 *
 * **The fixture is never edited to make an assertion here pass.** That single move would turn the
 * contract into a mirror of whatever the mirror happens to do, which is worth nothing. If the two
 * sides disagree, one of them is wrong and the fixture says which.
 *
 * What is deliberately NOT asserted here — Hijri, week clipping, instants, period generation, day
 * labels — is not left to a reader to infer from absence. `golden-coverage.test.ts` names every
 * block in the file as asserted or out of scope, with the reason, and fails the build on a block
 * nobody has classified.
 *
 * The path is built from `import.meta.dirname` for the reason `ymd.test.ts` records: under the
 * jsdom environment the global URL constructor is jsdom's, so a module-relative URL resolves
 * against the document base and yields an http URL rather than a file path.
 */
const goldenPath = join(import.meta.dirname, '..', '..', '..', 'tests', 'fixtures', 'calendar', 'golden.json');

interface GoldenSettings {
    hijri_offset_days: number;
    weekend_days: number[];
}

interface GoldenCaseDate {
    date: string;
    hijri: string;
    iso_weekday: number;
    weekend: boolean;
    day_type: string;
}

interface GoldenCase {
    _description?: string;
    settings: GoldenSettings;
    dates: GoldenCaseDate[];
}

interface GoldenWeek {
    _description?: string;
    weekend_days: number[];
    week_start_iso_day: number;
    of: string;
    starts_on: string;
    ends_on: string;
}

interface GoldenColumn {
    iso: number;
    label: string;
    short: string;
    weekend: boolean;
}

interface GoldenColumnCase {
    _description?: string;
    weekend_days: number[];
    week_start_iso_day: number;
    columns: GoldenColumn[];
}

interface GoldenHolidayExpectation {
    settings: GoldenSettings;
    date: string;
    holiday: boolean;
    day_type?: string;
}

interface GoldenHolidayCase {
    _description?: string;
    rule: { calendar: string; month: number; day: number; duration_days: number; year: number | null };
    expect: GoldenHolidayExpectation[];
}

interface Golden {
    version: number;
    cases: GoldenCase[];
    weeks: GoldenWeek[];
    weekday_columns: { cases: GoldenColumnCase[] };
    holiday_cases: GoldenHolidayCase[];
}

const golden = JSON.parse(readFileSync(goldenPath, 'utf8')) as Golden;

/**
 * The resolved holiday dates one holiday case implies for one department calibration.
 *
 * This is the honest half of what a Hijri-free mirror can say about `holiday_cases`. Which
 * Gregorian dates a rule resolves to is `Calendar::holidaysOn()`'s answer and arrives in the
 * evaluation context already resolved (Decision C / owner decision AA) — so the mirror is handed
 * the set rather than deriving it, and what it is asserted on is what it does with the set:
 * membership, and holiday winning over weekend. That precedence is a real property with a real
 * consequence — `dayType()` makes a holiday outrank a weekend deliberately, so that a coverage
 * template asking for holiday staffing gets it on a holiday that happens to fall on a weekend day
 * — and a mirror that flattened the three values to two would be green on every other block here.
 */
function resolvedHolidaysFor(holidayCase: GoldenHolidayCase, offsetDays: number): Set<Ymd> {
    const dates = holidayCase.expect
        .filter((expectation) => expectation.settings.hijri_offset_days === offsetDays && expectation.holiday)
        .map((expectation) => parseYmd(expectation.date));

    return new Set(dates);
}

describe('the fixture this suite mirrors', () => {
    // Non-vacuity. Every block below iterates the fixture, and an iteration over an empty or
    // renamed block passes silently while asserting nothing — the failure mode that makes a
    // fixture-driven suite indistinguishable from a deleted one.
    it('carries the blocks the mirror claims to assert', () => {
        expect(golden.version).toBeGreaterThanOrEqual(2);
        expect(golden.cases.length).toBeGreaterThanOrEqual(2);
        expect(golden.weeks.length).toBeGreaterThanOrEqual(3);
        expect(golden.weekday_columns.cases.length).toBeGreaterThanOrEqual(4);
        expect(golden.holiday_cases.length).toBeGreaterThanOrEqual(3);
    });
});

describe('cases — the ISO weekday, the weekend flag and the day type of a date', () => {
    const rows = golden.cases.flatMap((entry, caseIndex) =>
        entry.dates.map((date) => ({ caseIndex, weekendDays: entry.settings.weekend_days, date })),
    );

    it.each(rows)('case $caseIndex, $date.date', ({ weekendDays, date }) => {
        const day = parseYmd(date.date);

        expect(isoWeekday(day)).toBe(date.iso_weekday);
        expect(isWeekend(day, weekendDays)).toBe(date.weekend);

        // No holiday is configured in this block, so the day type is the weekend/weekday half
        // alone. The holiday half is asserted against `holiday_cases` below.
        expect(dayType(day, weekendDays, new Set<Ymd>())).toBe(date.day_type);
    });
});

describe('weeks — the week containing a date', () => {
    it.each(golden.weeks)('$of under week start $week_start_iso_day', (week) => {
        const resolved = weekOf(parseYmd(week.of), week.week_start_iso_day);

        expect(resolved.startsOn).toBe(week.starts_on);
        expect(resolved.endsOn).toBe(week.ends_on);

        // Both bounds inclusive, the same idiom `Person::levelAt()` and `Period::contains()`
        // share — so the week is seven dates, not six or eight. An off-by-one on `endsOn` is
        // invisible against `ends_on` alone if the same off-by-one is in the fixture's producer,
        // and this is the cheapest independent check that it is not.
        expect(datesBetween(resolved.startsOn, resolved.endsOn)).toHaveLength(7);
    });
});

describe('weekday_columns — the order the department week runs in', () => {
    it.each(golden.weekday_columns.cases)('week start $week_start_iso_day', (columnCase) => {
        const columns = weekdayColumns(columnCase.week_start_iso_day, columnCase.weekend_days);

        expect(columns.map((column) => column.iso)).toEqual(columnCase.columns.map((column) => column.iso));
        expect(columns.map((column) => column.weekend)).toEqual(columnCase.columns.map((column) => column.weekend));
    });

    // The vocabulary half of the block is asserted by nobody here, on purpose, and the coverage
    // manifest records it: day NAMES are lang-file data (AR-07, English-only at launch) and a
    // table of them inside this package would be both a second definition of a per-department
    // presentation fact and a build failure under the guard that scans this directory for
    // exactly that array.
    it('carries the presentation vocabulary the mirror deliberately does not', () => {
        const first = golden.weekday_columns.cases[0]?.columns[0];

        expect(first?.label).toBeTruthy();
        expect(first?.short).toBeTruthy();
    });
});

describe('holiday_cases — a resolved holiday outranks a weekend, and nothing else does', () => {
    const rows = golden.holiday_cases.flatMap((holidayCase, caseIndex) =>
        holidayCase.expect.map((expectation) => ({ caseIndex, holidayCase, expectation })),
    );

    it.each(rows)('case $caseIndex, $expectation.date at offset $expectation.settings.hijri_offset_days', ({
        holidayCase,
        expectation,
    }) => {
        const day = parseYmd(expectation.date);
        const weekendDays = expectation.settings.weekend_days;
        const resolved = resolvedHolidaysFor(holidayCase, expectation.settings.hijri_offset_days);

        const expected = expectation.holiday ? 'HOL' : isWeekend(day, weekendDays) ? 'WE' : 'WD';

        expect(dayType(day, weekendDays, resolved)).toBe(expected);

        // The one case in this block that states the day type outright is the precedence case: a
        // holiday falling on a configured weekend day.
        if (expectation.day_type !== undefined) {
            expect(dayType(day, weekendDays, resolved)).toBe(expectation.day_type);
            expect(isWeekend(day, weekendDays)).toBe(true);
        }
    });
});

describe('the department-varying facts are parameters, not module defaults', () => {
    // Owner decision X, asserted rather than trusted to review: the same date is a weekend day
    // under one department's configuration and a working day under another's, and the mirror
    // holds no opinion about which. A bundled default would be precisely the second definition
    // of a per-department fact that `golden.json` exists to prevent — and it would be green on
    // every case above, because every case above supplies its own configuration.
    it('answers differently for the same date under two weekend configurations', () => {
        const day = parseYmd('2026-08-09');

        expect(isWeekend(day, [5, 6])).toBe(false);
        expect(isWeekend(day, [7, 1])).toBe(true);
        expect(weekOf(day, 7).startsOn).toBe('2026-08-09');
        expect(weekOf(day, 1).startsOn).toBe('2026-08-03');
    });

    it('refuses a week start or a weekend day outside the ISO range', () => {
        const day = parseYmd('2026-08-09');

        expect(() => weekOf(day, 0)).toThrow(RangeError);
        expect(() => weekOf(day, 8)).toThrow(RangeError);
        expect(() => weekdayColumns(0, [])).toThrow(RangeError);
        expect(() => isWeekend(day, [0])).toThrow(RangeError);
        expect(() => isWeekend(day, [8])).toThrow(RangeError);
    });
});
