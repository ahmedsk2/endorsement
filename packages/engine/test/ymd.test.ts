import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, it, expect } from 'vitest';

import {
    MAX_SPAN_DAYS,
    addDays,
    civilFromDays,
    compareYmd,
    datesBetween,
    daysFromCivil,
    diffDays,
    formatYmd,
    isoWeekday,
    parseYmd,
    tryParseYmd,
} from '../src/calendar/ymd';

/**
 * The `Ymd` core (P2 Task 3): civil-date arithmetic with no `Date` object anywhere.
 *
 * Decision B is the reason this suite looks the way it does. The engine holds no instant, so the
 * cases that matter are not timezone cases — they are the ones integer arithmetic gets wrong when
 * the algorithm is written from memory: the leap boundaries in both directions, the two century
 * rules that disagree with each other, a date BEFORE the arithmetic origin (where a modulus over a
 * negative number is the single most likely defect), and the parse leniency that once created real
 * backdated clinical rows.
 *
 * `parse_rejects` is READ from `tests/fixtures/calendar/golden.json` rather than copied here. That
 * file is an input to P2, not something P2 authors; copying its two inputs would produce a mirror
 * assertion that stays green after the PHP side adds a third.
 *
 * The path is built from `import.meta.dirname`, and the obvious alternative is a trap worth
 * recording for Task 5, which reads this fixture in earnest: under the jsdom environment the
 * global `URL` is **jsdom's**, and `new URL('../…', import.meta.url)` resolves against the jsdom
 * document base rather than the module — it returns `http://localhost:3000/…`, and `fileURLToPath`
 * then fails with ERR_INVALID_URL_SCHEME rather than reading the wrong file. `import.meta.dirname`
 * is a real absolute path under both Vitest and `tsc --noEmit`.
 */
const goldenPath = join(import.meta.dirname, '..', '..', '..', 'tests', 'fixtures', 'calendar', 'golden.json');

const golden = JSON.parse(readFileSync(goldenPath, 'utf8')) as { parse_rejects: { inputs: string[] } };

describe('parseYmd / tryParseYmd', () => {
    it('accepts a well-formed date and returns it unchanged', () => {
        expect(parseYmd('2026-08-19')).toBe('2026-08-19');
        expect(tryParseYmd('2026-08-19')).toBe('2026-08-19');
    });

    // Non-vacuity: an empty or renamed block below would make every rejection case pass by
    // iterating nothing, which is indistinguishable from a lenient parser on a green suite.
    it('reads the golden fixture it claims to mirror', () => {
        expect(golden.parse_rejects.inputs.length).toBeGreaterThanOrEqual(2);
        expect(golden.parse_rejects.inputs).toContain('2026-02-30');
    });

    it.each(golden.parse_rejects.inputs)('rejects %s, which the PHP converter also rejects', (input) => {
        expect(() => parseYmd(input)).toThrow();
        expect(tryParseYmd(input)).toBeNull();
    });

    // The shapes the fixture does not carry, each a real serialiser or hand-edit mistake. The
    // month/day range checks matter because a pure format check accepts 2026-13-01 happily and the
    // civil algorithm would silently return a date in the following year.
    it.each([
        ['2026-2-3', 'unpadded, which the format check must refuse rather than pad'],
        ['20260819', 'no separators'],
        ['2026-13-01', 'month 13 — the civil algorithm would roll it into 2027 without a check'],
        ['2026-00-10', 'month 0'],
        ['2026-01-32', 'day 32'],
        ['2026-01-00', 'day 0'],
        ['2026-04-31', 'a month that has 30 days'],
        ['2026-08-19T00:00', 'a datetime, not a date'],
        ['', 'empty'],
        [' 2026-08-19 ', 'padded — see the docblock: the mirror does not trim, and PHP does'],
    ])('rejects %s (%s)', (input) => {
        expect(() => parseYmd(input)).toThrow();
        expect(tryParseYmd(input)).toBeNull();
    });

    it('rejects a well-formed-but-impossible leap day', () => {
        expect(() => parseYmd('2026-02-29')).toThrow();
        expect(parseYmd('2028-02-29')).toBe('2028-02-29');
    });
});

describe('formatYmd', () => {
    it('zero-pads and round-trips through parseYmd', () => {
        expect(formatYmd(2026, 8, 19)).toBe('2026-08-19');
        expect(formatYmd(2026, 1, 1)).toBe('2026-01-01');
    });

    it('refuses parts that are not a real date', () => {
        expect(() => formatYmd(2026, 2, 30)).toThrow();
        expect(() => formatYmd(2026, 13, 1)).toThrow();
    });
});

describe('daysFromCivil / civilFromDays', () => {
    // The origin is a CIVIL date, not an instant: day 0 is 1970-01-01 because the published
    // algorithm is written that way, and only differences of these integers are ever used.
    it.each([
        ['1970-01-01', 0],
        ['1969-12-31', -1],
        ['2026-08-19', 20684],
        ['2026-08-20', 20685],
        ['1900-02-28', -25509],
        ['2000-02-28', 11015],
    ])('%s is day %i', (date, expected) => {
        expect(daysFromCivil(parseYmd(date))).toBe(expected);
        expect(civilFromDays(expected)).toBe(date);
    });

    it('round-trips every date across a leap year, a century non-leap and the origin', () => {
        for (const start of ['1899-12-01', '1969-12-01', '2000-01-01', '2028-02-01']) {
            let day = daysFromCivil(parseYmd(start));

            for (let i = 0; i < 120; i += 1) {
                const date = civilFromDays(day);
                expect(daysFromCivil(date)).toBe(day);
                expect(parseYmd(date)).toBe(date);
                day += 1;
            }
        }
    });
});

