import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { datesBetween, isoWeekday, parseYmd, type Ymd } from '../src/calendar/ymd';
import { CONTRACT_SCHEMA } from '../src/contract/schema';
import {
    ANNOTATION_KEYWORDS,
    ASSERTION_KEYWORDS,
    assertValid,
    keywordsUsedBy,
    validate,
} from '../src/contract/validate';
import type {
    Condition,
    CoverageDetail,
    Day,
    EvaluationContext,
    Finding,
    Location,
    Schedule,
    Violation,
} from '../src/contract/types';
import { coverageWith } from '../src/coverage';
import {
    UnimplementedConditionTypeError,
    UnknownConditionTypeError,
    emitWithinHorizon,
    evaluate,
    evaluateWith,
    sortViolations,
} from '../src/evaluate';
import type { RegistryEntry } from '../src/registry';
import type { Slot } from '../src/duty/interval';

/**
 * The CG-10 contract (P2 Task 7): the three location shapes, `evaluate()`, and `coverage()`.
 *
 * Written before any condition type exists, which is the point. Every property asserted here is a
 * property of the CONTRACT — the ordering, the emission rule, the unknown-key throw, the schema —
 * and each one is the sort of thing that is impossible to retrofit once eleven types and a fixture
 * corpus have been written against the wrong shape. Decision D says so explicitly: widening
 * `location` after the fact is what this task exists to avoid.
 *
 * The synthetic registry entries below are TEST DATA, not stubs in the package. `evaluateWith()`
 * takes the catalog as an argument precisely so the contract's own properties can be asserted
 * against entries this file controls, before `registry.ts` carries any.
 */

const d = (value: string): Ymd => parseYmd(value);

/** A daily 17:00→08:00 night call. One slot is enough for every property this file asserts. */
const NIGHT: Slot = {
    key: 'night',
    kind: 'call',
    cadence: 'daily',
    spanDays: 1,
    startMinute: 17 * 60,
    endMinute: 8 * 60,
    crossesMidnight: true,
    countsHours: true,
};

function dayVector(from: Ymd, to: Ymd): Day[] {
    return datesBetween(from, to).map((date) => ({
        date,
        isoWeekday: isoWeekday(date),
        dayType: [5, 6].includes(isoWeekday(date)) ? ('WE' as const) : ('WD' as const),
        periodKey: 'block-01',
        holidays: [],
    }));
}

/**
 * A minimal synthetic week, with a genuine carry-in tail either side of it.
 *
 * The horizon is 2026-08-01..2026-08-07 and the tail reaches a week further in both directions, so
 * a violation located in the tail is a thing this fixture can actually express — which is what the
 * emission rule needs to be tested against something rather than asserted.
 */
function contextFor(overrides: Partial<EvaluationContext> = {}): EvaluationContext {
    return {
        timezone: 'Asia/Riyadh',
        weekStartIsoDay: 7,
        weekendDays: [5, 6],
        today: d('2026-08-01'),
        days: dayVector(d('2026-08-01'), d('2026-08-07')),
        periods: [
            {
                key: 'block-01',
                startsOn: d('2026-07-26'),
                endsOn: d('2026-08-22'),
                weeks: [
                    {
                        startsOn: d('2026-07-26'),
                        endsOn: d('2026-08-01'),
                        clippedStartsOn: d('2026-07-26'),
                        clippedEndsOn: d('2026-08-01'),
                    },
                ],
            },
        ],
        people: [
            {
                key: 'p-ali',
                levelSpans: [{ key: 'R2', from: d('2026-01-01'), to: d('2026-12-31') }],
                unitSpans: [{ key: 'PICU', from: d('2026-01-01'), to: d('2026-12-31') }],
                leaveDays: [],
                unwantedDays: [],
                eligibleDays: datesBetween(d('2026-08-01'), d('2026-08-07')),
                external: false,
            },
        ],
        slots: [NIGHT],
        clinics: [],
        historyAvailableFrom: null,
        priorDuties: [{ personKey: 'p-ali', date: d('2026-07-28'), slotKey: 'night' }],
        followingDuties: [],
        ...overrides,
    };
}

