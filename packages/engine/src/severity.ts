/**
 * CG-05/CG-06's severity and rank model: the one stamp, and the one order.
 *
 * ## Two things this file decides, and one it deliberately refuses to
 *
 * **The stamp.** A condition type answers WHERE and WHY — a {@link Finding} — and never how
 * severe. {@link stampViolation} is the single place `Condition.class`, `Condition.rank` and
 * `Condition.id` become fields of a `Violation`, and `evaluate()` calls it rather than repeating
 * it. Decision E's *"the engine still never overrides the row"* is then structural: there is
 * exactly one expression in the package that can set `severity`, and it reads the row. Twenty-two
 * types each remembering the rule would be twenty-two chances to forget it, and the precedent for
 * what two copies of one canonical fact cost is `AuditChain::canonical()`.
 *
 * **The order.** CG-05 puts Hard above everything; CG-06 grades the soft ones by CG-02's drag
 * rank. {@link comparePrecedence} is that, and nothing else.
 *
 * **What it refuses.** NO NUMERIC WEIGHT. AU-02 says the solver minimises violations *"weighted
 * monotonically by rank"* — a weight CURVE (rank 1 is worth eight of rank 5) is the solver's own
 * fact, lives in the solver's repository, and choosing one here would make this the second
 * definition of it before the first exists. So rank ordering is the only input and the comparator
 * returns only `-1`, `0` or `1`; `severity.test.ts` asserts that it never returns a magnitude,
 * which is the assertion that keeps a weight from quietly appearing as "the difference of the
 * ranks".
 *
 * ## Rank on a HARD row is carried, and not acted on
 *
 * The stamp copies `rank` verbatim whatever the class says, because the row is authored data and
 * editing it here would be the override this file exists to prevent. The ORDER ignores it between
 * two hard rows: CG-02's drag orders the soft conditions, hard ones "sit above", and there is no
 * gesture on that screen that ranks one hard row against another. Acting on the number anyway
 * would be the engine inventing an order the gate never offered.
 */

import type { Condition, Finding, Severity, Violation } from './contract/types';

/** Hard first. The only two members CG-05/CG-06 admit, and the only ordering between them. */
const CLASS_ORDER: Record<Severity, number> = { hard: 0, soft: 1 };

/**
 * A condition's own facts, stamped onto what a type found. The ONE place severity is set.
 *
 * `rank` is omitted rather than set to `undefined` when the row carries none: `exactOptionalPropertyTypes`
 * distinguishes them, `JSON.stringify` does not, and PU-03's publish dialog reads the serialised
 * form — so a violation carrying `rank: undefined` would arrive over the wire as a violation with
 * no rank while type-checking as one that has the key. One of those two is honest.
 */
export function stampViolation(condition: Condition, finding: Finding): Violation {
    return {
        conditionId: condition.id,
        severity: condition.class,
        ...(condition.rank === undefined ? {} : { rank: condition.rank }),
        location: finding.location,
        explanation: finding.explanation,
    };
}

/** Just enough of a violation to grade it — so a caller may order condition ROWS with it too. */
export type Graded = Pick<Violation, 'severity'> & { rank?: number | undefined };

/**
 * CG-05/CG-06's order: hard above all soft, soft ascending by CG-02's rank, unranked soft last.
 *
 * Unranked LAST is the deliberate half. An unset drag position is not position zero — sorting it
 * first would give the row nobody ranked the loudest grade in the list, which is the opposite of
 * what an unset field means, and it is exactly the sort of default that reads as correct in review.
 *
 * Returns `-1`, `0` or `1` and never a magnitude. See the module docblock for why that matters.
 */
export function comparePrecedence(a: Graded, b: Graded): number {
    const byClass = Math.sign(CLASS_ORDER[a.severity] - CLASS_ORDER[b.severity]);

    if (byClass !== 0 || a.severity === 'hard') {
        return byClass;
    }

    if (a.rank === b.rank) {
        return 0;
    }

    if (a.rank === undefined) {
        return 1;
    }

    if (b.rank === undefined) {
        return -1;
    }

    return Math.sign(a.rank - b.rank);
}

/**
 * {@link comparePrecedence} over a whole list. Returns a NEW array; the argument is untouched.
 *
 * A STABLE partial order, not a total one: violations of equal precedence keep the order they
 * arrived in. `sortViolations()` in `evaluate.ts` is the canonical TOTAL order — by condition, then
 * location, then explanation — and it is what a fixture comparison uses, because a comparison that
 * depends on input order is a comparison that passes for the wrong reason. This one is for a
 * SCREEN: WB-03 and the CG-01 gate show the loudest thing first.
 */
export function sortByPrecedence(violations: readonly Violation[]): Violation[] {
    return [...violations].sort(comparePrecedence);
}
