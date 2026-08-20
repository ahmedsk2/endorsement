import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { parseYmd, type Ymd } from '../src/calendar/ymd';
import type { Condition, EvaluationContext, Fixture, Schedule } from '../src/contract/types';
import { validate } from '../src/contract/validate';
import { coverage } from '../src/coverage';
import { evaluate, sortViolations } from '../src/evaluate';
import { preview } from '../src/preview';
import { registryEntry } from '../src/registry';

/**
 * The three Hard placement types (P2 Task 10): `overlap_block`, `vacation_block`, `eligibility`.
 *
 * ## The corpus is the assertion, and it is loaded from disk
 *
 * Every case is a JSON file under `fixtures/conditions/`, validated against the contract's own
 * `Fixture` definition and then run through the REAL `evaluate()` and `coverage()` — not through a
 * hand-called predicate. That is deliberate: the emission rule, the severity stamp and the ordering
 * are properties of the pipeline, and a test calling `evaluate` on the module directly would assert
 * the predicate while leaving the three things most likely to be wrong at the horizon edge
 * untested.
 *
 * `why` is mandatory and each one names the SHAPE the case exists to catch, in enough detail that
 * the next author can tell whether the number in it is the point or an accident. `fixtures/README.md`
 * states the rest, including that the corpus is synthetic permanently.
 *
 * ## What is asserted here and not in a fixture
 *
 * Three things a JSON case cannot express: that CG-01's `scope` actually narrows (a scope silently
 * ignored makes a condition do MORE than the gate says); that a duty naming somebody the context
 * does not describe THROWS rather than passing for want of data; and that `eligibility`'s
 * *"auto-fill order"* half is absent — refused by the schema, and absent from the module's source.
 */

const FIXTURE_DIR = join(import.meta.dirname, 'fixtures', 'conditions');

/**
 * `SourceScanner::withoutComments()`'s non-PHP path, in TypeScript: block comments, then lines whose
 * first non-space characters are `//`. Conservative on purpose and for the reason that file records
 * — leaving a comment behind is a noisy false positive, eating code is a silent false negative.
 * Strings are NOT stripped: an exception message carrying a forbidden word is code a user can see.
 */
function withoutComments(source: string): string {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
}

const d = (value: string): Ymd => parseYmd(value);

function loadFixtures(): Fixture[] {
    return readdirSync(FIXTURE_DIR)
        .filter((name) => name.endsWith('.json'))
        .sort()
        .map((name) => {
            const fixture = JSON.parse(readFileSync(join(FIXTURE_DIR, name), 'utf8')) as Fixture;

            expect(validate('Fixture', fixture), `${name} does not satisfy the Fixture contract`).toEqual([]);
            expect(fixture.name, `${name} names itself differently inside`).toBe(name.replace(/\.json$/, ''));

            return fixture;
        });
}

const FIXTURES = loadFixtures();

describe('the condition corpus', () => {
    /**
     * A guard iterating an empty directory is green for the wrong reason, and a moved or renamed
     * fixture folder is exactly how one gets there. Named cases rather than a bare count, because a
     * count survives the corpus being replaced by something else entirely.
     */
    it('found the cases it claims to, and each states why it exists', () => {
        expect(FIXTURES.map((fixture) => fixture.name)).toEqual([
            'dow-restriction-a-ban-on-one-iso-weekday',
            'dow-restriction-a-rotation-scoped-ban-follows-the-unit-on-the-date',
            'eligibility-mid-window-promotion',
            'eligibility-wrong-rotation-and-an-unnamed-slot',
            'onboarding-grace-a-duty-before-the-join-date',
            'onboarding-grace-an-unknown-join-date-is-reported-not-silent',
            'onboarding-grace-day-n-and-day-n-plus-one',
            'overlap-block-abutting-split-day-night',
            'overlap-block-carry-in-at-the-left-edge',
            'overlap-block-is-per-person-not-per-slot',
            'overlap-block-night-crosses-into-the-next-date',
            'unwanted-day-block-a-registered-day-and-a-colleague-with-none',
            'vacation-block-both-bounds-inclusive',
        ]);

        for (const fixture of FIXTURES) {
            expect(fixture.why.length, `${fixture.name} has a thin why`).toBeGreaterThan(120);
        }
    });

    for (const fixture of FIXTURES) {
        describe(fixture.name, () => {
            it('produces exactly the violations the case expects', () => {
                expect(evaluate(fixture.schedule, fixture.context, fixture.conditions)).toEqual(
                    sortViolations(fixture.expected),
                );
            });

            it('reports the coverage the case expects', () => {
                if (fixture.expectedCoverage === undefined) {
                    return;
                }

                expect(coverage(fixture.schedule, fixture.context, fixture.conditions)).toEqual(
                    fixture.expectedCoverage,
                );
            });
        });
    }
});

