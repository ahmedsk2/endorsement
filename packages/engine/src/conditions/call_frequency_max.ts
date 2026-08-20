/**
 * `call_frequency_max` — CG-07: *"In-house call ≤ one night in N | N; averaging window"*.
 *
 * ## Owner decision J, ANSWERED against its own default: the denominator is AVAILABLE days
 *
 * *"One in four"* is not *"at most seven calls in twenty-eight days"*. It is **at most one call for
 * every four days the person could actually have been scheduled**, and the days they could not —
 * leave, dates before `joinedAt`, dates they were off the roster — are removed from the divisor
 * before it is taken. `eligibleDays` is that list, computed once server-side by the one context
 * builder and carried (Decision A), never re-derived here from `leaveDays` plus a rotation scan:
 * four types read it and four re-derivations of one availability rule would disagree exactly where
 * a person's rotation changes mid-month.
 *
 * **The consequence is intended, it is the opposite of what a reader predicts, and it is printed.**
 * A calendar denominator LOOSENS around leave — fewer duties, same divisor. This one TIGHTENS: a
 * person with fourteen days' leave in a twenty-eight-day window is measured against fourteen
 * eligible days, so *"one in four"* permits three calls and not seven. That is what protects
 * somebody from being back-loaded around their own leave, and it is why both CG-04's preview and
 * every violation sentence state the denominator they used, as a number.
 *
 * The allowance is therefore {@link permittedFor}: `floor(availableDays / n)`. A window in which
 * somebody was available on fewer than `n` days permits ZERO calls, which is not a degenerate case
 * to be guarded away — it is the rule saying that a person who was around for one day of a
 * fortnight should not have spent it on call.
 *
 * ## DENSITY, not spacing, and the catalog ships both on purpose
 *
 * One call in three, averaged over four weeks, permits two calls on consecutive days; a `min_gap`
 * of three days forbids exactly that pair. Neither is a weaker version of the other and a
 * department will enable both. `call-frequency-max-is-density-where-min-gap-is-spacing` is the
 * corpus case where they disagree on one world, so an implementation that collapsed either into
 * the other fails rather than looking like a tidy-up.
 *
 * ## The window is ROLLING and its length is a count of DAYS
 *
 * Decision J leaves the alignment unchanged: rolling, anchored on the evaluated duty's date, length
 * in days. `enumerateWindows('rolling', …)` produces every window of that length that can touch the
 * horizon, which CONTAINS every window anchored on a duty's date and is the same answer for a cap;
 * the violation is then located at the WINDOW, which is what the registry entry declares and what
 * makes the finding actionable — the offending density is a property of a stretch of dates, not of
 * the one placement that happened to tip it.
 *
 * `windowDays` is a count rather than a `'week' | 'period'` enum, and the NAME is the explicit unit
 * the plan asks for. `rolling_hours_max` records the reason and it holds here twice over: a rolling
 * window aligns to nothing, and `weekStartIsoDay` is derived from `weekend_days`, so a department
 * editing its weekend would silently move a duty-hours rule. A single-member `unit` enum was
 * considered and rejected — it would carry no information and `preview.test.ts`'s matrix cannot
 * probe an enum with one member.
 *
 * ## A CAP whose limit is NOT authored, which is why it declines a partial window
 *
 * Owner decision L lets a cap evaluate a window the engine can only see part of, on the stated
 * ground that *"a count that is too low never exceeds a limit"*. That argument assumes the limit is
 * a number a department wrote down. Here it is `floor(availableDays / n)`, computed from the
 * window's OWN contents — so a partial window loses eligible days as fast as it loses calls, the
 * allowance falls with the count, and the result false-positives exactly as a floor does. One
 * measured example is in `call-frequency-max-a-window-it-can-only-see-part-of-is-left-unjudged`:
 * the window reaching two days outside the evaluable range would show one call against an allowance
 * of zero.
 *
 * So this type calls {@link wholeWindowVerdict}, and the dividing line the phase had been drawing
 * as *cap versus floor* is corrected to **authored limit versus derived limit**. `rolling_hours_max`
 * — landed in the same task and a cap with an authored figure — does not call it, which is what
 * makes the pair readable side by side.
 *
 * **`midWindowJoinSkip` is deliberately NOT called, and that is the same decision read the other
 * way.** Owner decision L's per-person half suppresses a FLOOR for somebody who joined part way
 * through the window, because an absolute number they could not have reached is a false positive.
 * Decision J's answer is that this rule's number is not absolute: the days before somebody joined
 * are already out of their denominator, so the allowance has moved with them and there is nothing
 * left to suppress. Suppressing anyway would delete the rule for every new starter's first window,
 * which is when a department is most likely to over-call them.
 *
 * ## Duty→date reading: ANCHOR DATE
 *
 * `DUTY_DATE_READING.call_frequency_max`. A Friday-night call running to Saturday morning is ONE
 * Friday call, counted in whichever windows Friday falls in. The split reading belongs to
 * `rolling_hours_max` alone and would make one call two thirds of a call in two windows here.
 *
 * ## No `kinds` parameter
 *
 * CG-07's cell is *"N; averaging window"* and names none, exactly as `target_per_period`'s and
 * `composition`'s do. A department wanting a per-kind density has `count_max`, whose cell does name
 * `kinds`. Stated because the absence otherwise reads as an oversight beside three neighbours that
 * have it.
 *
 * ## PLANTED
 *
 * `personInScope` answering `true` (the standing FIRST plant for a new window-located type); the
 * denominator swapped for calendar days; the allowance rounded up rather than down; the
 * `wholeWindowVerdict` gate removed so partial windows are judged; and the enumeration started at
 * `horizon.from`, so the window straddling the seam disappears. Each went red naming its own case.
 */

