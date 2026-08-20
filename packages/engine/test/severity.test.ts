import { describe, expect, it } from 'vitest';

import { parseYmd, type Ymd } from '../src/calendar/ymd';
import type { Condition, EvaluationContext, Finding, Schedule, Violation } from '../src/contract/types';
import { evaluateWith } from '../src/evaluate';
import type { RegistryEntry } from '../src/registry';
import { comparePrecedence, sortByPrecedence, stampViolation } from '../src/severity';

/**
 * CG-05/CG-06's severity and rank model (P2 Task 9).
 *
 * Two properties, and both of them are about the engine NOT deciding something:
 *
 *  - **`Condition.class` is the only input to severity.** §30 makes `class` a field on the
 *    condition row, CG-01 lists it per condition and CG-02 rank-orders the soft ones — so the
 *    engine reads it and never overrides it. A type reports only WHERE and WHY.
 *  - **Rank ordering is the only input to grading.** AU-02 says the solver weights soft violations
 *    *"monotonically by rank"*; a weight CURVE chosen here would be a second definition of the
 *    solver's own fact, in the wrong repository. So this file asserts an ORDER and no number.
 */

const d = (value: string): Ymd => parseYmd(value);

const PLACEMENT = { kind: 'placement' as const, personKey: 'p-ali', date: d('2026-08-03'), slotKey: 'night' };

function condition(overrides: Partial<Condition> = {}): Condition {
    return {
        id: 'c-1',
        typeKey: 'probe',
        class: 'soft',
        active: true,
        params: {},
        ...overrides,
    };
}

function violation(overrides: Partial<Violation> = {}): Violation {
    return {
        conditionId: 'c-1',
        severity: 'soft',
        location: PLACEMENT,
        explanation: 'why',
        ...overrides,
    };
}

const FINDING: Finding = { location: PLACEMENT, explanation: 'two duties overlap' };

describe('the stamp — severity comes from the condition row, never from the type', () => {
    it('carries a hard class through regardless of rank', () => {
        expect(stampViolation(condition({ class: 'hard', rank: 9 }), FINDING).severity).toBe('hard');
        expect(stampViolation(condition({ class: 'hard' }), FINDING).severity).toBe('hard');
    });

    it('carries a soft class and its rank through verbatim', () => {
        const stamped = stampViolation(condition({ class: 'soft', rank: 5 }), FINDING);

        expect(stamped.severity).toBe('soft');
        expect(stamped.rank).toBe(5);
    });

    /**
     * `exactOptionalPropertyTypes` is on, and CG-10 types `rank` as optional. An unranked condition
     * must therefore produce a violation with NO `rank` key rather than one carrying `undefined`:
     * the two serialise differently over the wire to PU-03's publish dialog, and `JSON.stringify`
     * drops one and keeps neither honestly.
     */
    it('omits rank entirely when the row carries none', () => {
        expect(Object.hasOwn(stampViolation(condition(), FINDING), 'rank')).toBe(false);
    });

    it('copies the location and explanation off the finding without editing either', () => {
        const stamped = stampViolation(condition({ id: 'c-overlap' }), FINDING);

        expect(stamped.conditionId).toBe('c-overlap');
        expect(stamped.location).toEqual(PLACEMENT);
        expect(stamped.explanation).toBe('two duties overlap');
    });

    /**
     * The stamp is not a second implementation of what `evaluate()` already does — `evaluate()`
     * CALLS it. Asserted behaviourally rather than by reading the source: a violation produced by
     * the real pipeline must be identical to the one the stamp produces alone.
     */
    it('is the same stamp evaluate() applies, not a parallel one', () => {
        const row = condition({ typeKey: 'probe', class: 'hard', rank: 2, id: 'c-probe' });
        const catalog: RegistryEntry[] = [
            {
                typeKey: 'probe',
                implemented: true,
                direction: 'block',
                locationKind: 'placement',
                needsCarryIn: false,
                evaluate: () => ({ findings: [FINDING], coverage: { evaluatedWindows: 1, skipped: [] } }),
            },
        ];

        const schedule: Schedule = {
            horizon: {
                from: d('2026-08-01'),
                to: d('2026-08-31'),
                evaluableFrom: d('2026-08-01'),
                evaluableTo: d('2026-08-31'),
            },
            duties: [],
        };

        const produced = evaluateWith(catalog, schedule, {} as EvaluationContext, [row]);

        expect(produced).toEqual([stampViolation(row, FINDING)]);
    });
});

