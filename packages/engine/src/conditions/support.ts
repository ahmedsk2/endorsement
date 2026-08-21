/**
 * What every condition type needs and none of them may answer twice.
 *
 * The precedent is `AuditChain::canonical()`: two copies of one canonical fact drifted the day
 * `APP_TIMEZONE` was set, and a live system announced its whole audit trail as tampered when
 * nothing had been. Twenty-two predicates each deciding for themselves what *"the level this
 * person holds on this date"* means is the same defect with twenty-two copies, and the ones that
 * disagree would disagree only on the dates a promotion or a rotation change falls — which is to
 * say, on the dates it matters.
 *
 * ## The three things here
 *
 *  - **A dated fact about a person** — {@link spanKeyAt}, and the level and unit readings built on
 *    it. Read at the DUTY's date, never once per evaluation.
 *  - **CG-01's scope** — {@link personInScope}. A scope that is quietly ignored makes a condition
 *    do MORE than the gate screen says it does, which is rulings 41/49's failure shape pointing
 *    the other way: not a control that appears to do nothing, but one that appears to do less.
 *  - **The carry-in left edge** — {@link carryInLeftEdge}. Every `needsCarryIn` type measures a
 *    relationship reaching back before `horizon.from`, and when no history was supplied it must say
 *    so rather than treat the 1st as the start of time. A silently dropped window is a guard that
 *    looks green.
 *
 * ## NO ENGLISH LIVES IN THIS FILE ANY MORE, and it used to
 *
 * Until P2-2's first task, `ConditionPreview` took the message table as an argument and
 * `ConditionEvaluator` did not, so every violation `explanation` and every `coverage()` reason was
 * assembled from literals at the call site — AR-07 holding for the preview beside them and not for
 * them. Threading the table through `evaluate()`/`coverage()` was a contract change worth making
 * ONCE, before eleven more types hardcoded English, and this file lost two things to it:
 *
 *  - **`list()`**, a second `conjoin`, deleted rather than kept. It existed because a predicate had
 *    no table to reach for, and a second definition of *"a, b and c"* is a second thing to translate.
 *  - **`hoursText()`**, moved to the table's `Vocabulary.hours`, because a decimal SEPARATOR is a
 *    locale's decision and a formatter outside the table can never honour one.
 *
 * {@link carryInLeftEdge} still decides WHICH of the two left-edge shapes happened; it no longer
 * decides how either is said.
 */

import { addDays, compareYmd, datesBetween, isoWeekday, type Ymd } from '../calendar/ymd';
import type {
    ConditionScope,
    CoverageDetail,
    Day,
    EvaluationContext,
    Period,
    Person,
    Schedule,
    SkippedWindow,
    ViolationMessages,
    Week,
} from '../contract/types';
import type { Duty } from '../duty/interval';
import { orderedDutiesFor, type DutyStreams, type PositionedDuty, type SlotIndex } from '../duty/order';
import { windowFor, windowTouchesHorizon, type Horizon, type Window } from '../duty/windows';
import type { Span } from '../contract/types';

/**
 * The key of the span covering `date`, or `null` when none does.
 *
 * Both bounds INCLUSIVE, matching `vacations` and every other dated span this system stores. A
 * person between two rotations, or before their first, holds nothing — which is a real state and
 * is answered as `null` rather than as an empty string, because an empty string compares equal to
 * an empty allow-list entry and would make a nobody eligible for everything.
 *
 * Overlapping spans are not this function's problem to resolve: the FIRST covering span wins, the
 * caller supplied the order, and a person holding two levels on one date is a defect in the roster
 * that P1b's screens own.
 */
export function spanKeyAt(spans: readonly Span[], date: Ymd): string | null {
    for (const span of spans) {
        if (compareYmd(date, span.from) >= 0 && compareYmd(date, span.to) <= 0) {
            return span.key;
        }
    }

    return null;
}

/** The level code this person holds on this date, or `null`. */
export function levelKeyAt(person: Person, date: Ymd): string | null {
    return spanKeyAt(person.levelSpans, date);
}

/** The unit code this person is rotating on on this date, or `null`. */
export function unitKeyAt(person: Person, date: Ymd): string | null {
    return spanKeyAt(person.unitSpans, date);
}

