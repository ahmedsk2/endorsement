/**
 * `rolling_hours_max` — CG-07: *"Max hours per rolling window | hours; window; averaging weeks"*.
 *
 * **P2 Task 9 landed the parameters and the preview; Task 18 lands the predicate.**
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
 * Saturday hours here (`DUTY_DATE_READING.rolling_hours_max`), through `minutesOfIntervalOn` — the
 * same reading `onDutyMinutesOn` delegates to, over the interval `orderedDutiesFor` already resolved.
 *
 * ## It is a CAP with an AUTHORED limit, so owner decision L applies in its simple form
 *
 * A window the engine can only see part of holds fewer minutes than really happened, and a total
 * that is short cannot exceed a number a department wrote down. So every window that touches the
 * horizon is measured, clipped ones included, and the unexaminable left edge is reported once by
 * {@link carryInLeftEdge} rather than per window. `wholeWindowVerdict` is deliberately NOT called —
 * `count_max`'s shape, and for `count_max`'s reason.
 *
 * **`call_frequency_max`, landed in the same task, is the counter-example and is worth reading
 * beside this**: it is a cap too, and its limit is computed from the window's own contents, so a
 * partial window shrinks the allowance alongside the count and it must decline one. The dividing
 * line is not cap-versus-floor; it is whether the number being compared against was AUTHORED.
 *
 * ## `countsHours: false` slots are excluded ENTIRELY, not weighted
 *
 * SL-01's flag is a statement about the slot rather than about the hours in it, and the plan's own
 * sentence is *"excluded entirely"*. A non-counting slot contributes nothing to the total and
 * nothing to `contributing` — a badge naming an administrative session as the reason a duty-hours
 * cap was breached would be wrong in the one way a scheduler cannot check by eye.
 *
 * ## PLANTED
 *
 * `personInScope` answering `true` (the standing FIRST plant for a new window-located type, after
 * five of Tasks 15-17's forty-seven stayed green on exactly that); the `countsHours` filter
 * removed; the split-at-midnight attribution replaced by the anchor-date one, so a night's whole
 * twelve hours land on the date it starts; `effectiveCap` replaced by the authored figures, so the
 * averaging multiplies neither number; and the enumeration started at `horizon.from` instead of
 * `lengthDays - 1` days before it, so the window straddling the seam disappears. Each went red
 * naming its own case.
 */

import { datesBetween } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    SkippedWindow,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { minutesOfIntervalOn, type Duty } from '../duty/interval';
import { slotIndex } from '../duty/order';
import { enumerateWindows } from '../duty/windows';
import { carryInLeftEdge, dutyStreams, orderedByPerson, personInScope, rosterFor } from './support';

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

/** Minutes in one hour. Named because the cap arrives in hours and the line is measured in minutes. */
const MINUTES_PER_HOUR = 60;

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const cap = effectiveCap(params);
    const slots = slotIndex(context.slots);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const findings: Finding[] = [];
    const skipped: SkippedWindow[] = [...carryInLeftEdge(context, schedule.horizon, messages)];
    const windows = enumerateWindows('rolling', cap.windowDays, schedule.horizon);

    // Resolved once per person for the WHOLE evaluation rather than once per window. A rolling
    // window per day over a month is thirty-seven of them, and `orderedDutiesFor` scans all three
    // streams and sorts, so asking inside the loop was thirty-seven identical sorts per person —
    // 34.6 ms of NF-01's 58 ms budget on a schedule of ninety-three duties (P2-2 review). Lazy, so
    // the set of resolutions is unchanged and a person the scope excludes is still never resolved.
    const ordered = orderedByPerson(streams, slots);

    let evaluated = 0;

    for (const window of windows) {
        evaluated += 1;

        const dates = datesBetween(window.from, window.to);

        for (const person of roster) {
            if (!personInScope(person, window.from, condition.scope)) {
                continue;
            }

            let minutes = 0;
            const contributing: Duty[] = [];

            // NO EARLY EXIT, in either loop. Stopping once the cap is passed would leave
            // `contributing` naming some of the duties that got there rather than all of them, and
            // a scheduler told a window is over budget and shown two of its four placements has
            // been handed a shape of the problem instead of the problem (Task 10's finding).
            for (const positioned of ordered(person.key)) {
                if (!positioned.slot.countsHours) {
                    continue;
                }

                let inWindow = 0;

                for (const date of dates) {
                    inWindow += minutesOfIntervalOn(positioned.interval, date);
                }

                if (inWindow === 0) {
                    continue;
                }

                minutes += inWindow;
                contributing.push(positioned.duty);
            }

            if (minutes <= cap.hours * MINUTES_PER_HOUR) {
                continue;
            }

            findings.push({
                location: {
                    kind: 'window',
                    personKey: person.key,
                    from: window.from,
                    to: window.to,
                    contributing,
                },
                explanation: messages.rollingHoursMaxViolation({
                    minutes,
                    hours: cap.hours,
                    windowDays: cap.windowDays,
                    averagingWeeks: params.averagingWeeks,
                    from: window.from,
                    to: window.to,
                }),
            });
        }
    }

    return { findings, coverage: { evaluatedWindows: evaluated, skipped } };
};
