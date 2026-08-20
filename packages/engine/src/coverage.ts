/**
 * `coverage(schedule, context, conditions) → CoverageReport[]` — the sibling of `evaluate()`.
 *
 * ## Why this is a separate function and not a field on the violation list
 *
 * Two reasons, and the first is the load-bearing one.
 *
 * **1. `evaluate()`'s return type must not widen.** CG-10 fixes it as `violations[]`; PU-03's
 * publish dialog consumes it unchanged; and every type P2-2 adds is then additive, because the
 * shared shape it adds to never moves. A `{ violations, skipped }` return would have made the
 * eleven window- and cohort-located types a change to the contract rather than eleven new
 * predicates behind it — which is the difference between CG-10's *"new types are additive"* being
 * true and being aspirational.
 *
 * **2. A skipped window is not a violation and must never grade like one.** A CAP under-counts on
 * a partial window and produces no false positive, so it may evaluate one. A FLOOR or a TARGET
 * false-positives on EVERY partial window — a four-week average has a partial window on roughly
 * the first 27 days of any month — so floors and targets evaluate only on windows fully inside
 * `[evaluableFrom, evaluableTo]` (owner decision L). That leaves windows deliberately unevaluated,
 * and **a silently dropped window is a guard that looks green**: it is indistinguishable, on a
 * green suite, from a window that was evaluated and passed. So the drop is REPORTED.
 *
 * This has real consumers in P2-1 already, before any window-measured type exists: `min_gap`,
 * `post_duty_exclusion` and `consecutive_max` all have an unevaluable left edge when `priorDuties`
 * is empty, and each has to say so rather than quietly treat the 1st as the start of time.
 *
 * It is a pure function beside `evaluate()` in the same shape `AvailabilitySummary` sits beside
 * `RotaGrid` — not a field on it.
 *
 * ## One producer, two projections
 *
 * Both functions read the SAME `runConditions()` list, in which each type answered once and
 * returned its findings and its coverage together. Two independent traversals could disagree — a
 * type reporting a window as skipped here while still firing on it there — and that disagreement
 * would be invisible on a green suite, which is the exact failure this function exists to prevent
 * one level down.
 */

import type {
    Condition,
    CoverageReport,
    EvaluationContext,
    Schedule,
    ViolationMessages,
} from './contract/types';
import { runConditions } from './evaluate';
import { EN } from './messages';
import { CATALOG, type RegistryEntry } from './registry';

/**
 * Report coverage against a caller-supplied catalog. See `evaluateWith` for why this is exported.
 *
 * Type keys resolve exactly as they do in `evaluate()` — an unknown key throws here too, from the
 * same code — so a caller cannot learn about a broken condition from one function and not the
 * other.
 */
export function coverageWith(
    catalog: readonly RegistryEntry[],
    schedule: Schedule,
    context: EvaluationContext,
    conditions: readonly Condition[],
    messages: ViolationMessages = EN,
): CoverageReport[] {
    return runConditions(catalog, schedule, context, conditions, messages).map(({ condition, outcome }) => ({
        conditionId: condition.id,
        evaluatedWindows: outcome.coverage.evaluatedWindows,
        skipped: outcome.coverage.skipped,
    }));
}

/**
 * What each condition actually measured, and what it had to leave alone, against the registry.
 *
 * A skipped window's `reason` comes from the SAME table `evaluate()`'s explanations come from
 * (AR-07, P2-2 Task 1). It is text a scheduler reads beside the violations, and a sentence that is
 * English wherever it happens to be assembled is not externalized because its neighbour is.
 */
export function coverage(
    schedule: Schedule,
    context: EvaluationContext,
    conditions: readonly Condition[],
    messages: ViolationMessages = EN,
): CoverageReport[] {
    return coverageWith(CATALOG, schedule, context, conditions, messages);
}