function scheduleFor(overrides: Partial<Schedule> = {}): Schedule {
    return {
        horizon: {
            from: d('2026-08-01'),
            to: d('2026-08-07'),
            evaluableFrom: d('2026-07-25'),
            evaluableTo: d('2026-08-14'),
        },
        duties: [{ personKey: 'p-ali', date: d('2026-08-03'), slotKey: 'night' }],
        ...overrides,
    };
}

function condition(id: string, typeKey: string, over: Partial<Condition> = {}): Condition {
    return { id, typeKey, class: 'hard', active: true, params: {}, ...over };
}

const NO_SKIPS: CoverageDetail = { evaluatedWindows: 1, skipped: [] };

/** A registry entry that returns exactly the findings it is handed. Test data, not a stub. */
function entryProducing(typeKey: string, findings: Finding[], detail: CoverageDetail = NO_SKIPS): RegistryEntry {
    return {
        typeKey,
        implemented: true,
        direction: 'block',
        locationKind: 'placement',
        needsCarryIn: false,
        evaluate: () => ({ findings, coverage: detail }),
    };
}

const placement = (date: string, slotKey = 'night', personKey = 'p-ali'): Location => ({
    kind: 'placement',
    personKey,
    date: d(date),
    slotKey,
});

const window = (from: string, to: string, personKey = 'p-ali'): Location => ({
    kind: 'window',
    personKey,
    from: d(from),
    to: d(to),
    contributing: [{ personKey, date: d(from), slotKey: 'night' }],
});

const cohort = (personKeys: string[], scopeLabel: string): Location => ({
    kind: 'cohort',
    personKeys,
    scopeLabel,
});

const finding = (location: Location, explanation = 'because'): Finding => ({ location, explanation });

