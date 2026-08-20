/**
 * `evaluate(schedule, context, conditions) → violations[]` — CG-10's sentence, unchanged.
 *
 * Pure: no I/O, no globals, no clock, no instant. Everything it knows arrives in its arguments,
 * "today" included.
 *
 * ## The return type does NOT widen
 *
 * `Violation[]`, exactly, because PU-03's publish dialog *"consumes `violations[]` unchanged"* and
 * a third severity beside CG-05's Hard and CG-06's soft would collide with `class` being authored
 * data. A window a floor could not honestly evaluate is reported by the sibling `coverage()`
 * instead of being smuggled in here as a half-violation. That separation is what lets P2-2 add
 * eleven window- and cohort-located types without touching a shared shape.
 *
 * ## An unresolvable typeKey THROWS, in two distinguishable ways
 *
 * A silently ignored condition is a control that appears to do nothing — the failure shape rulings
 * 41 and 49 cost this codebase three times in two slices, here one layer inside the engine, where
 * the thing appearing to do nothing is a Hard rule a department believes is blocking publication.
 * So:
 *
 *  - {@link UnknownConditionTypeError} — no catalog row carries that key at all. A typo, or a
 *    condition row written against a spec revision this engine has not caught up with.
 *  - {@link UnimplementedConditionTypeError} — a real catalog row this engine does not implement.
 *    The registry's stated reason travels with the throw, so a caller learns why without opening
 *    the spec.
 *
 * Both are raised even when the condition is switched OFF. An unresolvable key is a data defect
 * whether or not somebody has ticked the row's on/off box, and the moment it is ticked back on is
 * the worst moment to discover it.
 *
 * ## Severity is stamped ONCE, by `severity.ts`, from the condition row
 *
 * A type reports WHERE and WHY (a `Finding`); `stampViolation()` turns that into a `Violation` by
 * reading `conditionId`, `severity` and `rank` off the `Condition`. That makes Decision E's *"the
 * engine still never overrides the row"* structural rather than a rule twenty-two separate files
 * each have to remember — and twenty-two chances to override authored data is not a risk worth
 * taking for the sake of a shorter function.
 *
 * The expression lived HERE until P2 Task 9 moved it beside CG-05/CG-06's ordering model, which is
 * the other half of the same fact. It is called from here and defined once; `severity.test.ts`
 * asserts that the violation this pipeline produces is byte-identical to the stamp applied alone,
 * so the two cannot become two.
 *
 * PLANTED: `severity: condition.class` was replaced with the literal `'hard'` — which is what a
 * type asserting its own class amounts to — and the stamping case went red. Reverted.
 *
 * ## The emission rule is applied HERE too, for the same reason
 *
 * CG-03: never retroactive on published schedules. A type may measure freely across the carry-in
 * tail — it must, or every window-measured and pairwise type is systematically wrong on the 1st —
 * and this function decides what may be REPORTED. See {@link locationIsReportable} for why the
 * three location members answer that question differently, which is the one asymmetry in this file
 * that would otherwise be discovered by a fixture rather than read.
 */

import { compareYmd, type Ymd } from './calendar/ymd';
import { withinHorizon, type Horizon } from './duty/windows';
import type {
    Condition,
    ConditionOutcome,
    EvaluationContext,
    Location,
    Schedule,
    Violation,
} from './contract/types';
import { CATALOG, indexCatalog, type RegistryEntry } from './registry';
import { stampViolation } from './severity';

/** No catalog row carries this key. */
export class UnknownConditionTypeError extends Error {
    constructor(
        public readonly typeKey: string,
        public readonly conditionId: string,
    ) {
        super(
            `Condition "${conditionId}" names the type "${typeKey}", which no catalog row carries. ` +
                'A condition the engine cannot resolve is refused rather than skipped: a silently ' +
                'ignored rule is a control that appears to do nothing.',
        );
        this.name = 'UnknownConditionTypeError';
    }
}

/** A real catalog row, deliberately or not yet implemented by this engine. */
export class UnimplementedConditionTypeError extends Error {
    constructor(
        public readonly typeKey: string,
        public readonly conditionId: string,
        reason: string,
    ) {
        super(
            `Condition "${conditionId}" names the catalog type "${typeKey}", which this engine does ` +
                `not implement. ${reason}`,
        );
        this.name = 'UnimplementedConditionTypeError';
    }
}

/** One condition, and what its type produced for it. */
export interface ConditionRun {
    condition: Condition;
    outcome: ConditionOutcome;
}

/**
 * Resolve and run every condition ONCE, producing findings and coverage together.
 *
 * `evaluate()` and `coverage()` are two projections of this one list, never two traversals. Two
 * independent runs could disagree — a type reporting a window as skipped in one and firing on it
 * in the other — and that disagreement is invisible on a green suite, which is precisely the class
 * of defect `coverage()` exists to make visible.
 */
export function runConditions(
    catalog: readonly RegistryEntry[],
    schedule: Schedule,
    context: EvaluationContext,
    conditions: readonly Condition[],
): ConditionRun[] {
    const byTypeKey = indexCatalog(catalog);

    return conditions.map((condition) => {
        const entry = byTypeKey.get(condition.typeKey);

        if (entry === undefined) {
            throw new UnknownConditionTypeError(condition.typeKey, condition.id);
        }

        if (!entry.implemented || entry.evaluate === undefined) {
            throw new UnimplementedConditionTypeError(
                condition.typeKey,
                condition.id,
                entry.notImplementedBecause ?? 'No reason is recorded in the registry, which is itself a defect.',
            );
        }

        if (!condition.active) {
            return { condition, outcome: nothingEvaluated(schedule.horizon) };
        }

        return { condition, outcome: entry.evaluate(condition, schedule, context) };
    });
}

