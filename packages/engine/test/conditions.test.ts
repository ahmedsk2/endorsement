import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { isoWeekday, parseYmd, type Ymd } from '../src/calendar/ymd';
import { carriedCredits } from '../src/conditions/holiday_equity';
import type { Condition, EvaluationContext, Fixture, Person, Schedule } from '../src/contract/types';
import { validate } from '../src/contract/validate';
import { coverage } from '../src/coverage';
import { evaluate, locationIsReportable, sortViolations } from '../src/evaluate';
import { preview } from '../src/preview';
import { CATALOG, registryEntry } from '../src/registry';

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
            'call-frequency-max-a-window-it-can-only-see-part-of-is-left-unjudged',
            'call-frequency-max-is-density-where-min-gap-is-spacing',
            'call-frequency-max-the-denominator-is-eligible-days-not-calendar-days',
            'call-frequency-max-the-scope-excludes-somebody-their-own-calls-would-flag',
            'call-frequency-max-the-window-that-begins-in-the-published-month',
            'clinic-conflict-a-named-attendee-who-rotates-nowhere',
            'clinic-conflict-levels-mode-reads-the-level-on-the-clinic-date',
            'clinic-conflict-post-call-reaches-the-day-after-the-last-horizon-date',
            'clinic-conflict-the-same-day-variant-is-a-calendar-day-not-a-time-overlap',
            'composition-a-holiday-that-falls-on-a-weekend-is-its-own-bucket',
            'composition-a-target-with-no-holiday-figure-folds-them-into-the-weekend',
            'composition-the-period-that-begins-in-the-published-month',
            'consecutive-max-days-a-run-of-count-is-clean-and-the-next-date-breaks-it',
            'consecutive-max-hours-a-stretch-of-exactly-the-cap-is-clean',
            'consecutive-max-hours-joins-a-chain-across-a-short-transition',
            'consecutive-max-the-run-spans-the-thirty-first-into-the-first',
            'consecutive-max-what-the-nights-unit-and-the-kinds-list-leave-out',
            'count-max-a-clipped-week-at-a-period-edge-is-the-departments-own',
            'count-max-is-per-person-not-a-cohort-total',
            'count-max-the-kinds-list-names-which-duties-are-counted',
            'count-max-the-level-filter-is-read-at-the-window-start',
            'count-max-the-levels-list-narrows-who-the-cap-applies-to',
            'count-max-the-scope-and-the-levels-list-intersect',
            'count-max-the-week-that-begins-in-the-published-month',
            'count-max-the-window-parameter-picks-the-period-or-the-week',
            'count-min-a-floor-leaves-a-window-it-can-only-see-part-of-unjudged',
            'count-min-a-person-who-joined-part-way-through-the-window',
            'count-min-the-floor-counts-the-week-that-begins-in-the-published-month',
            'dow-restriction-a-ban-on-one-iso-weekday',
            'dow-restriction-a-rotation-scoped-ban-follows-the-unit-on-the-date',
            'eligibility-a-mid-window-rotation-change',
            'eligibility-mid-window-promotion',
            'eligibility-no-level-and-no-rotation-on-the-date-both-fail-closed',
            'eligibility-wrong-rotation-and-an-unnamed-slot',
            'fairness-distribution-a-pro-rated-target-of-zero-still-allows-one-duty',
            'fairness-distribution-a-quantity-nothing-tallies-is-reported-rather-than-passing-quietly',
            'fairness-distribution-external-people-are-left-out-when-the-rule-says-so',
            'fairness-distribution-spread-mode-measures-the-widest-gap-and-names-the-pair',
            'fairness-distribution-the-expected-share-is-pro-rated-by-availability',
            'fairness-distribution-the-scope-excludes-somebody-their-own-share-would-flag',
            'free-day-min-a-twenty-four-hour-call-on-the-fifth-leaves-the-sixth-occupied',
            'free-day-min-leave-counts-as-a-free-day-unless-the-rule-says-otherwise',
            'free-day-min-the-averaging-multiplies-the-window-and-the-requirement',
            'free-day-min-the-window-that-begins-in-the-published-month',
            'holiday-equity-a-carried-credit-of-zero-is-what-an-unrecorded-one-counts-as',
            'holiday-equity-a-named-holiday-the-schedule-never-reaches-is-reported',
            'holiday-equity-an-unseen-lookback-is-reported-and-the-schedule-judged-on-its-own',
            'holiday-equity-the-scope-excludes-somebody-their-own-credits-would-flag',
            'holiday-equity-working-any-part-of-a-holiday-is-one-credit-for-that-holiday-year',
            'max-gap-an-unfinished-gap-is-reported-rather-than-evaluated',
            'max-gap-at-exactly-the-limit-and-a-day-beyond-it',
            'max-gap-leave-stops-the-clock-and-an-off-roster-rotation-does-not',
            'max-gap-the-gap-that-begins-in-the-published-month',
            'min-gap-days-counts-between-start-dates-on-both-sides-of-the-boundary',
            'min-gap-hours-across-the-carry-in-from-the-published-month',
            'min-gap-hours-at-exactly-the-required-gap-and-an-hour-short',
            'min-gap-kinds-names-both-sides-of-the-pair',
            'min-gap-the-pair-the-two-readings-disagree-about',
            'onboarding-grace-a-duty-before-the-join-date',
            'onboarding-grace-an-unknown-join-date-is-reported-not-silent',
            'onboarding-grace-day-n-and-day-n-plus-one',
            'overlap-block-abutting-split-day-night',
            'overlap-block-carry-in-at-the-left-edge',
            'overlap-block-is-per-person-not-per-slot',
            'overlap-block-night-crosses-into-the-next-date',
            'post-duty-exclusion-a-weekly-cadence-from-duty-ends-by-spandays',
            'post-duty-exclusion-the-from-and-to-kinds-each-narrow',
            'post-duty-exclusion-the-window-opens-on-the-thirty-first-and-closes-on-the-first',
            'rolling-hours-max-a-night-call-counts-its-hours-on-both-dates-it-runs-on',
            'rolling-hours-max-a-slot-that-does-not-count-toward-hours-is-left-out',
            'rolling-hours-max-the-averaging-multiplies-both-the-window-and-the-cap',
            'rolling-hours-max-the-scope-excludes-somebody-their-own-hours-would-flag',
            'rolling-hours-max-the-window-that-begins-in-the-published-month',
            'same-unit-conflict-a-day-exception-lifts-the-ban-on-that-date-alone',
            'same-unit-conflict-two-rotators-on-one-unit-and-a-colleague-elsewhere',
            'target-per-period-a-level-with-no-entry-in-the-map-has-no-target',
            'target-per-period-a-modifier-replaces-the-target-and-a-vacation-week-is-any-overlap',
            'target-per-period-a-period-longer-than-the-modifier-allows-does-not-match',
            'target-per-period-the-level-is-read-at-the-period-start',
            'target-per-period-the-period-that-begins-in-the-published-month',
            'target-per-period-two-modifiers-and-the-first-match-wins',
            'unwanted-day-block-a-registered-day-and-a-colleague-with-none',
            'vacation-block-both-bounds-inclusive',
            'we-pairing-a-pair-the-calendar-never-produces-is-reported-rather-than-silent',
            'we-pairing-a-weekend-split-between-two-people-is-not-the-preferred-pairing',
            'we-pairing-a-weekend-with-only-one-of-its-days-covered-is-not-a-split',
            'we-pairing-an-adjacency-the-rule-does-not-name-is-not-a-weekend',
            'we-pairing-the-scope-excludes-a-weekend-split-between-people-it-does-not-cover',
            'we-pairing-the-weekend-that-straddles-the-month-boundary-is-one-weekend',
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

    /**
     * THE TWO DAY LISTS ARE DIFFERENT CLINICAL FACTS, and until now they were held apart only by
     * the corpus happening to leave one of them empty in each world — true, and true by accident.
     * P2-1's review asked whether the pair had collapsed into one; it had not, and the swap fails
     * four corpus cases. But a future case giving one person leave AND a registration on the same
     * date would make that swap invisible again, in a pair whose whole difference is Hard against
     * top-soft: a leave violation blocks publication and a preference violation is a warning, so a
     * type reading the wrong list misgrades a gate rather than mislabelling a badge.
     *
     * So: move the dates from one list to the other on ONE world, and the answers move with them.
     * PLANTED both ways — `vacation_block` reading `unwantedDays`, `unwanted_day_block` reading
     * `leaveDays` — each red here as well as in the corpus. Reverted.
     */
    it('reads leaveDays where unwanted_day_block reads unwantedDays, on one world', () => {
        const moved = withPeople(WORLD.context, (copy) => {
            for (const person of copy.people) {
                person.unwantedDays = person.leaveDays;
                person.leaveDays = [];
            }
        });

        const registration: Condition = {
            id: 'c-unwanted',
            typeKey: 'unwanted_day_block',
            class: 'soft',
            rank: 1,
            active: true,
            params: {},
        };

        expect(evaluate(WORLD.schedule, moved, WORLD.conditions)).toEqual([]);
        expect(evaluate(WORLD.schedule, moved, [registration])).toHaveLength(2);
        expect(evaluate(WORLD.schedule, WORLD.context, [registration])).toEqual([]);
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

describe('clinic_conflict — the variant switch, and the fallback the day vector cannot reach', () => {
    const sameDayWorld = FIXTURES.find(
        (f) => f.name === 'clinic-conflict-the-same-day-variant-is-a-calendar-day-not-a-time-overlap',
    ) as Fixture;

    /**
     * The frozen default (SPEC §4, owner decision S) is post-call ON and same-day OFF, and the two
     * variants must genuinely differ on the same world — a fixture proving the same-day variant
     * fires proves nothing about the setting a department actually ships with. An early duty's
     * post-duty window falls on its own anchor date, so under `post_call` there is nothing to
     * report here at all.
     */
    it('reports nothing on the same world under the frozen post-call-only variant', () => {
        const postCallOnly: Condition = {
            ...(sameDayWorld.conditions[0] as Condition),
            params: { variant: 'post_call' },
        };

        expect(evaluate(sameDayWorld.schedule, sameDayWorld.context, [postCallOnly])).toEqual([]);
        expect(coverage(sameDayWorld.schedule, sameDayWorld.context, [postCallOnly])[0]?.evaluatedWindows).toBe(1);
    });

    it('refuses a variant the spec does not name, and a row that omits it', () => {
        const row = (params: Record<string, unknown>): Condition => ({
            ...(sameDayWorld.conditions[0] as Condition),
            params,
        });

        expect(() => evaluate(sameDayWorld.schedule, sameDayWorld.context, [row({ variant: 'same_day' })])).toThrow(
            /is not one of/,
        );
        expect(() => evaluate(sameDayWorld.schedule, sameDayWorld.context, [row({})])).toThrow(/variant/);
    });

    /**
     * THE FALLBACK, PINNED. `isoWeekdayAt()` prefers the precomputed day vector and computes the ISO
     * weekday for a date the vector does not reach — which `clinic_conflict` needs, because a
     * post-duty window opened on the last date of the horizon closes on the day after it. That is
     * only safe if the two answers cannot disagree WHERE THEY OVERLAP, so this asserts exactly that,
     * over every day row of every case in the corpus rather than over a constructed date.
     *
     * It is deliberately not an argument about AR-08: the department's facts — `dayType`, the week
     * start, the weekend days — are never re-derived anywhere in this package. The ISO weekday of a
     * civil date is universal arithmetic that `golden.test.ts` already asserts against
     * `golden.json`'s own `iso_weekday`.
     */
    it('agrees with the day vector on every date the day vector describes', () => {
        const disagreements = FIXTURES.flatMap((fixture) =>
            fixture.context.days
                .filter((day) => day.isoWeekday !== isoWeekday(day.date))
                .map((day) => `${fixture.name}: ${day.date} is ${day.isoWeekday} in the vector`),
        );

        expect(disagreements).toEqual([]);
        expect(FIXTURES.flatMap((fixture) => fixture.context.days).length).toBeGreaterThan(20);
    });
});

describe('same_unit_conflict — the params the schema will not take on trust', () => {
    const world = FIXTURES.find(
        (f) => f.name === 'same-unit-conflict-two-rotators-on-one-unit-and-a-colleague-elsewhere',
    ) as Fixture;

    /**
     * `exceptDates` is `$ref: Ymd`, so a date this system could not have produced is refused rather
     * than silently never matching. An exception that lifts nothing is a control that appears to do
     * nothing — and the person who wrote it would have every reason to believe the ban was lifted.
     */
    it('refuses an exception date that is not a plain Y-m-d', () => {
        const row: Condition = {
            ...(world.conditions[0] as Condition),
            params: { exceptDates: ['7 Aug 2026'] },
        };

        expect(() => evaluate(world.schedule, world.context, [row])).toThrow(/does not match/);
    });

    /**
     * A person between rotations holds no unit, and `null` is not a unit two people can share.
     * Answering "they match" for want of data would put a collision on every pair whose spans have
     * a gap — the roster state a mid-year transfer produces routinely.
     */
    it('says nothing about two people who are rotating nowhere on the date', () => {
        const context = withPeople(world.context, (copy) => {
            for (const person of copy.people) {
                person.unitSpans = [];
            }
        });

        expect(evaluate(world.schedule, context, world.conditions)).toEqual([]);
    });
});

describe('min_gap and post_duty_exclusion, beyond the corpus', () => {
    const world = FIXTURES.find(
        (f) => f.name === 'min-gap-hours-across-the-carry-in-from-the-published-month',
    ) as Fixture;

    /**
     * Two duties that OVERLAP have no gap at all, and a subtraction reports that as a negative
     * number. "Only -4 h between this duty and …" reads as a defect in the tool rather than as a
     * defect in the rota, and a scheduler who does not believe one warning stops believing the
     * others. The pair is a real configuration: overlap_block and min_gap are both on, and both
     * fire.
     */
    it('says two duties overlap rather than reporting a negative gap', () => {
        const schedule: Schedule = {
            ...world.schedule,
            duties: [
                { personKey: 'p-ali', date: d('2026-08-01'), slotKey: 'late' },
                { personKey: 'p-ali', date: d('2026-08-01'), slotKey: 'night' },
            ],
        };

        const explanations = evaluate(schedule, { ...world.context, priorDuties: [] }, world.conditions).map(
            (violation) => violation.explanation,
        );

        expect(explanations).toHaveLength(2);
        expect(explanations.every((text) => text.includes('overlap'))).toBe(true);
        expect(explanations.some((text) => /-\d/.test(text.replace(/20\d\d-\d\d-\d\d/g, '')))).toBe(false);
    });

    /**
     * When a kind is in BOTH `from` and `to`, `post_duty_exclusion` degenerates into `min_gap` in
     * hours — the same duty kind blocking itself for H hours. That is a legitimate configuration
     * rather than a mistake, and the two types then produce the SAME judgement on the same pair,
     * which is what this asserts. What must not happen is the two disagreeing: they measure from
     * the same end, through `postDutyWindow()`, and owner decision H's start-in-window is the same
     * test as "the gap is shorter than H".
     */
    it('degenerates into min_gap in hours when from and to intersect, and agrees with it', () => {
        const context = { ...world.context, priorDuties: [] };
        const schedule: Schedule = {
            ...world.schedule,
            duties: [
                { personKey: 'p-ali', date: d('2026-08-01'), slotKey: 'night' },
                { personKey: 'p-ali', date: d('2026-08-02'), slotKey: 'late' },
            ],
        };

        const gap = evaluate(schedule, context, [
            { id: 'c-x', typeKey: 'min_gap', class: 'hard', active: true, params: { value: 10, unit: 'hours' } },
        ]);

        const exclusion = evaluate(schedule, context, [
            {
                id: 'c-x',
                typeKey: 'post_duty_exclusion',
                class: 'hard',
                active: true,
                params: { from: ['call', 'clinic-cover'], to: ['call', 'clinic-cover'], hours: 10 },
            },
        ]);

        expect(gap.map((violation) => violation.location)).toContainEqual(exclusion[0]?.location);
        expect(exclusion).toHaveLength(1);
        expect(gap).toHaveLength(2);
    });
});

describe('the horizon-edge corpus — what the carry-in tail is actually asserted to do', () => {
    const seamCases = FIXTURES.filter((fixture) => fixture.context.priorDuties.length > 0);

    /**
     * DERIVED FROM THE REGISTRY, in both directions. `needsCarryIn` is a claim each entry makes
     * about itself, and a claim nothing checks is a comment. So every implemented type that claims
     * to need the tail must have a corpus case that actually supplies one — and the named list
     * below is the non-vacuity floor, because a derivation that produced the empty set would
     * satisfy the first assertion on a tree with no corpus at all.
     *
     * `clinic_conflict` is deliberately NOT in this list: its entry was corrected to
     * `needsCarryIn: false` at Task 13, on the measurement that every finding it produces is
     * located at a DUTY, so one derived from a tail duty is dropped by the emission rule before
     * anybody sees it. A seam fixture for it could assert nothing, which is exactly what this guard
     * would otherwise have demanded.
     */
    it('has a carry-in case for every implemented type that claims to need one', () => {
        const claiming = CATALOG.filter((entry) => entry.needsCarryIn && entry.evaluate !== undefined)
            .map((entry) => entry.typeKey)
            .sort();

        const covered = new Set(
            seamCases.flatMap((fixture) => fixture.conditions.map((condition) => condition.typeKey)),
        );

        expect(claiming).toEqual([
            'call_frequency_max',
            'composition',
            'consecutive_max',
            'count_max',
            'count_min',
            'free_day_min',
            'max_gap',
            'min_gap',
            'overlap_block',
            'post_duty_exclusion',
            'rolling_hours_max',
            'target_per_period',
            'we_pairing',
        ]);
        expect(claiming.filter((typeKey) => !covered.has(typeKey))).toEqual([]);
    });

    /**
     * THE ASYMMETRIC EMISSION RULE, over the corpus rather than over a constructed violation. A
     * PLACEMENT must fall inside `[from, to]`; a WINDOW need only touch it, because a window
     * beginning in the tail constrains a duty on the 1st. Task 7 measured that the intuitive
     * containment reading is silently correct for eleven types and silently deletes the left edge
     * for eight, and this is what keeps the corpus honest about which one it is asserting: every
     * expected violation in every case satisfies the rule for ITS OWN location kind.
     */
    it('expects no violation whose location the emission rule would have dropped', () => {
        const escaping = FIXTURES.flatMap((fixture) =>
            fixture.expected
                .filter((violation) => !locationIsReportable(violation.location, fixture.schedule.horizon))
                .map((violation) => `${fixture.name}: ${JSON.stringify(violation.location)}`),
        );

        expect(escaping).toEqual([]);
        expect(FIXTURES.flatMap((fixture) => fixture.expected).length).toBeGreaterThan(15);
    });

    /**
     * THE PAIR THE CARRY-IN TYPES OWE, ASSERTED TOGETHER. Take away the history and the violation
     * that depended on it disappears — which is correct, and on its own is indistinguishable from a
     * type that never read the tail at all. So the same call must also produce the coverage row
     * saying the left edge could not be examined. `evaluate()` going quiet and `coverage()` staying
     * quiet with it is the failure this catches.
     */
    it.each(seamCases.map((fixture) => fixture.name))('%s says so in coverage when no history was supplied', (name) => {
        const fixture = seamCases.find((candidate) => candidate.name === name) as Fixture;
        const context: EvaluationContext = { ...fixture.context, historyAvailableFrom: null, priorDuties: [] };

        expect(evaluate(fixture.schedule, context, fixture.conditions)).toEqual([]);

        for (const row of coverage(fixture.schedule, context, fixture.conditions)) {
            expect(row.skipped).toHaveLength(1);
            expect(row.skipped[0]?.reason).toMatch(/historyAvailableFrom is null/);
            expect(row.skipped[0]?.to).toBe(d('2026-07-31'));
        }
    });

    /**
     * THE OTHER WAY TO HAVE NO USABLE HISTORY, and the reason had been announcing the wrong one.
     * `historyAvailableFrom` set to the 1st itself is history that exists and does not reach back
     * past the horizon — a first-ever draft, or a freshly provisioned instance. The skip is right;
     * the sentence saying *"historyAvailableFrom is null"* about a field the caller can see is not
     * null was not, and a coverage row a reader can catch out is one they stop reading.
     */
    it.each(seamCases.map((fixture) => fixture.name))('%s names the reason when history starts too late', (name) => {
        const fixture = seamCases.find((candidate) => candidate.name === name) as Fixture;
        const context: EvaluationContext = {
            ...fixture.context,
            historyAvailableFrom: fixture.schedule.horizon.from,
            priorDuties: [],
        };

        for (const row of coverage(fixture.schedule, context, fixture.conditions)) {
            expect(row.skipped).toHaveLength(1);
            expect(row.skipped[0]?.reason).toMatch(/begins at 2026-08-01, which is not before 2026-08-01/);
            expect(row.skipped[0]?.reason).not.toMatch(/is null/);
            expect(row.skipped[0]?.to).toBe(d('2026-07-31'));
        }
    });

    it('runs over the carry-in cases, named', () => {
        expect(seamCases.map((fixture) => fixture.name)).toEqual([
            'call-frequency-max-the-window-that-begins-in-the-published-month',
            'composition-the-period-that-begins-in-the-published-month',
            'consecutive-max-the-run-spans-the-thirty-first-into-the-first',
            'count-max-the-week-that-begins-in-the-published-month',
            'count-min-the-floor-counts-the-week-that-begins-in-the-published-month',
            'free-day-min-the-window-that-begins-in-the-published-month',
            'max-gap-the-gap-that-begins-in-the-published-month',
            'min-gap-hours-across-the-carry-in-from-the-published-month',
            'overlap-block-carry-in-at-the-left-edge',
            'post-duty-exclusion-the-window-opens-on-the-thirty-first-and-closes-on-the-first',
            'rolling-hours-max-the-window-that-begins-in-the-published-month',
            'target-per-period-the-period-that-begins-in-the-published-month',
            'we-pairing-the-weekend-that-straddles-the-month-boundary-is-one-weekend',
        ]);
    });
});

describe('the two duty-hours caps of Task 18, and the line between them', () => {
    const world = FIXTURES.find(
        (f) => f.name === 'call-frequency-max-a-window-it-can-only-see-part-of-is-left-unjudged',
    ) as Fixture;

    const row = (typeKey: string, params: Record<string, unknown>): Condition => ({
        id: 'c-probe',
        typeKey,
        class: 'soft',
        rank: 3,
        active: true,
        params,
    });

    /**
     * OWNER DECISION L'S DIVIDING LINE IS NOT CAP-VERSUS-FLOOR, AND THIS IS WHERE THAT SHOWS.
     *
     * The decision lets a cap evaluate a window the engine can only see part of, on the stated
     * ground that *"a count that is too low never exceeds a limit"* — which is true only when the
     * limit was AUTHORED. `rolling_hours_max`'s is: a department wrote 12 h down. `call_frequency_max`'s
     * is `floor(availableDays / n)`, computed from the window's own contents, so a partial window
     * loses eligible days as fast as it loses calls and the allowance falls with the count.
     *
     * Both are `direction: 'cap'` in the registry and both are asserted HERE, on ONE world, because
     * a fixture apiece would let the two behaviours drift into looking like two unrelated choices.
     * Five windows; the hours cap measures all five, the frequency cap measures the one whole window
     * and reports the other four.
     */
    it('the hours cap measures a partial window and the frequency cap declines it', () => {
        const hours = coverage(world.schedule, world.context, [
            row('rolling_hours_max', { hours: 12, windowDays: 3 }),
        ]);
        const frequency = coverage(world.schedule, world.context, [
            row('call_frequency_max', { n: 2, windowDays: 3 }),
        ]);

        expect(hours[0]?.evaluatedWindows).toBe(5);
        expect(hours[0]?.skipped).toEqual([]);
        expect(frequency[0]?.evaluatedWindows).toBe(1);
        expect(frequency[0]?.skipped).toHaveLength(4);
    });

    /**
     * THE FALSE POSITIVE THE GATE PREVENTS, MEASURED RATHER THAN ASSERTED IN PROSE. The window
     * 30 Jul – 1 Aug reaches two days outside the evaluable range: one call is visible in it and one
     * eligible day, so the allowance computes to zero and the call is "over" — on a person who took
     * two calls in three days they were available for, which is exactly at the rule. Reaching that
     * verdict needs the gate removed, so it is reached by evaluating the window directly.
     */
    it('would have reported the partial window as a breach, which is why it is declined', () => {
        const widened: Schedule = {
            ...world.schedule,
            horizon: { ...world.schedule.horizon, from: d('2026-07-30'), evaluableFrom: d('2026-07-30') },
        };

        const wide = evaluate(widened, world.context, [row('call_frequency_max', { n: 2, windowDays: 3 })]);

        expect(wide.map((violation) => (violation.location as { from: Ymd }).from)).toContain(d('2026-07-30'));
        expect(evaluate(world.schedule, world.context, world.conditions)).toHaveLength(1);
    });

    /**
     * Owner decision L's per-PERSON half is deliberately NOT applied by `call_frequency_max`, and
     * the two readings are one line apart. A floor suppresses somebody who joined mid-window because
     * an absolute number they could not have reached is a false positive. This rule's number is not
     * absolute: the days before they joined are already out of the denominator, so the allowance has
     * moved with them. Suppressing anyway would delete the rule for every new starter's first window
     * — which is when a department is likeliest to over-call them — and would show up as a coverage
     * row instead of a violation.
     */
    it('judges a person who joined part way through the window rather than naming them in coverage', () => {
        const joined = withPeople(world.context, (copy) => {
            for (const person of copy.people) {
                person.joinedAt = d('2026-08-01');
            }
        });

        const conditions = [row('call_frequency_max', { n: 2, windowDays: 3 })];

        expect(evaluate(world.schedule, joined, conditions)).toHaveLength(1);
        expect(coverage(world.schedule, joined, conditions)[0]?.skipped).toHaveLength(4);
    });
});

describe('the two cohort-located types of Task 19, beyond the corpus', () => {
    const fairWorld = FIXTURES.find(
        (f) => f.name === 'fairness-distribution-external-people-are-left-out-when-the-rule-says-so',
    ) as Fixture;

    const holidayWorld = FIXTURES.find(
        (f) => f.name === 'holiday-equity-a-carried-credit-of-zero-is-what-an-unrecorded-one-counts-as',
    ) as Fixture;

    const fairness = (params: Record<string, unknown>, scope?: Condition['scope']): Condition => ({
        id: 'c-fair',
        typeKey: 'fairness_distribution',
        class: 'soft',
        rank: 3,
        active: true,
        params,
        ...(scope === undefined ? {} : { scope }),
    });

    const DEVIATION = { quantity: 'nights', mode: 'deviation', excludeExternal: true };

    /**
     * `excludeExternal` MOVES THE DENOMINATOR, so the flip is not a longer list of violations but a
     * DIFFERENT one — the fixture can only assert one side of that, and a parameter asserted where
     * flipping it merely adds a row is the shape of a filter nobody would notice going missing.
     * Counting the external person in spreads six nights over thirty available days instead of
     * twenty, which drops the expected share from three to two: p-ali becomes clean and p-ext,
     * holding nothing, becomes a violation in their place.
     */
    it('counting external people in changes who is flagged, not merely how many', () => {
        const excluded = evaluate(fairWorld.schedule, fairWorld.context, [fairness(DEVIATION)]);
        const included = evaluate(fairWorld.schedule, fairWorld.context, [
            fairness({ ...DEVIATION, excludeExternal: false }),
        ]);

        const named = (violations: typeof excluded): string[] =>
            violations.flatMap((violation) => (violation.location as { personKeys: string[] }).personKeys).sort();

        expect(named(excluded)).toEqual(['p-ali', 'p-noor']);
        expect(named(included)).toEqual(['p-ext', 'p-noor']);
    });

    /**
     * THE TWO MODES MAY NOT CONTRADICT EACH OTHER, and this is the property that makes `spread`'s
     * derived allowance load-bearing rather than tidy. Spread's threshold is the sum of the two
     * extremes' OWN tolerances — the widest gap deviation mode would have permitted between them —
     * so a schedule clean under `deviation` is clean under `spread` by construction. A threshold of
     * its own would let one mode of one rule call a draft fair while the other calls it unfair,
     * with nothing on either screen able to adjudicate.
     *
     * Asserted over every fairness world in the corpus rather than over one, because the property
     * is about the arithmetic and not about any particular roster.
     */
    it('never reports a spread violation on a schedule deviation mode calls clean', () => {
        const worlds = FIXTURES.filter((fixture) => fixture.name.startsWith('fairness-distribution-'));

        const disagreements = worlds.filter((world) => {
            const scope = world.conditions[0]?.scope;
            const params = (world.conditions[0] as Condition).params as Record<string, unknown>;
            const clean = evaluate(world.schedule, world.context, [
                fairness({ ...params, mode: 'deviation' }, scope),
            ]);
            const spread = evaluate(world.schedule, world.context, [
                fairness({ ...params, mode: 'spread' }, scope),
            ]);

            return clean.length === 0 && spread.length > 0;
        });

        expect(disagreements.map((world) => world.name)).toEqual([]);
        expect(worlds.length).toBeGreaterThanOrEqual(6);
    });

    /**
     * A COHORT LOCATION CARRIES NO DATE, so `evaluate()`'s emission rule is unconditionally true
     * for one and CG-03 has to be kept by the TYPE. Found by a plant that stayed green: deleting
     * the horizon filter from the counted duties changed nothing anywhere, because every case's
     * duties already sat inside their own horizon and no fixture could express otherwise without
     * being confusing corpus data.
     *
     * A duty dated in the already-published month must not move the cohort's total, its
     * denominator or anybody's expected share — and the check is that the whole answer is
     * BYTE-IDENTICAL rather than merely the same length, because the arithmetic is what shifts:
     * counting it would take the total from six to seven and every printed share with it.
     */
    it('counts no duty dated outside the horizon, however it arrived in the schedule', () => {
        const withTail: Schedule = {
            ...fairWorld.schedule,
            duties: [
                { personKey: 'p-ali', date: d('2026-07-31'), slotKey: 'night' },
                ...fairWorld.schedule.duties,
            ],
        };

        expect(evaluate(withTail, fairWorld.context, [fairness(DEVIATION)])).toEqual(
            evaluate(fairWorld.schedule, fairWorld.context, [fairness(DEVIATION)]),
        );
    });

    /**
     * SPREAD'S BOUNDARY, EITHER SIDE OF IT, because the corpus case sits well past it and a
     * threshold asserted only where it is exceeded is a threshold that could be any number below
     * the one it is.
     *
     * Four nights spread 3–1 gives an expected share of two each, deviations of ±1, a gap of 2 and
     * an allowance of `tolerance(2) + tolerance(2)` = 2 — clean at exactly the limit. One more
     * night for the same person makes the expected share 2.5, the deviations ±1.5 and the gap 3
     * against the same allowance, which is over. The pair is what makes the sum-of-two-tolerances
     * derivation falsifiable rather than merely stated.
     */
    it('permits a gap of exactly the allowance and refuses the next duty past it', () => {
        const spread = { quantity: 'nights', mode: 'spread', excludeExternal: true };
        const nights = (count: number): Schedule => ({
            ...fairWorld.schedule,
            duties: [
                { personKey: 'p-ali', date: d('2026-08-01'), slotKey: 'night' },
                ...Array.from({ length: count }, (_unused, index) => ({
                    personKey: 'p-noor',
                    date: d(`2026-08-0${index + 2}`),
                    slotKey: 'night',
                })),
            ],
        });

        expect(evaluate(nights(3), fairWorld.context, [fairness(spread)])).toEqual([]);
        expect(evaluate(nights(4), fairWorld.context, [fairness(spread)])).toHaveLength(1);
    });

    /**
     * Owner decision W, ANSWERED, stated in words a reader can grep for rather than only encoded in
     * a fixture's arithmetic. An explicit `null` and an omitted key are the SAME answer, and both
     * are zero. The superseded default held a `null` person out of the comparison, which is what
     * made the lookback silently do nothing in year one — so the check is that the flagged set does
     * not move when the spelling does.
     */
    it('reads an explicit null carried credit exactly as it reads an absent one', () => {
        const spelled = withPeople(holidayWorld.context, (copy) => {
            for (const person of copy.people) {
                if (person.priorCredits?.['eid-al-fitr'] === null) {
                    delete person.priorCredits;
                }
            }
        });

        expect(evaluate(holidayWorld.schedule, spelled, holidayWorld.conditions)).toEqual(
            evaluate(holidayWorld.schedule, holidayWorld.context, holidayWorld.conditions),
        );

        expect(carriedCredits(holidayWorld.context.people[2] as Person, 'eid-al-fitr')).toBe(0);
        expect(carriedCredits(holidayWorld.context.people[0] as Person, 'eid-al-fitr')).toBe(0);
        expect(carriedCredits(holidayWorld.context.people[1] as Person, 'eid-al-fitr')).toBe(2);
    });

    /**
     * A rule naming no holiday spreads nothing, which on a gate screen is a control that appears to
     * do nothing. The schema refuses it with its own error rather than admitting a condition whose
     * every evaluation is silence.
     */
    it('refuses a holiday_equity row that names no holiday at all', () => {
        const empty: Condition = {
            ...(holidayWorld.conditions[0] as Condition),
            params: { holidays: [], lookbackYears: 0 },
        };

        expect(() => evaluate(holidayWorld.schedule, holidayWorld.context, [empty])).toThrow(
            /fewer than the 1 required/,
        );
    });

    /**
     * A cohort violation has no date and no slot, so the POPULATION is most of its meaning: the same
     * sentence against the whole department and against the four R1s on PICU are different claims,
     * and the badge carries nothing else able to tell them apart. It comes from the message table
     * like every other sentence (AR-07), and it narrows with the scope rather than being a constant.
     */
    it('labels the population a cohort violation compared somebody against', () => {
        const scoped = evaluate(fairWorld.schedule, fairWorld.context, [
            fairness(DEVIATION, { unitKeys: ['PICU'], levelKeys: ['R1'] }),
        ]);
        const unscoped = evaluate(fairWorld.schedule, fairWorld.context, [fairness(DEVIATION)]);

        const label = (violations: typeof scoped): string | undefined =>
            (violations[0]?.location as { scopeLabel: string } | undefined)?.scopeLabel;

        expect(label(unscoped)).toBe('everybody in this schedule');
        expect(label(scoped)).toBe('people at R1 and rotating on PICU');
    });
});

describe('we_pairing — owner decision Z, and the half of it that does not ship', () => {
    const world = FIXTURES.find(
        (f) => f.name === 'we-pairing-a-weekend-split-between-two-people-is-not-the-preferred-pairing',
    ) as Fixture;

    /**
     * `fallbacks` IS ABSENT AND THE ABSENCE IS ASSERTED, not merely omitted. Owner decision Z keeps
     * it out of P2 on the ground that an ordered list of acceptable alternatives produces no
     * violation when one is used — it produces a worse-but-acceptable placement, which is WB-04
     * fitness and AU-02's rank-weighted penalty terms, exactly the split decision P already makes
     * for `eligibility`'s auto-fill order.
     *
     * A department that writes the parameter must LEARN that this engine will not honour it. A
     * silently ignored key is a control that appears to do nothing, and here what appears to do
     * nothing is the second choice somebody believed the rule would fall back on.
     */
    it('refuses a condition row that carries a fallbacks list', () => {
        expect(() =>
            evaluate(world.schedule, world.context, [
                {
                    ...(world.conditions[0] as Condition),
                    params: { preferredPairs: [{ first: 5, second: 6 }], fallbacks: [{ first: 6, second: 7 }] },
                },
            ]),
        ).toThrow(/unknown property "fallbacks"/);
    });

    /**
     * A DOCBLOCK IS SCANNED SOURCE — the ninth occurrence in this phase, and `we_pairing.ts`'s own
     * prose explains at length why `fallbacks` is not here. So the scan strips comments, exactly as
     * `eligibility.ts`'s absence scan does, and is pinned in BOTH directions for that file's
     * recorded reason: eating the code would make every needle miss, which looks identical to a
     * clean tree.
     */
    it('names no fallback vocabulary in its CODE, docblocks stripped', () => {
        const path = join(import.meta.dirname, '..', 'src', 'conditions', 'we_pairing.ts');
        const raw = readFileSync(path, 'utf8');
        const code = withoutComments(raw);

        expect(raw, 'the docblock should still explain the absence').toContain('fallbacks');
        expect(code, 'the stripper ate the code, not just the prose').toContain('export const evaluate');

        for (const needle of ['fallbacks', 'fallback', 'alternative']) {
            expect(code, `we_pairing.ts names "${needle}" in code`).not.toContain(needle);
        }
    });

    /**
     * A pair is of DAYS, so both ends are ISO INTEGERS and a day NAME is refused with the schema's
     * own error rather than quietly matching nothing — `dow_restriction`'s rule, one type along, and
     * for its reason: there is no name-to-number table in this package and there deliberately never
     * will be one.
     *
     * THE NAME IS ASSEMBLED RATHER THAN WRITTEN, for the reason `dow_restriction`'s own test
     * records: `CalendarIsTheOnlyConverterTest`'s quoted-weekday pattern scans this file too, so a
     * test proving a weekday name is refused cannot itself contain one.
     */
    it('refuses a weekday name and a number outside 1..7 at either end of a pair', () => {
        const pairing = (pair: unknown): Condition => ({
            ...(world.conditions[0] as Condition),
            params: { preferredPairs: [pair] },
        });

        const name = ['Fri', 'day'].join('');

        expect(() => evaluate(world.schedule, world.context, [pairing({ first: name, second: 6 })])).toThrow(
            /expected integer/,
        );
        expect(() => evaluate(world.schedule, world.context, [pairing({ first: 5, second: 8 })])).toThrow(
            /above the maximum 7/,
        );
        expect(() => evaluate(world.schedule, world.context, [pairing({ first: 5 })])).toThrow(/second/);
        expect(() =>
            evaluate(world.schedule, world.context, [
                { ...(world.conditions[0] as Condition), params: { preferredPairs: [] } },
            ]),
        ).toThrow(/fewer than the 1 required/);
    });
});

describe('the eleven placement types are registered as implemented, with a preview and a schema', () => {
    it.each([
        'overlap_block',
        'vacation_block',
        'eligibility',
        'unwanted_day_block',
        'onboarding_grace',
        'dow_restriction',
        'clinic_conflict',
        'same_unit_conflict',
        'min_gap',
        'post_duty_exclusion',
        'consecutive_max',
    ])('%s', (typeKey) => {
        const entry = registryEntry(typeKey);

        expect(entry?.implemented).toBe(true);
        expect(entry?.evaluate).toBeDefined();
        expect(entry?.preview).toBeDefined();
        expect(entry?.paramsSchema).toBeDefined();
        expect(entry?.locationKind).toBe('placement');
    });
});

describe('no condition module assembles a sentence of its own', () => {
    /**
     * The SOURCE half of P2-2 Task 1, and it exists because the behavioural half cannot reach
     * everything.
     *
     * `messages.test.ts` hands `evaluate()` a second table and watches all eleven types' sentences
     * change — but it can only see the sentence shapes the CORPUS produces, and several types carry
     * a second shape no case reaches (`min_gap`'s overlapping pair, `consecutive_max` under a unit
     * the fixture does not use). A type that routed one branch through the table and kept a literal
     * in the other would be green there. This scan sees every site.
     *
     * ## What it is and what it deliberately is not
     *
     * It requires every `explanation:` and every `reason:` in a condition module to be an expression
     * beginning `messages.`. That reaches the shape a hardcoded sentence actually takes — the eleven
     * this task migrated were every one of them a literal or a ternary of literals at the property.
     *
     * **STATED RESIDUAL:** a module that called a local helper which itself built English would pass.
     * That is not bought, and the reason is measurement rather than optimism: the needle for it would
     * have to be *"no string literal longer than N appears in this directory"*, which matches the
     * schema `description` on every parameter of every type — text the gate screen legitimately
     * renders and which is already the table's problem to translate one layer along. A needle that
     * fires on nine files it must then allow-list blinds the guard exactly where a real offender is
     * born, which is ruling 42's whole finding.
     *
     * ## A docblock is scanned source, for the eighth time this phase, so comments are stripped
     *
     * Every one of these modules explains its explanations in prose — `onboarding_grace.ts` writes
     * *"the two shapes carry different explanations"* — and a guard that fails the build on the
     * documentation of its own rule teaches people to delete the documentation. `withoutComments()`
     * is the same stripper `eligibility.ts`'s absence scan uses, and it is pinned in both directions
     * below for the same recorded reason: eating code is a silent false negative that looks green.
     */
    const CONDITION_DIR = join(import.meta.dirname, '..', 'src', 'conditions');

    /** Every `explanation:`/`reason:` in this source whose value does not begin `messages.`. */
    function sentencesNotFromTheTable(source: string): string[] {
        return [...withoutComments(source).matchAll(/(?:explanation|reason):\s*(?!messages\.)(\S[^\n]*)/g)].map(
            (match) => (match[1] as string).trim(),
        );
    }

    const modules = readdirSync(CONDITION_DIR)
        .filter((name) => name.endsWith('.ts'))
        .sort();

    it('routes every explanation and every coverage reason through the message table', () => {
        const offenders = modules.flatMap((name) =>
            sentencesNotFromTheTable(readFileSync(join(CONDITION_DIR, name), 'utf8')).map(
                (site) => `${name}: ${site}`,
            ),
        );

        expect(offenders).toEqual([]);
    });

    /**
     * The two floors under it. A scan pointed at a moved directory iterates nothing and passes, and
     * a stripper that ate the code makes every needle miss — both look exactly like a clean tree.
     */
    it('scanned the modules it claims to, with the code still in them', () => {
        expect(modules).toContain('support.ts');
        expect(modules.length).toBeGreaterThanOrEqual(12);

        const stripped = modules.map((name) => withoutComments(readFileSync(join(CONDITION_DIR, name), 'utf8')));

        expect(stripped.filter((code) => code.includes('messages.')).length).toBeGreaterThanOrEqual(11);
        expect(stripped.join('\n')).toContain('export const evaluate');
        expect(stripped.join('\n')).not.toContain('CG-04');
    });

    /**
     * PLANTED, permanently, in both shapes this task removed: a bare literal and a ternary over two
     * of them. Green over the shipped modules is only meaningful if the same function is red over a
     * module that hardcodes, and the ternary is there because ten of the eleven migrated sites were
     * written as one and a needle anchored on a quote would have missed every branch but the first.
     */
    it('bites on a literal explanation and on a ternary of them', () => {
        expect(
            sentencesNotFromTheTable(
                [
                    "findings.push({ location, explanation: `On leave on ${duty.date}.` });",
                    'findings.push({ location, explanation: sameDay ? `A.` : `B.` });',
                    'skipped.push({ from, to, reason: messages.carryInSkip({ horizonFrom }) });',
                ].join('\n'),
            ),
        ).toEqual(['`On leave on ${duty.date}.` });', 'sameDay ? `A.` : `B.` });']);
    });
});
