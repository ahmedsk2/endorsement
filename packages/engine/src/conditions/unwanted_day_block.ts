/**
 * `unwanted_day_block` — CG-07: *"Avoid registered unwanted days | — (top soft default)"*.
 *
 * ## Owner decision R: P2 STORES NOTHING, and that is the whole shape of this type
 *
 * The days arrive in the evaluation context on the person (`unwantedDays`) and this package holds
 * no store, no schema and no screen for them. RQ-01 — the requests feature §30 spells as
 * `requests/{reqId} { type:'unwanted'|… }` — owns both, and §35 places it at Stage 3. Committing to
 * a schema here would be committing the owning feature to one it would then have to change, and
 * `people.constraints` is not a candidate as it stands (`json` nullable, validated only
 * `['nullable','array']`, one documented example in a textarea helper).
 *
 * **The disclosure question travels with the store, not with this file.** The engine runs in the
 * browser for WB-03, so one person's unwanted days would enter another person's Inertia props — a
 * real question, and the owner's — but P2 cannot leak a preference it never holds. Whoever ships the
 * store answers it; `PersonPolicy`/`PersonPresenter` is where the answer will have to live.
 *
 * ## Duty→date reading: ANCHOR DATE
 *
 * `DUTY_DATE_READING.unwanted_day_block`, and it is `vacation_block`'s reading for `vacation_block`'s
 * reason: a night duty starting the evening before an unwanted day runs into that morning and is
 * still one duty, on the day it started. Reading the occupied interval instead would flag the last
 * night before every requested day off, which is both wrong and the sort of wrong that makes a
 * department stop believing a soft warning.
 *
 * ## Per PERSON, and the fixture is what proves it
 *
 * The list read is the list belonging to the person on the duty. An implementation reading the first
 * person in the context — or the union of everybody's lists — is green on a one-person world, so the
 * corpus case puts two people on one date and gives only one of them the registration.
 *
 * ## No parameters
 *
 * CG-07's cell is an em dash. WHICH people the rule applies to is CG-01's `scope`, not a parameter
 * of the type — owner decision K's separation, and `vacation_block` states it the same way.
 */

import type { JsonSchema } from '../contract/schema';
import type { ConditionEvaluator, ConditionPreview, Finding } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { personInScope, personIndex, placementsCovered } from './support';

/** No parameters. See `overlap_block.ts` for why the closed object is the load-bearing half. */
export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {},
    additionalProperties: false,
};

/** CG-04's sentence, through the message table (AR-07). */
export const preview: ConditionPreview = (_condition, _context, messages) => messages.unwantedDayBlock();

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `unwanted_day_block on condition "${condition.id}"`);

    const people = personIndex(context);
    const findings: Finding[] = [];

    let evaluated = 0;

    for (const duty of schedule.duties) {
        const person = people.get(duty.personKey);

        if (!personInScope(person, duty.date, condition.scope)) {
            continue;
        }

        evaluated += 1;

        if (person.unwantedDays.includes(duty.date)) {
            findings.push({
                location: { kind: 'placement', personKey: duty.personKey, date: duty.date, slotKey: duty.slotKey },
                explanation: messages.unwantedDayBlockViolation({ date: duty.date }),
            });
        }
    }

    return { findings, coverage: placementsCovered(evaluated, []) };
};
