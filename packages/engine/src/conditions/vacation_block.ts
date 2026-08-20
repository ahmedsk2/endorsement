/**
 * `vacation_block` — CG-07: *"Never during vacation | — (Hard default)"*.
 *
 * ## Duty→date reading: ANCHOR DATE, and it is a decision rather than the easy option
 *
 * `DUTY_DATE_READING.vacation_block`. A night call starting the evening before a person's leave
 * begins runs into the first morning of that leave, and it is **not** a violation: it is one duty,
 * on the day it started, and the person is back before their leave was supposed to begin. Reading
 * the occupied interval instead would flag the last night before every holiday, everywhere, which
 * is both wrong and the sort of wrong a department stops believing the tool over.
 *
 * ## Both bounds inclusive, because `vacations` is
 *
 * `leaveDays` arrives as an explicit list of dates from the one converter, so inclusivity is not
 * re-derived here — it is inherited from the store, which is the point of the day vector and of
 * this field. The fixture asserts the FIRST and the LAST day of a leave period, because an
 * exclusive bound at either end is a person working the day they flew home.
 *
 * ## No parameters
 *
 * CG-07's cell is an em dash. Which people the rule applies to is CG-01's `scope`, not a parameter
 * of the type — the same separation owner decision K draws for `count_max`.
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

/** CG-04's sentence. It states the anchor-date reading, because that is what a reader will query. */
export const preview: ConditionPreview = () =>
    'No duty on a day the person is on leave, counting the day the duty starts on — the first and ' +
    'the last day of a leave period both count, and a night duty starting the evening before leave ' +
    'begins does not.';

/** The predicate. */
export const evaluate: ConditionEvaluator = (condition, schedule, context) => {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `vacation_block on condition "${condition.id}"`);

    const people = personIndex(context);
    const findings: Finding[] = [];

    let evaluated = 0;

    for (const duty of schedule.duties) {
        const person = people.get(duty.personKey);

        if (!personInScope(person, duty.date, condition.scope)) {
            continue;
        }

        evaluated += 1;

        if (person.leaveDays.includes(duty.date)) {
            findings.push({
                location: { kind: 'placement', personKey: duty.personKey, date: duty.date, slotKey: duty.slotKey },
                explanation: `On leave on ${duty.date}.`,
            });
        }
    }

    return { findings, coverage: placementsCovered(evaluated, []) };
};
