/**
 * `composition` — CG-07: *"Weekday/weekend mix per person | level→{WD,WE}"*.
 *
 * ## Owner decision N: the buckets are `{WD, WE, HOL}`, and `HOL` is OPTIONAL
 *
 * `Calendar::dayType()` is THREE-valued and makes a holiday win over a weekend deliberately — *"a
 * coverage template that asks for holiday staffing must get it on a holiday that happens to fall on
 * a Friday"*. So CG-07's two-bucket cell, taken literally, would silently drop every holiday duty
 * this rule is measuring: green on exactly the days it most matters, and invisible in review
 * because the two remaining numbers still add up to something plausible.
 *
 * Decision N's answer is an optional third bucket and a FOLD, never a flatten:
 *
 *  - a target naming `HOL` measures holiday duties in their own bucket;
 *  - a target NOT naming it folds them into `WE`, which is where a department that has never
 *    thought about holidays already believes they are;
 *  - `dayType()` itself is never re-derived and never reduced to two values. The day vector arrives
 *    precomputed (AR-08) and this type reads it.
 *
 * The fold is stated in the violation sentence rather than left to be inferred, because a reader
 * counting weekend duties by eye and getting a different number from the badge has no way to find
 * out which of them is wrong.
 *
 * ## The window is the PERIOD, stated rather than inherited
 *
 * CG-07 does not say, and a *"mix"* measured over a week and over a block are different rules with
 * the same numbers. Owner decision N fixes it at the period, so it is written here rather than
 * inherited from whichever type was implemented first.
 *
 * ## It is a TARGET, so it is two-sided and it declines a window it cannot see all of
 *
 * Owner decision L, unchanged from `target_per_period`: a partial window under-counts every bucket,
 * which is a false positive on a target every time. The gate and its per-person half — somebody who
 * joined part way through the block — are `wholeWindowVerdict` and `midWindowJoinSkip`, shared
 * rather than re-decided.
 *
 * ## A level absent from the map has NO target
 *
 * Not a mix of zeroes. The map names the levels the rule is about, exactly as `target_per_period`'s
 * does, and a consultant with no entry would otherwise be told to take no duties at all.
 *
 * ## Duty→date reading: ANCHOR DATE
 *
 * `DUTY_DATE_READING.composition`. A Friday-night call running into Saturday is one FRIDAY duty and
 * lands in Friday's bucket; splitting it would let one duty be half a weekend duty, which no
 * department's idea of a mix admits.
 *
 * ## PLANTED
 *
 * `dayType` flattened so a holiday counts as a weekend even when the target names `HOL`; the fold
 * removed so a holiday duty is dropped when it does not; the unmapped level defaulting to a mix of
 * zeroes; the two-sided comparison reduced to a cap; and the carry-in tail dropped from the count.
 * Each went red naming its own case.
 *
 * **CG-01's SCOPE was the plant that stayed green on all four window types at once.** Deleting
 * `personInScope` from this module changed nothing anywhere in the corpus, because no case of this
 * type set a scope — P2-1 review's thirteen-instance finding, reappearing on `max_gap`,
 * `free_day_min`, `composition` and `target_per_period` together, one task after `count_max` was
 * caught by exactly the same probe. Each is now closed by a third person in its own defining
 * fixture who would be flagged on their own figures and is excluded by the scope alone.
 */

import type { JsonSchema } from '../contract/schema';
import type { DayType } from '../calendar';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    SkippedWindow,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { slotIndex } from '../duty/order';
import {
    carryInLeftEdge,
    dayIndex,
    dutyStreams,
    levelKeyAt,
    midWindowJoinSkip,
    periodWindows,
    personInScope,
    positionedIn,
    rosterFor,
    wholeWindowVerdict,
} from './support';

/** The three buckets, in the order a sentence lists them. `HOL` is owner decision N's optional one. */
export const BUCKETS = ['WD', 'WE', 'HOL'] as const;

/** One bucket key. Identical to {@link DayType} by construction, and that is the point. */
export type Bucket = (typeof BUCKETS)[number];

/** One level's target mix. `HOL` absent means holidays are folded into `WE` (owner decision N). */
export interface CompositionTarget {
    WD: number;
    WE: number;
    HOL?: number;
}

