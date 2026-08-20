import { describe, expect, it } from 'vitest';

import { datesBetween, isoWeekday, parseYmd, type Ymd } from '../src/calendar/ymd';
import type { Condition, Day, EvaluationContext } from '../src/contract/types';
import { CONTRACT_SCHEMA, type JsonSchema } from '../src/contract/schema';
import { ASSERTION_KEYWORDS, keywordsUsedBy } from '../src/contract/validate';
import { toleranceFor } from '../src/conditions/fairness_distribution';
import { clauseFor } from '../src/conditions/target_per_period';
import { UnimplementedConditionTypeError, UnknownConditionTypeError } from '../src/evaluate';
import { EN } from '../src/messages';
import { NoPreviewForConditionTypeError, preview, previewWith } from '../src/preview';
import { CATALOG, registryEntry, type RegistryEntry } from '../src/registry';

/**
 * CG-04's plain-language previews (P2 Task 9): *"preview text auto-generated from parameters"*.
 *
 * ## The property that has to hold for all twenty-two, and the one that has to hold for four
 *
 * The MATRIX below is the one that survives the whole catalog: for every registry entry carrying
 * both a params schema and a preview, changing any single parameter must change the sentence. It is
 * the `PickerParityTest` shape — every fixture × every field — and it is what makes *"a parameter
 * added without its preview fails the build"* true rather than aspirational. A containment check
 * ("the sentence names the value") was tried first and rejected: it cannot express a BOOLEAN, and
 * `excludeExternal` is exactly the parameter a preview is most likely to drop.
 *
 * The four sentences asserted verbatim are the four the plan and the answered owner decisions
 * settle wording for, and each is a number a reader will otherwise predict wrongly:
 *
 *  - `min_gap` in `days` — owner decision H's off-by-one, rendered as a worked example.
 *  - `rolling_hours_max` with averaging — the multiplication spelled out at both scales.
 *  - `fairness_distribution` — owner decision Q: the tolerance as a NUMBER, never as `10%`.
 *  - `target_per_period` — owner decision M: a modifier REPLACES, and both branches print.
 *
 * ## The rulings 41/49 hazard, in its unusual form
 *
 * Preview text is generated here and rendered by NOTHING until P3's gate screen — a string produced
 * under a key no screen consumes, which is precisely the shape that shipped three times as a control
 * that appeared to do nothing. The two halves cannot be asserted together yet, because the render
 * site does not exist. What is asserted instead is the preview against the PARAMETER SET, and P2
 * Task 1's open item 28 records that P3's gate owes the render-site half. Stating a half-finished
 * pair is not the same as finishing it.
 */

const d = (value: string): Ymd => parseYmd(value);

function dayVector(from: Ymd, to: Ymd): Day[] {
    return datesBetween(from, to).map((date) => ({
        date,
        isoWeekday: isoWeekday(date),
        dayType: [5, 6].includes(isoWeekday(date)) ? ('WE' as const) : ('WD' as const),
        periodKey: 'block-01',
        holidays: [],
    }));
}

const CONTEXT: EvaluationContext = {
    timezone: 'Asia/Riyadh',
    weekStartIsoDay: 7,
    weekendDays: [5, 6],
    today: d('2026-08-01'),
    days: dayVector(d('2026-08-01'), d('2026-08-07')),
    periods: [],
    people: [],
    slots: [],
    clinics: [],
    historyAvailableFrom: null,
    priorDuties: [],
    followingDuties: [],
};

function condition(typeKey: string, params: Record<string, unknown>, overrides: Partial<Condition> = {}): Condition {
    return { id: `c-${typeKey}`, typeKey, class: 'soft', active: true, params, ...overrides };
}

const previewed = CATALOG.filter((entry) => entry.preview !== undefined && entry.paramsSchema !== undefined);

// ---------------------------------------------------------------------------------------------
// The probe generator, and the matrix it feeds.
// ---------------------------------------------------------------------------------------------

/** Two values satisfying each pattern a params schema may constrain a string with. One entry. */
const PATTERN_PROBES: Record<string, [string, string]> = {
    '^[0-9]{4}-[0-9]{2}-[0-9]{2}$': ['2026-08-03', '2026-08-04'],
};

/**
 * Two distinct, schema-valid values for one parameter — the probe pair the matrix varies.
 *
 * It REFUSES rather than guesses. A property whose schema it cannot build a pair for throws, so a
 * parameter added in a shape nobody taught this generator fails the build loudly instead of being
 * silently skipped — which would leave the matrix green over a parameter it never varied, the exact
 * shape of a guard that has quietly stopped guarding.
 */