describe('precedence — hard above all soft, soft monotonically by rank', () => {
    it('puts a hard violation above a soft one whatever the two ranks say', () => {
        const hard = violation({ severity: 'hard', rank: 9 });
        const soft = violation({ severity: 'soft', rank: 1 });

        expect(comparePrecedence(hard, soft)).toBeLessThan(0);
        expect(comparePrecedence(soft, hard)).toBeGreaterThan(0);
    });

    it('grades two soft violations by rank, lower rank first', () => {
        const first = violation({ rank: 1 });
        const fifth = violation({ rank: 5 });

        expect(comparePrecedence(first, fifth)).toBeLessThan(0);
        expect(comparePrecedence(fifth, first)).toBeGreaterThan(0);
        expect(comparePrecedence(first, violation({ rank: 1 }))).toBe(0);
    });

    /**
     * Rank is CG-02's DRAG ORDER over the soft rows. Two hard rows are not draggable relative to
     * each other, so a rank on a hard row is data the engine carries and does not act on — and
     * acting on it would be the engine inventing an order the gate screen never offered.
     */
    it('ignores rank between two hard violations', () => {
        expect(comparePrecedence(violation({ severity: 'hard', rank: 1 }), violation({ severity: 'hard', rank: 9 }))).toBe(0);
    });

    /**
     * An unranked soft row has no position in CG-02's order. Sorting it FIRST would give the row
     * nobody ranked the loudest grade in the list, which is the opposite of what an unset field
     * means.
     */
    it('sorts an unranked soft violation after every ranked one', () => {
        expect(comparePrecedence(violation({}), violation({ rank: 9 }))).toBeGreaterThan(0);
        expect(comparePrecedence(violation({ rank: 9 }), violation({}))).toBeLessThan(0);
        expect(comparePrecedence(violation({}), violation({}))).toBe(0);
    });

    it('sorts a whole list, hard first and soft by rank, leaving the argument untouched', () => {
        const list = [
            violation({ conditionId: 'a', rank: 5 }),
            violation({ conditionId: 'b', severity: 'hard' }),
            violation({ conditionId: 'c' }),
            violation({ conditionId: 'd', rank: 1 }),
        ];

        expect(sortByPrecedence(list).map((entry) => entry.conditionId)).toEqual(['b', 'd', 'a', 'c']);
        expect(list.map((entry) => entry.conditionId)).toEqual(['a', 'b', 'c', 'd']);
    });

    /**
     * NO WEIGHT IS INVENTED HERE, and this is the assertion that keeps it that way. A comparator
     * returning a difference of ranks is an order; a function returning "rank 1 is worth 8 points"
     * is AU-02's penalty curve, which belongs in the solver's repository and would silently become
     * a second definition of it if it were written here first.
     */
    it('exposes an order and no numeric weight — the answer is only ever -1, 0 or 1', () => {
        const answers = new Set<number>();

        for (const a of [violation({ severity: 'hard' }), violation({ rank: 1 }), violation({ rank: 500 }), violation({})]) {
            for (const b of [violation({ severity: 'hard' }), violation({ rank: 1 }), violation({ rank: 500 }), violation({})]) {
                answers.add(comparePrecedence(a, b));
            }
        }

        expect([...answers].sort()).toEqual([-1, 0, 1]);
    });
});
