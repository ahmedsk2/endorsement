/**
 * `onboarding_grace` — CG-07: *"No duties in first N days | levels; days"*.
 *
 * ## Owner decision T, and the one place this file goes beyond it deliberately
 *
 * The window is **calendar days**, **day 1 is the join date**, `levels` is CG-01's scope rather than
 * a parameter of the type (owner decision K's separation), and **external people are in scope**.
 * `N` has never been stated by any owner, so it is a required parameter with no default and the
 * residency preset ships it absent rather than guessed (Decision H).
 *
 * The deliberate addition: a duty **before** the join date is also a violation. A literal reading of
 * *"the first N days"* is the closed range `[joinedAt, joinedAt + N - 1]`, and a rota drafted before
 * somebody starts — which is exactly when this rule is needed — puts duties **outside** it on the
 * early side, where a range test reports nothing at all for a person who had not joined the
 * department. So the grace opens at the start of time and closes on the last grace day, and the two
 * shapes carry different explanations: *"day 0"* on a gate screen is a number a scheduler would
 * rightly not believe. Fixtured on `onboarding-grace-a-duty-before-the-join-date`.
 *
 * ## AN UNKNOWN JOIN DATE IS NO VIOLATION, AND MUST NOT BE SILENT
 *
 * Owner decision T makes a missing `joined_at` produce no violation, because treating null as
 * *"joined today"* would block an entire department on its first evaluation. **But P2 Task 1's
 * finding 18 is that `joined_at` is written by NO seeder, factory or demo path anywhere in this
 * repository, and production already holds people without one** — so on the live instance the
 * honest answer and the answer of a rule that never fires are the same answer, on a green suite and
 * on a gate screen alike.
 *
 * That is rulings 41/49's shape one layer inside the engine: a control that appears to do something
 * and does not. So every person whose join date is unknown AND who holds a placement this condition
 * would otherwise have judged is reported through `coverage()`, by key, with the placement count —
 * `evaluate()` says *"no violation"* and `coverage()` says *"and here is who I could not judge"*.
 * A person with no join date and no duty is NOT reported: a row on every roster gap would put noise
 * on almost every evaluation and train a reader to ignore the field, which is `carryInLeftEdge()`'s
 * recorded reason for refusing exactly that.
 *
 * ## Duty→date reading: ANCHOR DATE
 *
 * `DUTY_DATE_READING.onboarding_grace`. A night duty starting on the last grace day is one duty on
 * that day; the person's first unrestricted morning is not made restricted by the night before it.
 */

import { addDays, compareYmd, diffDays } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    SkippedWindow,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { personInScope, personIndex, placementsCovered } from './support';

/** `onboarding_grace`'s parameters. */
export interface OnboardingGraceParams {
    days: number;
}

/**
 * `days` has no default and is required.
 *
 * Decision H: the numeric policy §37 still owes has not been stated, and a plausible-looking default
 * on a Hard rule that blocks placements is worse than an absent one — a department can see a missing
 * number on a gate screen and cannot see a guessed one.
 */
export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        days: {
            type: 'integer',
            minimum: 1,
            description: 'Calendar days of grace, counting the join date as day 1. No default (Decision H).',
        },
    },
    required: ['days'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): OnboardingGraceParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `onboarding_grace on condition "${condition.id}"`);

    return condition.params as unknown as OnboardingGraceParams;
}

/** CG-04's sentence. It says the unknown-join-date answer out loud (owner decision T). */
export const preview: ConditionPreview = (condition, _context, messages) =>
    messages.onboardingGrace(readParams(condition));

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context) => {
    const { days } = readParams(condition);
    const people = personIndex(context);
    const findings: Finding[] = [];
    const unjudged = new Map<string, number>();

    let evaluated = 0;

    for (const duty of schedule.duties) {
        const person = people.get(duty.personKey);

        if (!personInScope(person, duty.date, condition.scope)) {
            continue;
        }

        const joinedAt = person.joinedAt;

        if (joinedAt === undefined) {
            unjudged.set(person.key, (unjudged.get(person.key) ?? 0) + 1);

            continue;
        }

        evaluated += 1;

        const location = {
            kind: 'placement' as const,
            personKey: duty.personKey,
            date: duty.date,
            slotKey: duty.slotKey,
        };

        if (compareYmd(duty.date, joinedAt) < 0) {
            findings.push({ location, explanation: `Before the join date ${joinedAt}.` });

            continue;
        }

        if (compareYmd(duty.date, addDays(joinedAt, days - 1)) <= 0) {
            findings.push({
                location,
                explanation:
                    `Day ${diffDays(joinedAt, duty.date) + 1} of the ${days}-day onboarding grace, ` +
                    `counting the join date ${joinedAt} as day 1.`,
            });
        }
    }

    return { findings, coverage: placementsCovered(evaluated, unknownJoinDates(unjudged, schedule)) };
};

/** One coverage row per person whose join date the context does not carry. See the docblock. */
function unknownJoinDates(unjudged: Map<string, number>, schedule: Parameters<ConditionEvaluator>[1]): SkippedWindow[] {
    return [...unjudged.entries()]
        .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
        .map(([personKey, count]) => ({
            from: schedule.horizon.from,
            to: schedule.horizon.to,
            reason:
                `No join date is recorded for "${personKey}", so ${count} ` +
                `${count === 1 ? 'placement was' : 'placements were'} not evaluated. An unknown join ` +
                'date is no violation (owner decision T), and this row is what distinguishes that from ' +
                'a rule that ran and found nothing.',
        }));
}