describe('evaluate()', () => {
    it('answers a month with no conditions with no violations', () => {
        expect(evaluate(scheduleFor(), contextFor(), [])).toEqual([]);
    });

    /**
     * `not_a_type` rather than a real catalog key, deliberately. Task 8 registers all 23 keys, so
     * a permanent assertion that `min_gap` is UNKNOWN would have been a test with a two-task
     * shelf life. The plan's `min_gap` plant was run by hand at this task and is recorded in the
     * commit rather than frozen here: it threw `UnknownConditionTypeError` naming the key.
     */
    it('throws a named error on a typeKey no catalog row carries', () => {
        expect(() => evaluate(scheduleFor(), contextFor(), [condition('c1', 'not_a_type')])).toThrow(
            UnknownConditionTypeError,
        );

        expect(() => evaluate(scheduleFor(), contextFor(), [condition('c1', 'not_a_type')])).toThrow(/not_a_type/);
    });

    it('throws a DIFFERENT named error on a catalog row the engine does not implement', () => {
        const catalog: RegistryEntry[] = [
            {
                typeKey: 'forbidden_transition',
                implemented: false,
                notImplementedBecause: 'CG-07 marks it (Stage 5) in its own parameters cell.',
                direction: 'block',
                locationKind: 'placement',
                needsCarryIn: false,
            },
        ];

        expect(() =>
            evaluateWith(catalog, scheduleFor(), contextFor(), [condition('c1', 'forbidden_transition')]),
        ).toThrow(UnimplementedConditionTypeError);

        // The reason travels with the throw, so a caller learns WHY without opening the spec.
        expect(() =>
            evaluateWith(catalog, scheduleFor(), contextFor(), [condition('c1', 'forbidden_transition')]),
        ).toThrow(/Stage 5/);
    });

    it('refuses an unresolvable typeKey even when the condition is switched OFF', () => {
        expect(() =>
            evaluate(scheduleFor(), contextFor(), [condition('c1', 'not_a_type', { active: false })]),
        ).toThrow(UnknownConditionTypeError);
    });

    it('stamps severity from Condition.class, never from the registry', () => {
        const catalog = [entryProducing('vacation_block', [finding(placement('2026-08-03'))])];
        const soft = evaluateWith(catalog, scheduleFor(), contextFor(), [
            condition('c1', 'vacation_block', { class: 'soft', rank: 4 }),
        ]);

        expect(soft).toHaveLength(1);
        expect(soft[0]?.severity).toBe('soft');
        expect(soft[0]?.rank).toBe(4);

        const hard = evaluateWith(catalog, scheduleFor(), contextFor(), [condition('c1', 'vacation_block')]);

        expect(hard[0]?.severity).toBe('hard');
        expect(hard[0]).not.toHaveProperty('rank');
    });

    it('emits a violation carrying exactly CG-10’s five fields, and no more', () => {
        const catalog = [entryProducing('vacation_block', [finding(placement('2026-08-03'), 'on leave')])];
        const [violation] = evaluateWith(catalog, scheduleFor(), contextFor(), [
            condition('c1', 'vacation_block'),
        ]);

        expect(Object.keys(violation ?? {}).sort()).toEqual([
            'conditionId',
            'explanation',
            'location',
            'severity',
        ]);
    });

    it('evaluates nothing for a condition switched off, and says so through coverage()', () => {
        const catalog = [entryProducing('vacation_block', [finding(placement('2026-08-03'))])];
        const off = [condition('c1', 'vacation_block', { active: false })];

        expect(evaluateWith(catalog, scheduleFor(), contextFor(), off)).toEqual([]);

        const reports = coverageWith(catalog, scheduleFor(), contextFor(), off);

        expect(reports).toHaveLength(1);
        expect(reports[0]?.evaluatedWindows).toBe(0);
        expect(reports[0]?.skipped[0]?.reason).toMatch(/inactive/i);
    });

    it('is deterministic: one hundred runs, byte-identical output', () => {
        const catalog = [
            entryProducing('vacation_block', [
                finding(placement('2026-08-05'), 'e'),
                finding(placement('2026-08-02'), 'b'),
                finding(cohort(['p-ali'], 'PICU'), 'd'),
                finding(window('2026-08-01', '2026-08-07'), 'c'),
                finding(placement('2026-08-02', 'day'), 'a'),
            ]),
        ];
        const conditions = [condition('c1', 'vacation_block')];
        const first = JSON.stringify(evaluateWith(catalog, scheduleFor(), contextFor(), conditions));

        for (let run = 0; run < 100; run += 1) {
            expect(JSON.stringify(evaluateWith(catalog, scheduleFor(), contextFor(), conditions))).toBe(first);
        }
    });

    it('orders by conditionId, then by location, then by explanation', () => {
        const catalog = [
            entryProducing('a_type', [
                finding(cohort(['p-ali'], 'PICU'), 'cohort'),
                finding(window('2026-08-01', '2026-08-07'), 'window'),
                finding(placement('2026-08-05'), 'later date'),
                finding(placement('2026-08-02', 'night'), 'z'),
                finding(placement('2026-08-02', 'night'), 'a'),
                finding(placement('2026-08-02', 'day'), 'earlier slot'),
            ]),
            entryProducing('b_type', [finding(placement('2026-08-01'), 'first condition wins')]),
        ];

        const out = evaluateWith(catalog, scheduleFor(), contextFor(), [
            condition('c2', 'a_type'),
            condition('c1', 'b_type'),
        ]);

        expect(out.map((v) => `${v.conditionId}:${v.explanation}`)).toEqual([
            'c1:first condition wins',
            'c2:earlier slot',
            'c2:a',
            'c2:z',
            'c2:later date',
            'c2:window',
            'c2:cohort',
        ]);
    });

    it('sortViolations does not mutate its argument', () => {
        const unsorted: Violation[] = [
            { conditionId: 'b', severity: 'hard', location: placement('2026-08-02'), explanation: 'x' },
            { conditionId: 'a', severity: 'hard', location: placement('2026-08-03'), explanation: 'y' },
        ];
        const copy = [...unsorted];

        sortViolations(unsorted);

        expect(unsorted).toEqual(copy);
    });
});