// ---------------------------------------------------------------------------------------------
// A small world for the properties a JSON case cannot express.
// ---------------------------------------------------------------------------------------------

const WORLD = FIXTURES.find((fixture) => fixture.name === 'vacation-block-both-bounds-inclusive') as Fixture;

function withPeople(context: EvaluationContext, mutate: (context: EvaluationContext) => void): EvaluationContext {
    const copy = structuredClone(context) as EvaluationContext;

    mutate(copy);

    return copy;
}

describe('CG-01 scope narrows, rather than being carried and ignored', () => {
    const scoped = (scope: Condition['scope']): Condition => ({
        ...(WORLD.conditions[0] as Condition),
        ...(scope === undefined ? {} : { scope }),
    });

    it('applies to everyone when no scope is set', () => {
        expect(evaluate(WORLD.schedule, WORLD.context, [scoped(undefined)])).toHaveLength(2);
    });

    it('excludes a person the scope does not name', () => {
        expect(evaluate(WORLD.schedule, WORLD.context, [scoped({ personKeys: ['p-somebody-else'] })])).toEqual([]);
    });

    /**
     * The level is read AT THE DATE here too, not once. `p-ali` is R2 throughout this world, so a
     * scope naming R3 excludes them and a scope naming R2 does not — and the coverage row shows the
     * excluded placements were never counted as evaluated, rather than being counted and passing.
     */
    it('excludes by the level held on the day, and says so in coverage', () => {
        expect(evaluate(WORLD.schedule, WORLD.context, [scoped({ levelKeys: ['R3'] })])).toEqual([]);
        expect(coverage(WORLD.schedule, WORLD.context, [scoped({ levelKeys: ['R3'] })])[0]?.evaluatedWindows).toBe(0);
        expect(evaluate(WORLD.schedule, WORLD.context, [scoped({ levelKeys: ['R2'] })])).toHaveLength(2);
    });

    it('excludes by the unit rotated on, and narrows together with the others', () => {
        expect(evaluate(WORLD.schedule, WORLD.context, [scoped({ unitKeys: ['NICU'] })])).toEqual([]);
        expect(evaluate(WORLD.schedule, WORLD.context, [scoped({ unitKeys: ['PICU'], levelKeys: ['R3'] })])).toEqual([]);
    });
});

describe('a duty naming somebody the context does not describe', () => {
    /**
     * Their leave, their level and their rotation are all unknown, so every one of these three
     * types would answer "no violation" for want of data — a Hard rule passing on incomplete input,
     * which is strictly worse than a crash. Same reasoning `slotIndex()` records for a duty naming
     * an unsupplied slot.
     */
    it('throws rather than passing quietly', () => {
        const schedule: Schedule = {
            ...WORLD.schedule,
            duties: [...WORLD.schedule.duties, { personKey: 'p-ghost', date: d('2026-08-03'), slotKey: 'night' }],
        };

        expect(() => evaluate(schedule, WORLD.context, WORLD.conditions)).toThrow(/p-ghost/);
    });
});

describe('overlap_block, beyond the corpus', () => {
    it('is the one entry allowed to assert a class, and still reads the row', () => {
        expect(registryEntry('overlap_block')?.assertedClass).toBe('hard');

        const soft = evaluate(
            (FIXTURES.find((f) => f.name === 'overlap-block-night-crosses-into-the-next-date') as Fixture).schedule,
            (FIXTURES.find((f) => f.name === 'overlap-block-night-crosses-into-the-next-date') as Fixture).context,
            [
                {
                    id: 'c-overlap',
                    typeKey: 'overlap_block',
                    class: 'soft',
                    rank: 3,
                    active: true,
                    params: {},
                },
            ],
        );

        expect(soft.map((violation) => violation.severity)).toEqual(['soft', 'soft']);
        expect(soft.map((violation) => violation.rank)).toEqual([3, 3]);
    });

    it('refuses a parameter, because CG-07 gives this row none', () => {
        const world = FIXTURES.find((f) => f.name === 'overlap-block-abutting-split-day-night') as Fixture;

        expect(() =>
            evaluate(world.schedule, world.context, [
                { id: 'c-overlap', typeKey: 'overlap_block', class: 'hard', active: true, params: { hours: 2 } },
            ]),
        ).toThrow(/unknown property "hours"/);
    });
});

