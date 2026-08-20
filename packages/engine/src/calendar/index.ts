/**
 * The calendar mirror — this repository's ONE deliberate second definition of a fact, and the
 * smallest one that could be built (P2 Task 5, Decision C).
 *
 * `App\Support\Calendar` is the only date converter in this system (AR-08). §7 Decision A of P1a
 * overruled the design doc's own *"PHP plus a mirrored package"* wording with *"ONE
 * implementation, not two"*, on the ground that two definitions of one fact is the failure class
 * `AuditChain::canonical()` already carries a docblock against — two copies of one canonical
 * string drifted the day `APP_TIMEZONE` was set and the live system announced its whole audit
 * trail as tampered, when nothing had been. P2 knowingly creates the second implementation,
 * because UX-05 requires hints that never touch the network and AR-03 requires one pure engine.
 * It pays for it in four ways, and this docblock is where they are stated rather than assumed:
 *
 * 1. **`tests/fixtures/calendar/golden.json` is the contract, asserted from BOTH sides.**
 *    `GoldenFixtureTest` (PHP) and `golden.test.ts` (here) read the same file. It is an INPUT to
 *    P2 — its `_purpose` already names this package — and it is never edited to make either side
 *    pass.
 * 2. **The surface is as small as the fixture can hold honestly.** Four functions here, plus the
 *    `Ymd` core re-exported below. Everything else a department's calendar knows arrives in the
 *    evaluation context, resolved once, server-side, by the one converter.
 * 3. **Every department-varying fact is a PARAMETER** (owner decision X). Weekend days, the week
 *    start and the resolved holiday set are arguments, never module constants. A bundled default
 *    would be a second definition of a per-department fact, which is precisely what the fixture
 *    exists to prevent — and it would be invisible, because every fixture case supplies its own
 *    configuration and would keep passing.
 * 4. **What is absent is declared.** `golden-coverage.test.ts` names every block of the fixture as
 *    asserted or deliberately out of scope, so a block nobody has classified fails the build.
 *    *"We have not built it"* and *"we have decided not to build it"* are different states and
 *    only the second is safe to build on.
 *
 * ## What this mirror does NOT implement, and why
 *
 * - **No Hijri conversion** (Decision C, owner decision AA). `Calendar` resolves Hijri through ICU
 *   with a per-department offset, and a browser's ICU build is not guaranteed to agree with PHP's
 *   — while the ICU formatter's own name is one of the ten needles the date guard forbids in this
 *   directory besides. Holidays reach the engine as already-resolved Gregorian dates in the day
 *   vector. A Hijri LABEL is display text and arrives as a string.
 * - **No week clipping** (owner decision O). `Calendar::weeksIn()`'s `clipped_*` bounds have zero
 *   coverage in the fixture, so a copy here would be an unasserted second definition of a
 *   per-department fact. Week windows arrive in the context as `periods[].weeks`.
 * - **No day NAMES.** The vocabulary is `lang/en/calendar.php`'s (AR-07, English-only at launch)
 *   and reaches a screen as a prop. A table of them here would be a second vocabulary AND a build
 *   failure: `CalendarIsTheOnlyConverterTest` scans this directory for exactly that array.
 * - **No week START derivation.** `Calendar::weekStartIsoDay()` derives it from the configured
 *   weekend; here it is supplied, and the fixture carries both halves so the supplied value is the
 *   one PHP derived.
 * - **No instant, no timezone, no `Date` object anywhere** (Decision B) — see `./ymd`.
 */

import { addDays, isoWeekday, type Ymd } from './ymd';

export * from './ymd';

/** A working day. */
export const DAY_WEEKDAY = 'WD';
/** A configured weekend day that is not also a holiday. */
export const DAY_WEEKEND = 'WE';
/** A resolved holiday, which outranks both of the above. */
export const DAY_HOLIDAY = 'HOL';

/** The three values `Calendar::dayType()` produces, and the only three. */
export type DayType = typeof DAY_WEEKDAY | typeof DAY_WEEKEND | typeof DAY_HOLIDAY;

/**
 * One column of the department's week, in the order that department runs it.
 *
 * Carries no name: see the module docblock. A screen renders the name from a prop; the engine
 * only ever needs the ISO number and whether the column is a weekend one.
 */
export interface WeekdayColumn {
    iso: number;
    weekend: boolean;
}

/** A week, BOTH BOUNDS INCLUSIVE — the same idiom `Person::levelAt()` and `Period::contains()` share. */
export interface WeekBounds {
    startsOn: Ymd;
    endsOn: Ymd;
}