describe('the emission rule — CG-03, never retroactive on a published schedule', () => {
    const horizon = scheduleFor().horizon;

    it('drops a placement violation located in the carry-in tail', () => {
        const kept = emitWithinHorizon(
            [
                { conditionId: 'c', severity: 'hard', location: placement('2026-07-28'), explanation: 'tail' },
                { conditionId: 'c', severity: 'hard', location: placement('2026-08-01'), explanation: 'edge' },
            ],
            horizon,
        );

        expect(kept.map((v) => v.explanation)).toEqual(['edge']);
    });

    it('keeps a window violation that BEGINS in the tail and reaches the horizon', () => {
        const kept = emitWithinHorizon(
            [
                {
                    conditionId: 'c',
                    severity: 'hard',
                    location: window('2026-07-26', '2026-08-01'),
                    explanation: 'straddles the 1st',
                },
            ],
            horizon,
        );

        expect(kept).toHaveLength(1);
    });

    it('drops a window violation lying wholly in the tail', () => {
        const kept = emitWithinHorizon(
            [
                {
                    conditionId: 'c',
                    severity: 'hard',
                    location: window('2026-07-19', '2026-07-25'),
                    explanation: 'last month',
                },
            ],
            horizon,
        );

        expect(kept).toEqual([]);
    });

    it('keeps a cohort violation, which carries no date at all', () => {
        const kept = emitWithinHorizon(
            [
                {
                    conditionId: 'c',
                    severity: 'soft',
                    location: cohort(['p-ali'], 'PICU R2'),
                    explanation: 'spread',
                },
            ],
            horizon,
        );

        expect(kept).toHaveLength(1);
    });

    it('the boundary violation disappears when the horizon moves one day later', () => {
        const boundary: Violation[] = [
            { conditionId: 'c', severity: 'hard', location: placement('2026-08-01'), explanation: 'the 1st' },
        ];

        expect(emitWithinHorizon(boundary, horizon)).toHaveLength(1);
        expect(
            emitWithinHorizon(boundary, { ...horizon, from: d('2026-08-02') }),
        ).toEqual([]);
    });

    it('is applied by evaluate(), not left to each of the twenty-two types', () => {
        const catalog = [
            entryProducing('a_type', [
                finding(placement('2026-07-28'), 'in the tail'),
                finding(placement('2026-08-03'), 'in the horizon'),
            ]),
        ];

        const out = evaluateWith(catalog, scheduleFor(), contextFor(), [condition('c1', 'a_type')]);

        expect(out.map((v) => v.explanation)).toEqual(['in the horizon']);
    });
});

describe('coverage()', () => {
    it('reports what each condition evaluated and what it skipped, with a reason', () => {
        const detail: CoverageDetail = {
            evaluatedWindows: 3,
            skipped: [{ from: d('2026-07-25'), to: d('2026-07-31'), reason: 'no history before 2026-07-25' }],
        };
        const catalog = [entryProducing('count_min', [], detail)];

        const reports = coverageWith(catalog, scheduleFor(), contextFor(), [condition('c1', 'count_min')]);

        expect(reports).toEqual([
            {
                conditionId: 'c1',
                evaluatedWindows: 3,
                skipped: [{ from: '2026-07-25', to: '2026-07-31', reason: 'no history before 2026-07-25' }],
            },
        ]);
    });

    it('resolves typeKeys exactly as evaluate() does — an unknown key throws here too', () => {
        expect(() => coverageWith([], scheduleFor(), contextFor(), [condition('c1', 'nope')])).toThrow(
            UnknownConditionTypeError,
        );
    });

    it('is one producer with two projections: a type cannot skip a window and still fire on it', () => {
        // The single call each type makes returns findings AND coverage together, so the two can
        // never disagree. Asserted structurally: one evaluator invocation serves both projections.
        let calls = 0;
        const catalog: RegistryEntry[] = [
            {
                typeKey: 'count_max',
                implemented: true,
                direction: 'cap',
                locationKind: 'window',
                needsCarryIn: true,
                evaluate: () => {
                    calls += 1;

                    return { findings: [finding(window('2026-08-01', '2026-08-07'))], coverage: NO_SKIPS };
                },
            },
        ];

        const conditions = [condition('c1', 'count_max')];

        expect(evaluateWith(catalog, scheduleFor(), contextFor(), conditions)).toHaveLength(1);
        expect(calls).toBe(1);

        expect(coverageWith(catalog, scheduleFor(), contextFor(), conditions)).toHaveLength(1);
        expect(calls).toBe(2);
    });
});

