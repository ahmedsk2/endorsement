/**
 * `consecutive_max` — CG-07: *"Max consecutive duty days/nights | kinds; count"*.
 *
 * ## Owner decision V: a third unit, `hours`, and CG-08's 24 h cap lands on it
 *
 * CG-08's *"24 h continuous cap"* maps onto no catalog row as written — `consecutive_max` counts
 * days, `rolling_hours_max` is ROLLING rather than contiguous, and the two genuinely differ. So this
 * type carries `unit: 'days' | 'nights' | 'hours'`, where `hours` measures a **contiguous duty
 * chain**: duties are joined into one stretch when the gap between them is at most
 * `transitionMinutes`, which is SPEC Appendix A's *"with limited transition time"* — the clause CG-08
 * drops entirely, and without which the preset either forbids a legitimate handover overlap or
 * silently permits an unbounded one. No twenty-third type key.
 *
 * ## Duty→date reading: ANCHOR DATE for two units, OCCUPIED INTERVAL for the third
 *
 * `DUTY_DATE_READING.consecutive_max` carries BOTH, and it is the second entry to do so after
 * `min_gap` — for the same reason, and it is a correction to Decision A's table rather than a
 * departure from it. The table was written before owner decision V added the `hours` unit, and a
 * chain joined by the GAP between two duties cannot be measured from anchor dates: `days` and
 * `nights` count the dates duties start on, `hours` measures the absolute-minute line. Declaring one
 * reading for a type that has two is the silent divergence that table exists to prevent.
 *
 * ## What a NIGHT is, stated rather than assumed
 *
 * `unit: 'nights'` counts duties whose slot CROSSES MIDNIGHT — a structural fact the contract
 * already carries, rather than a name. SL-01's slot vocabulary is stored nowhere in this repository
 * (Decision A), so matching on a kind called `night` would be inventing the vocabulary and would
 * miss a department that calls it something else. A 24 h call crossing midnight counts as a night by
 * this reading, which is deliberate — the person is in the hospital overnight — and a department
 * wanting something narrower says so with `kinds`.
 *
 * ## The violation is located at the date, or the duty, THAT BROKE THE CAP
 *
 * Not at the whole run: a scheduler needs a cell to move, and *"you have five consecutive days"* is
 * not one. Every date beyond the cap in a run is reported, at each matching duty on it, so the badge
 * covers exactly the placements that must change. Two duties on ONE date are ONE date — a type
 * counting duties rather than dates breaks the cap a day early on any doubled-up day.
 *
 * ## A run spans the horizon edge, and that is what the carry-in tail is for
 *
 * `priorDuties` extends the run backwards, so a run beginning on the 30th and continuing into the
 * 2nd is one run of four. Horizon-local evaluation reports a run of two and says nothing at all,
 * which is invisible on any mid-month corpus. The findings on tail placements are dropped by
 * `evaluate()`'s emission rule; the one inside the horizon is what a scheduler sees.
 */

import { compareYmd, diffDays, type Ymd } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    ViolationMessages,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { orderedDutiesFor, slotIndex, type PositionedDuty } from '../duty/order';
import {
    carryInLeftEdge,
    dutyStreams,
    kindMatches,
    personInScope,
    personIndex,
    placementsCovered,
} from './support';

/** `consecutive_max`'s parameters, normalised. */
export interface ConsecutiveMaxParams {
    count: number;
    unit: 'days' | 'nights' | 'hours';
    transitionMinutes: number;
    kinds: string[];
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        count: {
            type: 'integer',
            minimum: 1,
            description: 'Consecutive dates, consecutive nights, or hours of one continuous stretch.',
        },
        unit: {
            enum: ['days', 'nights', 'hours'],
            description: "Owner decision V. 'hours' is CG-08's 24 h continuous cap, on a joined chain.",
        },
        transitionMinutes: {
            type: 'integer',
            minimum: 0,
            description:
                "SPEC Appendix A's limited transition time: duties this far apart or less are ONE " +
                "stretch. Read by the 'hours' unit; the preview says so under the other two.",
        },
        kinds: {
            type: 'array',
            items: { type: 'string' },
            description: 'Slot kinds the run is counted over. Absent or empty means every kind.',
        },
    },
    required: ['count', 'unit'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): ConsecutiveMaxParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `consecutive_max on condition "${condition.id}"`);

    const params = condition.params as {
        count: number;
        unit: 'days' | 'nights' | 'hours';
        transitionMinutes?: number;
        kinds?: string[];
    };

    return {
        count: params.count,
        unit: params.unit,
        transitionMinutes: params.transitionMinutes ?? 0,
        kinds: params.kinds ?? [],
    };
}

