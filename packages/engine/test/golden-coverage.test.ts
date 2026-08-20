import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, it, expect } from 'vitest';

/**
 * The coverage manifest for `tests/fixtures/calendar/golden.json` (P2 Task 5, Decision C).
 *
 * `golden.test.ts` catches the drift it happens to have a case for. THIS file catches the drift
 * nobody wrote a case for, which is the failure mode a golden fixture actually dies of: a block
 * sitting unasserted looks identical to a block somebody forgot, and *"we have not built it"* and
 * *"we have decided not to build it"* are different states, only the second of which is safe to
 * build on.
 *
 * So every top-level block of the fixture is classified below as either asserted by the mirror or
 * deliberately out of scope, each with its reason, and the union is asserted to EQUAL the file's
 * actual key set. When the fixture reaches version 3 with a new block, this fails until somebody
 * decides which list it joins. Absence becomes a decision instead of an oversight — the device
 * `UnitMerge::REFERENCES` uses for a table a merge deliberately leaves alone, and the one the
 * engine registry uses for the catalog row it deliberately does not implement.
 *
 * **The residual, stated rather than left for a reader to discover.** This manifest is keyed on
 * TOP-LEVEL blocks. A new FIELD inside an existing block — a new per-date value in `cases`, a new
 * bound in `weeks` — is invisible to it, with one exception bought below because it is the one
 * that has already been identified as likely (`weeksIn()`'s clipped bounds, owner decision O). A
 * field-level manifest was considered and not built: it would have to enumerate the shape of every
 * entry of every block, which is a second copy of the fixture's schema, and a second copy of a
 * fact is what this whole phase is spending its care to avoid.
 */
const fixtureDir = join(import.meta.dirname, '..', '..', '..', 'tests', 'fixtures', 'calendar');
const goldenPath = join(fixtureDir, 'golden.json');

const raw = readFileSync(goldenPath, 'utf8');
const golden = JSON.parse(raw) as Record<string, unknown>;

interface ManifestEntry {
    key: string;
    /** The suite that asserts it. Read as source and checked, so a claim here cannot outrun a test. */
    assertedIn: string;
    reason: string;
}

interface ExclusionEntry {
    key: string;
    reason: string;
}

/**
 * Blocks the mirror asserts, and where.
 *
 * `assertedIn` is checked against the named file's SOURCE: a block classified as asserted whose
 * key is not so much as named by the suite that claims it is the exact self-deception this
 * manifest exists to make impossible.
 */
const ASSERTED: ManifestEntry[] = [
    {
        key: 'cases',
        assertedIn: 'golden.test.ts',
        reason:
            'The ISO weekday, the weekend flag and the day type of a date, under two department ' +
            'calibrations. The `hijri` value on each row is NOT asserted — see the exclusions.',
    },
    {
        key: 'weeks',
        assertedIn: 'golden.test.ts',
        reason:
            'weekOf() — the week containing a date, both bounds inclusive, under three different ' +
            'week starts. This block covers weekOf() ONLY; see the clipped-bounds test below.',
    },
    {
        key: 'weekday_columns',
        assertedIn: 'golden.test.ts',
        reason:
            'The ISO order the department week runs in and which columns are weekend ones. The ' +
            'label/short vocabulary in the same block is NOT asserted — see the exclusions.',
    },
    {
        key: 'holiday_cases',
        assertedIn: 'golden.test.ts',
        reason:
            'The day-type half only: membership of the resolved holiday set, and a holiday ' +
            'outranking a weekend. Resolving a rule to Gregorian dates is the server side of ' +
            'Decision C and is asserted by GoldenFixtureTest, never here.',
    },
    {
        key: 'parse_rejects',
        assertedIn: 'ymd.test.ts',
        reason:
            'Both inputs are read from the fixture rather than copied, so a third rejection added ' +
            'on the PHP side reaches the mirror unasked. Strictness is the one property this ' +
            'parser may not drift on: leniency once created real backdated clinical rows.',
    },
];

/** Blocks the mirror deliberately does not assert, each with the decision that put it here. */
const OUT_OF_SCOPE: ExclusionEntry[] = [
    {
        key: '_purpose',
        reason: 'Prose. It states the contract; it is not a value to mirror.',
    },
    {
        key: 'version',
        reason:
            'Fixture metadata. Read as a non-vacuity floor by golden.test.ts, never mirrored — a ' +
            'version number is not a calendar fact. The key-set assertion below, not this number, ' +
            'is what makes a version 3 block impossible to ignore.',
    },
    {
        key: 'timezone',
        reason:
            'Decision B: the engine holds no instant, so it holds no timezone. The value is ' +
            'fixture provenance and, in the evaluation context, identity — never arithmetic.',
    },
    {
        key: 'hijri_month_boundary',
        reason:
            'Decision C / owner decision AA: no Hijri in the mirror. ICU in a browser is not ' +
            'guaranteed to agree with PHP ICU, and the offset-before-conversion rule this block ' +
            'pins is applied server-side, where the one converter lives.',
    },
    {
        key: 'hijri_labels',
        reason:
            'Decision C: a Hijri label is display text and arrives as a string prop. A month-name ' +
            'table here would be a second vocabulary for a fact AR-07 keeps in the lang files.',
    },
    {
        key: 'day_boundary_cases',
        reason:
            'Decision B: an engine with no instants cannot have the UTC/Riyadh day-boundary bug ' +
            'at all, which is stronger than a test that remembers to set TZ. The PHP-side ' +
            'assertion remains the one that matters, and DayBoundaryTest is where it lives.',
    },
    {
        key: 'period_runs',
        reason:
            'Period generation is server-side: periods arrive in the evaluation context as ' +
            'boundaries, so the mirror generates none. PeriodGenerator is the one definition and ' +
            'GoldenFixtureTest asserts this block against it.',
    },
];