/**
 * CG-01's scope: does this condition apply to this person on this date?
 *
 * An ABSENT member is no filter; a PRESENT one narrows. All three narrow together — a condition
 * scoped to `{ unitKeys: ['PICU'], levelKeys: ['R1'] }` applies to R1s on PICU and to nobody else,
 * because that is what two filters on one row read as on the gate screen.
 *
 * Unit and level are read AT THE DATE, for the reason the module docblock gives.
 */
export function personInScope(person: Person, date: Ymd, scope: ConditionScope | undefined): boolean {
    if (scope === undefined) {
        return true;
    }

    if (scope.personKeys !== undefined && !scope.personKeys.includes(person.key)) {
        return false;
    }

    if (scope.levelKeys !== undefined) {
        const level = levelKeyAt(person, date);

        if (level === null || !scope.levelKeys.includes(level)) {
            return false;
        }
    }

    if (scope.unitKeys !== undefined) {
        const unit = unitKeyAt(person, date);

        if (unit === null || !scope.unitKeys.includes(unit)) {
            return false;
        }
    }

    return true;
}

/**
 * The people the context knows, by key. A duty naming a stranger THROWS.
 *
 * A duty whose person the context does not describe cannot be judged: their leave, their level and
 * their rotation are all unknown, and every one of the three types in this task would answer "no
 * violation" for want of data. That is a Hard rule silently passing on incomplete input, which is
 * strictly worse than a crash — the same reasoning `slotIndex()` records for a duty naming a slot
 * nobody supplied.
 */
export function personIndex(context: EvaluationContext): { get(key: string): Person } {
    const byKey = new Map<string, Person>();

    for (const person of context.people) {
        if (byKey.has(person.key)) {
            throw new RangeError(`Two people share the key "${person.key}"; a person key identifies one person.`);
        }

        byKey.set(person.key, person);
    }

    return {
        get(key: string): Person {
            const person = byKey.get(key);

            if (person === undefined) {
                throw new RangeError(
                    `No person named "${key}" in the evaluation context. A duty for somebody the ` +
                        'context does not describe cannot be judged — their leave, level and rotation are ' +
                        'all unknown — and answering "no violation" would be a Hard rule passing for want ' +
                        'of data.',
                );
            }

            return person;
        },
    };
}

/**
 * Whether a slot's kind is one this condition names. An EMPTY list names every kind.
 *
 * Three types narrow through this one function — `min_gap`, `post_duty_exclusion` and
 * `consecutive_max` — and until P2-1's review not one of them had a case in which it narrowed
 * anything: no corpus entry set `kinds`, `from` or `to` to a list that excluded a duty actually
 * present, so `return true` here was green across the whole suite. The empty-list half was
 * exercised by every case and the NAMED half by none, which is the same green as an unwritten
 * filter.
 *
 * PLANTED, after the three cases below were added: `return true` fails 7 tests across all three
 * types. Reverted. The cases are `min-gap-kinds-names-both-sides-of-the-pair`,
 * `consecutive-max-what-the-nights-unit-and-the-kinds-list-leave-out` and
 * `post-duty-exclusion-the-from-and-to-kinds-each-narrow`.
 */
export function kindMatches(kind: string, kinds: readonly string[]): boolean {
    return kinds.length === 0 || kinds.includes(kind);
}

/** The precomputed day vector, by date. `get` throws on a date the context does not describe. */
export interface DayIndex {
    get(date: Ymd): Day;
    find(date: Ymd): Day | null;
}

/**
 * The day vector by date — the ONE answer to "what kind of day is this", never recomputed here.
 *
 * `days` is precomputed server-side by the one converter (AR-08, finding 21): `dayType` makes
 * holiday win over weekend deliberately, `isoWeekday` is the department's own calendar's, and a
 * type re-deriving either from `weekendDays` would be a second definition of a per-department
 * fact — which is what `golden.json` and Decision C exist to prevent.
 *
 * `get` THROWS on a date the vector does not cover, exactly as `slotIndex()` and `personIndex()`
 * throw: a duty inside the horizon whose date the caller omitted is dropped context, and answering
 * "not a banned day" for want of the row is a Hard rule passing on incomplete input. `find` is the
 * lenient half, for the one question that legitimately reaches PAST the horizon — a post-duty
 * window opened on the last date of the month closes on a date the vector does not describe, and
 * the type asking it says so through `coverage()` rather than crashing on a correct schedule.
 */
