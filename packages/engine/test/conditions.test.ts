import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { addDays, compareYmd, isoWeekday, parseYmd, type Ymd } from '../src/calendar/ymd';
import { carriedCredits } from '../src/conditions/holiday_equity';
import { measuredGap } from '../src/conditions/max_gap';
import { candidateStarts } from '../src/conditions/we_pairing';
import type { Condition, EvaluationContext, Fixture, Person, Schedule } from '../src/contract/types';
import { validate } from '../src/contract/validate';
import { coverage } from '../src/coverage';
import { evaluate, locationIsReportable, sortViolations } from '../src/evaluate';
import { windowTouchesHorizon } from '../src/duty/windows';
import { preview } from '../src/preview';
import { CATALOG, registryEntry } from '../src/registry';
import { withoutComments } from './support/source';

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
            'call-frequency-max-availability-for-the-horizon-alone-fires-on-a-clean-month',
            'call-frequency-max-availability-for-the-whole-evaluable-range-is-clean',
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
            'we-pairing-the-weekend-that-runs-into-the-month-after-the-horizon',
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

    /**
     * A WINDOW-located type has to be told the same thing, IN ALL THREE STREAMS, and the two tail
     * ones were unasserted: narrowing `rosterFor` to the schedule's own duties left the whole suite
     * green, because no case in the corpus puts a stranger in `priorDuties` or `followingDuties`.
     *
     * `rosterFor` exists at all because a window type iterates PEOPLE — a floor's whole purpose is
     * the person who holds nothing — so `personIndex().get()` is never reached by the ordinary path
     * and the stranger check every placement type gets for free would simply not happen. The tail
     * is where a stranger is likeliest to arrive, which is why the unasserted half matters more
     * than the asserted one: `ContextBuilder` reads the published months either side of the horizon
     * and unions in anybody still holding a rotation, precisely because a departed colleague can
     * still be named in last month's rota.
     */
    const windowWorld = FIXTURES.find(
        (fixture) => fixture.name === 'count-min-a-person-who-joined-part-way-through-the-window',
    ) as Fixture;

    const strangerIn = (stream: 'priorDuties' | 'followingDuties', date: string): EvaluationContext =>
        withPeople(windowWorld.context, (copy) => {
            copy[stream] = [...copy[stream], { personKey: 'p-ghost', date: d(date), slotKey: 'night' }];
        });

    it('throws for a stranger in the carry-in tail on either side', () => {
        expect(() => evaluate(windowWorld.schedule, windowWorld.context, windowWorld.conditions)).not.toThrow();

        expect(() =>
            evaluate(windowWorld.schedule, strangerIn('priorDuties', '2026-07-28'), windowWorld.conditions),
        ).toThrow(/p-ghost/);

        expect(() =>
            evaluate(windowWorld.schedule, strangerIn('followingDuties', '2026-08-18'), windowWorld.conditions),
        ).toThrow(/p-ghost/);
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
     * THE ENUMERATION IS THE EMISSION RULE, and this is what stops the derivation becoming two
     * definitions of one fact.
     *
     * A cohort location has no date, so `evaluate()`'s rule is unconditionally true for one and
     * CG-03 has to be kept by the type. `we_pairing.ts` did that with an explicit
     * `windowTouchesHorizon` check inside its scan, and a plant proved it DEAD: for a two-day pair,
     * the predicate holds for every start in `[from - 1, to]`, which is precisely the range
     * `candidateStarts` returns. A branch that cannot be taken is a control that appears to do
     * something, so it is gone — and this property is what keeps its reason true. Both directions:
     * every candidate touches, and the date one earlier does not.
     */
    it('enumerates exactly the pair starts the emission rule would have admitted', () => {
        const horizon = world.schedule.horizon;
        const starts = candidateStarts(horizon);

        expect(
            starts.filter((start) => !windowTouchesHorizon(start, addDays(start, 1), horizon)),
        ).toEqual([]);
        expect(starts[0]).toBe(addDays(horizon.from, -1));
        expect(windowTouchesHorizon(addDays(horizon.from, -2), addDays(horizon.from, -1), horizon)).toBe(false);
        expect(windowTouchesHorizon(addDays(horizon.to, 1), addDays(horizon.to, 2), horizon)).toBe(false);
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

        // Assembled, and split at a point the vocabulary scan does not needle: a three-letter
        // abbreviation is itself a needle, so the obvious split reintroduces the offence this
        // case exists to prove is refused.
        const name = ['F', 'riday'].join('');

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

describe('five smaller readings the P2-2 review found unasserted', () => {
    /**
     * 1. A COVERAGE ROW NEVER ENDS BEFORE IT STARTS, and `carryInLeftEdge`'s guard against that was
     * unasserted: deleting it left the suite green, because every carry-in case in the corpus has a
     * real tail and the guard only bites when there is none. A schedule with `evaluableFrom` equal
     * to `horizon.from` — the first draft of a fresh instance, and what `ContextBuilder` produces
     * when asked for exactly one month — makes the reported window run from the 1st back to the
     * 31st. A range a reader cannot parse is a row they stop reading.
     *
     * Asserted as a property over the whole corpus AND on the no-tail world, because the corpus
     * cannot reach the state and the property alone would be vacuous for it.
     */
    it('reports no coverage window that ends before it starts', () => {
        const backwards = FIXTURES.flatMap((fixture) =>
            coverage(fixture.schedule, fixture.context, fixture.conditions).flatMap((row) =>
                row.skipped.filter((skip) => compareYmd(skip.from, skip.to) > 0),
            ),
        );

        expect(backwards).toEqual([]);
    });

    it('reports no carry-in window at all when the horizon has no tail', () => {
        const world = FIXTURES.find(
            (fixture) => fixture.name === 'count-min-the-floor-counts-the-week-that-begins-in-the-published-month',
        ) as Fixture;

        const schedule: Schedule = {
            ...world.schedule,
            horizon: { ...world.schedule.horizon, evaluableFrom: world.schedule.horizon.from },
        };

        const context = withPeople(world.context, (copy) => {
            copy.historyAvailableFrom = null;
        });

        const rows = coverage(schedule, context, world.conditions)[0]?.skipped ?? [];

        expect(rows.filter((row) => compareYmd(row.from, row.to) > 0)).toEqual([]);
        expect(rows.filter((row) => row.reason.includes('duty history'))).toEqual([]);
    });

    /**
     * 2. A CREDIT IS KEYED ON THE HOLIDAY AND ITS YEAR (owner decision W), and dropping the year
     * left the suite green: no corpus case reaches two occurrences of one holiday, because a
     * month-long horizon cannot hold two and every case is a month. The year is what makes a
     * multi-day holiday one credit AND two years of it two, and only the first half was fixtured.
     *
     * The day vector here is deliberately SPARSE — the fixture's own August, plus one day a Hijri
     * year later. Nothing in this type reads a date it was not given, and a contiguous year of day
     * rows would be four hundred lines of fixture asserting the same one fact.
     */
    it('counts two occurrences of one holiday as two credits, not one', () => {
        const world = FIXTURES.find(
            (fixture) =>
                fixture.name === 'holiday-equity-working-any-part-of-a-holiday-is-one-credit-for-that-holiday-year',
        ) as Fixture;

        const nextEid = d('2027-07-24');

        const context = withPeople(world.context, (copy) => {
            copy.days = [
                ...copy.days,
                {
                    date: nextEid,
                    isoWeekday: 6,
                    dayType: 'HOL',
                    periodKey: null,
                    holidays: [{ key: 'eid-al-fitr', year: 1448 }],
                },
            ];
        });

        const schedule: Schedule = {
            horizon: { ...world.schedule.horizon, to: nextEid, evaluableTo: nextEid },
            duties: [...world.schedule.duties, { personKey: 'p-noor', date: nextEid, slotKey: 'night' }],
        };

        // p-ali holds eid 1447 and national day; p-noor now holds eid 1447 AND eid 1448. Both are
        // two credits clear of p-zaid's none. Keyed on the holiday alone, p-noor's two eids collapse
        // into one and they drop out of the finding entirely.
        const found = evaluate(schedule, context, world.conditions);

        expect(found).toHaveLength(1);
        expect((found[0]?.location as { personKeys: string[] }).personKeys).toEqual(['p-ali', 'p-noor']);
    });

    /**
     * 3. `we_pairing` DE-DUPLICATES the people holding a slot on a day, and dropping the de-dup left
     * the suite green because no case gives one person two duties in one slot on one date. That is
     * a schedule `overlap_block` would refuse, but conditions are independent and a department may
     * run one without the other — and with a duplicated row the two days' holder lists compare
     * unequal (`["p-ali", "p-ali"]` against `["p-ali"]`) and the type reports a split between a
     * person and themselves.
     */
    it('does not report a weekend split between one person and themselves', () => {
        const world = FIXTURES.find(
            (fixture) => fixture.name === 'we-pairing-an-adjacency-the-rule-does-not-name-is-not-a-weekend',
        ) as Fixture;

        // p-ali covers the WHOLE weekend, which is the arrangement this rule prefers and reports
        // nothing about. Their Friday row is then written twice.
        const friday = { personKey: 'p-ali', date: d('2026-08-07'), slotKey: 'night' };
        const whole: Schedule = {
            ...world.schedule,
            duties: [friday, { personKey: 'p-ali', date: d('2026-08-08'), slotKey: 'night' }],
        };

        expect(evaluate(whole, world.context, world.conditions)).toEqual([]);
        expect(evaluate({ ...whole, duties: [friday, ...whole.duties] }, world.context, world.conditions)).toEqual(
            [],
        );
    });

    /**
     * 4. A GAP IS NOT A SPLIT IN EITHER DIRECTION. The corpus asserts the first day covered and the
     * second not; the mirror was unasserted, and it is the direction the enumeration does not
     * naturally reach — `slotKeys` is the union of both days precisely so the answer does not
     * depend on which day the scan started from.
     */
    it('is silent for a slot covered on the second day of a weekend and not the first', () => {
        const world = FIXTURES.find(
            (fixture) => fixture.name === 'we-pairing-a-weekend-with-only-one-of-its-days-covered-is-not-a-split',
        ) as Fixture;

        const secondDayOnly: Schedule = {
            ...world.schedule,
            duties: world.schedule.duties.filter((duty) => duty.date === d('2026-08-08')),
        };

        expect(evaluate(secondDayOnly, world.context, world.conditions)).toEqual([]);
    });

    /**
     * 5. `target_per_period` CALLS owner decision L's per-person half and nothing asserted it —
     * replacing `midWindowJoinSkip` with `null` left the suite green, because no case of this type
     * carries a join date at all. `count_min` has the fixture; the two types share the decision and
     * shared one case between them, which is Task 15-17's finding 2 in a different clause.
     */
    it('leaves a period unjudged for somebody who joined part way through it, and names them', () => {
        const world = FIXTURES.find(
            (fixture) => fixture.name === 'target-per-period-a-level-with-no-entry-in-the-map-has-no-target',
        ) as Fixture;

        const context = withPeople(world.context, (copy) => {
            for (const person of copy.people) {
                person.joinedAt = d('2026-08-10');
            }
        });

        expect(evaluate(world.schedule, context, world.conditions)).toEqual([]);

        // Two rows, not three: the world's third person rotates on NICU and the condition's scope
        // names PICU, so they never reach the per-person gate at all.
        expect(
            (coverage(world.schedule, context, world.conditions)[0]?.skipped ?? []).map((row) =>
                row.reason.includes('joined on 2026-08-10'),
            ),
        ).toEqual([true, true]);
    });
});

describe('composition, on a context whose day vector stops at the horizon', () => {
    /**
     * `Day` is documented as *"one date of the horizon"* and `ContextBuilder` builds the vector over
     * whatever range its caller asked for, so a context describing the horizon and no more is
     * ordinary rather than malformed. `composition`'s window is the PERIOD, which routinely opens
     * before the horizon does — that is what the seam case in the corpus is for — and it bucketed
     * every duty in that window through `days.get()`, which THROWS on a date the vector does not
     * describe.
     *
     * So the type crashed on a contract-valid context, and only on the shape it is most likely to
     * meet: a block that began in the already-published month. The corpus never saw it because its
     * seam case supplies day rows across the whole tail, which is generous rather than required.
     *
     * A crash is the wrong answer twice over. `dayIndex().get()` throws to stop a HARD rule passing
     * for want of data, and this is a target rather than a bar; the package already has the honest
     * answer for a legitimate reach past the vector — `find()` and a coverage row, which is what
     * `clinic_conflict` does for its post-duty window on the last date of a month.
     */
    const world = FIXTURES.find(
        (fixture) => fixture.name === 'composition-the-period-that-begins-in-the-published-month',
    ) as Fixture;

    /** The same world with the day vector trimmed to the horizon, which is all `Day` promises. */
    const horizonDaysOnly = (): EvaluationContext =>
        withPeople(world.context, (copy) => {
            copy.days = copy.days.filter(
                (day) =>
                    compareYmd(day.date, world.schedule.horizon.from) >= 0 &&
                    compareYmd(day.date, world.schedule.horizon.to) <= 0,
            );
        });

    it('reports the person whose duties it cannot bucket, rather than throwing', () => {
        const context = horizonDaysOnly();
        const rows = coverage(world.schedule, context, world.conditions)[0];

        // p-ali holds two duties on 27 and 28 July, inside the block and outside the vector. Their
        // period is unjudged and NAMED; nothing about p-noor, whose duties are all described.
        expect(rows?.skipped.map((row) => row.reason.includes('p-ali'))).toEqual([true]);
        expect(rows?.skipped[0]?.reason).toContain('2026-07-27');
        expect(rows?.skipped[0]?.from).toBe(d('2026-07-26'));

        expect(evaluate(world.schedule, context, world.conditions)).toEqual([]);
    });

    /**
     * And the vector reaching across the whole window still answers in full, so the row above is a
     * report of missing input rather than a rule that quietly stopped working. Same world, same
     * conditions, the corpus's own generous day vector: no skip at all.
     */
    it('says nothing when the vector reaches across the whole period', () => {
        expect(coverage(world.schedule, world.context, world.conditions)).toEqual(world.expectedCoverage);
    });
});

describe('a window whose left part no history reaches is never DROPPED, only reported', () => {
    /**
     * `wholeWindowVerdict` answers `{measure: false, skip: null}` for a window reaching back before
     * the horizon with no history behind it, on the stated ground that {@link carryInLeftEdge}'s
     * single row already speaks for every such window. That is true of the shape it was written
     * against — no history at all, or history starting at or after `horizon.from` — and FALSE of a
     * third: history that reaches back past the horizon but not as far as this window.
     *
     * In that third shape `carryInLeftEdge` is silent (it has seen real history before the 1st) and
     * the verdict is silent (it believes somebody else is speaking), so the window is measured by
     * nobody and reported by nobody. `evaluatedWindows` simply falls. That is the exact state
     * `coverage()` exists to prevent, and it is one row's difference from the state it already
     * reports correctly.
     *
     * The world is the corpus's own carry-in case with `historyAvailableFrom` moved forward two
     * days: block 13 opens on 26 July, the horizon on 1 August, and the caller can only speak for
     * 28 July onwards.
     */
    const world = FIXTURES.find(
        (fixture) => fixture.name === 'count-min-the-floor-counts-the-week-that-begins-in-the-published-month',
    ) as Fixture;

    const historyFrom = (date: string): EvaluationContext =>
        withPeople(world.context, (copy) => {
            copy.historyAvailableFrom = d(date);
        });

    it('reports the window the supplied history stops short of, rather than losing it', () => {
        const reaching = coverage(world.schedule, historyFrom('2026-07-01'), world.conditions)[0];
        const short = coverage(world.schedule, historyFrom('2026-07-28'), world.conditions)[0];

        // The healthy case measures both weeks of the block and has nothing to report.
        expect(reaching?.evaluatedWindows).toBe(2);
        expect(reaching?.skipped).toEqual([]);

        // The short one measures one, and the one it did not measure is NAMED. Its bounds are the
        // window's own, which is what tells a reader which week went and how much history would
        // have to be supplied to get it back.
        expect(short?.evaluatedWindows).toBe(1);
        expect(short?.skipped).toHaveLength(1);
        expect(short?.skipped[0]?.from).toBe(d('2026-07-26'));
        expect(short?.skipped[0]?.to).toBe(d('2026-08-01'));
        expect(short?.skipped[0]?.reason).toContain('2026-07-28');
    });

    /**
     * And the shape `carryInLeftEdge` DOES own still gets exactly one row for all of its windows,
     * rather than one apiece. The two are one branch apart and the whole point of the branch is
     * that the answers are reported differently — a window that is individually nameable versus a
     * fact that is identical for every window and would train a reader to skip the list.
     */
    it('leaves the no-history-at-all shape to carryInLeftEdge’s single row', () => {
        const rows = coverage(
            world.schedule,
            withPeople(world.context, (copy) => {
                copy.historyAvailableFrom = null;
            }),
            world.conditions,
        )[0];

        expect(rows?.skipped).toHaveLength(1);
        expect(rows?.skipped[0]?.reason).toContain('No duty history was supplied');
    });
});

describe('max_gap — the two edges of owner decision I, and who the rows are about', () => {
    const world = FIXTURES.find(
        (fixture) => fixture.name === 'max-gap-at-exactly-the-limit-and-a-day-beyond-it',
    ) as Fixture;

    /**
     * The trailing open gap is reported when the last counted duty falls at or BEFORE the last
     * horizon date, and `<=` relaxed to `<` left the suite green: no case put a duty on the very
     * last day of its own horizon, so the boundary was asserted everywhere except at itself.
     *
     * It is the one date where the answer is least obvious and most consequential. A duty on the
     * 31st has a gap after it that is exactly as unfinished as a duty on the 30th — nothing here
     * knows when the next one is — and dropping the row for it makes the rule silently complete on
     * the schedule's own edge, which is the edge a scheduler is drafting.
     */
    it('reports the open gap after a duty on the LAST horizon date', () => {
        const schedule: Schedule = {
            ...world.schedule,
            duties: [...world.schedule.duties, { personKey: 'p-ali', date: d('2026-08-15'), slotKey: 'day' }],
        };

        const trailing = (coverage(schedule, world.context, world.conditions)[0]?.skipped ?? []).filter(
            (row) => row.from === d('2026-08-15'),
        );

        expect(trailing).toHaveLength(1);
        expect(trailing[0]?.to).toBe(d('2026-08-15'));
        expect(trailing[0]?.reason).toContain('p-ali');
    });

    /**
     * The measured gap counts the days STRICTLY BETWEEN two duties, and the filter that says so was
     * unasserted: removing it left the suite green, because it changes the answer only when the
     * LATER duty's own date is a day the clock is stopped on — leave, or a date before the join
     * date. A duty on a leave day is `vacation_block`'s violation and is not this type's business,
     * but the two are independent conditions and a department may run one without the other.
     *
     * Called directly, because the quantity is the rule: a gap of thirteen days with nine stopped
     * days strictly inside it is four apart. Counting the closing date as a tenth makes it three,
     * and the difference is a whole day at exactly the limit.
     */
    it('measures the days strictly between two duties, never the closing one', () => {
        const person: Person = {
            key: 'p-probe',
            levelSpans: [],
            unitSpans: [],
            leaveDays: [
                d('2026-08-05'),
                d('2026-08-06'),
                d('2026-08-07'),
                d('2026-08-08'),
                d('2026-08-09'),
                d('2026-08-10'),
                d('2026-08-11'),
                d('2026-08-12'),
                d('2026-08-13'),
                d('2026-08-14'),
            ],
            unwantedDays: [],
            eligibleDays: [],
            external: false,
        };

        expect(measuredGap(person, d('2026-08-01'), d('2026-08-14'))).toEqual({ apart: 4, stopped: 9 });
    });
});

describe('three window-located readings that were asserted only where they MATCH', () => {
    /**
     * `composition` reads the LEVEL at the period start (owner decision M's unchanged half) and
     * nothing asserted the date. The sibling rule in `target_per_period` IS fixtured
     * (`target-per-period-the-level-is-read-at-the-period-start`), which is why this one looks
     * covered: two types, one decision, one case between them. Moving `composition`'s read to
     * `window.to` left the whole suite green.
     *
     * Both directions, because a promotion is only half the input. A person promoted INSIDE the
     * block is still judged against the level they started it at; a person promoted BEFORE it holds
     * a level with no entry in the map and is not judged at all. The second is what a date read at
     * either end would agree about, and it is here so the first is not carrying the case alone.
     */
    const mixWorld = FIXTURES.find(
        (fixture) => fixture.name === 'composition-a-holiday-that-falls-on-a-weekend-is-its-own-bucket',
    ) as Fixture;

    const promotedOn = (from: string): EvaluationContext =>
        withPeople(mixWorld.context, (copy) => {
            for (const person of copy.people) {
                if (person.key !== 'p-noor') {
                    continue;
                }

                person.levelSpans = [
                    { key: 'R1', from: d('2026-01-01'), to: addDays(d(from), -1) },
                    { key: 'R2', from: d(from), to: d('2026-12-31') },
                ];
            }
        });

    it('composition judges a mid-block promotion against the level the block STARTED at', () => {
        expect(evaluate(mixWorld.schedule, promotedOn('2026-08-09'), mixWorld.conditions)).toEqual(
            sortViolations(mixWorld.expected),
        );

        expect(evaluate(mixWorld.schedule, promotedOn('2026-08-02'), mixWorld.conditions)).toEqual([]);
    });

    /**
     * `onRosterThroughout`'s boundary is INCLUSIVE — somebody whose first day is the window's first
     * day had the whole window — and `<=` relaxed to `<` left the suite green, because no case in
     * the corpus put a join date exactly on a window bound. The reviewer's own copy of that
     * mutation was left in the tree and reverted; this is what would have caught it.
     *
     * It matters in one direction only, and that is the expensive one: a `<` reading suppresses a
     * floor for a person who genuinely had the window, so the rule goes quiet on somebody it should
     * judge and says so in a coverage row that reads like a considered decision.
     */
    const joinWorld = FIXTURES.find(
        (fixture) => fixture.name === 'count-min-a-person-who-joined-part-way-through-the-window',
    ) as Fixture;

    it('a join date ON the window’s first day is a whole window, not a partial one', () => {
        const context = withPeople(joinWorld.context, (copy) => {
            for (const person of copy.people) {
                if (person.key === 'p-noor') {
                    person.joinedAt = d('2026-08-02');
                }
            }
        });

        const found = evaluate(joinWorld.schedule, context, joinWorld.conditions);
        const rows = coverage(joinWorld.schedule, context, joinWorld.conditions);

        // p-noor holds one duty in the week beginning on the 2nd against a floor of two, so being
        // judged and being skipped are two visibly different answers rather than the same silence.
        expect(
            found.map((violation) => [
                (violation.location as { personKey: string }).personKey,
                (violation.location as { from: Ymd }).from,
            ]),
        ).toEqual([
            ['p-ali', d('2026-08-09')],
            ['p-noor', d('2026-08-02')],
        ]);

        expect(rows[0]?.skipped).toEqual([]);
    });

    /**
     * Owner decision N's vacation week is measured on a week's CLIPPED bounds, and swapping them
     * for the raw pair left the suite green — every week in every corpus case is unclipped, because
     * every block in the corpus starts on the department's own week start. That is a fixture
     * convenience, not a property of a real calendar: `institutions.block_weeks` gives block 13
     * five weeks and a year does not divide evenly, so a block edge lands mid-week eventually.
     *
     * Leave in the days a block does not own belongs to the NEIGHBOURING block's count. Here it
     * moves p-noor from one vacation week to two, which under the raw reading fires
     * `vacationWeeksAtLeast: 2` and replaces their target of four with two — exactly the number
     * they hold. The rule would go quiet on them, on leave the block never contained.
     */
    const targetWorld = FIXTURES.find(
        (fixture) =>
            fixture.name === 'target-per-period-a-modifier-replaces-the-target-and-a-vacation-week-is-any-overlap',
    ) as Fixture;

    it('counts a vacation week over the clipped bounds, not the department’s whole week', () => {
        const context = withPeople(targetWorld.context, (copy) => {
            const block = copy.periods[0] as (typeof copy.periods)[number];

            block.startsOn = d('2026-08-04');
            (block.weeks[0] as (typeof block.weeks)[number]).clippedStartsOn = d('2026-08-04');

            for (const person of copy.people) {
                if (person.key === 'p-noor') {
                    person.leaveDays = [d('2026-08-02'), d('2026-08-03'), ...person.leaveDays];
                }
            }
        });

        const found = evaluate(targetWorld.schedule, context, targetWorld.conditions);

        expect(found.map((violation) => (violation.location as { personKey: string }).personKey)).toEqual(['p-noor']);
    });
});

describe('periodWindows enumerates only the windows that TOUCH the horizon', () => {
    /**
     * The filter that says so is one line per branch and neither was asserted: replacing the period
     * branch's test with `true`, and deleting the week branch's `continue`, each left 571/571 green
     * and the corpus green. No case in the corpus carried a period or a week that misses the
     * horizon entirely, which is exactly the shape the filter exists for — a department's blocks
     * run all year and an evaluation asks about one month of them.
     *
     * What it costs when it goes is not a wrong violation: `evaluate()`'s emission rule drops a
     * window location that does not touch `[from, to]`, so the findings are identical either way.
     * It is `coverage()` that moves, and in the direction that reads as MORE work having been done
     * — a count of windows measured, whose results were then thrown away. A coverage number a
     * reader cannot act on is `carryInSkip`'s lesson one file along, and it is the half a
     * violations-only assertion is structurally unable to see.
     */
    const world = FIXTURES.find(
        (fixture) => fixture.name === 'count-max-the-window-parameter-picks-the-period-or-the-week',
    ) as Fixture;

    /**
     * The same world with a whole block on each side of the horizon, neither of them touching it —
     * and each one's edge week deliberately RAW-overlapping it while its CLIPPED bounds do not.
     *
     * That second half is bought for one line of fixture data and closes a residual the first half
     * cannot: measuring `windowTouchesHorizon` against `startsOn`/`endsOn` instead of the clipped
     * pair admits exactly these two weeks, and the raw bounds are a superset of the clipped ones,
     * so no world without a genuinely clipped edge week can tell the two spellings apart.
     */
    const withNeighbouringBlocks = (): EvaluationContext =>
        withPeople(world.context, (copy) => {
            copy.periods = [
                {
                    key: 'block-00',
                    startsOn: d('2026-07-27'),
                    endsOn: d('2026-08-01'),
                    weeks: [
                        {
                            startsOn: d('2026-07-27'),
                            endsOn: d('2026-08-02'),
                            clippedStartsOn: d('2026-07-27'),
                            clippedEndsOn: d('2026-08-01'),
                        },
                    ],
                },
                ...copy.periods,
                {
                    key: 'block-02',
                    startsOn: d('2026-08-16'),
                    endsOn: d('2026-08-21'),
                    weeks: [
                        {
                            startsOn: d('2026-08-15'),
                            endsOn: d('2026-08-21'),
                            clippedStartsOn: d('2026-08-16'),
                            clippedEndsOn: d('2026-08-21'),
                        },
                    ],
                },
            ];
        });

    it('counts neither the block before the horizon nor the block after it', () => {
        const context = withNeighbouringBlocks();

        expect(coverage(world.schedule, context, world.conditions)).toEqual(world.expectedCoverage);
        expect(evaluate(world.schedule, context, world.conditions)).toEqual(
            evaluate(world.schedule, world.context, world.conditions),
        );
    });

    /**
     * And the two branches are asserted APART. One check over a world carrying both a stray period
     * and a stray week passes while either branch alone is healthy, which is the pooled-check
     * mistake `contract.test.ts` had to unpick for `contributing` at Task 15. The period-windowed
     * row and the week-windowed row are two conditions in this world, so the two answers are
     * already separate — this names which is which so a failure says which branch went.
     */
    it('reports one period window and two week windows, whatever the neighbours look like', () => {
        const rows = coverage(world.schedule, withNeighbouringBlocks(), world.conditions);

        expect(rows.find((row) => row.conditionId === 'c-period')?.evaluatedWindows).toBe(1);
        expect(rows.find((row) => row.conditionId === 'c-week')?.evaluatedWindows).toBe(2);
    });
});

describe('the DATE a window- or cohort-located type resolves CG-01 scope at', () => {
    /**
     * WHEN the scope is read, which is one axis along from WHETHER it is read.
     *
     * P2-2's standing first plant — `personInScope` answering `true` — is now habitual and goes red
     * on every one of these types. It says nothing about the date handed to it. Moving `window.from`
     * to `window.to` at all nine sites left 571/571 green and the corpus green, because no case held
     * a person whose SCOPED fact moved inside a window: the level-FILTER half of the same question
     * is fixtured (`count-max-the-level-filter-is-read-at-the-window-start`,
     * `target-per-period-the-level-is-read-at-the-period-start`) and CG-01's SCOPE half was not — on
     * the very sites those two fixtures already cover for `levels`.
     *
     * It matters because the two filters answer different questions. `levels` is the type's own
     * parameter and a department reads it beside the number; `scope` is the gate screen's own column
     * and decides which rows apply to whom at all. A scope read at the wrong end of a block applies
     * a rule to a window the person spent most of outside it — silently, and only where somebody
     * rotates mid-block, which is to say only where it matters.
     *
     * ## TWO devices, and the second one is not decoration — it is what the first could not catch
     *
     * **`bounded`**, for a type whose windows all start at or before one nameable date: the last
     * window START for a period- or week-windowed type, and `horizon.from` for a cohort type, whose
     * window is the whole schedule. Two directions on one world:
     *
     *  - **Rotating only up to and including `readAt`** must leave the answer BYTE-IDENTICAL. Any
     *    reader later than that date finds nobody on PICU and the type goes quiet.
     *  - **Rotating only from the day AFTER it** must leave NO violation at all. This is the half
     *    the review's species names — a filter asserted where it MATCHES and never where it must
     *    not — since under any later reading somebody is back in scope and the rule fires again.
     *
     * **`rolling`**, for the three types whose windows are enumerated rather than supplied. The
     * bounded device was WRITTEN FOR THESE FIRST AND STAYED GREEN on all three: `readAt` there is
     * `horizon.to`, so the mutation's only surviving windows are the ones running past the horizon,
     * and none of the three fixtures happens to carry a violation in one. That is this review's own
     * species reappearing inside the case written to close it, so the probe is sharper here: the
     * rotation is clipped to ONE date that IS a violating window's start, and every violation must
     * then be located at a window starting on exactly that date. A reader `windowDays - 1` days out
     * lands on the window ENDING there instead, which is a different window with a different answer.
     *
     * Only `unitSpans` is moved. `levelSpans` is left alone deliberately, so a type that reads a
     * LEVEL for its own purposes — `composition` and `target_per_period` both do, at the period
     * start — measures the same thing before and after, and the only thing that moved is the date
     * the scope was asked about.
     */
    const SITES: {
        typeKey: string;
        fixture: string;
        probe: 'bounded' | 'rolling';
        readAt: string;
        window: string;
    }[] = [
        {
            typeKey: 'count_max',
            fixture: 'count-max-the-scope-and-the-levels-list-intersect',
            probe: 'bounded',
            readAt: '2026-08-02',
            window: 'its one week starts on the 2nd',
        },
        {
            typeKey: 'count_min',
            fixture: 'count-min-a-person-who-joined-part-way-through-the-window',
            probe: 'bounded',
            readAt: '2026-08-09',
            window: 'the later of its two weeks starts on the 9th',
        },
        {
            typeKey: 'target_per_period',
            fixture: 'target-per-period-a-level-with-no-entry-in-the-map-has-no-target',
            probe: 'bounded',
            readAt: '2026-08-02',
            window: 'its one period starts on the 2nd',
        },
        {
            typeKey: 'composition',
            fixture: 'composition-a-holiday-that-falls-on-a-weekend-is-its-own-bucket',
            probe: 'bounded',
            readAt: '2026-08-02',
            window: 'its one period starts on the 2nd',
        },
        {
            typeKey: 'free_day_min',
            fixture: 'free-day-min-a-twenty-four-hour-call-on-the-fifth-leaves-the-sixth-occupied',
            probe: 'rolling',
            readAt: '2026-08-05',
            window: 'the two-day window it fires on starts on the 5th',
        },
        {
            typeKey: 'rolling_hours_max',
            fixture: 'rolling-hours-max-the-scope-excludes-somebody-their-own-hours-would-flag',
            probe: 'rolling',
            readAt: '2026-08-01',
            window: 'the averaged two-day window it fires on starts on the 1st',
        },
        {
            typeKey: 'call_frequency_max',
            fixture: 'call-frequency-max-the-scope-excludes-somebody-their-own-calls-would-flag',
            probe: 'rolling',
            readAt: '2026-08-01',
            window: 'the two-day window it fires on starts on the 1st',
        },
        {
            typeKey: 'fairness_distribution',
            fixture: 'fairness-distribution-the-scope-excludes-somebody-their-own-share-would-flag',
            probe: 'bounded',
            readAt: '2026-08-01',
            window: 'a cohort window is the whole schedule, so it starts at the horizon',
        },
        {
            typeKey: 'holiday_equity',
            fixture: 'holiday-equity-the-scope-excludes-somebody-their-own-credits-would-flag',
            probe: 'bounded',
            readAt: '2026-08-01',
            window: 'a cohort window is the whole schedule, so it starts at the horizon',
        },
        {
            typeKey: 'we_pairing',
            fixture: 'we-pairing-the-scope-excludes-a-weekend-split-between-people-it-does-not-cover',
            probe: 'bounded',
            readAt: '2026-08-07',
            window: 'a cohort window is the whole schedule, so it starts at the horizon',
        },
    ];

    /** The fixture's own rows, with the unit half of the scope pinned so the device has a lever. */
    const scopedToPicu = (fixture: Fixture): Condition[] =>
        fixture.conditions.map((condition) => ({
            ...condition,
            scope: { ...(condition.scope ?? {}), unitKeys: ['PICU'] },
        }));

    const rotatingUntil = (context: EvaluationContext, through: Ymd): EvaluationContext =>
        withPeople(context, (copy) => {
            for (const person of copy.people) {
                person.unitSpans = person.unitSpans
                    .filter((span) => compareYmd(span.from, through) <= 0)
                    .map((span) => ({ ...span, to: compareYmd(span.to, through) <= 0 ? span.to : through }));
            }
        });

    const rotatingFrom = (context: EvaluationContext, notBefore: Ymd): EvaluationContext =>
        withPeople(context, (copy) => {
            for (const person of copy.people) {
                person.unitSpans = person.unitSpans
                    .filter((span) => compareYmd(span.to, notBefore) >= 0)
                    .map((span) => ({ ...span, from: compareYmd(span.from, notBefore) >= 0 ? span.from : notBefore }));
            }
        });

    /**
     * A NAMED list rather than a derived one, with a floor under it. Deriving the sites from the
     * registry would keep them in step with the catalog and would carry no `latestRead`, which is
     * the whole assertion — a derived list would have to guess the date and would then be asserting
     * its own guess. This is what fails when a tenth site is written and not listed.
     */
    it('covers every type that resolves the scope at a window rather than at a duty', () => {
        expect(SITES.map((site) => site.typeKey).sort()).toEqual([
            'call_frequency_max',
            'composition',
            'count_max',
            'count_min',
            'fairness_distribution',
            'free_day_min',
            'holiday_equity',
            'rolling_hours_max',
            'target_per_period',
            'we_pairing',
        ]);
    });

    for (const site of SITES) {
        const fixture = FIXTURES.find((entry) => entry.name === site.fixture) as Fixture;

        it(`${site.typeKey} reads it at ${site.readAt} — ${site.window}`, () => {
            const conditions = scopedToPicu(fixture);
            const asIs = evaluate(fixture.schedule, fixture.context, conditions);
            const readAt = d(site.readAt);

            // A world producing nothing makes every direction the same empty answer, which is the
            // shape this whole describe exists to refuse.
            expect(asIs.length, `${site.fixture} produces no violation under a PICU scope`).toBeGreaterThan(0);

            if (site.probe === 'bounded') {
                expect(evaluate(fixture.schedule, rotatingUntil(fixture.context, readAt), conditions)).toEqual(asIs);
                expect(
                    evaluate(fixture.schedule, rotatingFrom(fixture.context, addDays(readAt, 1)), conditions),
                ).toEqual([]);

                return;
            }

            const onlyThatDay = rotatingFrom(rotatingUntil(fixture.context, readAt), readAt);
            const found = evaluate(fixture.schedule, onlyThatDay, conditions);

            expect(found.length, 'rotating on the violating window’s own start day found nothing').toBeGreaterThan(
                0,
            );
            expect([...new Set(found.map((violation) => (violation.location as { from: Ymd }).from))]).toEqual([
                readAt,
            ]);
        });
    }
});
