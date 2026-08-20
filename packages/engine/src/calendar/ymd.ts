/**
 * `Ymd` — the engine's date type, and every operation it supports.
 *
 * **There is no `Date` object in this package, and this module is why one is never needed.** A date
 * is a branded `'YYYY-MM-DD'` string and all arithmetic is integer civil-date arithmetic: a date
 * converts to a day number, integers add and subtract, and the day number converts back. No
 * instant, no epoch time, no ICU, no timezone (P2 Decision B).
 *
 * That is not tidiness. `tests/fixtures/calendar/golden.json` carries a `day_boundary_cases` block
 * because the 00:00–03:00 UTC/Riyadh disagreement window is a defect class this codebase has
 * already paid for, and a Node process with `TZ` unset runs at UTC while a browser at +03:00 does
 * not. An engine holding no instants cannot have that bug at all, which is a stronger guarantee
 * than a test that remembers to set `TZ`. It is the same move `App\Support\Rota\AvailabilitySummary`
 * already makes on the PHP side — *"IT HANDLES NO DATES (ST-06). Not one … four comparisons between
 * Y-m-d STRINGS — a format that sorts correctly as text."*
 *
 * ## Two deliberate divergences from `App\Support\Calendar`, stated rather than papered over
 *
 * 1. **`datesBetween` is capped at 550 days; `Calendar::datesBetween()` is not.** The PHP cap lives
 *    on `Calendar::weeksIn()` alone (`Calendar.php:445`), a P1a guard against a mistyped year
 *    exhausting memory. This mirror caps the enumeration itself, at the same number and with the
 *    same comparison, because the memory being protected here is a browser tab's rather than a
 *    request's. `golden.json` carries no case either way, so nothing would catch a silent
 *    disagreement — which is exactly why it is written down here instead.
 * 2. **This parser does not trim; `Calendar::parse()` does.** PHP parses operator input from forms.
 *    The engine only ever parses JSON a server produced through the one converter, so leading
 *    whitespace there is a serialiser defect and surfacing it is worth more than accepting it. The
 *    divergence is in the strict direction, and it is the only direction that is safe to diverge in:
 *    `golden.json`'s `parse_rejects` block exists because `strtotime()` leniency once accepted
 *    `"+5 years"` and created real backdated clinical rows.
 *
 * Everything else is parity, and `parse_rejects` is asserted against the fixture itself rather than
 * copied, so a third rejection added on the PHP side reaches this suite unasked.
 */

declare const ymdBrand: unique symbol;

/**
 * A calendar date, `'YYYY-MM-DD'`, that has been checked to be a real one.
 *
 * The brand is what stops a raw string being used as a date: every `Ymd` in the engine came from
 * `parseYmd`, `formatYmd` or arithmetic on another `Ymd`, so a malformed date cannot enter through
 * a context object without being refused at its edge.
 */
export type Ymd = string & { readonly [ymdBrand]: true };

/** The civil parts of a date. Internal to the arithmetic; the engine's currency is `Ymd`. */
interface CivilParts {
    year: number;
    month: number;
    day: number;
}

/**
 * The maximum span `datesBetween` will enumerate, matching `Calendar::weeksIn()`'s 550-day throw.
 * Comfortably more than the longest academic year this system generates (owner decision 4: 365 or
 * 366 days, block 13 absorbing the remainder).
 */
export const MAX_SPAN_DAYS = 550;

const YMD_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Days from 1970-01-01, by the standard branchless civil algorithm (Howard Hinnant's
 * `days_from_civil`, the same one every calendar library is built on).
 *
 * The origin is a **civil date**, not an instant: it names no time, no zone and no epoch second,
 * and only differences between these integers are ever used. `Math.trunc` is deliberate throughout
 * — the algorithm's era adjustment is written for truncating division, and `Math.floor` would give
 * a different answer for dates before the origin.
 */