export function dayIndex(context: EvaluationContext): DayIndex {
    const byDate = new Map<string, Day>();

    for (const day of context.days) {
        if (byDate.has(day.date)) {
            throw new RangeError(`Two day rows share the date ${day.date}; a date describes one day.`);
        }

        byDate.set(day.date, day);
    }

    return {
        find: (date: Ymd): Day | null => byDate.get(date) ?? null,
        get: (date: Ymd): Day => {
            const day = byDate.get(date);

            if (day === undefined) {
                throw new RangeError(
                    `The evaluation context describes no day ${date}, so nothing here knows what kind ` +
                        'of day it is. The day vector is precomputed server-side by the one converter ' +
                        '(AR-08) and this package deliberately cannot fill the gap in.',
                );
            }

            return day;
        },
    };
}

/**
 * The ISO weekday of a date, from the day vector where it reaches and from arithmetic where it does
 * not — and this is the ONE place that fallback is allowed to exist.
 *
 * The vector covers the horizon. One question legitimately reaches past it: `clinic_conflict`'s
 * post-duty window opens on the last date of the month and closes on the 1st of the next, where a
 * clinic runs and the violation is located on a placement INSIDE the horizon. Refusing to answer
 * would drop a real collision at the edge a scheduler hits first; asking for the day vector to be
 * extended is a contract change for one field.
 *
 * **This is not AR-08's second definition, and the distinction is the whole justification.** What
 * AR-08 forbids re-deriving are the DEPARTMENT's facts — `dayType` (holiday wins over weekend), the
 * week start, the weekend days — because those are configuration and a mirror would disagree with
 * the server about them. The ISO weekday of a civil date is universal arithmetic, `ymd.ts` owns it,
 * and `golden.test.ts` asserts it against `golden.json`'s own `iso_weekday` for every date in the
 * corpus. `conditions.test.ts` pins the other half: for every date the day vector DOES describe,
 * the two answers agree, in every fixture — so the fallback cannot become a second answer.
 */
export function isoWeekdayAt(days: DayIndex, date: Ymd): number {
    return days.find(date)?.isoWeekday ?? isoWeekday(date);
}

/**
 * The window before the horizon that could not be examined, because no history was supplied.
 *
 * Owner decision F puts `priorDuties` in the context and makes it read-only; `historyAvailableFrom`
 * says how far back that history reaches. When it is `null` there is none at all, so a duty running
 * past midnight into the 1st — or a gap, or a run — is invisible on one side, and the type must say
 * so through `coverage()` rather than treat the 1st as the start of time.
 *
 * An EMPTY `priorDuties` with a real `historyAvailableFrom` is NOT a gap: it is the caller saying
 * *"I looked, and there were no duties"*, which is an answer. Conflating the two would report a
 * skipped window on every correctly-supplied month and train a reader to ignore the field.
 *
 * ## There are TWO ways to have no usable history, and the reason names the one that happened
 *
 * `null` is one. The other is a `historyAvailableFrom` that is real but does not reach BACK PAST
 * `horizon.from` — history supplied only from the 1st onwards, which is what a first-ever draft or
 * a freshly provisioned instance has. The window is unevaluable either way and the skip is correct
 * either way, but the reason shipped saying *"historyAvailableFrom is null"* in both, so the second
 * case printed a sentence contradicted by the very field it names. A coverage row a reader can
 * catch out is one they stop reading, which is the whole thing this function exists to avoid.
 *
 * STATED RESIDUAL: there is no `futureAvailableTo` counterpart, so the RIGHT edge is not reported.
 * `followingDuties` is usually empty and legitimately so, and no context field distinguishes
 * *"nothing follows"* from *"nothing was supplied"*. Adding one is a contract change; guessing from
 * emptiness would report a skip on almost every evaluation, which is the noise this function's own
 * second paragraph refuses.
 */