/** `composition`'s parameters. */
export interface CompositionParams {
    targets: Record<string, CompositionTarget>;
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        targets: {
            type: 'object',
            description:
                'Level CODE to the mix of duties expected in one period. A level absent from the ' +
                'map has no target, which is not the same statement as a mix of zeroes.',
            additionalProperties: {
                type: 'object',
                properties: {
                    WD: { type: 'integer', minimum: 0, description: 'Duties anchored on a weekday.' },
                    WE: { type: 'integer', minimum: 0, description: 'Duties anchored on a weekend day.' },
                    HOL: {
                        type: 'integer',
                        minimum: 0,
                        description:
                            'Owner decision N: OPTIONAL. Absent means holiday duties are counted in ' +
                            'WE rather than dropped — dayType() is never flattened to two values.',
                    },
                },
                required: ['WD', 'WE'],
                additionalProperties: false,
            },
        },
    },
    required: ['targets'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): CompositionParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `composition on condition "${condition.id}"`);

    return condition.params as unknown as CompositionParams;
}

/**
 * Which bucket a day's type lands in, GIVEN the target — the fold, and the only place it happens.
 *
 * A holiday is a holiday when the target has somewhere to put it and a weekend day when it does
 * not. It is never a weekday and never nothing, which are the two ways a flatten would lose it.
 */
export function bucketFor(dayType: DayType, target: CompositionTarget): Bucket {
    if (dayType !== 'HOL') {
        return dayType;
    }

    return target.HOL === undefined ? 'WE' : 'HOL';
}

/** The buckets this target actually states. `HOL` is in the list only when the target names it. */
export function bucketsOf(target: CompositionTarget): Bucket[] {
    return target.HOL === undefined ? ['WD', 'WE'] : ['WD', 'WE', 'HOL'];
}

/** CG-04's sentence: the mix per level, and what happens to holidays under each shape of target. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const { targets } = readParams(condition);

    return messages.composition({
        targets: Object.keys(targets)
            .sort()
            .map((levelKey) => {
                const target = targets[levelKey] as CompositionTarget;

                return {
                    levelKey,
                    weekday: target.WD,
                    weekend: target.WE,
                    holiday: target.HOL ?? null,
                };
            }),
    });
};

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const { targets } = readParams(condition);
    const slots = slotIndex(context.slots);
    const days = dayIndex(context);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const findings: Finding[] = [];
    const skipped: SkippedWindow[] = [...carryInLeftEdge(context, schedule.horizon, messages)];

    let evaluated = 0;

    for (const { window } of periodWindows(context, schedule.horizon, 'period')) {
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

            const joinSkip = midWindowJoinSkip(person, window, messages);

            if (joinSkip !== null) {
                skipped.push(joinSkip);

                continue;
            }

            const levelKey = levelKeyAt(person, window.from);
            const target = levelKey === null ? undefined : targets[levelKey];

            if (target === undefined || levelKey === null) {
                continue;
            }

            const positioned = positionedIn(person.key, window, streams, slots);
            const held: Record<Bucket, number> = { WD: 0, WE: 0, HOL: 0 };

            // NO EARLY EXIT: every duty is bucketed even once one bucket is already over, because
            // the sentence names every bucket that is off and a scan that stopped at the first
            // would report a shape of the problem rather than the problem.
            for (const entry of positioned) {
                held[bucketFor(days.get(entry.duty.date).dayType, target)] += 1;
            }

            const off = bucketsOf(target)
                .map((bucket) => ({ bucket, target: target[bucket] as number, actual: held[bucket] }))
                .filter((row) => row.actual !== row.target);

            if (off.length === 0) {
                continue;
            }

            findings.push({
                location: {
                    kind: 'window',
                    personKey: person.key,
                    from: window.from,
                    to: window.to,
                    contributing: positioned.map((entry) => entry.duty),
                },
                explanation: messages.compositionViolation({
                    levelKey,
                    from: window.from,
                    to: window.to,
                    holidaysFolded: target.HOL === undefined,
                    buckets: off,
                }),
            });
        }
    }

    return { findings, coverage: { evaluatedWindows: evaluated, skipped } };
};