describe('the JSON Schema, and the validator that runs it', () => {
    it('uses no keyword the validator does not implement', () => {
        const used = keywordsUsedBy(CONTRACT_SCHEMA);
        const known = new Set<string>([...ASSERTION_KEYWORDS, ...ANNOTATION_KEYWORDS]);

        expect([...used].filter((keyword) => !known.has(keyword)).sort()).toEqual([]);
    });

    it('implements no keyword the schema never uses — the other direction', () => {
        const used = keywordsUsedBy(CONTRACT_SCHEMA);

        expect(ASSERTION_KEYWORDS.filter((keyword) => !used.has(keyword))).toEqual([]);
    });

    it('validates each of the three Location members', () => {
        expect(validate('Location', placement('2026-08-03'))).toEqual([]);
        expect(validate('Location', window('2026-08-01', '2026-08-07'))).toEqual([]);
        expect(validate('Location', cohort(['p-ali', 'p-noor'], 'PICU R2'))).toEqual([]);
    });

    it('refuses a location that mixes two members', () => {
        const mixed = { ...placement('2026-08-03'), from: '2026-08-01', to: '2026-08-07' };

        expect(validate('Location', mixed)).not.toEqual([]);
    });

    it('refuses a window location with no contributing duties — WB-03 cannot act on a range', () => {
        const { contributing: _dropped, ...withoutDuties } = window('2026-08-01', '2026-08-07') as Extract<
            Location,
            { kind: 'window' }
        >;

        expect(validate('Location', withoutDuties)).not.toEqual([]);
        expect(validate('Location', { ...withoutDuties, contributing: [] })).not.toEqual([]);
    });

    it('refuses a date that is not a civil Y-m-d', () => {
        expect(validate('Ymd', '2026-8-3')).not.toEqual([]);
        expect(validate('Ymd', '2026-08-03')).toEqual([]);
    });

    it('refuses an unknown property rather than ignoring it', () => {
        const errors = validate('Violation', {
            conditionId: 'c1',
            severity: 'hard',
            location: placement('2026-08-03'),
            explanation: 'x',
            typeKey: 'vacation_block',
        });

        expect(errors.map((error) => error.message).join(' ')).toMatch(/typeKey/);
    });

    it('validates a whole EvaluationContext and Schedule', () => {
        expect(validate('EvaluationContext', JSON.parse(JSON.stringify(contextFor())))).toEqual([]);
        expect(validate('Schedule', JSON.parse(JSON.stringify(scheduleFor())))).toEqual([]);
    });

    it('assertValid throws, naming the path that failed', () => {
        expect(() => assertValid('Ymd', 'nope')).toThrow(/Ymd/);
    });

    /**
     * The schema and `types.ts` are two definitions of one shape, which is the failure class this
     * whole phase spends its care avoiding — and here it is unavoidable, because TypeScript checks
     * the compiler's inputs and a fixture file, a PHP-built context and stdin JSON are not among
     * them. So the duplication is paid for the way `UnitMerge::REFERENCES` pays for its own: a
     * manifest compared in BOTH directions, with an exclusion list that states its reasons.
     *
     * It did not need planting: it went red on its first run, naming `Fixture` — a def written
     * into the schema with no TypeScript type behind it. That is the drift it exists to catch,
     * caught on the day it was written.
     */
    it('names a contract type for every def, and a def for every contract type', () => {
        const contractSources = ['contract/types.ts', 'duty/interval.ts', 'duty/windows.ts', 'calendar/ymd.ts'];
        const declared = new Map<string, string>();

        for (const relative of contractSources) {
            const source = readFileSync(join(import.meta.dirname, '..', 'src', relative), 'utf8');

            for (const match of source.matchAll(/^export (?:interface|type) (\w+)/gm)) {
                declared.set(match[1] as string, relative);
            }
        }

        const defs = Object.keys(CONTRACT_SCHEMA.$defs);

        expect(defs.filter((name) => !declared.has(name))).toEqual([]);

        /** Exported from `contract/types.ts` and deliberately NOT in the schema, with the reason. */
        const NOT_IN_SCHEMA: Record<string, string> = {
            ConditionEvaluator: 'A function. JSON cannot carry one, and the registry is not wire data.',
            ConditionPreview: 'A function, for the same reason.',
            ConditionOutcome:
                'Internal to one evaluator call. It never crosses a boundary — evaluate() and ' +
                'coverage() project it into Violation and CoverageReport, which are both schema-d.',
            LocationKind: 'A registry declaration, not a value any contract payload carries.',
            Location: 'IS in the schema; listed here only if the def is ever removed.',
        };

        const typesSource = readFileSync(join(import.meta.dirname, '..', 'src', 'contract', 'types.ts'), 'utf8');
        const exportedByContract = [...typesSource.matchAll(/^export (?:interface|type) (\w+)/gm)].map(
            (match) => match[1] as string,
        );

        const unschemad = exportedByContract.filter(
            (name) => !defs.includes(name) && NOT_IN_SCHEMA[name] === undefined,
        );

        expect(unschemad).toEqual([]);
    });
});