/** CG-04's sentence, through the message table (AR-07). */
export const preview: ConditionPreview = (condition, _context, messages) =>
    messages.consecutiveMax(readParams(condition));

/** The duties this condition counts at all: its kinds, its unit, and CG-01's scope. */
function counted(
    ordered: readonly PositionedDuty[],
    params: ConsecutiveMaxParams,
    inScope: (date: Ymd) => boolean,
): PositionedDuty[] {
    return ordered.filter(
        (positioned) =>
            kindMatches(positioned.slot.kind, params.kinds) &&
            (params.unit !== 'nights' || positioned.slot.crossesMidnight) &&
            inScope(positioned.duty.date),
    );
}

/** The `days`/`nights` reading: consecutive DATES, however many duties sit on each. */
function runsOfDates(
    matching: readonly PositionedDuty[],
    params: ConsecutiveMaxParams,
    messages: ViolationMessages,
): Finding[] {
    const dates = [...new Set(matching.map((positioned) => positioned.duty.date))].sort(compareYmd);
    const findings: Finding[] = [];

    let run = 0;
    let previous: Ymd | null = null;

    for (const date of dates) {
        run = previous !== null && diffDays(previous, date) === 1 ? run + 1 : 1;
        previous = date;

        if (run <= params.count) {
            continue;
        }

        for (const positioned of matching.filter((candidate) => candidate.duty.date === date)) {
            findings.push({
                location: {
                    kind: 'placement',
                    personKey: positioned.duty.personKey,
                    date: positioned.duty.date,
                    slotKey: positioned.duty.slotKey,
                },
                explanation: messages.consecutiveMaxDatesViolation({
                    // `nights` and `days` differ only in the noun; the run and the cap are the same
                    // measurement, and `hours` is a different function entirely.
                    unit: params.unit === 'nights' ? 'nights' : 'days',
                    run,
                    count: params.count,
                }),
            });
        }
    }

    return findings;
}

/** The `hours` reading: one contiguous stretch, joined across gaps of at most `transitionMinutes`. */
function runsOfHours(
    matching: readonly PositionedDuty[],
    params: ConsecutiveMaxParams,
    messages: ViolationMessages,
): Finding[] {
    const findings: Finding[] = [];
    const cap = params.count * 60;

    let start: number | null = null;
    let end = 0;

    for (const positioned of matching) {
        if (start === null || positioned.interval.start - end > params.transitionMinutes) {
            start = positioned.interval.start;
            end = positioned.interval.end;
        } else {
            end = Math.max(end, positioned.interval.end);
        }

        if (end - start <= cap) {
            continue;
        }

        findings.push({
            location: {
                kind: 'placement',
                personKey: positioned.duty.personKey,
                date: positioned.duty.date,
                slotKey: positioned.duty.slotKey,
            },
            explanation: messages.consecutiveMaxHoursViolation({
                minutes: end - start,
                transitionMinutes: params.transitionMinutes,
                count: params.count,
            }),
        });
    }

    return findings;
}

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const slots = slotIndex(context.slots);
    const people = personIndex(context);
    const streams = dutyStreams(schedule, context);
    const findings: Finding[] = [];

    const personKeys = [
        ...new Set(
            [...streams.priorDuties, ...streams.duties, ...streams.followingDuties].map((duty) => duty.personKey),
        ),
    ].sort();

    let evaluated = 0;

    for (const personKey of personKeys) {
        const person = people.get(personKey);
        const ordered = orderedDutiesFor(personKey, streams, slots);
        const matching = counted(ordered, params, (date) => personInScope(person, date, condition.scope));

        evaluated += matching.filter((positioned) => positioned.origin === 'horizon').length;

        findings.push(
            ...(params.unit === 'hours'
                ? runsOfHours(matching, params, messages)
                : runsOfDates(matching, params, messages)),
        );
    }

    return {
        findings,
        coverage: placementsCovered(evaluated, carryInLeftEdge(context, schedule.horizon, messages)),
    };
};