function probePair(schema: JsonSchema, at: string): [unknown, unknown] {
    // A params schema may reference the contract's own definitions rather than restating them —
    // `same_unit_conflict`'s exception dates are `$ref: Ymd`, which is the difference between a
    // date a department could have produced and any string at all. Resolving it here is what lets
    // the matrix vary such a parameter instead of quietly declining to.
    if (schema.$ref !== undefined) {
        const target = CONTRACT_SCHEMA.$defs[schema.$ref.replace('#/$defs/', '')];

        if (target === undefined) {
            throw new Error(`${at}: ${schema.$ref} resolves to no contract definition.`);
        }

        return probePair(target, at);
    }

    if (schema.enum !== undefined) {
        if (schema.enum.length < 2) {
            throw new Error(`${at}: an enum needs two members for the matrix to vary it.`);
        }

        return [schema.enum[0], schema.enum[1]];
    }

    switch (schema.type) {
        case 'boolean':
            return [false, true];
        case 'integer':
        case 'number': {
            const low = Math.max(schema.minimum ?? 3, 3);

            return [low, low + 4];
        }
        case 'string': {
            if (schema.pattern === undefined) {
                return ['alpha', 'beta'];
            }

            // A pattern-constrained string needs two values that SATISFY it, or the probe is
            // refused by the very schema it is probing and the matrix reports a crash instead of an
            // ignored parameter. The table has one entry and throws for anything else, so a second
            // pattern is a decision somebody takes rather than a silent skip.
            const probes = PATTERN_PROBES[schema.pattern];

            if (probes === undefined) {
                throw new Error(`${at}: no probe pair is defined for the pattern ${schema.pattern}.`);
            }

            return probes;
        }
        case 'array': {
            if (schema.items === undefined) {
                throw new Error(`${at}: an array parameter must declare its items.`);
            }

            const [first, second] = probePair(schema.items, `${at}/items`);

            return [[first], [first, second]];
        }
        case 'object': {
            if (schema.properties !== undefined) {
                const low: Record<string, unknown> = {};
                const high: Record<string, unknown> = {};

                for (const [name, child] of Object.entries(schema.properties)) {
                    const [first, second] = probePair(child, `${at}/${name}`);

                    low[name] = first;
                    high[name] = second;
                }

                return [low, high];
            }

            if (typeof schema.additionalProperties === 'object') {
                const [first, second] = probePair(schema.additionalProperties, `${at}/*`);

                return [{ alpha: first }, { alpha: first, beta: second }];
            }

            throw new Error(`${at}: an object parameter must declare properties or additionalProperties.`);
        }
        default:
            throw new Error(`${at}: no probe pair is defined for type "${String(schema.type)}".`);
    }
}

/** Every property of a params schema, with a valid value for each — the "fully parameterised" row. */
function fullParams(schema: JsonSchema, pick: 0 | 1 = 0): Record<string, unknown> {
    const params: Record<string, unknown> = {};

    for (const [name, child] of Object.entries(schema.properties ?? {})) {
        params[name] = probePair(child, name)[pick];
    }

    return params;
}

/**
 * The matrix, as a function over a catalog so it can be run against a PLANTED one.
 *
 * Returns `typeKey.parameter` for every parameter the type's preview does not react to. Green over
 * the shipped catalog is only meaningful if the same code is red over an entry that ignores a
 * parameter, and `it('bites…')` below is that.
 */
function parametersIgnoredByPreview(catalog: readonly RegistryEntry[]): string[] {
    const ignored: string[] = [];

    for (const entry of catalog) {
        const schema = entry.paramsSchema;
        const render = entry.preview;

        if (schema === undefined || render === undefined) {
            continue;
        }

        for (const name of Object.keys(schema.properties ?? {})) {
            const [low, high] = probePair(schema.properties?.[name] as JsonSchema, `${entry.typeKey}.${name}`);
            const base = fullParams(schema);

            const first = render(condition(entry.typeKey, { ...base, [name]: low }), CONTEXT, EN);
            const second = render(condition(entry.typeKey, { ...base, [name]: high }), CONTEXT, EN);

            if (first === second) {
                ignored.push(`${entry.typeKey}.${name}`);
            }
        }
    }

    return ignored;
}