describe('the fixture corpus', () => {
    const fixtureDir = join(import.meta.dirname, 'fixtures');

    const shapes = JSON.parse(readFileSync(join(fixtureDir, 'contract-shapes.json'), 'utf8')) as {
        name: string;
        why: string;
        expected: Violation[];
    };

    it('contract-shapes.json validates as a fixture, and every part of it validates too', () => {
        expect(validate('Fixture', shapes)).toEqual([]);
        expect(shapes.why.length).toBeGreaterThan(40);
    });

    it('constructs one violation of each Location member', () => {
        expect(new Set(shapes.expected.map((v) => v.location.kind))).toEqual(
            new Set(['placement', 'window', 'cohort']),
        );
    });

    it('sorts its deliberately scrambled expectations into the canonical order', () => {
        // Authored placement → cohort → window; ordered by conditionId first, so it comes back
        // window → cohort → placement. If the file is ever re-ordered this still holds.
        expect(sortViolations(shapes.expected).map((v) => `${v.conditionId}:${v.location.kind}`)).toEqual([
            'c-count-max:window',
            'c-fairness:cohort',
            'c-min-gap:placement',
        ]);

        expect(shapes.expected.map((v) => v.location.kind)).not.toEqual(['window', 'cohort', 'placement']);
    });

    it('README fixes the format, and says the corpus is synthetic permanently', () => {
        const readme = readFileSync(join(fixtureDir, 'README.md'), 'utf8');

        expect(readme).toMatch(/synthetic/i);
        expect(readme).toMatch(/`why`/);
        expect(readme).toMatch(/expectedCoverage/);
    });
});

describe('what the contract deliberately does NOT carry', () => {
    const srcDir = join(import.meta.dirname, '..', 'src');

    it('names AU-02’s templates and constraints as absent, with the reason', () => {
        const types = readFileSync(join(srcDir, 'contract', 'types.ts'), 'utf8');

        expect(types).toMatch(/templates/);
        expect(types).toMatch(/constraints/);
        expect(types).toMatch(/AU-02/);
    });

    /**
     * `timezone` is provenance and fixture identity. An engine that READS one has acquired an
     * instant, and Decision B's whole guarantee — no `Date`, no instant, so no timezone trap — is
     * a property of the code rather than of a test that remembers to set `TZ`.
     *
     * MEASURED, per ruling 42, and narrowed once because of what the measurement showed. The
     * obvious needle is the bare word, and it matched `calendar/index.ts`'s docblock sentence
     * declaring that there is no timezone here — naming the forbidden thing in order to forbid it.
     * That is a guard failing on its own explanation, not on a defect. The two needles below match
     * a READ instead: any receiver's property access, and the destructuring form.
     *
     * STATED RESIDUAL: bracket access, `context['timezone']`, is not matched. The needle for it is
     * the quoted word, which already appears in `schema.ts`'s `required` array — so buying it costs
     * the first allow-list entry in a scan whose emptiness is the point, and it is not bought.
     *
     * PLANTED: `const zone = context.timezone;` was added to `coverage.ts`, this test went red
     * naming the file, and it was reverted.
     */
    it('never reads context.timezone — it is provenance, and reading one acquires an instant', () => {
        const readers = sourceFilesUnder(srcDir)
            .filter((relative) => {
                const source = readFileSync(join(srcDir, relative), 'utf8');

                return source.includes('.timezone') || source.includes('{ timezone');
            })
            .sort();

        expect(readers).toEqual([]);
    });

    it('scans the whole package, so a new module is guarded by default', () => {
        const scanned = sourceFilesUnder(srcDir);

        expect(scanned).toContain('evaluate.ts');
        expect(scanned).toContain(join('contract', 'types.ts'));
        expect(scanned.length).toBeGreaterThanOrEqual(9);
    });
});

/** Every `.ts` under a directory, repository-relative to it. A hardcoded list stops guarding. */
function sourceFilesUnder(dir: string): string[] {
    return readdirSync(dir, { recursive: true, encoding: 'utf8' }).filter((entry) => entry.endsWith('.ts'));
}