export function carryInLeftEdge(
    context: EvaluationContext,
    horizon: Horizon,
    messages: ViolationMessages,
): SkippedWindow[] {
    const available = context.historyAvailableFrom;

    if (available !== null && compareYmd(available, horizon.from) < 0) {
        return [];
    }

    const to = addDays(horizon.from, -1);

    if (compareYmd(horizon.evaluableFrom, to) > 0) {
        return [];
    }

    return [
        {
            from: horizon.evaluableFrom,
            to,
            // WHICH shape happened is this function's decision; how it is SAID is the table's.
            reason: messages.carryInSkip({ horizonFrom: horizon.from, historyAvailableFrom: available }),
        },
    ];
}

/** The three duty streams, in the shape `orderedDutiesFor` reads them. */
export function dutyStreams(
    schedule: Schedule,
    context: EvaluationContext,
): { priorDuties: readonly Duty[]; duties: readonly Duty[]; followingDuties: readonly Duty[] } {
    return {
        priorDuties: context.priorDuties,
        duties: schedule.duties,
        followingDuties: context.followingDuties,
    };
}

/** A placement type measured this many placements. See {@link CoverageDetail}'s own docblock. */
export function placementsCovered(evaluatedWindows: number, skipped: SkippedWindow[]): CoverageDetail {
    return { evaluatedWindows, skipped };
}

// ---------------------------------------------------------------------------------------------
// The window-located half (P2-2, Tasks 15–17). Everything below is consumed by a type whose
// violation is a `{kind: 'window'}` location, and by nothing that produces a placement.
// ---------------------------------------------------------------------------------------------

/**
 * One measurable range, with the period — and, for a week window, the week — it came from.
 *
 * The department's own weeks arrive in the context as `periods[].weeks` with CLIPPED bounds,
 * computed server-side by `Calendar::weeksIn()` (owner decision O). They are never recomputed here
 * and never derived from `weekStartIsoDay`: `golden.json` has zero coverage of the clipped bounds,
 * so a mirror implementation would be an unasserted second definition of a per-department fact.
 * The period is carried because `target_per_period`'s modifiers read `weeks.length`.
 */
export interface PeriodWindow {
    window: Window;
    period: Period;
    week: Week | null;
}

/**
 * The period or week windows that can hold a reportable violation, earliest first.
 *
 * ## The CLIPPED bounds are the window, and that is the whole point of carrying them
 *
 * A week at a period edge is shorter than seven days, and its real extent is the department's
 * answer rather than this package's. Measuring the raw `startsOn..endsOn` instead counts duties
 * belonging to the neighbouring block — silently, and only at a block boundary, which is where a
 * scheduler is least able to check the arithmetic by eye.
 *
 * ## Only windows that TOUCH the horizon are enumerated
 *
 * A window entirely inside the carry-in tail can hold no violation anybody may see: `evaluate()`'s
 * emission rule drops it (CG-03, never retroactive on published schedules). Enumerating it anyway
 * would inflate `evaluatedWindows` with measurements whose results are discarded, which reads on a
 * coverage row as work that was done and was not. {@link windowTouchesHorizon} is the SAME
 * predicate the emission rule applies, imported rather than restated, so what is measured and what
 * may be reported cannot disagree at the left edge.
 */
export function periodWindows(
    context: EvaluationContext,
    horizon: Horizon,
    kind: 'period' | 'week',
): PeriodWindow[] {
    const found: PeriodWindow[] = [];

    for (const period of context.periods) {
        if (kind === 'period') {
            if (windowTouchesHorizon(period.startsOn, period.endsOn, horizon)) {
                found.push({ window: windowFor(period.startsOn, period.endsOn, horizon), period, week: null });
            }

            continue;
        }

        for (const week of period.weeks) {
            if (!windowTouchesHorizon(week.clippedStartsOn, week.clippedEndsOn, horizon)) {
                continue;
            }

            found.push({
                window: windowFor(week.clippedStartsOn, week.clippedEndsOn, horizon),
                period,
                week,
            });
        }
    }

    return found.sort(
        (a, b) => compareYmd(a.window.from, b.window.from) || compareYmd(a.window.to, b.window.to),
    );
}