describe('the matrix — every parameter a type reads is named by its preview', () => {
    it('reacts to every parameter of every previewable type', () => {
        expect(parametersIgnoredByPreview(CATALOG)).toEqual([]);
    });

    /**
     * The non-vacuity floor, and it is a FLOOR rather than a staleness twin on purpose: an
     * allow-list that is empty by design makes a staleness check iterate nothing and pass on a
     * healthy tree, a deleted directory and a renamed module alike (P2 Task 6 measured exactly
     * that). What this check can really have gone wrong is finding nothing to check.
     */
    it('runs over every type that has a preview today, named one by one', () => {
        expect(previewed.map((entry) => entry.typeKey).sort()).toEqual([
            // Task 13 — the two the tree could not resolve without the owner: decision S's three
            // attendee modes, and decision U's confirmed reading (a).
            'clinic_conflict',
            // Task 14 — owner decision V's third unit, and the transition allowance CG-08 drops.
            'consecutive_max',
            // Task 12 — the three placement types owner decisions R, T and the ISO-integer half of
            // the day-of-week ban settle. Their sentences live in the message table (AR-07).
            'dow_restriction',
            // Task 10 — the three Hard placement types, preview and predicate together.
            'eligibility',
            // Task 9 — the four whose WORDING an answered owner decision settles. Their predicates
            // land at Tasks 14, 16, 18 and 19 against the schema that is already here.
            'fairness_distribution',
            'min_gap',
            'onboarding_grace',
            'overlap_block',
            'post_duty_exclusion',
            'rolling_hours_max',
            'same_unit_conflict',
            'target_per_period',
            'unwanted_day_block',
            'vacation_block',
        ]);

        const parameters = previewed.flatMap((entry) =>
            Object.keys(entry.paramsSchema?.properties ?? {}).map((name) => `${entry.typeKey}.${name}`),
        );

        expect(parameters.length).toBeGreaterThanOrEqual(11);
    });

    /**
     * PLANTED, permanently. A preview that drops one parameter is what this matrix exists to catch,
     * so the catch is asserted rather than assumed — the same device `evaluateWith()` uses to make
     * the contract's properties testable before a predicate existed.
     */
    it('bites on a preview that ignores one of its own parameters', () => {
        const forgetful: RegistryEntry[] = [
            {
                typeKey: 'probe',
                implemented: true,
                direction: 'cap',
                locationKind: 'window',
                needsCarryIn: false,
                paramsSchema: {
                    type: 'object',
                    properties: { count: { type: 'integer' }, window: { enum: ['week', 'period'] } },
                    required: ['count', 'window'],
                    additionalProperties: false,
                },
                preview: (row) => `At most ${String(row.params['count'])} duties.`,
            },
        ];

        expect(parametersIgnoredByPreview(forgetful)).toEqual(['probe.window']);
    });
});

describe('the coupling — a type may not ship an evaluator without a preview', () => {
    /**
     * A violation whose condition row cannot be explained in words is a badge on a cell with
     * nothing behind it. So `evaluate` implies `preview` AND `paramsSchema`, one way: a preview may
     * land ahead of its evaluator (four did, at this task), never behind it.
     */
    const uncoupled = (catalog: readonly RegistryEntry[]): string[] =>
        catalog
            .filter((entry) => entry.evaluate !== undefined && (entry.preview === undefined || entry.paramsSchema === undefined))
            .map((entry) => entry.typeKey);

    it('holds over the shipped catalog', () => {
        expect(uncoupled(CATALOG)).toEqual([]);
    });

    it('bites on an evaluator with no preview behind it', () => {
        expect(
            uncoupled([
                {
                    typeKey: 'probe',
                    implemented: true,
                    direction: 'block',
                    locationKind: 'placement',
                    needsCarryIn: false,
                    evaluate: () => ({ findings: [], coverage: { evaluatedWindows: 0, skipped: [] } }),
                },
            ]),
        ).toEqual(['probe']);
    });
});

describe('the params schemas are validated by the validator this package actually has', () => {
    /**
     * A schema keyword the validator does not implement is a constraint that does nothing — and it
     * reads, in review, exactly like one that does. `contract.test.ts` asserts this both ways for
     * the contract document; the params schemas are a second family of schemas and needed the same
     * check, because they are the ones a department's own numbers arrive through.
     */
    it('uses no keyword the validator would silently ignore', () => {
        const known = new Set([...ASSERTION_KEYWORDS, ...['description', 'title']]);
        const unknown: string[] = [];

        for (const entry of previewed) {
            for (const keyword of keywordsUsedBy(entry.paramsSchema as JsonSchema)) {
                if (!known.has(keyword)) {
                    unknown.push(`${entry.typeKey}: ${keyword}`);
                }
            }
        }

        expect(unknown).toEqual([]);
    });

    it('closes every params object, so a typo in a department parameter is refused rather than ignored', () => {
        const open = previewed
            .filter((entry) => entry.paramsSchema?.additionalProperties !== false)
            .map((entry) => entry.typeKey);

        expect(open).toEqual([]);
    });
});