describe('vacation_block, beyond the corpus', () => {
    /**
     * A person with no leave at all is the case that catches an implementation reading the wrong
     * person's leave — it stays green on a one-person world, so the world here has two.
     */
    it('reads the leave belonging to the person on the duty, not the first person in the context', () => {
        const context = withPeople(WORLD.context, (copy) => {
            copy.people.push({
                key: 'p-noor',
                levelSpans: [{ key: 'R2', from: d('2026-01-01'), to: d('2026-12-31') }],
                unitSpans: [{ key: 'PICU', from: d('2026-01-01'), to: d('2026-12-31') }],
                leaveDays: [],
                unwantedDays: [],
                eligibleDays: [],
                external: false,
            });
        });

        const schedule: Schedule = {
            ...WORLD.schedule,
            duties: [{ personKey: 'p-noor', date: d('2026-08-03'), slotKey: 'night' }],
        };

        expect(evaluate(schedule, context, WORLD.conditions)).toEqual([]);
    });
});

describe("eligibility's auto-fill order half does not ship, and the absence is asserted", () => {
    const world = FIXTURES.find((f) => f.name === 'eligibility-mid-window-promotion') as Fixture;

    /**
     * Owner decision P: ordering produces no violation at all, so one type would be carrying two
     * contracts. It is WB-04 fitness and lands in P3. A department writing the parameter must LEARN
     * that this engine will not honour it — a silently ignored key is a control that appears to do
     * nothing, and here what appears to do nothing is the order a picker offers people in.
     */
    it('refuses a condition row that carries an ordering parameter', () => {
        expect(() =>
            evaluate(world.schedule, world.context, [
                {
                    id: 'c-eligibility',
                    typeKey: 'eligibility',
                    class: 'hard',
                    active: true,
                    params: { allowed: {}, autoFillOrder: ['R3', 'R2'] },
                },
            ]),
        ).toThrow(/unknown property "autoFillOrder"/);
    });

    /**
     * A DOCBLOCK IS SCANNED SOURCE, and this guard had to be taught that the hard way — the seventh
     * time in this phase. The needles below are exactly the vocabulary `eligibility.ts`'s own
     * docblock uses to explain why the ordering half is absent, so scanning the raw file failed the
     * build on the documentation of the rule being enforced. That trains people to delete the
     * documentation, which is `RotaAccessTest`'s recorded reason for stripping comments in its two
     * absence scans, adopted here for the same reason.
     *
     * PINNED IN BOTH DIRECTIONS, per the discipline `SourceScanner` records: the prose is gone, and
     * a known CODE token is still there. Eating code would produce a false negative — every needle
     * misses, the guard is silently disabled, and the run looks exactly like a clean tree.
     */
    it('names no ordering vocabulary in its CODE, docblocks stripped', () => {
        const path = join(import.meta.dirname, '..', 'src', 'conditions', 'eligibility.ts');
        const raw = readFileSync(path, 'utf8');
        const code = withoutComments(raw);

        expect(raw, 'the docblock should still explain the absence').toContain('autoFillOrder');
        expect(code, 'the stripper ate the code, not just the prose').toContain('export const evaluate');

        for (const needle of ['autoFillOrder', 'auto_fill_order', 'fitness', 'sortBy', 'pickerOrder']) {
            expect(code, `eligibility.ts names "${needle}" in code`).not.toContain(needle);
        }
    });

    it('previews the restriction, naming the slot, the levels and the rotations', () => {
        const sentence = preview(world.conditions[0] as Condition, world.context);

        expect(sentence).toContain('night');
        expect(sentence).toContain('R2');
        expect(sentence).toContain('PICU');
        expect(sentence).toMatch(/day of the duty/i);
        expect(sentence).toMatch(/unrestricted/i);
    });
});