describe('the coverage manifest names every block of the fixture', () => {
    const classified = [...ASSERTED.map((entry) => entry.key), ...OUT_OF_SCOPE.map((entry) => entry.key)];

    it('classifies every block exactly once, and classifies nothing that is not there', () => {
        const actual = Object.keys(golden).sort();

        // Named in both directions on purpose. An unclassified block is a decision nobody has
        // taken; a classified block that no longer exists is a manifest describing a fixture that
        // has moved on, and the second rots as quietly as the first.
        const unclassified = actual.filter((key) => !classified.includes(key));
        const stale = classified.filter((key) => !actual.includes(key));

        expect(unclassified, `Unclassified block(s) in golden.json: ${unclassified.join(', ')}`).toEqual([]);
        expect(stale, `Manifest names block(s) golden.json no longer has: ${stale.join(', ')}`).toEqual([]);
        expect(classified.slice().sort()).toEqual(actual);
    });

    it('classifies no block twice', () => {
        expect(classified.length).toBe(new Set(classified).size);
    });

    it('states a reason for every classification', () => {
        const silent = [...ASSERTED, ...OUT_OF_SCOPE].filter((entry) => entry.reason.trim().length < 20);

        expect(silent.map((entry) => entry.key)).toEqual([]);
    });

    // Non-vacuity: an emptied manifest would satisfy the equality above only against an emptied
    // fixture, but it would satisfy every OTHER assertion here trivially.
    it('is not empty in either direction', () => {
        expect(ASSERTED.length).toBeGreaterThanOrEqual(5);
        expect(OUT_OF_SCOPE.length).toBeGreaterThanOrEqual(7);
    });
});

describe('a block claimed as asserted is named by the suite that claims it', () => {
    /**
     * THE NEEDLE IS ROOTED AT THE FIXTURE, and `.${key}` was not — which made one block's claim
     * satisfiable by a DIFFERENT block's key. `cases` is the collision that exists today:
     * `golden.weekday_columns.cases` ends in `.cases`, so the top-level `cases` block reported
     * itself asserted with every reference to it deleted from `golden.test.ts`. A manifest whose
     * entries can be satisfied by each other is the self-deception this file exists to make
     * impossible, one level in. Measured at no cost — every entry names `golden.<key>` already.
     *
     * STATED RESIDUAL, and it is the same limit the reverse direction was refused for below: this
     * still cannot tell an ASSERTION from a MENTION, so a key named only by a non-vacuity floor
     * counts as named. What it now tells apart is one block from another, which is the failure
     * that was actually present.
     */
    it.each(ASSERTED)('$key, in $assertedIn', (entry) => {
        const source = readFileSync(join(import.meta.dirname, entry.assertedIn), 'utf8');

        expect(source).toContain(`golden.${entry.key}`);
    });

    // The reverse check — that an out-of-scope key is named NOWHERE in the mirror suites — was
    // measured and NOT bought: it cannot tell an assertion from a mention, and it would fire on
    // golden.test.ts reading `version` as a non-vacuity floor while asserting nothing about it.
    // The direction kept is the one that catches the real failure: a block classified as covered
    // with nothing covering it.
    it('reads real files, so a renamed suite fails rather than passing silently', () => {
        expect(() => readFileSync(join(import.meta.dirname, 'no-such-suite.ts'), 'utf8')).toThrow();
    });
});

describe('what no block of the fixture covers', () => {
    /**
     * Owner decision O, held mechanically rather than by memory.
     *
     * `Calendar::weeksIn()` returns `clipped_starts_on`/`clipped_ends_on` — a week's true bounds
     * trimmed to the range asked for — and those are exactly what a week-windowed count and the
     * vacation-week modifier consume. The fixture has ZERO coverage of them, which is why the
     * mirror does not implement that function at all and week windows arrive in the evaluation
     * context instead, computed once by the one converter.
     *
     * If a future version adds that coverage, this test goes red — and it should: the reason the
     * mirror does not implement the function would have stopped being true, and that is a decision
     * to retake rather than a line to delete.
     */
    it('has no coverage of the clipped week bounds, which is why the mirror omits weeksIn', () => {
        expect(raw).not.toContain('clipped');
    });
});