/**
 * Does the supplied duty history reach back far enough to know what happened at `from`?
 *
 * A window that begins inside the horizon needs no history at all — the schedule under evaluation
 * IS the answer. A window reaching back before `horizon.from` is asking about a month somebody else
 * published, and `historyAvailableFrom` is the caller's statement of how far back that reaches.
 *
 * The distinction this exists to keep is between a window that is SHORTER and a window whose left
 * part is UNKNOWN. A clipped week at a period edge is a genuinely smaller window and counting over
 * it is a correct answer to a smaller question; a window whose first four days were never supplied
 * is a wrong answer to the right one. Owner decision L lets a CAP evaluate the first (an
 * under-count produces no false positive); nothing may treat the second as an answer, which is why
 * {@link carryInLeftEdge} reports it rather than any type quietly counting zero.
 */
export function historyReaches(context: EvaluationContext, horizon: Horizon, from: Ymd): boolean {
    if (compareYmd(from, horizon.from) >= 0) {
        return true;
    }

    const available = context.historyAvailableFrom;

    return available !== null && compareYmd(available, from) <= 0;
}

/**
 * `levels` inside a type's own parameters, as owner decision K defines it: a SCOPE FILTER.
 *
 * It names which people the rule applies to, and it INTERSECTS CG-01's `scope` rather than
 * replacing it — decision K's own words, *"a bare `levels` list documented as intersecting
 * `scope`"*. An empty or absent list names everybody the scope already selected.
 *
 * The level is read AT THE DATE, through `spanKeyAt`, exactly as `personInScope` reads it. A person
 * holding no level on that date matches no named level and is excluded: an absent level is not a
 * wildcard, and treating it as one would apply a per-level cap to somebody the department has not
 * placed on the ladder at all.
 */
export function levelFilterMatches(person: Person, date: Ymd, levels: readonly string[]): boolean {
    if (levels.length === 0) {
        return true;
    }

    const level = levelKeyAt(person, date);

    return level !== null && levels.includes(level);
}

/**
 * Was this person on the roster for the WHOLE of this window?
 *
 * Owner decision L applied one axis along: *"floors and targets evaluate only on whole windows"*.
 * A person who joined half way through a period did not have the window the floor is measuring, and
 * judging them against the absolute number produces a false positive on their very first block —
 * which is the shape decision L exists to refuse, differing only in whether the window was clipped
 * by the DATA or by the PERSON.
 *
 * **It is not the same statement as leave, and leave deliberately does NOT suppress a floor.** A
 * person on leave for three weeks of a block has the whole window; they were simply unavailable in
 * it, and pro-rating the number for that is exactly the scaling decision L refuses (*"period-windowed
 * numbers are ABSOLUTE, not scaled by period length"*). A person who has not joined has no window
 * yet, which is a different fact. The two are one line apart in an implementation and a month of
 * different behaviour on a rota, so both halves are stated here and both are fixtured.
 *
 * A person with no `joinedAt` at all is treated as having always been on the roster — the same
 * reading owner decision T gives `onboarding_grace`, and for the same measured reason: the column
 * is written by no seeder, factory or demo path in this repository, so the opposite reading would
 * suppress every window for everybody on the live instance.
 */
export function onRosterThroughout(person: Person, from: Ymd): boolean {
    return person.joinedAt === undefined || compareYmd(person.joinedAt, from) <= 0;
}

/**
 * One person's duties whose ANCHOR DATE falls inside a window, chronologically — with their slots.
 *
 * Decision A's anchor-date reading, in one place for the four window-located types that take it: a
 * Friday-night call running to Saturday morning is ONE Friday call, in the window Friday falls in
 * and in no other. Four copies of that filter would disagree only at a week boundary, which is
 * where a scheduler is least able to check it by eye.
 *
 * It reads all three streams, so a window reaching into the carry-in tail counts what is there.
 */
export function positionedIn(
    personKey: string,
    window: Window,
    streams: DutyStreams,
    slots: SlotIndex,
): PositionedDuty[] {
    return positionedWithin(orderedDutiesFor(personKey, streams, slots), window);
}