describe('min_gap — owner decision H, and the off-by-one rendered as a worked example', () => {
    const render = (params: Record<string, unknown>): string => preview(condition('min_gap', params), CONTEXT);

    it('names every parameter of a fully parameterised condition', () => {
        const sentence = render({ value: 3, unit: 'days', kinds: ['night', 'backup'] });

        expect(sentence).toContain('3');
        expect(sentence).toContain('days');
        expect(sentence).toContain('night');
        expect(sentence).toContain('backup');
    });

    /**
     * `N` means AT LEAST N APART between start dates, so 1 Aug → 4 Aug is legal at `N = 3` and
     * 1 Aug → 3 Aug is not. Both readings are one character apart in an implementation and a month
     * of different behaviour on a rota, and neither is visible in review — which is why the
     * sentence carries the worked example rather than the number alone.
     */
    it('spells the days reading out on dates, both the legal and the illegal side', () => {
        const sentence = render({ value: 3, unit: 'days' });

        expect(sentence).toContain('1 Aug then 4 Aug is allowed');
        expect(sentence).toContain('1 Aug then 3 Aug is not');
    });

    it('moves the worked example with the parameter', () => {
        expect(render({ value: 2, unit: 'days' })).toContain('1 Aug then 3 Aug is allowed');
        expect(render({ value: 2, unit: 'days' })).toContain('1 Aug then 2 Aug is not');
    });

    /** The hours reading is END-to-START (ACGME's ten hours between duties), and says so. */
    it('says which two endpoints the hours reading measures between', () => {
        const sentence = render({ value: 10, unit: 'hours' });

        expect(sentence).toContain('10 h');
        expect(sentence).toMatch(/end of one duty/i);
        expect(sentence).toMatch(/start of the next/i);
        expect(sentence).not.toContain('is allowed');
    });
});

describe('rolling_hours_max — the averaging multiplication, in words', () => {
    const render = (params: Record<string, unknown>): string =>
        preview(condition('rolling_hours_max', params), CONTEXT);

    it('renders both scales when the cap is averaged, so neither reading has to be inferred', () => {
        const sentence = render({ hours: 80, windowDays: 7, averagingWeeks: 4 });

        expect(sentence).toContain('80 h');
        expect(sentence).toContain('7 consecutive days');
        expect(sentence).toContain('4');
        expect(sentence).toContain('320 h');
        expect(sentence).toContain('28 consecutive days');
    });

    it('renders one scale and no multiplication when nothing is averaged', () => {
        const sentence = render({ hours: 80, windowDays: 7 });

        expect(sentence).toContain('80 h');
        expect(sentence).toContain('7 consecutive days');
        expect(sentence).not.toContain('320');
        expect(sentence).not.toMatch(/averaged/i);
    });
});

describe('fairness_distribution — owner decision Q, the tolerance as a number', () => {
    const render = (params: Record<string, unknown>): string =>
        preview(condition('fairness_distribution', params), CONTEXT);

    const DEFAULTS = { quantity: 'nights', mode: 'deviation', excludeExternal: true };

    /**
     * THE POINT OF THE ANSWER. `tolerance = max(1, ceil(0.1 × proRatedTarget))` behaves as two
     * different rules either side of ten, and a reader told "10%" on a four-duty target predicts
     * 0.4 and is wrong in the direction that matters — they would expect the condition to permit
     * nothing. So the sentence carries the applied NUMBER at both regimes and never the percentage
     * on its own.
     */
    it('states the allowance as a number at both regimes, and never as a bare percentage', () => {
        const sentence = render(DEFAULTS);

        expect(sentence).toContain('an expected share of 4 allows 1');
        expect(sentence).toContain('an expected share of 40 allows 4');
        expect(sentence).not.toContain('10%');
    });

    it('says which denominator the expected share is pro-rated by', () => {
        expect(render(DEFAULTS)).toMatch(/available/i);
    });

    it('names the quantity and says what happens to external people', () => {
        expect(render(DEFAULTS)).toContain('nights');
        expect(render(DEFAULTS)).toMatch(/external/i);
        expect(render({ ...DEFAULTS, excludeExternal: false })).toMatch(/external/i);
        expect(render({ ...DEFAULTS, excludeExternal: false })).not.toEqual(render(DEFAULTS));
    });

    it('distinguishes the two modes, because they answer different questions', () => {
        expect(render({ ...DEFAULTS, mode: 'spread' })).not.toEqual(render(DEFAULTS));
        expect(render({ ...DEFAULTS, mode: 'spread' })).toMatch(/busiest|widest|gap/i);
    });

    /**
     * THE FLOOR, ASSERTED WHERE IT ACTUALLY BITES — and this test exists because a plant proved the
     * obvious place does not.
     *
     * Removing `max(1, …)` from `max(1, ceil(0.1 × target))` was planted and the whole suite stayed
     * GREEN: `ceil(0.1 × 4)` is already 1, so an expected share of 4 — the number owner decision Q's
     * own justification uses — cannot distinguish the two formulas. The decision's wording ("0.4
     * floors to a tolerance of zero") describes rounding DOWN; the formula it states rounds UP. With
     * `ceil` the floor changes the answer at exactly one input, and that input is REAL: a pro-rated
     * target of zero, which is what a person on leave for the whole period has.
     *
     * So the floor is asserted at zero, and the docblock on `toleranceFor` records why it must stay
     * anyway — `Math.round(0.1 * 4)` is 0, and `round` is what the next author reaches for.
     */
    it('floors the tolerance at one, including at a pro-rated target of zero', () => {
        expect(toleranceFor(0)).toBe(1);
        expect(toleranceFor(0.4)).toBe(1);
        expect(toleranceFor(4)).toBe(1);
        expect(toleranceFor(10)).toBe(1);
        expect(toleranceFor(40)).toBe(4);
        expect(toleranceFor(41)).toBe(5);
    });

    it('is the same tolerance the sentence prints — one definition, not two', () => {
        expect(render(DEFAULTS)).toContain(`an expected share of 40 allows ${toleranceFor(40)}`);
    });
});

