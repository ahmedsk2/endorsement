import { describe, expect, it } from 'vitest';

import { datesBetween, isoWeekday, parseYmd, type Ymd } from '../src/calendar/ymd';
import type { Condition, Day, EvaluationContext } from '../src/contract/types';
import type { JsonSchema } from '../src/contract/schema';
import { ASSERTION_KEYWORDS, keywordsUsedBy } from '../src/contract/validate';
import { toleranceFor } from '../src/conditions/fairness_distribution';
import { UnimplementedConditionTypeError, UnknownConditionTypeError } from '../src/evaluate';
import { EN } from '../src/messages';
import { NoPreviewForConditionTypeError, preview, previewWith } from '../src/preview';
import { CATALOG, type RegistryEntry } from '../src/registry';

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

/**
 * Two distinct, schema-valid values for one parameter — the probe pair the matrix varies.
 *
 * It REFUSES rather than guesses. A property whose schema it cannot build a pair for throws, so a
 * parameter added in a shape nobody taught this generator fails the build loudly instead of being
 * silently skipped — which would leave the matrix green over a parameter it never varied, the exact
 * shape of a guard that has quietly stopped guarding.
 */
function probePair(schema: JsonSchema, at: string): [unknown, unknown] {
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
        case 'string':
            return ['alpha', 'beta'];
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
    it('runs over the four types whose wording the answered decisions settle, and their parameters', () => {
        expect(previewed.map((entry) => entry.typeKey).sort()).toEqual([
            'fairness_distribution',
            'min_gap',
            'rolling_hours_max',
            'target_per_period',
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
        expect(() => preview(condition('overlap_block', {}), CONTEXT)).toThrow(NoPreviewForConditionTypeError);
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
});
