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

import { diffDays } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type { Condition, ConditionEvaluator, ConditionPreview, Finding } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { orderedDutiesFor, slotIndex, type PositionedDuty } from '../duty/order';
import {
    carryInLeftEdge,
    dutyStreams,
    hoursText,
    kindMatches,
    personInScope,
    personIndex,
    placementsCovered,
} from './support';

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

/**
 * The shortfall between two duties of one person, or `null` when there is none.
 *
 * ONE function for both readings, because the choice between them is `unit` and nothing else —
 * `DUTY_DATE_READING.min_gap` is the only entry in that table carrying two readings, and this is
 * where they part. `hours` subtracts the earlier duty's END from the later one's START on the
 * absolute-minute line; `days` subtracts the dates they START on, which is the anchor-date reading.
 */
function shortfall(earlier: PositionedDuty, later: PositionedDuty, params: MinGapParams): string | null {
    if (params.unit === 'days') {
        const apart = diffDays(earlier.duty.date, later.duty.date);

        return apart >= params.value ? null : `${apart} ${apart === 1 ? 'day' : 'days'}`;
    }

    const gap = later.interval.start - earlier.interval.end;

    return gap >= params.value * 60 ? null : `${hoursText(gap)} h`;
}

/**
 * The predicate.
 *
 * ## Both placements are reported, and NO PRUNING
 *
 * A pair that is too close produces a finding at EACH of its two placements, each naming the other,
 * exactly as `overlap_block` does: a scheduler may move either duty, and badging one and not the
 * other chooses for them. When one of the pair is in the carry-in tail — the last night of the
 * published month against the first duty of this one — `evaluate()`'s emission rule drops that
 * placement and keeps the one inside the horizon.
 *
 * **The pair scan carries no early exit, and that is deliberate.** `overlap_block`'s did, and it
 * made the phase's defining fixture unfalsifiable: the stop condition had already skipped the
 * abutting pair before the comparison it was testing was ever consulted. Here the stop condition
 * would have to be a second statement of the gap rule, in a type whose whole content is that rule.
 * A month of one person's duties is a handful of entries; there is nothing to buy.
 *
 * ## `kinds` names the kinds on BOTH sides
 *
 * *"Minimum spacing between duties [of these kinds]"* is a statement about a PAIR, so a pair is
 * measured only when both of its duties are of a named kind. A rule spacing nights apart says
 * nothing about the day shift that happens to sit between two of them.
 *
 * It is read ONCE per duty and used twice — by the pair scan and by the count — because a duty of
 * an unnamed kind can never appear in a finding this condition produces, so it is not a placement
 * this condition judged. `eligibility` already counts that way for a slot absent from its map, and
 * `consecutive_max` for a duty outside its own `kinds`. The hoist is not pruning: every pair is
 * still visited, and the `continue` states the same rule the inner test states.
 *
 * The parameter narrowed NOTHING in the corpus until P2-1's review — no case set it, so the whole
 * filter could be deleted with 587/587 green. `min-gap-kinds-names-both-sides-of-the-pair` is what
 * fails now, on each side separately and in the coverage row.
 */
export const evaluate: ConditionEvaluator = (condition, schedule, context) => {
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

        for (let index = 0; index < ordered.length; index += 1) {
            const here = ordered[index] as PositionedDuty;
            // ONE reading of `kinds` on this side of the pair, used by the count and by the scan.
            const spaced = kindMatches(here.slot.kind, params.kinds);

            if (spaced && here.origin === 'horizon' && personInScope(person, here.duty.date, condition.scope)) {
                evaluated += 1;
            }

            for (let other = index + 1; other < ordered.length; other += 1) {
                const there = ordered[other] as PositionedDuty;

                if (!spaced || !kindMatches(there.slot.kind, params.kinds)) {
                    continue;
                }

                const short = shortfall(here, there, params);

                if (short === null) {
                    continue;
                }

                const overlapping = params.unit === 'hours' && there.interval.start < here.interval.end;

                for (const [subject, partner] of [
                    [here, there],
                    [there, here],
                ] as const) {
                    if (!personInScope(person, subject.duty.date, condition.scope)) {
                        continue;
                    }

                    findings.push({
                        location: {
                            kind: 'placement',
                            personKey,
                            date: subject.duty.date,
                            slotKey: subject.duty.slotKey,
                        },
                        explanation: overlapping
                            ? `This duty overlaps "${partner.duty.slotKey}" on ${partner.duty.date}, so the ` +
                              `required ${params.value} h gap between them is not there at all.`
                            : params.unit === 'hours'
                              ? `Only ${short} between this duty and "${partner.duty.slotKey}" on ` +
                                `${partner.duty.date}; at least ${params.value} h is required between the ` +
                                'end of one duty and the start of the next.'
                              : `${short} between this duty and "${partner.duty.slotKey}" on ` +
                                `${partner.duty.date}, counted between the dates they start on; at least ` +
                                `${params.value} are required.`,
                    });
                }
            }
        }
    }

    return {
        findings,
        coverage: placementsCovered(evaluated, carryInLeftEdge(context, schedule.horizon)),
    };
};
