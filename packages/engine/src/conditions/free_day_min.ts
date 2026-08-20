/**
 * `free_day_min` — CG-07: *"One fully free day in N | N; averaged weeks"*.
 *
 * **The second absence-shaped type**, and the one whose defining word is doing all the work.
 *
 * ## *"Fully free"* means NO ON-DUTY MINUTE, not "no duty row dated that day"
 *
 * This is the OCCUPIED-INTERVAL reading (`DUTY_DATE_READING.free_day_min`) and it is materially
 * stronger than the anchor-date one every neighbouring type uses. A 24 h call starting on the 5th
 * ends on the 6th, so the person was in the hospital on the 6th and the 6th is not a free day —
 * even though no duty row is dated the 6th at all. The two readings differ on exactly the rota a
 * department runs when it is short, which is when this rule earns its keep, and the fixture that
 * separates them is the plan's own: a 24 h call on the 5th with nothing on the 6th.
 *
 * ## The averaging is `rolling_hours_max`'s multiplication, and it multiplies BOTH numbers
 *
 * *"One free day in 7, averaged over 4 weeks"* is *"at least 4 free days in any 28 consecutive
 * days"* — not *"each 7-day window has one"* and not *"the mean is one"*. A window of `n` days
 * requiring one free day becomes a window of `n × weeks` days requiring `weeks` of them, and the
 * preview prints both scales because nobody performs that multiplication while looking at a gate
 * screen.
 *
 * ## It is a FLOOR, so owner decision L applies unchanged
 *
 * A window the engine can only see part of has fewer duties in it than really happened, so it
 * over-counts free days — which for a floor is the harmless direction — but it also has fewer DAYS
 * that were ever examined. Either way a partial window is not the window the rule names, and a
 * floor judged on one false-positives on every edge of every month. `wholeWindowVerdict` is the
 * shared gate; the windows it declines are reported, because a silently dropped window is a guard
 * that looks green.
 *
 * ## Leave counts as free, and that is a PARAMETER
 *
 * Defaulting true: a day nobody could have scheduled you is a day you were not at work. A
 * department reading the rule as *"a day off that is not annual leave"* sets it false, and the
 * violation sentence says which reading produced the number — the two differ only for a person on
 * leave, who is precisely the person a reader would query.
 *
 * ## No `kinds` parameter
 *
 * CG-07's cell is *"N; averaged weeks"* and names none. A day is free of ALL duty or it is not
 * free; a rule that let one kind of duty leave a day "free" would be describing something other
 * than rest, and inventing the parameter is inventing policy.
 *
 * ## PLANTED
 *
 * The anchor-date reading in place of the occupied-interval one, so a 24 h call stops occupying the
 * following date; `leaveCountsAsFree` ignored in each direction; the averaging applied to the window
 * and not to the requirement; the floor evaluating a partial window; and the carry-in tail dropped
 * so a window reaching into the published month counts its days as free. Each went red naming its
 * own case.
 *
 * **CG-01's SCOPE was the plant that stayed green on all four window types at once.** Deleting
 * `personInScope` from this module changed nothing anywhere in the corpus, because no case of this
 * type set a scope — P2-1 review's thirteen-instance finding, reappearing on `max_gap`,
 * `free_day_min`, `composition` and `target_per_period` together, one task after `count_max` was
 * caught by exactly the same probe. Each is now closed by a third person in its own defining
 * fixture who would be flagged on their own figures and is excluded by the scope alone.
 */

import { compareYmd, datesBetween } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    SkippedWindow,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { occupiedDates } from '../duty/interval';
import { orderedDutiesFor, slotIndex } from '../duty/order';
import { enumerateWindows, windowLengthDays } from '../duty/windows';
import {
    carryInLeftEdge,
    dutyStreams,
    personInScope,
    rosterFor,
    wholeWindowVerdict,
} from './support';