/**
 * What an inactive condition produces: no findings, and a stated reason covering the whole horizon.
 *
 * CG-01's on/off is a legitimate control and turning it off must do exactly what it says. It must
 * also be VISIBLE that it did: a condition contributing nothing because somebody switched it off
 * and a condition contributing nothing because it silently failed to resolve look identical in a
 * violation list, and only one of them is fine.
 */
function nothingEvaluated(horizon: Horizon): ConditionOutcome {
    return {
        findings: [],
        coverage: {
            evaluatedWindows: 0,
            skipped: [
                {
                    from: horizon.from,
                    to: horizon.to,
                    reason: 'The condition is inactive (CG-01 on/off), so nothing was evaluated.',
                },
            ],
        },
    };
}

/**
 * Evaluate against a caller-supplied catalog.
 *
 * Exported because the contract's own properties — the ordering, the emission rule, the two
 * throws, the severity stamp — are properties of THIS function and had to be assertable before a
 * single predicate existed. P3 gets a second use out of it: evaluating a draft against a restricted
 * catalog. {@link evaluate} is the supported entry point and passes the shipped registry.
 */
export function evaluateWith(
    catalog: readonly RegistryEntry[],
    schedule: Schedule,
    context: EvaluationContext,
    conditions: readonly Condition[],
): Violation[] {
    const violations: Violation[] = [];

    for (const { condition, outcome } of runConditions(catalog, schedule, context, conditions)) {
        for (const finding of outcome.findings) {
            violations.push(stampViolation(condition, finding));
        }
    }

    return sortViolations(emitWithinHorizon(violations, schedule.horizon));
}

/** CG-10, against the shipped catalog. */
export function evaluate(
    schedule: Schedule,
    context: EvaluationContext,
    conditions: readonly Condition[],
): Violation[] {
    return evaluateWith(CATALOG, schedule, context, conditions);
}

/**
 * May a violation at this location be REPORTED, given what is being evaluated?
 *
 * The three members answer differently, and the asymmetry is deliberate:
 *
 *  - **placement** — the date must be inside `[from, to]`. A duty in last month's published
 *    schedule is context; re-reporting it is exactly what CG-03 forbids.
 *  - **window** — the window must TOUCH `[from, to]`. A window that begins in the tail and reaches
 *    the 1st constrains a duty on the 1st, which is the horizon-edge case a mid-month corpus never
 *    exercises and the reason `enumerateWindows` starts before the horizon at all. Requiring
 *    containment here would delete every one of those, silently, at the left edge.
 *  - **cohort** — always. It carries no date: *"this level is unevenly loaded"* is a statement
 *    about the schedule under evaluation, not about a day in it.
 *
 * PLANTED at P2 Task 7: the window branch was rewritten as containment
 * (`withinHorizon(from) && withinHorizon(to)`) — the reading a careful author arrives at from the
 * words *"falls inside"* — and the straddling-window case went red while every other case in the
 * file stayed green. That is the whole hazard: containment is silently correct for eleven types
 * and silently deletes the left edge for eight.
 */
export function locationIsReportable(location: Location, horizon: Horizon): boolean {
    switch (location.kind) {
        case 'placement':
            return withinHorizon(horizon, location.date);
        case 'window':
            return compareYmd(location.to, horizon.from) >= 0 && compareYmd(location.from, horizon.to) <= 0;
        case 'cohort':
            return true;
        default: {
            const unreachable: never = location;

            throw new RangeError(`Unknown location kind: ${JSON.stringify(unreachable)}.`);
        }
    }
}

/** The emission rule over a whole list. Returns a new array; the argument is untouched. */
export function emitWithinHorizon(violations: readonly Violation[], horizon: Horizon): Violation[] {
    return violations.filter((violation) => locationIsReportable(violation.location, horizon));
}

const LOCATION_KIND_ORDER: Record<Location['kind'], number> = { placement: 0, window: 1, cohort: 2 };

/**
 * A total order over locations, so a fixture comparison is stable across runs and runtimes.
 *
 * Total rather than merely consistent: two violations that tie on every field a human would think
 * of still have to order the same way in Node and in the browser, or a corpus comparison fails for
 * a reason that has nothing to do with the rule under test.
 */
export function compareLocations(a: Location, b: Location): number {
    const byKind = LOCATION_KIND_ORDER[a.kind] - LOCATION_KIND_ORDER[b.kind];

    if (byKind !== 0 || a.kind !== b.kind) {
        return byKind;
    }

    if (a.kind === 'placement' && b.kind === 'placement') {
        return (
            compareText(a.personKey, b.personKey) ||
            compareDate(a.date, b.date) ||
            compareText(a.slotKey, b.slotKey)
        );
    }

    if (a.kind === 'window' && b.kind === 'window') {
        return (
            compareText(a.personKey, b.personKey) || compareDate(a.from, b.from) || compareDate(a.to, b.to)
        );
    }

    if (a.kind === 'cohort' && b.kind === 'cohort') {
        return compareText(a.scopeLabel, b.scopeLabel) || compareText(a.personKeys.join('|'), b.personKeys.join('|'));
    }

    return 0;
}

/** Order by condition, then by location, then by explanation. Returns a NEW array. */
export function sortViolations(violations: readonly Violation[]): Violation[] {
    return [...violations].sort(
        (a, b) =>
            compareText(a.conditionId, b.conditionId) ||
            compareLocations(a.location, b.location) ||
            compareText(a.explanation, b.explanation),
    );
}

function compareText(a: string, b: string): number {
    return a < b ? -1 : a > b ? 1 : 0;
}

function compareDate(a: Ymd, b: Ymd): number {
    return compareYmd(a, b);
}
