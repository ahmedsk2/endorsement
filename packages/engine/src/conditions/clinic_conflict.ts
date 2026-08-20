/**
 * `clinic_conflict` — CG-07: *"Clinic vs post-call (and optionally same-day) | variant"*.
 *
 * ## Owner decision S: item 22's formulation, EXTENDED with the attendee mode
 *
 * Design §14 item 22 formulates this type as the person's rotation on the date against the clinic's
 * unit. That is right for the default mode and wrong in BOTH directions for the other two (finding
 * 17): a `named` clinic comes down to the people named on it — `ClinicRoster::forDate()` does not
 * consult the rota for them at all — so a unit-only reading misses a named attendee who rotates
 * nowhere, and reports a collision for every rotator who was never on that clinic. A `levels` clinic
 * is the rotators whose level ON THAT DATE is in the attached set, which a unit-only reading widens
 * to the whole unit.
 *
 * So the three modes are mirrored from `ClinicRoster`'s shipped resolution rather than re-decided:
 * `rotators` → rotating on the clinic's unit that day, `levels` → that AND holding one of the
 * attached levels that day, `named` → named, rota never consulted.
 *
 * **It does not CALL `ClinicRoster::forDate()`, deliberately** (owner decision S). That resolver is
 * a database reader that returns presented people; this engine is a pure function running in the
 * browser (AR-03, D4) and is handed `context.clinics` instead. The shapes are the same rule; the
 * one that queries stays server-side.
 *
 * ## The date the attendance is read on is the CLINIC's, not the duty's
 *
 * A person attends the clinic on the day the clinic runs, so their rotation and their level are read
 * on THAT date — `ClinicRoster::forDate($clinic, $date)`'s own contract, and *"the level is the
 * cell's, never the row's"*. A resident promoted overnight is on the Tuesday clinic under Tuesday's
 * level, and an implementation reading the duty's date instead is green on every month in which
 * nobody is promoted, which is most of them. Fixtured on exactly that promotion.
 *
 * ## The two variants, and the frozen default
 *
 * SPEC §4 freezes post-call ON and same-day OFF, so `variant` carries `post_call` or
 * `post_call_and_same_day` and is REQUIRED — a default hidden in this file would be a department
 * policy nobody can see on the gate screen, and CG-08's preset carries the frozen value explicitly.
 *
 * The post-call dates come from `postDutyWindow()` with a zero-length window and EXCLUDE the duty's
 * own anchor date, which is the same-day question and has its own switch. That is why a day duty
 * ending at 17:00 is post-call on nothing: the window's instant falls on its own date. A night, or a
 * 24 h call, ends on the following date and is post-call there.
 *
 * **The same-day variant is a CALENDAR-DAY overlap, never a time one.** `clinics.session` is
 * `string(2)` — `AM`/`PM` — with no minutes and no session-to-minutes configuration anywhere in the
 * schema (finding 3, open item 32). An hour comparison here would be inventing the times it needs.
 *
 * ## An INACTIVE clinic is skipped, which is the one place this differs from `ClinicRoster`
 *
 * That resolver deliberately still resolves a deactivated clinic — *"resolution is not
 * authorization: the map decides what to show, this answers what was asked"*. Here the question is
 * different: a clinic that is not running cannot be attended, so a warning about it is a warning
 * nobody can act on, and CG-06's tracker would carry it until somebody ignored it.
 *
 * ## Duty→date reading, and why the carry-in tail buys this type nothing
 *
 * `DUTY_DATE_READING.clinic_conflict` is the anchor date — which person, and their facts, are read
 * there — and the post-duty window supplies the clinic DATES, which is the one thing this type
 * reaches past the horizon for. What it reaches for is a CLINIC, and clinics are a weekly recurrence
 * carried in the context for every weekday, so they are always available. `priorDuties` would add
 * nothing: every finding here is located at a DUTY, so one derived from a tail duty is dropped by
 * `evaluate()`'s emission rule before anybody sees it. See the registry entry, where that is
 * recorded as a measurement rather than as an opinion.
 */

import type { Ymd } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type {
    Clinic,
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    Person,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { slotIndex } from '../duty/order';
import { postDutyDates, postDutyWindow } from '../duty/post-duty-window';
import {
    dayIndex,
    isoWeekdayAt,
    levelKeyAt,
    personInScope,
    personIndex,
    placementsCovered,
    unitKeyAt,
} from './support';

/** CG-07's `variant` cell, as the two values SPEC §4 actually names. */
export type ClinicVariant = 'post_call' | 'post_call_and_same_day';

/** `clinic_conflict`'s parameters. */
export interface ClinicConflictParams {
    variant: ClinicVariant;
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        variant: {
            enum: ['post_call', 'post_call_and_same_day'],
            description:
                'SPEC §4 freezes post-call ON and same-day OFF. Required, so the choice is visible on ' +
                'the gate screen rather than defaulted inside this engine.',
        },
    },
    required: ['variant'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): ClinicConflictParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `clinic_conflict on condition "${condition.id}"`);

    return condition.params as unknown as ClinicConflictParams;
}

/** CG-04's sentence, through the message table (AR-07). */
export const preview: ConditionPreview = (condition, _context, messages) =>
    messages.clinicConflict(readParams(condition));

/** Whether this clinic comes down to this person on this date. See the module docblock. */
function attends(person: Person, clinic: Clinic, date: Ymd): boolean {
    if (clinic.attendeeMode === 'named') {
        return clinic.attendeePersonKeys.includes(person.key);
    }

    if (unitKeyAt(person, date) !== clinic.unitKey) {
        return false;
    }

    if (clinic.attendeeMode === 'rotators') {
        return true;
    }

    const level = levelKeyAt(person, date);

    return level !== null && clinic.attendeeLevelKeys.includes(level);
}

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context) => {
    const { variant } = readParams(condition);
    const people = personIndex(context);
    const slots = slotIndex(context.slots);
    const days = dayIndex(context);
    const running = [...context.clinics]
        .filter((clinic) => clinic.active)
        .sort((a, b) => (a.key < b.key ? -1 : a.key > b.key ? 1 : 0));
    const findings: Finding[] = [];

    let evaluated = 0;

    for (const duty of schedule.duties) {
        const person = people.get(duty.personKey);

        if (!personInScope(person, duty.date, condition.scope)) {
            continue;
        }

        evaluated += 1;

        const slot = slots.get(duty.slotKey);
        const after = postDutyDates(postDutyWindow(duty, slot)).filter((date) => date !== duty.date);
        const occasions: { date: Ymd; sameDay: boolean }[] = [
            ...(variant === 'post_call_and_same_day' ? [{ date: duty.date, sameDay: true }] : []),
            ...after.map((date) => ({ date, sameDay: false })),
        ];

        for (const { date, sameDay } of occasions) {
            const weekday = isoWeekdayAt(days, date);

            for (const clinic of running) {
                if (clinic.isoWeekday !== weekday || !attends(person, clinic, date)) {
                    continue;
                }

                findings.push({
                    location: {
                        kind: 'placement',
                        personKey: duty.personKey,
                        date: duty.date,
                        slotKey: duty.slotKey,
                    },
                    explanation: sameDay
                        ? `Same day: clinic "${clinic.key}" (session ${clinic.session}) runs on ${date}, ` +
                          'the date this duty starts on.'
                        : `Post-call: clinic "${clinic.key}" (session ${clinic.session}) runs on ${date}, ` +
                          'after this duty ends.',
                });
            }
        }
    }

    return { findings, coverage: placementsCovered(evaluated, []) };
};
