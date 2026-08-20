/**
 * `max_gap` — CG-07: *"Maximum spacing (regular exposure) | duty kinds; days"*.
 *
 * **The first of the two absence-shaped types**: violated by something NOT happening, which is why
 * it is window-located and why it is the harder of the catalog to make actionable. There is no
 * placement to badge — the whole point is the placement that is missing — so the violation is the
 * INTERVAL between two duties, and `contributing` names the pair it was measured between.
 *
 * ## The measurement is `min_gap`'s in the other direction, deliberately
 *
 * Owner decision H settles `min_gap`'s `days` as *"the difference between START DATES, and N means
 * at least N apart"*. This row's cell is the same word in the opposite direction, so `days` means
 * the same thing and `N` means **at most N apart**: 1 Aug then 6 Aug is five apart and legal at
 * `N = 5`; 1 Aug then 7 Aug is six and is not. Two types measuring one quantity in two ways would
 * be a month of different behaviour with no document saying which, and the preview renders the
 * boundary on dates rather than stating the number and hoping — the same device decision H's own
 * off-by-one bought.
 *
 * ## Owner decision I: what stops the clock, and what does not
 *
 * **Leave stops it. `joinedAt` stops it. An off-roster rotation does NOT.** Days a person could not
 * have been on duty are removed from the measured gap, so somebody returning from three weeks'
 * leave is not flagged for the gap their leave created. Off-roster is deliberately excluded because
 * **MR-04's per-person include/exclude override has no column anywhere in this repository**, and
 * inferring one from `units.call_target` — department-level configuration — would be exactly the
 * inference `RotaAccessTest` exists to refuse. The two are one line apart in an implementation and
 * both are fixtured, on one person each, in one world.
 *
 * ## Owner decision I again: an unfinished gap is REPORTED, never evaluated
 *
 * A gap needs two ends. The gap after somebody's last duty has no closing date — evaluating it
 * against the end of the horizon flags nearly every person nearly every month, which is a rule a
 * department switches off in its first week. Ignoring it silently is worse. So it goes to
 * `coverage()`, and **so does its mirror image**: the gap BEFORE somebody's first duty is equally
 * unfinished, and decision I names only the trailing one because the trailing one is the one a
 * scheduler notices. A person with no counted duty at all is one open gap, reported once.
 *
 * The one exception is the shape {@link carryInLeftEdge} already reports: when no history reaches
 * before the horizon, every person's leading gap is open for the same reason and one row says so
 * for all of them. Repeating it per person would print one fact until a reader stopped reading.
 *
 * ## Duty→date reading: ANCHOR DATE
 *
 * `DUTY_DATE_READING.max_gap`. Regular exposure is counted in duties, and a night call running past
 * midnight is one duty on the date it started.
 *
 * ## PLANTED
 *
 * The off-by-one (`>=` for `>`); the `kinds` filter ignored, so an intervening backup duty splits a
 * gap the rule does not count; leave no longer stopping the clock; an off-roster rotation stopping
 * it; the trailing gap evaluated against the end of the horizon; and the carry-in tail dropped so a
 * gap reaching back into the published month is never measured. Each went red naming its own case.
 *
 * **CG-01's SCOPE was the plant that stayed green on all four window types at once.** Deleting
 * `personInScope` from this module changed nothing anywhere in the corpus, because no case of this
 * type set a scope — P2-1 review's thirteen-instance finding, reappearing on `max_gap`,
 * `free_day_min`, `composition` and `target_per_period` together, one task after `count_max` was
 * caught by exactly the same probe. Each is now closed by a third person in its own defining
 * fixture who would be flagged on their own figures and is excluded by the scope alone.
 */

import { compareYmd, datesBetween, diffDays, addDays, type Ymd } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    Person,
    SkippedWindow,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import type { PositionedDuty } from '../duty/order';
import { orderedDutiesFor, slotIndex } from '../duty/order';
import {
    carryInLeftEdge,
    dutyStreams,
    historyReaches,
    kindMatches,
    personInScope,
    rosterFor,
} from './support';

/** `max_gap`'s parameters, normalised — `kinds` absent and `kinds: []` both mean "every kind". */
export interface MaxGapParams {
    days: number;
    kinds: string[];
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        days: {
            type: 'integer',
            minimum: 1,
            description:
                'At most this many days between the dates two duties START on — the opposite ' +
                "direction of min_gap's `days` reading, and the same measurement (owner decision H).",
        },
        kinds: {
            type: 'array',
            items: { type: 'string' },
            description: 'Slot kinds that count as exposure. Absent or empty means every kind.',
        },
    },
    required: ['days'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): MaxGapParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `max_gap on condition "${condition.id}"`);

    const params = condition.params as { days: number; kinds?: string[] };

    return { days: params.days, kinds: params.kinds ?? [] };
}