describe('target_per_period — owner decision M, a modifier replaces and both branches print', () => {
    const render = (params: Record<string, unknown>): string =>
        preview(condition('target_per_period', params), CONTEXT);

    const PARAMS = {
        targets: { R1: 4, R2: 3 },
        modifiers: [{ when: { vacationWeeksAtLeast: 2 }, target: 2 }],
    };

    it('prints the base target per level and the effective target at the modifier branch', () => {
        const sentence = render(PARAMS);

        expect(sentence).toContain('R1');
        expect(sentence).toContain('4');
        expect(sentence).toContain('R2');
        expect(sentence).toContain('3');
        expect(sentence).toContain('2 vacation weeks');
        expect(sentence).toContain('2 duties instead');
    });

    /**
     * Replace, not adjust. A delta grammar lets two modifiers compound to a target below zero
     * silently and hides the resulting number from a reader who is not doing the arithmetic
     * themselves, which is why the answer kept `replace` — and why the sentence says so in words
     * rather than leaving it to be inferred from a number that happens to be smaller.
     */
    it('says in words that the number replaces the target rather than adjusting it', () => {
        expect(render(PARAMS)).toMatch(/replac/i);
        expect(render(PARAMS)).not.toMatch(/fewer|less by|reduce/i);
    });

    it('states that the first matching modifier wins, once, however many there are', () => {
        const sentence = render({
            targets: { R1: 4 },
            modifiers: [
                { when: { vacationWeeksAtLeast: 2 }, target: 2 },
                { when: { periodWeeksAtMost: 4 }, target: 3 },
            ],
        });

        expect(sentence).toMatch(/first/i);
        expect(sentence).toContain('at most 4 weeks');
        expect(sentence).toContain('3 duties instead');
    });

    it('says nothing about exceptions when there are none', () => {
        expect(render({ targets: { R1: 4 }, modifiers: [] })).not.toMatch(/instead|first/i);
    });
});

describe('onboarding_grace — owner decision T, said out loud rather than left to be discovered', () => {
    const render = (params: Record<string, unknown>): string => preview(condition('onboarding_grace', params), CONTEXT);

    /**
     * Day 1 is the JOIN DATE, which is the half a reader gets wrong — "first 3 days" reads as three
     * days AFTER joining to about half the people who read it. So the sentence carries the first
     * date the person may be scheduled on, the same device owner decision H bought for `min_gap`,
     * and it moves with the parameter rather than being written into the prose once.
     */
    it('renders the boundary as a date rather than as a number to be reasoned about', () => {
        expect(render({ days: 3 })).toContain('4 Aug');
        expect(render({ days: 7 })).toContain('8 Aug');
        expect(render({ days: 1 })).toContain("first 1 day");
    });

    /**
     * The unknown-join-date answer is in the SENTENCE, not only in the coverage row. A department
     * reading this rule on the gate screen is looking at the one place they could learn that a
     * person with no recorded join date is not protected by it — and `joined_at` is written by no
     * seeder, factory or demo path in this repository (finding 18), so that is the majority case on
     * a fresh instance rather than an edge one.
     */
    it('says what happens to a person whose join date is not recorded', () => {
        expect(render({ days: 3 })).toMatch(/join date is not recorded/i);
        expect(render({ days: 3 })).toMatch(/not blocked/i);
    });
});