function daysFromParts(year: number, month: number, day: number): number {
    const shifted = year - (month <= 2 ? 1 : 0);
    const era = Math.trunc((shifted >= 0 ? shifted : shifted - 399) / 400);
    const yearOfEra = shifted - era * 400;
    const dayOfYear = Math.trunc((153 * (month + (month > 2 ? -3 : 9)) + 2) / 5) + day - 1;
    const dayOfEra = yearOfEra * 365 + Math.trunc(yearOfEra / 4) - Math.trunc(yearOfEra / 100) + dayOfYear;

    return era * 146097 + dayOfEra - 719468;
}

/** The inverse of {@link daysFromParts} (`civil_from_days`). Total for every integer. */
function partsFromDays(serial: number): CivilParts {
    const shifted = serial + 719468;
    const era = Math.trunc((shifted >= 0 ? shifted : shifted - 146096) / 146097);
    const dayOfEra = shifted - era * 146097;
    const yearOfEra = Math.trunc(
        (dayOfEra - Math.trunc(dayOfEra / 1460) + Math.trunc(dayOfEra / 36524) - Math.trunc(dayOfEra / 146096)) / 365,
    );
    const year = yearOfEra + era * 400;
    const dayOfYear = dayOfEra - (365 * yearOfEra + Math.trunc(yearOfEra / 4) - Math.trunc(yearOfEra / 100));
    const monthOfYear = Math.trunc((5 * dayOfYear + 2) / 153);
    const day = dayOfYear - Math.trunc((153 * monthOfYear + 2) / 5) + 1;
    const month = monthOfYear + (monthOfYear < 10 ? 3 : -9);

    return { year: year + (month <= 2 ? 1 : 0), month, day };
}

/**
 * Render parts the arithmetic produced. Never validates, because `partsFromDays` cannot produce an
 * impossible date; the public {@link formatYmd} validates before it gets here.
 */
function renderParts(parts: CivilParts): Ymd {
    const year = String(parts.year).padStart(4, '0');
    const month = String(parts.month).padStart(2, '0');
    const day = String(parts.day).padStart(2, '0');

    return `${year}-${month}-${day}` as Ymd;
}

/**
 * The one place a string becomes a date.
 *
 * Format check, range check, then a **round trip through the civil algorithm** — which is what
 * rejects a well-formed-but-impossible date such as `2026-02-30` (it would otherwise arrive back as
 * 2 March, exactly the roll-forward `Calendar::parse()`'s own docblock names).
 */
function partsOf(input: string): CivilParts | null {
    const matched = YMD_PATTERN.exec(input);

    if (matched === null) {
        return null;
    }

    // Destructured with explicit undefined checks rather than indexed: `noUncheckedIndexedAccess`
    // is on deliberately (tsconfig.base.json), and `Number(undefined)` is NaN, which would sail
    // through every comparison below as false.
    const [, yearText, monthText, dayText] = matched;

    if (yearText === undefined || monthText === undefined || dayText === undefined) {
        return null;
    }

    const year = Number(yearText);
    const month = Number(monthText);
    const day = Number(dayText);

    if (month < 1 || month > 12 || day < 1 || day > 31) {
        return null;
    }

    const roundTrip = partsFromDays(daysFromParts(year, month, day));

    if (roundTrip.year !== year || roundTrip.month !== month || roundTrip.day !== day) {
        return null;
    }

    return { year, month, day };
}

/**
 * `Y-m-d` ONLY, and a real date. Throws on anything else — the mirror of `Calendar::parse()`, whose
 * strictness is the most safety-critical behaviour in that module.
 */
export function parseYmd(input: string): Ymd {
    if (partsOf(input) === null) {
        throw new RangeError(
            `Not a Y-m-d date: "${input.slice(0, 32)}". Leniency here is what let "+5 years" create ` +
                'real backdated clinical rows on the legacy system.',
        );
    }

    return input as Ymd;
}