/**
 * The anchor-date filter ALONE, over a line somebody has already resolved.
 *
 * {@link positionedIn} resolves and filters in one call, which is right for a type measuring a
 * handful of period or week windows. It is wrong for one enumerating a rolling window per day:
 * `orderedDutiesFor` scans all three streams and SORTS, so resolving inside the window loop is
 * `windows x people` sorts of the same list. Measured on the NF-01 case (P2-2 review): 34.6 ms of
 * a 58 ms budget in `rolling_hours_max` alone, on a schedule of ninety-three duties.
 *
 * The split keeps ONE definition of the filter while letting the caller decide when to resolve.
 * Two copies of *"whose anchor date falls in this window"* would disagree at a week boundary, which
 * is where a scheduler is least able to check it by eye — this function's own recorded reason.
 */
export function positionedWithin(ordered: readonly PositionedDuty[], window: Window): PositionedDuty[] {
    return ordered.filter(
        (positioned) =>
            compareYmd(positioned.duty.date, window.from) >= 0 &&
            compareYmd(positioned.duty.date, window.to) <= 0,
    );
}

/**
 * One person's ordered duty line, resolved at most ONCE per evaluation.
 *
 * LAZY rather than precomputed over the roster, and that is a correctness choice rather than a
 * performance one: `orderedDutiesFor` throws on a duty naming an unsupplied slot, so resolving
 * everybody up front would raise on a person the condition's scope was about to exclude — a
 * different answer for the same input, which is the one thing a pure function may not do. Asking on
 * demand makes exactly the same set of calls as before, just not repeatedly.
 */
export function orderedByPerson(streams: DutyStreams, slots: SlotIndex): (personKey: string) => PositionedDuty[] {
    const resolved = new Map<string, PositionedDuty[]>();

    return (personKey: string): PositionedDuty[] => {
        let found = resolved.get(personKey);

        if (found === undefined) {
            found = orderedDutiesFor(personKey, streams, slots);
            resolved.set(personKey, found);
        }

        return found;
    };
}

/**
 * The same list as bare duties — a window violation's `contributing`.
 *
 * `contributing` is MANDATORY on a window location for exactly this reason: a scheduler told a
 * period is off target and not which placement to move has been told nothing they can act on. It
 * MAY be empty, and that is a floor or a target answering rather than failing to — the person who
 * holds nothing is precisely whom a floor exists to find.
 */
export function dutiesIn(
    personKey: string,
    window: Window,
    streams: DutyStreams,
    slots: SlotIndex,
): Duty[] {
    return positionedIn(personKey, window, streams, slots).map((positioned) => positioned.duty);
}

/** May this window be measured, and if not, is there a row to show for it? */
export type WindowVerdict = { measure: true } | { measure: false; skip: SkippedWindow | null };

/**
 * Owner decision L's gate, in ONE place for the three types that need it.
 *
 * A floor and a target may measure only a WHOLE window, and a window can fail to be whole in two
 * different ways that deserve two different reports:
 *
 *  - **Clipped by the evaluable range** — named individually, because *which* window it was is the
 *    actionable half and the answer differs per window.
 *  - **Reaching back before the horizon with no history behind it** — reported by
 *    {@link carryInLeftEdge}'s single row instead, because the answer is identical for every such
 *    window and one row apiece would repeat one fact until a reader stopped reading them. The
 *    `skip: null` is that decision, spelled as a value rather than left as a missing branch.
 *  - **Reaching back further than the history that WAS supplied** — named individually, and added
 *    by the P2-2 review because it was silently a THIRD shape wearing the second's answer. See
 *    below.
 *
 * A CAP does not call this at all: an under-count cannot exceed a limit, so it evaluates both
 * shapes. That asymmetry is the whole of decision L and it is why this returns a verdict rather
 * than a boolean — a boolean would have made the two skip shapes one, which is the distinction.
 *
 * ## The third shape, and why `skip: null` was silently wrong for it
 *
 * {@link carryInLeftEdge} speaks for exactly two states: `historyAvailableFrom` is `null`, or it is
 * real and begins at or after `horizon.from`. It is SILENT when history reaches back past the
 * horizon — a caller who supplied last week — and that is precisely when a window opening before
 * the horizon can still reach back further than the history does. A block that opened on 26 July,
 * a horizon opening on 1 August, and history from 28 July: `carryInLeftEdge` returns nothing
 * because it saw real history before the 1st, and this function returned `skip: null` because it
 * believed `carryInLeftEdge` was speaking. The window was measured by nobody and reported by
 * nobody; `evaluatedWindows` simply fell.
 *
 * That is the state `coverage()` exists to prevent, one branch away from the state it already
 * reported correctly. The row is per-window rather than pooled because which window went, and how
 * much further back the history would have to reach, are both the window's own answer — the same
 * line as the clipped shape above, for the same reason.
 */