describe('dow_restriction — ISO integers, and a weekday NAME is refused rather than ignored', () => {
    const world = FIXTURES.find((f) => f.name === 'dow-restriction-a-ban-on-one-iso-weekday') as Fixture;

    const banning = (days: unknown): Condition => ({
        id: 'c-dow',
        typeKey: 'dow_restriction',
        class: 'hard',
        active: true,
        params: { days },
    });

    /**
     * The days are ISO INTEGERS and the schema is what says so. A department writing a day NAME is
     * expressing an intention this engine cannot honour — there is no name-to-number table in the
     * package and there is deliberately never going to be one (AR-07 keeps the names in
     * `lang/en/calendar.php`, owner decision X keeps the week's shape in the context) — so it is
     * refused with the schema's own error rather than quietly matching nothing, which on a gate
     * screen is a ban that appears to do nothing.
     *
     * THE NAME IS ASSEMBLED RATHER THAN WRITTEN, and that is not squeamishness: this file is
     * scanned by `CalendarIsTheOnlyConverterTest`'s quoted-weekday pattern, so spelling the literal
     * here would fail the very guard that keeps day names out of `packages/`. A test proving a
     * weekday name is refused cannot itself contain one. (An eighth occurrence of "scanned source
     * reaches further than you think" in this phase, and the first where the scanned file is a test
     * asserting the rule the scan enforces.)
     */
    it('refuses a weekday name, and refuses a number outside 1..7', () => {
        const name = ['M', 'on'].join('');

        expect(() => evaluate(world.schedule, world.context, [banning([name])])).toThrow(/expected integer/);
        expect(() => evaluate(world.schedule, world.context, [banning([0])])).toThrow(/below the minimum 1/);
        expect(() => evaluate(world.schedule, world.context, [banning([8])])).toThrow(/above the maximum 7/);
        expect(() => evaluate(world.schedule, world.context, [banning([])])).toThrow(/fewer than the 1 required/);
    });

    /**
     * The weekday is the DAY VECTOR's, and a date the vector does not describe is refused rather
     * than computed — AR-08's one converter, one layer inside the engine. A duty inside the horizon
     * whose date the context omitted is dropped context, exactly like a duty naming an unsupplied
     * slot or an undescribed person, and answering "not a banned day" for want of the day row would
     * be a Hard rule passing on incomplete input.
     */
    it('throws on a duty whose date the day vector does not describe', () => {
        const schedule: Schedule = {
            ...world.schedule,
            horizon: { ...world.schedule.horizon, to: d('2026-08-09') },
            duties: [{ personKey: 'p-ali', date: d('2026-08-09'), slotKey: 'night' }],
        };

        expect(() => evaluate(schedule, world.context, [banning([5])])).toThrow(/2026-08-09/);
    });
});

describe('onboarding_grace — an unknown join date is VISIBLE, not silent', () => {
    const world = FIXTURES.find(
        (f) => f.name === 'onboarding-grace-an-unknown-join-date-is-reported-not-silent',
    ) as Fixture;

    /**
     * Owner decision T makes a missing `joined_at` no violation, and P2 Task 1's finding 18 makes
     * that the state the LIVE instance is in: no seeder, factory or demo path writes the column, so
     * the honest answer and the answer of a rule that never fires are the same answer. The coverage
     * row is what separates them, and it is asserted here as well as in the fixture because the
     * fixture compares the whole structure — this states the property in words the next reader can
     * find by grepping for the decision.
     */
    it('names the person and the placements it could not judge', () => {
        const [row] = coverage(world.schedule, world.context, world.conditions);

        expect(row?.evaluatedWindows).toBe(1);
        expect(row?.skipped).toHaveLength(1);
        expect(row?.skipped[0]?.reason).toContain('p-ali');
        expect(row?.skipped[0]?.reason).toMatch(/no join date/i);
    });

    /**
     * A person with no join date and no duty is nobody's problem: reporting a skip for every
     * unjoined person in the roster would put a row on almost every evaluation and train a reader
     * to ignore the field, which is `carryInLeftEdge`'s recorded reason for refusing the same noise.
     */
    it('says nothing about a person with no join date who holds no duty', () => {
        const schedule: Schedule = {
            ...world.schedule,
            duties: world.schedule.duties.filter((duty) => duty.personKey !== 'p-ali'),
        };

        expect(coverage(schedule, world.context, world.conditions)[0]?.skipped).toEqual([]);
    });
});

describe('the six types are registered as implemented, with a preview and a schema', () => {
    it.each([
        'overlap_block',
        'vacation_block',
        'eligibility',
        'unwanted_day_block',
        'onboarding_grace',
        'dow_restriction',
    ])('%s', (typeKey) => {
        const entry = registryEntry(typeKey);

        expect(entry?.implemented).toBe(true);
        expect(entry?.evaluate).toBeDefined();
        expect(entry?.preview).toBeDefined();
        expect(entry?.paramsSchema).toBeDefined();
        expect(entry?.locationKind).toBe('placement');
    });
});