describe('dow_restriction — ISO numbers, because the names are the server’s', () => {
    const render = (params: Record<string, unknown>): string => preview(condition('dow_restriction', params), CONTEXT);

    /**
     * AR-07 keeps the day names in `lang/en/calendar.php` and owner decision X keeps the week's
     * shape in the context, so this sentence names ISO NUMBERS and says why — a reader shown "5"
     * with no explanation would reasonably assume a bug. The quoted-weekday scan over `packages/`
     * enforces the other half: the names cannot appear here even if somebody wanted them to.
     */
    it('lists the banned weekdays as numbers and says whose the names are', () => {
        const sentence = render({ days: [5, 6] });

        expect(sentence).toContain('ISO weekdays 5 and 6');
        expect(sentence).toMatch(/rendered by the server/i);
        expect(render({ days: [5] })).toContain('ISO weekday 5');
    });

    it("names the scope as where the rule's rotation-or-person half lives", () => {
        expect(render({ days: [5] })).toMatch(/scope/i);
    });
});

describe('unwanted_day_block — owner decision R, a rule that stores nothing', () => {
    it('states the anchor-date reading and that the days come from the request', () => {
        const sentence = preview(condition('unwanted_day_block', {}), CONTEXT);

        expect(sentence).toMatch(/day the duty starts on/i);
        expect(sentence).toMatch(/stores none of them/i);
    });
});

describe('clinic_conflict — owner decision S, the variant and who a clinic comes down to', () => {
    const render = (params: Record<string, unknown>): string => preview(condition('clinic_conflict', params), CONTEXT);

    /**
     * The two variants are a real behavioural difference on the same rota and the sentence must
     * make it visible: under the frozen default a clinic on the day a duty STARTS is fine, and
     * under the other it is not. A reader who cannot tell which they have configured has a rule
     * whose effect they will discover from a warning they did not expect.
     */
    it('says what the same-day half does under each variant', () => {
        expect(render({ variant: 'post_call' })).toMatch(/day a duty STARTS is not a conflict/);
        expect(render({ variant: 'post_call_and_same_day' })).toMatch(/CALENDAR DAY/);
        expect(render({ variant: 'post_call_and_same_day' })).toMatch(/no hours to compare/);
    });

    /**
     * The attendee mode is the half design item 22 leaves out, and the half that decides whether a
     * named attendee who rotates nowhere is caught at all (finding 17). A preview that describes
     * only the unit reading would describe a rule this engine does not implement.
     */
    it('names all three ways a clinic comes down to people, and when they are read', () => {
        const sentence = render({ variant: 'post_call' });

        expect(sentence).toMatch(/rotating on the unit/i);
        expect(sentence).toMatch(/levels attached/i);
        expect(sentence).toMatch(/named on it/i);
        expect(sentence).toMatch(/day the clinic runs/i);
    });
});

describe('same_unit_conflict — owner decision U, and the exception that LIFTS', () => {
    const render = (params: Record<string, unknown>): string =>
        preview(condition('same_unit_conflict', params), CONTEXT);

    /**
     * The parameters cell reads as the opposite predicate ("unit pairs") and the Meaning cell reads
     * as a third one ("pairs never together"), so this sentence has to state which of the three the
     * engine implements — in words, on the gate screen, where the department reading it never saw
     * owner decision U.
     */
    it('states reading (a) in words rather than leaving the key name to carry it', () => {
        const sentence = render({ units: ['PICU'] });

        expect(sentence).toContain('PICU');
        expect(sentence).toMatch(/same day/i);
        expect(sentence).toMatch(/rotation each of them is on that day/i);
    });

    it('says the exception lifts the ban, and on which dates', () => {
        expect(render({ exceptDates: ['2026-08-07'] })).toMatch(/2026-08-07/);
        expect(render({ exceptDates: ['2026-08-07'] })).toMatch(/lifted/i);
        expect(render({})).toMatch(/every day of the schedule/i);
        expect(render({})).toMatch(/any one unit/i);
    });
});