/** `free_day_min`'s parameters, normalised. `averagingWeeks` absent means the rule is not averaged. */
export interface FreeDayMinParams {
    n: number;
    averagingWeeks: number | null;
    leaveCountsAsFree: boolean;
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        n: {
            type: 'integer',
            minimum: 1,
            description: 'One fully free day in every N consecutive days. Rolling, so it aligns to nothing.',
        },
        averagingWeeks: {
            type: 'integer',
            minimum: 1,
            description:
                'How many such windows the rule is averaged over. Absent means none. Both the ' +
                'window and the number of free days required are multiplied by it.',
        },
        leaveCountsAsFree: {
            type: 'boolean',
            description: 'Whether a day on leave counts as a free day. Defaults TRUE.',
        },
    },
    required: ['n'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): FreeDayMinParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `free_day_min on condition "${condition.id}"`);

    const params = condition.params as {
        n: number;
        averagingWeeks?: number;
        leaveCountsAsFree?: boolean;
    };

    return {
        n: params.n,
        averagingWeeks: params.averagingWeeks ?? null,
        leaveCountsAsFree: params.leaveCountsAsFree ?? true,
    };
}

/**
 * The rule at the scale it is actually enforced: a longer window needing proportionally more.
 *
 * Exported so the predicate measures against the two numbers the sentence printed. A preview
 * promising *"4 free days in 28"* while the predicate enforces one in seven is worse than no
 * preview, and the two are a multiplication apart.
 */
export function effectiveRule(params: FreeDayMinParams): { windowDays: number; freeDays: number } {
    const windows = params.averagingWeeks ?? 1;

    return { windowDays: params.n * windows, freeDays: windows };
}

/** CG-04's sentence, with the multiplication spelled out at both scales. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const params = readParams(condition);
    const averaged = params.averagingWeeks === null ? null : effectiveRule(params);

    return messages.freeDayMin({
        n: params.n,
        averagingWeeks: params.averagingWeeks,
        averagedDays: averaged?.windowDays ?? null,
        averagedFreeDays: averaged?.freeDays ?? null,
        leaveCountsAsFree: params.leaveCountsAsFree,
    });
};

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const rule = effectiveRule(params);
    const slots = slotIndex(context.slots);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const findings: Finding[] = [];
    const skipped: SkippedWindow[] = [...carryInLeftEdge(context, schedule.horizon, messages)];
    const windows = enumerateWindows('rolling', rule.windowDays, schedule.horizon);

    let evaluated = 0;

    for (const window of windows) {
        const verdict = wholeWindowVerdict(window, context, schedule.horizon, messages);

        if (!verdict.measure) {
            if (verdict.skip !== null) {
                skipped.push(verdict.skip);
            }

            continue;
        }

        evaluated += 1;

        for (const person of roster) {
            if (!personInScope(person, window.from, condition.scope)) {
                continue;
            }

            const leave = new Set<string>(params.leaveCountsAsFree ? [] : person.leaveDays);
            const occupied = new Set<string>();
            const contributing = [];

            // Every duty of this person is expanded to the DATES ITS INTERVAL TOUCHES, which is the
            // whole difference between this type and its neighbours. No early exit: a window with
            // one free day and a window with none are the same answer to `some()` and different
            // answers to this rule the moment `freeDays` is above one.
            for (const positioned of orderedDutiesFor(person.key, streams, slots)) {
                const touched = occupiedDates(positioned.duty, positioned.slot).filter(
                    (date) => compareYmd(date, window.from) >= 0 && compareYmd(date, window.to) <= 0,
                );

                if (touched.length === 0) {
                    continue;
                }

                contributing.push(positioned.duty);

                for (const date of touched) {
                    occupied.add(date);
                }
            }

            const free = datesBetween(window.from, window.to).filter(
                (date) => !occupied.has(date) && !leave.has(date),
            );

            if (free.length >= rule.freeDays) {
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
                explanation: messages.freeDayMinViolation({
                    free: free.length,
                    required: rule.freeDays,
                    windowDays: windowLengthDays(window),
                    from: window.from,
                    to: window.to,
                    leaveCountsAsFree: params.leaveCountsAsFree,
                }),
            });
        }
    }

    return { findings, coverage: { evaluatedWindows: evaluated, skipped } };
};
