/**
 * `rolling_hours_max` — CG-07: *"Max hours per rolling window | hours; window; averaging weeks"*.
 *
 * **P2 Task 9 lands the parameters and the preview; Task 18 lands the predicate.**
 *
 * ## The averaging is the parameter nobody reads correctly
 *
 * `80 h a week averaged over 4 weeks` is not a weekly cap that may be exceeded and made up later;
 * it is a **320 h cap over any 28 consecutive days**, and a department reading it as the first will
 * run somebody 100 h in a week and believe the rule permitted it. The two readings differ by a
 * multiplication nobody performs while looking at a gate screen, so the preview performs it — the
 * cap is printed at BOTH scales, with the department's own numbers in both.
 *
 * ## What is NOT decided here
 *
 * The numbers themselves. Design §37 still owes the duty-hours policy and no plausible-looking
 * default is invented in its place (P2's "not the duty-hours numbers" scope line); this file fixes
 * the shape a department states them in and nothing else. `windowDays` is a count of days rather
 * than a `'week' | 'period'` enum on purpose: a rolling window has no alignment to a department's
 * week, and naming it `week` would invite one.
 *
 * This is the one type in the catalog reading Decision A's SPLIT-AT-MIDNIGHT attribution — a
 * Friday-night call is one Friday call to every other type and twelve Friday hours plus twelve
 * Saturday hours here (`DUTY_DATE_READING.rolling_hours_max`). Task 18 consumes `onDutyMinutesOn`;
 * nothing in this file needs it.
 */

import type { JsonSchema } from '../contract/schema';
import type { Condition, ConditionPreview } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';

/** `rolling_hours_max`'s parameters. `averagingWeeks` absent means the cap is not averaged. */
export interface RollingHoursMaxParams {
    hours: number;
    windowDays: number;
    averagingWeeks: number | null;
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        hours: { type: 'integer', minimum: 1, description: 'The cap, in hours, over one window.' },
        windowDays: {
            type: 'integer',
            minimum: 1,
            description: 'The rolling window, in consecutive days. Rolling, so it aligns to nothing.',
        },
        averagingWeeks: {
            type: 'integer',
            minimum: 1,
            description: 'How many consecutive windows the cap is averaged over. Absent means none.',
        },
    },
    required: ['hours', 'windowDays'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): RollingHoursMaxParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `rolling_hours_max on condition "${condition.id}"`);

    const params = condition.params as { hours: number; windowDays: number; averagingWeeks?: number };

    return {
        hours: params.hours,
        windowDays: params.windowDays,
        averagingWeeks: params.averagingWeeks ?? null,
    };
}

/**
 * The effective cap once averaging is applied: hours × windows, over days × windows.
 *
 * Exported so Task 18's predicate measures against the same two numbers the sentence printed. A
 * preview that says 320 h while the predicate enforces 80 is worse than no preview.
 */
export function effectiveCap(params: RollingHoursMaxParams): { hours: number; windowDays: number } {
    const windows = params.averagingWeeks ?? 1;

    return { hours: params.hours * windows, windowDays: params.windowDays * windows };
}

/** CG-04's sentence, with the multiplication spelled out. See the module docblock. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const params = readParams(condition);
    const averaged = params.averagingWeeks === null ? null : effectiveCap(params);

    return messages.rollingHoursMax({
        hours: params.hours,
        windowDays: params.windowDays,
        averagingWeeks: params.averagingWeeks,
        averagedHours: averaged?.hours ?? null,
        averagedDays: averaged?.windowDays ?? null,
    });
};