/**
 * ISO-8601 weekday numbering, Monday = 1 … Sunday = 7 — the same numbering `clinics.weekday`,
 * `weekend_days` and `week_start_iso_day` all use.
 *
 * Carbon's `dayOfWeek`, where Sunday is 0, is a DIFFERENT scheme and never what this module means.
 * The range check exists to catch it: a 0 arriving here is that other scheme leaking through a
 * serialiser, and silently treating it as valid would shift an entire department's week by one
 * column with nothing to show for it.
 */
function assertIsoDay(iso: number, what: string): void {
    if (!Number.isInteger(iso) || iso < 1 || iso > 7) {
        throw new RangeError(
            `${what} must be an ISO-8601 weekday, 1..7 (Monday = 1 ... Sunday = 7); got ${iso}. ` +
                "Carbon's dayOfWeek numbering, where Sunday is 0, is a second scheme and is never what this means.",
        );
    }
}

function assertWeekendDays(weekendDays: readonly number[]): void {
    // An empty list is a real configuration, not an unset one — the fixture carries the case, and
    // the week start falls back rather than dividing by zero.
    weekendDays.forEach((iso) => assertIsoDay(iso, 'A weekend day'));
}

/**
 * Is this date one of the department's configured weekend days?
 *
 * Read from the supplied list, never recomputed from a rotation, so there is one definition of
 * "is this a weekend day" and not two — the same property `Calendar::weekdayColumns()` states on
 * the PHP side.
 */
export function isWeekend(date: Ymd, weekendDays: readonly number[]): boolean {
    assertWeekendDays(weekendDays);

    return weekendDays.includes(isoWeekday(date));
}

/**
 * `WD`, `WE` or `HOL` for one date — the mirror of `Calendar::dayType()`.
 *
 * **A holiday outranks a weekend, deliberately.** `Calendar`'s own docblock states the reason: a
 * coverage template that asks for holiday staffing must get it on a holiday that happens to fall
 * on a Friday. A mirror that flattened these three values to two would be green on every other
 * assertion in the golden suite and wrong on exactly the days that matter most.
 *
 * `holidayDates` is the RESOLVED set — the Gregorian dates `Calendar::holidaysOn()` produced for
 * this department, including its Hijri rules, its per-department offset and its multi-day
 * walk-back. This module resolves no rule and knows no Hijri (Decision C).
 */
export function dayType(date: Ymd, weekendDays: readonly number[], holidayDates: ReadonlySet<Ymd>): DayType {
    if (holidayDates.has(date)) {
        return DAY_HOLIDAY;
    }

    return isWeekend(date, weekendDays) ? DAY_WEEKEND : DAY_WEEKDAY;
}

/**
 * The department's week, in the order IT runs, one entry per day — the mirror of
 * `Calendar::weekdayColumns()`.
 *
 * NOTHING STORED DEPENDS ON THE WEEK START: the ISO integer in `clinics.weekday` is absolute and
 * only presentation rotates. A department that changes its weekend re-orders every column with no
 * stored value changing and no migration, which is the property the fixture's four cases exist to
 * hold — same seven ISO days, same seven columns, different order and different weekend flags.
 */
export function weekdayColumns(weekStartIsoDay: number, weekendDays: readonly number[]): WeekdayColumn[] {
    assertIsoDay(weekStartIsoDay, 'A week start');
    assertWeekendDays(weekendDays);

    const columns: WeekdayColumn[] = [];

    for (let offset = 0; offset < 7; offset += 1) {
        const iso = ((weekStartIsoDay - 1 + offset) % 7) + 1;

        columns.push({ iso, weekend: weekendDays.includes(iso) });
    }

    return columns;
}

/**
 * The week containing a date, both bounds inclusive — the mirror of `Calendar::weekOf()`.
 *
 * Returns bounds only. The PHP side also returns dual-dated labels for both ends (UX-04), which
 * are display text this package never produces.
 *
 * The week start is SUPPLIED rather than derived from `weekendDays`: the derivation ("the day
 * after the last configured weekend day, wrapping") is `Calendar::weekStartIsoDay()`'s, and
 * duplicating a rule the context already carries the answer to is the second definition this
 * module is otherwise built to avoid.
 */
export function weekOf(date: Ymd, weekStartIsoDay: number): WeekBounds {
    assertIsoDay(weekStartIsoDay, 'A week start');

    // How many days back to the most recent week-start weekday, 0..6.
    const back = (isoWeekday(date) - weekStartIsoDay + 7) % 7;
    const startsOn = addDays(date, -back);

    return { startsOn, endsOn: addDays(startsOn, 6) };
}