/** {@link parseYmd} without the throw — `null` for null, empty and every malformed input. */
export function tryParseYmd(input: string | null | undefined): Ymd | null {
    if (input === null || input === undefined || input === '') {
        return null;
    }

    return partsOf(input) === null ? null : (input as Ymd);
}

/** Compose a date from civil parts. Throws unless the three integers are a real date. */
export function formatYmd(year: number, month: number, day: number): Ymd {
    if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) {
        throw new RangeError(`Not a date: ${year}-${month}-${day} (all three parts must be integers).`);
    }

    if (year < 0 || year > 9999) {
        throw new RangeError(`Year ${year} is outside the four-digit range this format can express.`);
    }

    return parseYmd(renderParts({ year, month, day }));
}

/** Days from the civil origin, 1970-01-01. Negative before it. */
export function daysFromCivil(date: Ymd): number {
    const parts = partsOf(date);

    if (parts === null) {
        throw new RangeError(`Not a Y-m-d date: "${date.slice(0, 32)}".`);
    }

    return daysFromParts(parts.year, parts.month, parts.day);
}

/** The inverse of {@link daysFromCivil}. */
export function civilFromDays(days: number): Ymd {
    if (!Number.isInteger(days)) {
        throw new RangeError(`A day number must be an integer; got ${days}.`);
    }

    return renderParts(partsFromDays(days));
}

/** `date` shifted by `days`, which may be negative or zero. */
export function addDays(date: Ymd, days: number): Ymd {
    if (!Number.isInteger(days)) {
        throw new RangeError(`A day offset must be an integer; got ${days}.`);
    }

    return civilFromDays(daysFromCivil(date) + days);
}

/** Signed day difference, `to - from`. `diffDays(a, addDays(a, n)) === n` for every integer `n`. */
export function diffDays(from: Ymd, to: Ymd): number {
    return daysFromCivil(to) - daysFromCivil(from);
}

/**
 * ISO weekday, Monday = 1 … Sunday = 7 — the same numbering `clinics.weekday`, `weekendDays` and
 * `weekStartIsoDay` all use.
 *
 * 1970-01-01 is ISO day 4, hence the `+ 3`. The doubled modulus is not decoration: `%` in
 * JavaScript keeps the sign of its left operand, so every date before the origin would land on a
 * negative index without it — and that is the defect this function is most likely to acquire,
 * invisible on any fixture whose dates are all in this century.
 */
export function isoWeekday(date: Ymd): number {
    return (((daysFromCivil(date) + 3) % 7) + 7) % 7 + 1;
}

/**
 * Every date from `from` to `to`, inclusive at both ends; empty when `to` precedes `from`, exactly
 * as `Calendar::datesBetween()` is.
 *
 * Capped — see the module docblock's divergence 1. The cap is a parameter so a caller with a
 * genuinely longer range states so at the call site rather than editing this module.
 */
export function datesBetween(from: Ymd, to: Ymd, maxSpanDays: number = MAX_SPAN_DAYS): Ymd[] {
    const start = daysFromCivil(from);
    const end = daysFromCivil(to);

    if (end < start) {
        return [];
    }

    if (end - start > maxSpanDays) {
        throw new RangeError(
            `A date range may not exceed ${maxSpanDays} days; ${from}..${to} is ${end - start}. ` +
                'An unbounded enumeration built from a mistyped year is how a screen becomes a memory exhaustion.',
        );
    }

    const out: Ymd[] = [];

    for (let day = start; day <= end; day += 1) {
        out.push(civilFromDays(day));
    }

    return out;
}

/**
 * Three-way comparison, `-1 | 0 | 1`.
 *
 * A zero-padded `Y-m-d` sorts identically as text and as a date, so this is a string comparison —
 * written once, here, so that the reason is stated once rather than re-derived at every call site
 * that might otherwise reach for arithmetic to be safe.
 */
export function compareYmd(a: Ymd, b: Ymd): number {
    if (a === b) {
        return 0;
    }

    return a < b ? -1 : 1;
}
