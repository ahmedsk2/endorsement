/**
 * `min_gap` — CG-07: *"Minimum spacing between duties | duty kinds; days or hours; value"*.
 *
 * **P2 Task 9 lands the parameters and the preview; Task 14 lands the predicate.** That order is
 * deliberate rather than accidental: owner decision H settles the WORDING of this rule, and the
 * wording is where its defect lives. The predicate that arrives later implements the schema this
 * file already fixes, instead of the schema being back-formed from whatever the predicate did.
 *
 * ## The off-by-one, and why the preview carries a worked example
 *
 * Owner decision H gives the type two readings on one key, which is what CG-07's *"days or hours"*
 * implies:
 *
 *  - **`hours` measures END-to-START** — ACGME's *"10 h between duties"* — and is the
 *    occupied-interval reading of Decision A.
 *  - **`days` measures between the dates the duties START on**, and `N` means **at least N apart**,
 *    so 1 Aug → 4 Aug is legal at `N = 3` and 1 Aug → 3 Aug is not. This is the anchor-date reading.
 *
 * `DUTY_DATE_READING.min_gap` is the only entry in that table carrying TWO readings, for exactly
 * this reason. The two `days` readings — "at least N apart" and "at least N clear days between" —
 * are one character apart in an implementation, are a month of different behaviour on a rota, and
 * are invisible in review. So the preview renders both sides of the boundary on dates rather than
 * stating the number and hoping.
 */

import type { JsonSchema } from '../contract/schema';
import type { Condition, ConditionPreview } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';

/** `min_gap`'s parameters, normalised — `kinds` absent and `kinds: []` both mean "every kind". */
export interface MinGapParams {
    value: number;
    unit: 'days' | 'hours';
    kinds: string[];
}

/**
 * `kinds` names SLOT KINDS, which are opaque strings (Decision A): SL-01's vocabulary is stored
 * nowhere in this repository, so an enum here would be its first and only definition.
 */
export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        value: {
            type: 'integer',
            minimum: 1,
            description: 'Hours end-to-start, or days between the dates the two duties start on.',
        },
        unit: { enum: ['days', 'hours'], description: 'Which of the two readings owner decision H gives.' },
        kinds: {
            type: 'array',
            items: { type: 'string' },
            description: 'Slot kinds this spacing applies between. Absent or empty means every kind.',
        },
    },
    required: ['value', 'unit'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): MinGapParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `min_gap on condition "${condition.id}"`);

    const params = condition.params as { value: number; unit: 'days' | 'hours'; kinds?: string[] };

    return { value: params.value, unit: params.unit, kinds: params.kinds ?? [] };
}

/** CG-04's sentence. The worked example moves with the parameter; see the module docblock. */
export const preview: ConditionPreview = (condition, _context, messages) => messages.minGap(readParams(condition));