/** CG-04's sentence, with the boundary rendered on dates (AR-07). */
export const preview: ConditionPreview = (condition, _context, messages) =>
    messages.maxGap(readParams(condition));

/**
 * The days between two duties that this person could actually have worked.
 *
 * Owner decision I's clock, and the ONLY place it stops. The span is the difference between the two
 * START dates; every date strictly between them that is leave, or before the person joined, is
 * removed. Nothing else is: an off-roster rotation is not a state this repository records per
 * person, and treating a `unitSpans` gap as one would be an inference rather than a fact.
 */
export function measuredGap(person: Person, from: Ymd, to: Ymd): { apart: number; stopped: number } {
    const leave = new Set<string>(person.leaveDays);
    const between = datesBetween(addDays(from, 1), to).filter((date) => compareYmd(date, to) < 0);

    const stopped = between.filter(
        (date) =>
            leave.has(date) || (person.joinedAt !== undefined && compareYmd(date, person.joinedAt) < 0),
    ).length;

    return { apart: diffDays(from, to) - stopped, stopped };
}

/** The duties this rule counts as exposure, for one person, across all three streams. */
function exposure(
    personKey: string,
    condition: Condition,
    params: MaxGapParams,
    person: Person,
    streams: Parameters<typeof orderedDutiesFor>[1],
    slots: Parameters<typeof orderedDutiesFor>[2],
): PositionedDuty[] {
    return orderedDutiesFor(personKey, streams, slots).filter(
        (positioned) =>
            kindMatches(positioned.slot.kind, params.kinds) &&
            personInScope(person, positioned.duty.date, condition.scope),
    );
}

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const slots = slotIndex(context.slots);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const horizon = schedule.horizon;
    const findings: Finding[] = [];
    const skipped: SkippedWindow[] = [...carryInLeftEdge(context, horizon, messages)];

    // TRUE when history reaches back past the horizon — which is exactly when `carryInLeftEdge`
    // stayed silent, and therefore exactly when a per-person leading row is this type's to report.
    const historyKnown = historyReaches(context, horizon, addDays(horizon.from, -1));

    let evaluated = 0;

    for (const person of roster) {
        const counted = exposure(person.key, condition, params, person, streams, slots);

        if (counted.length === 0) {
            skipped.push(openGap(person.key, horizon.from, horizon.to, messages));

            continue;
        }

        const first = counted[0] as PositionedDuty;
        const last = counted[counted.length - 1] as PositionedDuty;

        if (historyKnown && compareYmd(first.duty.date, horizon.from) >= 0) {
            skipped.push(openGap(person.key, horizon.evaluableFrom, first.duty.date, messages));
        }

        // NO EARLY EXIT: every consecutive pair is measured even after one has already breached.
        // Two gaps in one month are two things to fix, and a scan stopping at the first would badge
        // one interval and leave the other invisible until it was repaired.
        for (let at = 1; at < counted.length; at += 1) {
            const before = counted[at - 1] as PositionedDuty;
            const after = counted[at] as PositionedDuty;
            const { apart, stopped } = measuredGap(person, before.duty.date, after.duty.date);

            evaluated += 1;

            if (apart <= params.days) {
                continue;
            }

            findings.push({
                location: {
                    kind: 'window',
                    personKey: person.key,
                    from: before.duty.date,
                    to: after.duty.date,
                    contributing: [before.duty, after.duty],
                },
                explanation: messages.maxGapViolation({
                    apart,
                    stopped,
                    days: params.days,
                    from: before.duty.date,
                    to: after.duty.date,
                }),
            });
        }

        if (compareYmd(last.duty.date, horizon.to) <= 0) {
            skipped.push(openGap(person.key, last.duty.date, horizon.to, messages));
        }
    }

    return { findings, coverage: { evaluatedWindows: evaluated, skipped } };
};

/** One gap with only one end — owner decision I's report, in the one shape both edges take. */
function openGap(
    personKey: string,
    from: Ymd,
    to: Ymd,
    messages: Parameters<ConditionEvaluator>[3],
): SkippedWindow {
    return { from, to, reason: messages.openGapSkip({ personKey, from, to }) };
}
