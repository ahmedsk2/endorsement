/**
 * `holiday_equity` — CG-07: *"Spread named holidays across people & years | holidays; lookback
 * years"*.
 *
 * ## Owner decision W, ANSWERED: a carried credit starts at ZERO, never at unknown
 *
 * `Person.priorCredits` is `Record<holidayKey, number | null>` in the contract, authored at Task 7
 * against the decision's SUPERSEDED default, in which `null` meant UNKNOWN and a person carrying
 * one was held out of the comparison. The answer reverses that: **`null` and an absent key both
 * read as ZERO**, and year one distributes on that year's own assignments alone.
 *
 * The shape is left as it is rather than narrowed, deliberately — `App\Support\Engine` serialises
 * into it and P2-2 adds predicates and no shared shape — so the `null` spelling remains admissible
 * and means exactly what an omission means. `contract/types.ts` and `contract/schema.ts` carried
 * prose asserting the opposite until this task and now record the answer instead.
 *
 * **The limitation is accepted knowingly and belongs in the PREVIEW**, which is where the decision
 * puts it: duty covered on paper before this system existed is invisible, so year one spreads
 * evenly on top of a past that may not have been. The fix, if it becomes a complaint, is an
 * operator-entered prior-credit field, which is additive and changes nothing here.
 *
 * ## One credit per holiday PER YEAR, however many of its days somebody works
 *
 * Decision W's own sentence. Eid runs for several dates; working two of them is one credit and not
 * two, and the per-DAY reading is the mistake this type is likeliest to ship with — it produces a
 * plausible number a scheduler cannot reconcile with a calendar. The year is the HOLIDAY's OWN
 * (`yearBasis: 'ruleCalendar'`), which arrives on `Day.holidays[].year` precomputed server-side:
 * a Hijri rule's year is a Hijri year, and decision AA keeps ICU out of the browser by carrying the
 * number rather than converting for it.
 *
 * ## The comparison is over the NAMED SET, not one holiday at a time
 *
 * `holidays` is a list and the credits are summed across all of it. Per-holiday comparisons were
 * written first and are unfalsifiable in a one-month horizon: a horizon holds at most one year of
 * any one holiday, so every person holds nought or one of it and `max − min` can never exceed one.
 * The rule would have been structurally incapable of firing in the year the answer says it must
 * distribute in. Summing across the set is also what CG-07's cell says — *"spread named holidays
 * across people & years"*, plural on both axes.
 *
 * ## The threshold is `max − min <= 1`, and it is a definition rather than a number
 *
 * A credit is indivisible, so the fairest reachable allocation of `k` credits over `n` people has a
 * spread of at most one. Anything wider contains a credit that could have gone to somebody holding
 * fewer. No figure is invented and none is authored — CG-07 gives this row no tolerance parameter,
 * unlike `fairness_distribution`, and inventing one would be inventing policy.
 *
 * **STATED RESIDUAL:** availability does not enter. Somebody on leave across an entire holiday
 * cannot take it, so a spread of one may be unreachable for reasons this type cannot see. CG-07
 * gives it no availability input and `fairness_distribution` is the type that owns pro-rating;
 * adding one here would be a second, differently-shaped answer to the same question.
 *
 * ## `lookbackYears` is read, and what it can honestly do is stated
 *
 * The engine cannot verify the DEPTH of a lookback: `priorCredits` arrives already aggregated by
 * the caller, and turning `historyAvailableFrom` into a count of rule-calendar years would need the
 * Hijri conversion decision AA keeps out of this package. What it can prove is the one case where
 * the caller had nothing to aggregate — `historyAvailableFrom` is `null` — and the one where the
 * rule asked for no lookback at all. In both, carried credits are IGNORED rather than counted as
 * zero, and the first is reported through `coverage()`: a lookback that quietly counted nothing
 * would look identical, on a green suite, to one that was read and found nothing.
 *
 * ## Cohort-located, with the same two consequences `fairness_distribution` records
 *
 * A cohort location is ALWAYS reportable, so the horizon filter is applied here rather than left to
 * `evaluate()`'s emission rule; and `scopeLabel` is text from the message table. `contributing` is
 * optional on this member and is supplied on every finding, empty included — somebody flagged
 * entirely on carried credits holds no duty in this schedule at all, and `[]` is that answer rather
 * than a field nobody filled in.
 *
 * ## Duty→date reading: ANCHOR DATE
 *
 * `DUTY_DATE_READING.holiday_equity`. A night starting on the last evening of Eid is an Eid duty;
 * one starting the evening before it is not. `needsCarryIn: false` — the history arrives as
 * `priorCredits`, and a lookback of years is not a carry-in tail of days.
 *
 * ## PLANTED
 *
 * `personInScope` answering `true` — the standing FIRST plant; `null` read as UNKNOWN and held out
 * of the comparison; carried credits ignored when they were supplied; the credit counted per DAY
 * rather than per holiday-year; the spread relaxed so only a difference above two is reported; and
 * the holiday filter dropped so every duty in the horizon earns a credit. Each went red naming its
 * own case.
 */

import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    ConditionScope,
    Finding,
    Person,
    SkippedWindow,
    ViolationMessages,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import type { Duty } from '../duty/interval';
import { withinHorizon } from '../duty/windows';
import { dutyStreams, personInScope, rosterFor } from './support';