describe('post_duty_exclusion — owner decision H, and the degenerate case named out loud', () => {
    const render = (params: Record<string, unknown>): string =>
        preview(condition('post_duty_exclusion', params), CONTEXT);

    /**
     * The clock runs from the END of the first duty, which is the half a reader assumes wrongly:
     * anchored on the START, a 24 h call would block nothing at all after it finished. The sentence
     * says which endpoint it measures from, because the alternative is a department discovering it
     * from a warning that did not appear.
     */
    it('says the clock runs from the end of the first duty', () => {
        const sentence = render({ from: ['call'], to: ['clinic'], hours: 10 });

        expect(sentence).toContain('call');
        expect(sentence).toContain('clinic');
        expect(sentence).toContain('10 h');
        expect(sentence).toContain('runs from the END of the first duty');
        expect(sentence).toMatch(/may not START/);
    });

    /**
     * When a kind is on BOTH sides the type degenerates into `min_gap` in hours — the same kind
     * spacing itself — and a department that wrote that configuration deliberately should see it
     * confirmed, while one that wrote it by accident should see it at all. The plan asks for this
     * explicitly, and the two types are asserted to AGREE on such a pair in `conditions.test.ts`.
     */
    it('names the from/to intersection and what it degenerates into', () => {
        expect(render({ from: ['call'], to: ['call'], hours: 10 })).toMatch(/both sides/i);
        expect(render({ from: ['call'], to: ['call'], hours: 10 })).toMatch(/from each other/i);
        expect(render({ from: ['call'], to: ['clinic'], hours: 10 })).not.toMatch(/both sides/i);
    });
});

describe('consecutive_max — owner decision V, three units and one allowance', () => {
    const render = (params: Record<string, unknown>): string =>
        preview(condition('consecutive_max', params), CONTEXT);

    it('renders the 24 h continuous cap as a joined chain, with the allowance that joins it', () => {
        const sentence = render({ count: 24, unit: 'hours', transitionMinutes: 60, kinds: [] });

        expect(sentence).toContain('24 h');
        expect(sentence).toContain('60 minutes or less apart are ONE stretch');
        expect(sentence).toMatch(/does not restart the clock/i);
    });

    /**
     * `transitionMinutes` is read by the `hours` unit ALONE, and the sentence says so under the
     * other two rather than printing a number that does nothing. A parameter carried on a condition
     * row and silently ignored is rulings 41/49's shape — and the person who set it has every
     * reason to believe it applies.
     */
    it('says the transition allowance is not read under the day and night units', () => {
        for (const unit of ['days', 'nights']) {
            const sentence = render({ count: 3, unit, transitionMinutes: 60, kinds: [] });

            expect(sentence).toMatch(/read only by the hours version/i);
            expect(sentence).toContain('60-minute');
        }

        expect(render({ count: 3, unit: 'days', transitionMinutes: 0, kinds: [] })).toContain('3 days on duty');
        expect(render({ count: 3, unit: 'nights', transitionMinutes: 0, kinds: [] })).toContain('3 nights on duty');
    });

    it('states that two duties on one date are one date', () => {
        expect(render({ count: 3, unit: 'days', transitionMinutes: 0, kinds: [] })).toMatch(
            /two duties on one date are one date/i,
        );
    });
});

describe('what the dispatcher refuses, and how loudly', () => {
    it('throws on a type key no catalog row carries', () => {
        expect(() => preview(condition('no_such_type', {}), CONTEXT)).toThrow(UnknownConditionTypeError);
    });

    it('throws the unimplemented error for the one row this engine does not implement', () => {
        expect(() => preview(condition('forbidden_transition', {}), CONTEXT)).toThrow(
            UnimplementedConditionTypeError,
        );
    });

    /**
     * A catalog row this engine implements but has not yet written a preview for is a THIRD thing,
     * and it is refused rather than answered with an empty string or the type key. A gate screen
     * rendering a blank cell where a rule should be described is a control that appears to do
     * nothing, one layer along from rulings 41 and 49.
     */
    it('throws a distinguishable error for an implemented row with no preview yet', () => {
        expect(registryEntry('count_max')?.implemented).toBe(true);
        expect(() => preview(condition('count_max', {}), CONTEXT)).toThrow(NoPreviewForConditionTypeError);
    });

    it('takes the catalog as an argument, so P3 can preview against a restricted one', () => {
        const restricted: RegistryEntry[] = [
            {
                typeKey: 'probe',
                implemented: true,
                direction: 'block',
                locationKind: 'placement',
                needsCarryIn: false,
                paramsSchema: { type: 'object', properties: {}, additionalProperties: false },
                preview: () => 'A probe.',
            },
        ];

        expect(previewWith(restricted, condition('probe', {}), CONTEXT, EN)).toBe('A probe.');
    });
});