import { compareYmd } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    Person,
    SkippedWindow,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { slotIndex } from '../duty/order';
import { enumerateWindows, type Window } from '../duty/windows';
import {
    carryInLeftEdge,
    dutyStreams,
    personInScope,
    positionedIn,
    rosterFor,
    wholeWindowVerdict,
} from './support';

/** `call_frequency_max`'s parameters, normalised. */
export interface CallFrequencyMaxParams {
    n: number;
    windowDays: number;
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        n: {
            type: 'integer',
            minimum: 1,
            description:
                'At most one call in every N days the person is AVAILABLE (owner decision J). Days ' +
                'on leave, before the join date and off the roster are not in the denominator.',
        },
        windowDays: {
            type: 'integer',
            minimum: 1,
            description:
                "CG-07's averaging window, in consecutive days. Rolling, so it aligns to nothing — " +
                'and a count of days rather than "weeks", which would move with weekend_days.',
        },
    },
    required: ['n', 'windowDays'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): CallFrequencyMaxParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `call_frequency_max on condition "${condition.id}"`);

    return condition.params as unknown as CallFrequencyMaxParams;
}

/**
 * Owner decision J's allowance: `floor(availableDays / n)`. The ONE definition, shared.
 *
 * Called by the preview's worked points and by the predicate, so the number a gate screen promises
 * and the number that blocks a publish cannot become two. It rounds DOWN because *"one in four"*
 * with three available days is not one call — rounding up would hand back the seventh call the
 * decision exists to remove.
 */
export function permittedFor(availableDays: number, n: number): number {
    return Math.floor(availableDays / n);
}

/**
 * The two worked points CG-04's sentence prints: a full window, and one halved by leave.
 *
 * Decision J requires the preview to say WHICH denominator it used, and a reader who has been told
 * *"available days"* still has to divide to find out what changes. The second point is what makes
 * the tightening visible — it is the half of the answer a calendar-denominator reader gets wrong.
 */
export function previewExamples(params: CallFrequencyMaxParams): { availableDays: number; permitted: number }[] {
    return [params.windowDays, Math.floor(params.windowDays / 2)].map((availableDays) => ({
        availableDays,
        permitted: permittedFor(availableDays, params.n),
    }));
}

/** CG-04's sentence, naming the denominator it used. See the module docblock. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const params = readParams(condition);

    return messages.callFrequencyMax({
        n: params.n,
        windowDays: params.windowDays,
        examples: previewExamples(params),
    });
};

/** How many days of this window the person could actually have been scheduled on. */
function availableDaysIn(person: Person, window: Window): number {
    return person.eligibleDays.filter(
        (date) => compareYmd(date, window.from) >= 0 && compareYmd(date, window.to) <= 0,
    ).length;
}

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const slots = slotIndex(context.slots);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const findings: Finding[] = [];
    const skipped: SkippedWindow[] = [...carryInLeftEdge(context, schedule.horizon, messages)];
    const windows = enumerateWindows('rolling', params.windowDays, schedule.horizon);

    let evaluated = 0;

    for (const window of windows) {
        // A cap with a DERIVED limit refuses both partial shapes — see the module docblock. The
        // gate is shared with the floors and the targets rather than restated, so the two skip
        // reports cannot drift apart from theirs.
        const verdict = wholeWindowVerdict(window, context, schedule.horizon, messages);

        if (!verdict.measure) {
            if (verdict.skip !== null) {
                skipped.push(verdict.skip);
            }

            continue;
        }

        evaluated += 1;

        for (const person of roster) {
            if (!personInScope(person, window.from, condition.scope)) {
                continue;
            }

            const availableDays = availableDaysIn(person, window);
            const permitted = permittedFor(availableDays, params.n);
            const contributing = positionedIn(person.key, window, streams, slots).map(
                (positioned) => positioned.duty,
            );

            if (contributing.length <= permitted) {
                continue;
            }

            findings.push({
                location: {
                    kind: 'window',
                    personKey: person.key,
                    from: window.from,
                    to: window.to,
                    contributing,
                },
                explanation: messages.callFrequencyMaxViolation({
                    calls: contributing.length,
                    permitted,
                    n: params.n,
                    availableDays,
                    windowDays: params.windowDays,
                    from: window.from,
                    to: window.to,
                }),
            });
        }
    }

    return { findings, coverage: { evaluatedWindows: evaluated, skipped } };
};