/** `holiday_equity`'s parameters, normalised. */
export interface HolidayEquityParams {
    holidays: string[];
    lookbackYears: number;
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        holidays: {
            type: 'array',
            minItems: 1,
            items: { type: 'string' },
            description:
                'The holiday KEYS this rule spreads, matched against the day vector\'s own. An ' +
                'empty list would be a rule that does nothing, so it is refused rather than admitted.',
        },
        lookbackYears: {
            type: 'integer',
            minimum: 0,
            description:
                'How many earlier years of carried credits count toward the totals. 0 is this ' +
                'schedule alone; any number needs duty history, and none supplied is reported.',
        },
    },
    required: ['holidays', 'lookbackYears'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): HolidayEquityParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `holiday_equity on condition "${condition.id}"`);

    return condition.params as unknown as HolidayEquityParams;
}

/**
 * Owner decision W's reading of a carried credit: an absent key and an explicit `null` are ZERO.
 *
 * The ONE definition, so the predicate and any future reader cannot disagree about what an
 * unrecorded past means. Encoding it as unknown is what the answer overrode: it makes the lookback
 * half silently do nothing in year one and actively mis-schedule in year two, because a person
 * nobody has a record for is not a person who has done nothing.
 */
export function carriedCredits(person: Person, holidayKey: string): number {
    return person.priorCredits?.[holidayKey] ?? 0;
}

/** CG-04's sentence, carrying decision W's accepted limitation. See the module docblock. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const params = readParams(condition);

    return messages.holidayEquity({ holidays: params.holidays, lookbackYears: params.lookbackYears });
};

/** What one person carries, once the schedule and the lookback have both been read. */
export interface HolidayStanding {
    person: Person;
    credits: number;
    duties: Duty[];
}

/** The one label a cohort violation carries, built from the condition's own scope. */
function scopeLabelFor(scope: ConditionScope | undefined, messages: ViolationMessages): string {
    return messages.cohortScopeLabel({
        unitKeys: scope?.unitKeys ?? [],
        levelKeys: scope?.levelKeys ?? [],
        personKeys: scope?.personKeys ?? [],
    });
}

/** How many credits above the fewest a spread may reach before it is a credit somebody else's. */
const SPREAD_ALLOWANCE = 1;

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const horizon = schedule.horizon;
    const named = new Set(params.holidays);
    const skipped: SkippedWindow[] = [];

    // The horizon ONLY. A cohort location has no date for `evaluate()`'s emission rule to test, so
    // a holiday sitting in the carry-in tail would otherwise credit somebody in a finding nothing
    // could drop — CG-03 enforced by the type, because it cannot be enforced for it.
    const horizonDays = context.days.filter((day) => withinHorizon(horizon, day.date));

    // `${key}|${year}` — one credit per holiday PER YEAR, so the year is part of the identity and
    // a multi-day holiday collapses to a single entry by construction rather than by a de-dupe.
    const creditKeysByDate = new Map<string, Set<string>>();

    for (const day of horizonDays) {
        const keys = new Set<string>();

        for (const holiday of day.holidays) {
            if (named.has(holiday.key)) {
                keys.add(`${holiday.key}|${holiday.year}`);
            }
        }

        if (keys.size > 0) {
            creditKeysByDate.set(day.date, keys);
        }
    }

    for (const holidayKey of params.holidays) {
        const reached = horizonDays.some((day) => day.holidays.some((holiday) => holiday.key === holidayKey));

        if (!reached) {
            skipped.push({
                from: horizon.from,
                to: horizon.to,
                reason: messages.holidayNotInHorizonSkip({
                    holidayKey,
                    from: horizon.from,
                    to: horizon.to,
                }),
            });
        }
    }

    const lookbackCounted = params.lookbackYears > 0 && context.historyAvailableFrom !== null;

    if (params.lookbackYears > 0 && !lookbackCounted) {
        skipped.push({
            from: horizon.from,
            to: horizon.to,
            reason: messages.holidayLookbackSkip({
                lookbackYears: params.lookbackYears,
                from: horizon.from,
                to: horizon.to,
            }),
        });
    }

    const cohort = roster.filter((person) => personInScope(person, horizon.from, condition.scope));

    const standings: HolidayStanding[] = cohort.map((person) => {
        const earned = new Set<string>();
        const duties: Duty[] = [];

        // NO EARLY EXIT: every duty is examined even once the person has earned a credit for the
        // holiday-year it falls on, because `contributing` is the whole list a scheduler acts on
        // and a scan that stopped at the first would name one date of a holiday somebody worked
        // three of.
        for (const duty of schedule.duties) {
            if (duty.personKey !== person.key) {
                continue;
            }

            const keys = creditKeysByDate.get(duty.date);

            if (keys === undefined) {
                continue;
            }

            duties.push(duty);

            for (const key of keys) {
                earned.add(key);
            }
        }

        const carried = lookbackCounted
            ? params.holidays.reduce((sum, holidayKey) => sum + carriedCredits(person, holidayKey), 0)
            : 0;

        return { person, duties, credits: carried + earned.size };
    });

    if (standings.length === 0) {
        return { findings: [], coverage: { evaluatedWindows: 0, skipped } };
    }

    const fewest = Math.min(...standings.map((standing) => standing.credits));
    const over = standings.filter((standing) => standing.credits > fewest + SPREAD_ALLOWANCE);

    const findings: Finding[] =
        over.length === 0
            ? []
            : [
                  {
                      location: {
                          kind: 'cohort',
                          personKeys: over.map((standing) => standing.person.key),
                          scopeLabel: scopeLabelFor(condition.scope, messages),
                          contributing: over.flatMap((standing) => standing.duties),
                      },
                      explanation: messages.holidayEquityViolation({
                          holidays: params.holidays,
                          holdings: over.map((standing) => ({
                              personKey: standing.person.key,
                              credits: standing.credits,
                          })),
                          fewest,
                          lookbackCounted,
                          lookbackYears: params.lookbackYears,
                      }),
                  },
              ];

    return { findings, coverage: { evaluatedWindows: 1, skipped } };
};
