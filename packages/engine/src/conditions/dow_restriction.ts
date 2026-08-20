/**
 * `dow_restriction` — CG-07: *"Day-of-week bans | rotation or person; days"*.
 *
 * ## The cell's two halves land in two different places, and that is owner decision K's separation
 *
 * *"days"* is this type's parameter. *"rotation or person"* is **CG-01's `scope`** — the same
 * `{unitKeys, levelKeys, personKeys}` every other type is narrowed by, resolved AT THE DUTY'S DATE
 * (`support.ts`'s `spanKeyAt`). Duplicating it as a parameter here would be two ways to say one
 * thing on one gate screen, and the two would eventually disagree about a person who changed
 * rotation mid-month.
 *
 * ## The days are ISO INTEGERS, and a NAME is refused rather than ignored
 *
 * There is no name-to-number table in this package and there is deliberately never going to be one:
 * AR-07 keeps the day names in `lang/en/calendar.php` where a second locale can reach them, and
 * owner decision X makes the week's shape arrive in the context rather than being written down
 * here. `CalendarIsTheOnlyConverterTest`'s quoted-weekday scan enforces the literal half across all
 * of `packages/`.
 *
 * So `days` is `integer 1..7`, `minItems: 1`, and a department writing a day name gets the schema's
 * own error. A ban that quietly matched nothing would be a control that appears to do nothing — and
 * an empty `days` list is refused for the same reason: an empty ban is a rule somebody meant to
 * write and did not.
 *
 * ## The weekday comes off the PRECOMPUTED day vector
 *
 * `Day.isoWeekday`, never recomputed (finding 21, AR-08) — `dayIndex()` is the one reader. Every
 * date this type asks about is inside the horizon, so the vector always has the answer, and a date
 * it does not describe THROWS rather than being computed here: a duty whose day row the caller
 * omitted is dropped context, exactly like a duty naming an unsupplied slot.
 *
 * ## Duty→date reading: ANCHOR DATE
 *
 * `DUTY_DATE_READING.dow_restriction`. A night duty starting on a banned day is banned; the same
 * duty running into the following morning does not ban that morning too.
 */

import type { JsonSchema } from '../contract/schema';
import type { Condition, ConditionEvaluator, ConditionPreview, Finding } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { dayIndex, list, personInScope, personIndex, placementsCovered } from './support';

/** `dow_restriction`'s parameters: the ISO weekdays this condition bans. */
export interface DowRestrictionParams {
    days: number[];
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        days: {
            type: 'array',
            minItems: 1,
            items: {
                type: 'integer',
                minimum: 1,
                maximum: 7,
                description: 'ISO weekday numbers. Day NAMES are the server’s (AR-07) and are refused here.',
            },
            description: 'The ISO weekdays this condition bans. An empty list is refused, not ignored.',
        },
    },
    required: ['days'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit — a weekday name included. */
export function readParams(condition: Condition): DowRestrictionParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `dow_restriction on condition "${condition.id}"`);

    return condition.params as unknown as DowRestrictionParams;
}

/** CG-04's sentence, through the message table (AR-07). */
export const preview: ConditionPreview = (condition, _context, messages) =>
    messages.dowRestriction(readParams(condition));

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context) => {
    const { days } = readParams(condition);
    const people = personIndex(context);
    const daysByDate = dayIndex(context);
    const findings: Finding[] = [];
    const banned = list(days.map((day) => String(day)));

    let evaluated = 0;

    for (const duty of schedule.duties) {
        const person = people.get(duty.personKey);

        if (!personInScope(person, duty.date, condition.scope)) {
            continue;
        }

        evaluated += 1;

        const { isoWeekday } = daysByDate.get(duty.date);

        if (days.includes(isoWeekday)) {
            findings.push({
                location: { kind: 'placement', personKey: duty.personKey, date: duty.date, slotKey: duty.slotKey },
                explanation:
                    `${duty.date} is ISO weekday ${isoWeekday}, and this rule bans ISO ` +
                    `${days.length === 1 ? 'weekday' : 'weekdays'} ${banned}.`,
            });
        }
    }

    return { findings, coverage: placementsCovered(evaluated, []) };
};
