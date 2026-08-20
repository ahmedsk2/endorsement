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
 * ## Explanations are English literals here, and that is a STATED residual
 *
 * CG-04's preview text goes through `messages.ts` because `ConditionPreview` takes the table as an
 * argument (AR-07). A violation's `explanation` does not: `ConditionEvaluator` was fixed at Task 7
 * without one. Threading the table through `evaluate()`/`coverage()` is a contract change worth
 * making ONCE, before nineteen more types hardcode English — recorded in the plan's recommended
 * additions rather than done here, because it is a change to Task 7's shape and not to Task 10's.
 */

import { addDays, compareYmd, type Ymd } from '../calendar/ymd';
import type {
    ConditionScope,
    CoverageDetail,
    EvaluationContext,
    Person,
    Schedule,
    SkippedWindow,
} from '../contract/types';
import type { Duty } from '../duty/interval';
import type { Horizon } from '../duty/windows';
import type { Span } from '../contract/types';

/** `a`, `a and b`, `a, b and c` — for an explanation. Not the message table; see the docblock. */
export function list(items: readonly string[]): string {
    if (items.length <= 1) {
        return items[0] ?? '';
    }

    return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1] as string}`;
}

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
 * STATED RESIDUAL: there is no `futureAvailableTo` counterpart, so the RIGHT edge is not reported.
 * `followingDuties` is usually empty and legitimately so, and no context field distinguishes
 * *"nothing follows"* from *"nothing was supplied"*. Adding one is a contract change; guessing from
 * emptiness would report a skip on almost every evaluation, which is the noise this function's own
 * second paragraph refuses.
 */
export function carryInLeftEdge(context: EvaluationContext, horizon: Horizon): SkippedWindow[] {
    if (context.historyAvailableFrom !== null && compareYmd(context.historyAvailableFrom, horizon.from) < 0) {
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
            reason:
                `No duty history was supplied before ${horizon.from} (historyAvailableFrom is null), so a ` +
                'duty running past midnight into the horizon cannot be seen.',
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
