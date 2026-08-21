/**
 * `overlap_block` — CG-07: *"One duty per overlapping window | — (Hard, built-in)"*.
 *
 * The one row in the catalog that states a class the engine could assert, and the only entry
 * carrying `assertedClass: 'hard'`. It still reads `Condition.class` like everything else: the
 * marking is a fact P3's gate may refuse a relaxation against, not an input to a severity.
 *
 * ## PER PERSON, and that is the whole predicate
 *
 * Nobody holds two duties whose hours overlap. A SLOT filled twice is a different statement
 * entirely — it is SL-03 coverage-template territory and lands in P3 — so a fixture with two people
 * on one slot on one date expects NOTHING here, and the day it starts expecting something is the
 * day P3's feature was built here by accident.
 *
 * ## Half-open intervals, `[start, end)`
 *
 * Under SL-02's configurable split day/night the night window begins exactly when the day window
 * ends. `intersects()` uses strict `<` in both directions, so the abutting pair does not overlap;
 * `<=` would flag every legal split-call department on every single day it runs. One comparison
 * operator, invisible in review, fixtured on the abutting pair.
 *
 * **AND THERE IS NO PRUNING IN THE PAIR SCAN, WHICH IS NOT AN OVERSIGHT.** The obvious optimisation
 * — the duties are sorted by start, so stop once a later duty starts at or after this one's end —
 * was written first and it made the abutting fixture UNFALSIFIABLE: swapping `<` for `<=` inside
 * `intersects()` left the suite green, because the `>=` in the loop's own stop condition had already
 * skipped the pair before `intersects()` was consulted. That is two definitions of the half-open
 * rule, one of them invisible, which is the exact defect `AuditChain::canonical()` carries its
 * docblock against — and the second copy was three lines from the sentence explaining the first.
 * Found by planting, not by reading. The scan is per person over one month; there is nothing here
 * worth buying with a second copy of the rule.
 *
 * ## Both placements are reported, and the emission rule sorts out which survive
 *
 * An overlapping pair produces a finding at EACH of the two placements, each naming the other. WB-03
 * badges cells and a scheduler may move either duty, so badging one and not the other would be
 * choosing for them. When one of the pair sits in the carry-in tail — a night on the last of the
 * previous month running past midnight into the 1st — `evaluate()`'s emission rule drops that
 * placement and keeps the one inside the horizon, which is exactly CG-03's *"never retroactive on
 * published schedules"* doing its job rather than this predicate second-guessing it.
 *
 * ## Duty→date reading: OCCUPIED INTERVAL
 *
 * `DUTY_DATE_READING.overlap_block`. The only reading that can express a night call at all.
 */

import type { JsonSchema } from '../contract/schema';
import type { ConditionEvaluator, ConditionPreview, Finding } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { intersects } from '../duty/interval';
import { orderedDutiesFor, slotIndex } from '../duty/order';
import { carryInLeftEdge, dutyStreams, personInScope, personIndex, placementsCovered } from './support';

/**
 * No parameters, and CG-07 says so with an em dash.
 *
 * `additionalProperties: false` is the load-bearing half: a department that writes a number into
 * this row is expressing an intention the engine will not honour, and refusing it is the only way
 * they find that out. An empty properties map that accepted anything would be a rule that ignored
 * its own configuration silently.
 */
export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {},
    additionalProperties: false,
};

/** CG-04's sentence. It says what abutting means, because that is the half people get wrong. */
export const preview: ConditionPreview = (_condition, _context, messages) => messages.overlapBlock();

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `overlap_block on condition "${condition.id}"`);

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
        const ordered = orderedDutiesFor(personKey, streams, slots);

        for (let index = 0; index < ordered.length; index += 1) {
            const here = ordered[index] as (typeof ordered)[number];

            if (here.origin === 'horizon' && personInScope(people.get(personKey), here.duty.date, condition.scope)) {
                evaluated += 1;
            }

            // EVERY later duty is tested, and the pruning that suggests itself here is deliberately
            // absent — see the module docblock. `intersects()` is the only thing in this package
            // that decides whether two windows overlap.
            for (let other = index + 1; other < ordered.length; other += 1) {
                const there = ordered[other] as (typeof ordered)[number];

                if (!intersects(here.interval, there.interval)) {
                    continue;
                }

                for (const [subject, partner] of [
                    [here, there],
                    [there, here],
                ] as const) {
                    if (!personInScope(people.get(personKey), subject.duty.date, condition.scope)) {
                        continue;
                    }

                    findings.push({
                        location: {
                            kind: 'placement',
                            personKey,
                            date: subject.duty.date,
                            slotKey: subject.duty.slotKey,
                        },
                        explanation: messages.overlapBlockViolation({
                            partner: { slotKey: partner.duty.slotKey, date: partner.duty.date },
                        }),
                    });
                }
            }
        }
    }

    return {
        findings,
        coverage: placementsCovered(evaluated, carryInLeftEdge(context, schedule.horizon, messages)),
    };
};
