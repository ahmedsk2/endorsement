/**
 * `post_duty_exclusion` — CG-07: *"After kind A, blocked from kinds B for H hours | from; to; hours
 * (generalized post-call)"*.
 *
 * ## Owner decision H: anchored on the END of `a`, and `b` is tested by START-IN-WINDOW
 *
 * The window is `postDutyWindow(a, slotA, hours × 60)` — `[end, end + H h)` on the absolute-minute
 * line — and a duty is in violation when it STARTS inside it. Not when it overlaps it: a long duty
 * beginning one minute before the exclusion closes is inside, and one beginning after it closes is
 * clean however far back its own window reaches. Half-open at both ends, so a duty starting exactly
 * when the exclusion closes is clean, which is `intersects()`'s rule for abutting windows.
 *
 * The window comes from `duty/post-duty-window.ts` and is SHARED with `clinic_conflict`, which asks
 * the day-granular question of the same fact. Two implementations of *"after this duty"* disagree on
 * a real configuration and a scheduler shown one warning and not the other cannot tell which is
 * right — `AuditChain::canonical()`'s defect, which this repository has already paid for once.
 *
 * ## A weekly-cadence `from` duty ends by `spanDays` (owner decision K)
 *
 * `dutyInterval()` already answers that, and it is `date + spanDays - 1` rather than `+ spanDays` —
 * the correction the plan records, which the seven-date acceptance case forced. Nothing here
 * re-derives it, and the corpus fixtures a backup week whose exclusion opens on the seventh date.
 *
 * ## ONE placement is reported, unlike `overlap_block` and `min_gap`
 *
 * This type is DIRECTIONAL: the anchor duty is not itself wrong — it is the thing that happened —
 * and the placement a scheduler must move is the one that landed inside the exclusion. Reporting
 * the anchor too would badge a cell that is not in violation of anything, and at the horizon edge
 * that cell is usually in a published month the emission rule would drop anyway.
 *
 * ## The `from`/`to` INTERSECTION is a legitimate configuration, and the preview says so
 *
 * When a kind appears in both lists the type degenerates into `min_gap` in hours — the same kind
 * blocking itself for H hours — and the two types then produce the same judgement on the same pair,
 * measured from the same end. That is asserted rather than assumed, in `conditions.test.ts`, because
 * two spacing rules that quietly disagree is the failure this whole shared window exists to prevent.
 *
 * ## Every ordered pair is examined, in both directions
 *
 * It is provable that only the earlier duty can be the anchor — the window opens at its end, which
 * is after its own start — but a proof that lets a scan skip a comparison is exactly what made
 * `overlap_block`'s abutting fixture unfalsifiable at Task 10: a second, invisible statement of the
 * rule the fixture exists to test. So both orderings are tested and `startsWithin()` decides.
 */

import type { JsonSchema } from '../contract/schema';
import type { Condition, ConditionEvaluator, ConditionPreview, Finding } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { orderedDutiesFor, slotIndex, type PositionedDuty } from '../duty/order';
import { postDutyDates, postDutyWindow, startsWithin } from '../duty/post-duty-window';
import {
    carryInLeftEdge,
    dutyStreams,
    kindMatches,
    personInScope,
    personIndex,
    placementsCovered,
} from './support';

/** `post_duty_exclusion`'s parameters, normalised — an absent or empty list means every kind. */
export interface PostDutyExclusionParams {
    from: string[];
    to: string[];
    hours: number;
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        from: {
            type: 'array',
            items: { type: 'string' },
            description: 'Slot kinds that OPEN the exclusion. Absent or empty means every kind.',
        },
        to: {
            type: 'array',
            items: { type: 'string' },
            description: 'Slot kinds the exclusion blocks. Absent or empty means every kind.',
        },
        hours: {
            type: 'integer',
            minimum: 1,
            description: 'How long the exclusion runs, from the END of the opening duty (decision H).',
        },
    },
    required: ['hours'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): PostDutyExclusionParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `post_duty_exclusion on condition "${condition.id}"`);

    const params = condition.params as { from?: string[]; to?: string[]; hours: number };

    return { from: params.from ?? [], to: params.to ?? [], hours: params.hours };
}

/** CG-04's sentence, through the message table (AR-07). */
export const preview: ConditionPreview = (condition, _context, messages) =>
    messages.postDutyExclusion(readParams(condition));

/** The predicate. See the module docblock for every decision in it. */
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

        for (const subject of ordered) {
            if (subject.origin === 'horizon' && personInScope(person, subject.duty.date, condition.scope)) {
                evaluated += 1;
            }

            if (!kindMatches(subject.slot.kind, params.to)) {
                continue;
            }

            for (const anchor of ordered) {
                if (anchor === subject || !kindMatches(anchor.slot.kind, params.from)) {
                    continue;
                }

                const window = postDutyWindow(anchor.duty, anchor.slot, params.hours * 60);

                if (!startsWithin(window, subject.interval)) {
                    continue;
                }

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
                    explanation:
                        `Starts inside the ${params.hours} h exclusion after "${anchor.duty.slotKey}" on ` +
                        `${anchor.duty.date}, which ends ${endsOn(anchor)}.`,
                });
            }
        }
    }

    return {
        findings,
        coverage: placementsCovered(evaluated, carryInLeftEdge(context, schedule.horizon)),
    };
};

/** The civil date the anchor duty's own window closes on — the date the exclusion opens. */
function endsOn(anchor: PositionedDuty): string {
    return postDutyDates(postDutyWindow(anchor.duty, anchor.slot))[0] as string;
}