describe('the message table — English today, and a second one is possible tomorrow', () => {
    /**
     * AR-07 makes translation future work, and the way to keep it possible is for the sentences to
     * come from a TABLE the caller supplies rather than from string literals scattered through
     * twenty-two predicates. Asserted by passing a second table and watching the output change:
     * a message table nothing can override is a message table in name only.
     */
    it('renders through the table it is handed, not through a literal in the type module', () => {
        const shouting = { ...EN, minGap: () => 'TRANSLATED' };

        expect(previewWith(CATALOG, condition('min_gap', { value: 3, unit: 'days' }), CONTEXT, shouting)).toBe(
            'TRANSLATED',
        );
    });

    /**
     * THE SAME PROPERTY OVER EVERY PREVIEWABLE TYPE, AND IT DID NOT HOLD UNTIL P2-2's FIRST TASK.
     *
     * The check above proved it on `min_gap` — one type of fourteen — and the plan's own note said
     * *"`preview` already goes through the table"* without qualification. That was half true:
     * `overlap_block`, `vacation_block` and `eligibility` (Task 10) each carried English inline and
     * would have passed every check in this file, because the matrix only asks that a sentence REACT
     * to a parameter and two of those three have no parameters at all. A property asserted at the
     * one input where the defect cannot appear is P2-1's recurring green plant, four times over.
     *
     * The second table is derived from `EN`'s own keys rather than written out, so a method added
     * tomorrow is covered without anybody remembering — and the whole table shouts, vocabulary
     * included, so a preview that called `conjoin` and wrapped a literal around it returns a tag
     * with text around it rather than a tag.
     *
     * Every previewable entry is parameterised through the SAME probe generator the matrix uses, so
     * a type whose sentence branches on its parameters is exercised on a real branch rather than on
     * an empty object it would have refused anyway.
     */
    it('renders every previewable type through the table, not just the one that proved the device', () => {
        const shouting = Object.fromEntries(
            Object.keys(EN).map((key) => [key, () => `«${key}»`]),
        ) as unknown as typeof EN;

        const notFromTheTable = previewed
            .map((entry) => ({
                typeKey: entry.typeKey,
                sentence: previewWith(
                    CATALOG,
                    condition(entry.typeKey, fullParams(entry.paramsSchema as JsonSchema)),
                    CONTEXT,
                    shouting,
                ),
            }))
            .filter(({ sentence }) => !/^«[a-zA-Z]+»$/.test(sentence));

        expect(notFromTheTable).toEqual([]);
        expect(previewed.length).toBeGreaterThanOrEqual(14);
    });

    /**
     * THE ONE THING THE CHECK ABOVE STRUCTURALLY CANNOT SEE, and it had a real occupant.
     *
     * A type may assemble a FRAGMENT and pass it into a table sentence, and the outer tag then
     * swallows the fragment whole — the shouting table returns `«targetPerPeriod»` whatever its
     * `modifiers[].clause` says. `clauseFor()` was exactly that: it routed its two predicate clauses
     * through the table and kept `'the period is any period at all'` and a `' and '` joiner inline,
     * green under every check in this file. Found by surveying the package's string literals rather
     * than by a guard, which is the honest way to record how it was found.
     *
     * So the fragment builder is asserted at ITS OWN boundary, which is the only place the fragment
     * is visible. The joiner is now `conjoin` — a modifier has exactly two possible members, so this
     * is `conjoin`'s two-item case and a local `' and '` was a second definition of one connective.
     */
    it('renders a modifier CLAUSE through the table too, including the one that names no predicate', () => {
        const shouting = Object.fromEntries(
            Object.keys(EN).map((key) => [key, () => `«${key}»`]),
        ) as unknown as typeof EN;

        // `conjoin` stays REAL so the composition is visible: a fully shouting table would return
        // `«conjoin»` for the two-member case and hide which fragments went into it.
        const leaves = { ...shouting, conjoin: EN.conjoin };

        expect(clauseFor({}, leaves)).toBe('«anyPeriodClause»');
        expect(clauseFor({ vacationWeeksAtLeast: 2 }, leaves)).toBe('«vacationWeeksAtLeast»');
        expect(clauseFor({ vacationWeeksAtLeast: 2, periodWeeksAtMost: 4 }, leaves)).toBe(
            '«vacationWeeksAtLeast» and «periodWeeksAtMost»',
        );

        // And the JOINER is the table's too, not a local `' and '`.
        expect(clauseFor({ vacationWeeksAtLeast: 2, periodWeeksAtMost: 4 }, shouting)).toBe('«conjoin»');

        // The English did not move with it: both fragments still read as they did.
        expect(clauseFor({}, EN)).toBe('the period is any period at all');
        expect(clauseFor({ vacationWeeksAtLeast: 2, periodWeeksAtMost: 4 }, EN)).toBe(
            'a person has at least 2 vacation weeks in the period and the period is at most 4 weeks long',
        );
    });
});