export function wholeWindowVerdict(
    window: Window,
    context: EvaluationContext,
    horizon: Horizon,
    messages: ViolationMessages,
): WindowVerdict {
    if (!window.fullyEvaluable) {
        return {
            measure: false,
            skip: {
                from: window.from,
                to: window.to,
                reason: messages.partialWindowSkip({
                    from: window.from,
                    to: window.to,
                    evaluableFrom: horizon.evaluableFrom,
                    evaluableTo: horizon.evaluableTo,
                }),
            },
        };
    }

    if (!historyReaches(context, horizon, window.from)) {
        const available = context.historyAvailableFrom;

        // The two shapes `carryInLeftEdge` owns. One row already speaks for every window they
        // affect, so a row apiece here would repeat one fact until a reader stopped reading.
        if (available === null || compareYmd(available, horizon.from) >= 0) {
            return { measure: false, skip: null };
        }

        return {
            measure: false,
            skip: {
                from: window.from,
                to: window.to,
                reason: messages.historyShortOfWindowSkip({
                    from: window.from,
                    to: window.to,
                    historyAvailableFrom: available,
                }),
            },
        };
    }

    return { measure: true };
}

/**
 * The per-PERSON half of the same gate: did they have the whole of this window? (Owner decision L.)
 *
 * Returns the row when they did not, so the caller both skips and says so in one place — the pair
 * that P2-1's carry-in types learned to assert together, because a rule going quiet and a rule
 * reporting nothing look identical on a green suite.
 */
export function midWindowJoinSkip(
    person: Person,
    window: Window,
    messages: ViolationMessages,
): SkippedWindow | null {
    if (onRosterThroughout(person, window.from)) {
        return null;
    }

    return {
        from: window.from,
        to: window.to,
        reason: messages.midWindowJoinSkip({
            personKey: person.key,
            joinedAt: person.joinedAt as string,
            from: window.from,
            to: window.to,
        }),
    };
}

/**
 * `AvailabilitySummary`'s vacation-week rule, VERBATIM: any overlap with a week's CLIPPED bounds
 * counts as a whole vacation week (owner decision N).
 *
 * So a Thursday-to-Monday leave is TWO vacation weeks and a Sunday-to-Thursday leave is one, and
 * the count moves with where the leave falls rather than with how long it is. That is deliberately
 * not the intuitive reading, which is exactly why it is carried from one definition: the engine and
 * the rota screen reporting different counts for the same person in the same period is a
 * disagreement nobody could adjudicate from either screen.
 *
 * It reads the CLIPPED bounds for the same reason `periodWindows` does — at a block edge the
 * department's week is shorter, and leave in the days the block does not own belongs to the
 * neighbouring block's count.
 */
export function vacationWeeksIn(person: Person, period: Period): number {
    const leave = new Set<string>(person.leaveDays);

    return period.weeks.filter((week) =>
        datesBetween(week.clippedStartsOn, week.clippedEndsOn).some((date) => leave.has(date)),
    ).length;
}

/**
 * The roster, having refused any duty naming somebody the context does not describe.
 *
 * A window-located type iterates PEOPLE rather than duties — a floor's whole purpose is the person
 * who holds none — so `personIndex().get()` is never reached by the ordinary path and the stranger
 * check every placement type gets for free would simply not happen. Resolving every duty's person
 * up front restores it: a duty for somebody whose leave, level and rotation are all unknown cannot
 * be judged, and answering "no violation" for want of data is strictly worse than a crash.
 */
export function rosterFor(context: EvaluationContext, streams: DutyStreams): readonly Person[] {
    const people = personIndex(context);

    for (const duty of [...streams.priorDuties, ...streams.duties, ...streams.followingDuties]) {
        people.get(duty.personKey);
    }

    return context.people;
}