describe('addDays / diffDays', () => {
    it.each([
        ['2026-02-28', 1, '2026-03-01', '2026 is not a leap year'],
        ['2028-02-28', 1, '2028-02-29', '2028 is'],
        ['1900-02-28', 1, '1900-03-01', 'a century year divisible by 100 is not a leap year'],
        ['2000-02-28', 1, '2000-02-29', 'a century year divisible by 400 is'],
        ['2026-12-31', 1, '2027-01-01', 'the year boundary'],
        ['2027-01-01', -1, '2026-12-31', 'and backwards over it'],
        ['2028-03-01', -1, '2028-02-29', 'backwards into a leap day'],
        ['1970-01-01', -1, '1969-12-31', 'backwards across the arithmetic origin'],
        ['2026-08-19', 0, '2026-08-19', 'zero is identity'],
    ])('%s + %i days is %s (%s)', (date, days, expected) => {
        expect(addDays(parseYmd(date), days)).toBe(expected);
    });

    it('diffDays is signed and is addDays inverted', () => {
        expect(diffDays(parseYmd('2026-08-19'), parseYmd('2026-08-20'))).toBe(1);
        expect(diffDays(parseYmd('2026-08-20'), parseYmd('2026-08-19'))).toBe(-1);
        expect(diffDays(parseYmd('2026-08-19'), parseYmd('2026-08-19'))).toBe(0);
        expect(diffDays(parseYmd('2028-02-28'), parseYmd('2028-03-01'))).toBe(2);
        expect(diffDays(parseYmd('2026-02-28'), parseYmd('2026-03-01'))).toBe(1);
    });
});

describe('isoWeekday', () => {
    // 1970-01-01 is ISO day 4, so the origin itself is not a week boundary in either direction —
    // which is what makes the negative branch below a real case rather than a symmetric one.
    it.each([
        ['2026-08-19', 3],
        ['2026-08-20', 4],
        ['2026-08-21', 5],
        ['2026-08-22', 6],
        ['2026-08-23', 7],
        ['2026-08-24', 1],
        ['1970-01-01', 4],
        ['1969-12-31', 3],
        ['1900-02-28', 3],
        ['2000-02-28', 1],
    ])('%s is ISO weekday %i', (date, expected) => {
        expect(isoWeekday(parseYmd(date))).toBe(expected);
    });

    it('is 1..7 and advances by one per day across the origin', () => {
        let previous = isoWeekday(parseYmd('1969-12-20'));

        for (let i = 1; i <= 40; i += 1) {
            const day = isoWeekday(addDays(parseYmd('1969-12-20'), i));
            expect(day).toBeGreaterThanOrEqual(1);
            expect(day).toBeLessThanOrEqual(7);
            expect(day).toBe((previous % 7) + 1);
            previous = day;
        }
    });
});

describe('datesBetween', () => {
    it('is inclusive at both ends', () => {
        expect(datesBetween(parseYmd('2026-02-27'), parseYmd('2026-03-01'))).toEqual([
            '2026-02-27',
            '2026-02-28',
            '2026-03-01',
        ]);
        expect(datesBetween(parseYmd('2026-08-19'), parseYmd('2026-08-19'))).toEqual(['2026-08-19']);
    });

    it('is empty when the range ends before it starts, exactly as the PHP converter is', () => {
        expect(datesBetween(parseYmd('2026-08-20'), parseYmd('2026-08-19'))).toEqual([]);
    });

    // The cap is the one deliberate divergence from PHP (see the module docblock). Both sides of
    // the boundary are asserted: a cap that throws one day early would break a full academic year.
    it('accepts a span of exactly the cap and throws beyond it', () => {
        const from = parseYmd('2026-01-01');

        expect(datesBetween(from, addDays(from, MAX_SPAN_DAYS))).toHaveLength(MAX_SPAN_DAYS + 1);
        expect(() => datesBetween(from, addDays(from, MAX_SPAN_DAYS + 1))).toThrow(/550/);
    });

    it('caps at 550 days, the same number the PHP week range throws at', () => {
        expect(MAX_SPAN_DAYS).toBe(550);
    });
});

describe('compareYmd', () => {
    it('orders as dates, and as text, because a zero-padded Y-m-d sorts either way', () => {
        expect(compareYmd(parseYmd('2026-08-19'), parseYmd('2026-08-20'))).toBe(-1);
        expect(compareYmd(parseYmd('2026-09-01'), parseYmd('2026-08-31'))).toBe(1);
        expect(compareYmd(parseYmd('2026-08-19'), parseYmd('2026-08-19'))).toBe(0);
        expect(compareYmd(parseYmd('1969-12-31'), parseYmd('1970-01-01'))).toBe(-1);
    });
});
