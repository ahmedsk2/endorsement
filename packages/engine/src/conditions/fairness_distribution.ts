/**
 * `fairness_distribution` — CG-07: *"Even spread across colleagues | quantity; tolerance"*.
 *
 * **P2 Task 9 lands the parameters, the tolerance rule and the preview; Task 19 lands the
 * predicate.**
 *
 * ## Owner decision Q, answered 2026-08-20, and the floor that is not decoration
 *
 * The tolerance is **proportional at 10% with a floor of 1**:
 * `tolerance = max(1, ceil(0.1 × proRatedTarget))`. {@link toleranceFor} is the only definition of
 * that in this repository, and Task 19's predicate calls it rather than repeating it.
 *
 * The floor must not be dropped as a simplification. A bare 10% is STRICTER than the absolute-1
 * default it replaced, for every target under ten: a tenth of a four-weekend target is 0.4, which
 * floors to a tolerance of ZERO, so the condition would reject any unevenness at all on a small
 * roster — the opposite of the slack the answer was chosen for. With the floor it behaves as
 * intended in both regimes: *within one* where a tenth is meaningless, and real proportional slack
 * above ten.
 *
 * ## WHERE THE FLOOR ACTUALLY BITES, measured rather than assumed (P2 Task 9)
 *
 * The decision's justification — *"0.4 floors to a tolerance of zero"* — describes rounding DOWN,
 * and the formula it states rounds UP. With `ceil`, `0.1 × 4` is already 1, so the `max(1, …)`
 * changes the answer at exactly one place: a pro-rated target of **zero**, which is a real input
 * (a person whose eligible days are all leave, or a quantity with no duties in the schedule at
 * all). This was found by PLANTING the floor's removal and watching the suite stay GREEN, which is
 * the finding: an expected share of 4 does not distinguish the two formulas and cannot prove the
 * floor. `toleranceFor(0)` is what does, and it is asserted.
 *
 * **The floor therefore stays, and this paragraph is why.** It is one character from becoming
 * load-bearing across the whole under-ten range again — `Math.round(0.1 * 4)` is 0, and `round` is
 * the more natural thing for the next author to reach for. Deleting a redundant-looking guard
 * beside a rounding mode nobody wrote down is exactly how the regime the answer overrode comes
 * back.
 *
 * ## Why the preview prints two worked numbers rather than one applied number
 *
 * Decision Q requires the preview to state the tolerance **as a number, never as `10%`** — a reader
 * told `10%` on a four-duty target predicts 0.4, and would expect the condition to permit nothing.
 *
 * The applied number depends on the pro-rated target, which depends on the SCHEDULE: how many
 * duties of the quantity exist, and each person's share of the eligible days. `ConditionPreview`
 * receives the condition and the context and — correctly — not the schedule: CG-04 previews a RULE
 * on the gate screen, before any draft exists, and a preview that changed as a draft was edited
 * would be a different artifact. So the sentence prints the tolerance FUNCTION as two worked points
 * spanning both regimes ({@link PREVIEW_EXAMPLE_SHARES}), which removes the mis-prediction the
 * decision names without pretending to a number nothing has computed yet. The applied number
 * belongs in the violation's own explanation, where the target is known, and Task 19 owes it there.
 *
 * ## The base is pro-rated, not raw
 *
 * From `eligibleDays` (owner decision Q's unchanged half). Raw counts flag the person on leave as
 * under-loaded, and a solver's fix for that is to overload the few days they were available.
 */

import type { JsonSchema } from '../contract/schema';
import type { Condition, ConditionPreview } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';

/** `fairness_distribution`'s parameters. */
export interface FairnessDistributionParams {
    quantity: string;
    mode: 'deviation' | 'spread';
    excludeExternal: boolean;
}

/**
 * `tolerance` is absent from this schema, and its absence is a DECISION.
 *
 * CG-07's parameters cell names it, and owner decision Q's superseded default made it an authored
 * number defaulting to 1. The answer replaced the number with a RULE — proportional, floored — and
 * a rule and a number cannot both be authoritative. Leaving the key here as an optional override
 * would mean a department could set a tolerance of 0 and re-acquire the exact defect the floor
 * exists to prevent. If an override is ever wanted it is additive, and it arrives with a floor.
 */
export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        quantity: {
            type: 'string',
            description: "SL-01's tally key — weekends, nights, holidays. Opaque: P3 owns the vocabulary.",
        },
        mode: {
            enum: ['deviation', 'spread'],
            description: 'deviation names WHO is over or under; spread measures only the widest gap.',
        },
        excludeExternal: {
            type: 'boolean',
            description: 'Whether people flagged external are left out of the comparison.',
        },
    },
    required: ['quantity', 'mode', 'excludeExternal'],
    additionalProperties: false,
};

/** The two expected shares the preview works through: one either side of where the floor stops biting. */
export const PREVIEW_EXAMPLE_SHARES: readonly number[] = [4, 40];

/**
 * Owner decision Q's tolerance: `max(1, ceil(0.1 × proRatedTarget))`.
 *
 * One definition, called by the sentence and by the predicate. Two would be two answers to *"how
 * uneven is too uneven"*, and the one that appeared on the gate screen would not be the one that
 * blocked a publish.
 */
export function toleranceFor(proRatedTarget: number): number {
    return Math.max(1, Math.ceil(0.1 * proRatedTarget));
}

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): FairnessDistributionParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `fairness_distribution on condition "${condition.id}"`);

    return condition.params as unknown as FairnessDistributionParams;
}

/** CG-04's sentence, with the allowance as a number at both regimes. See the module docblock. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const params = readParams(condition);

    return messages.fairnessDistribution({
        quantity: params.quantity,
        mode: params.mode,
        excludeExternal: params.excludeExternal,
        examples: PREVIEW_EXAMPLE_SHARES.map((share) => ({ share, allowance: toleranceFor(share) })),
    });
};
