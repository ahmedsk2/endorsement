/**
 * The horizon, the windows measured over it, and which of them the context can actually answer.
 *
 * ## The horizon carries FOUR dates, not two
 *
 * `[from, to]` is what is being evaluated. `[evaluableFrom, evaluableTo]` is how far the carry-in
 * tail reaches — the span the supplied `prior`/`following` duties genuinely cover. They are
 * different questions and conflating them produces a specific, silent defect: a four-week average
 * has a partial window on roughly the first 27 days of any month, and a cap under-counting on a
 * partial window produces no false positive while a FLOOR or a TARGET false-positives on every one
 * of them.
 *
 * So a window knows whether it is {@link Window.fullyEvaluable}, and the decision of what to do
 * about it belongs to the type: a cap may evaluate a partial window, a floor may not, and a window
 * a floor skips is REPORTED through `coverage()` rather than dropped. A silently dropped window is
 * a guard that looks green.
 *
 * ## The emission rule
 *
 * {@link withinHorizon} is the other half: a violation is emitted only when its location falls
 * inside `[from, to]`. Reading the tail is not re-evaluating it (CG-03), and this is the predicate
 * that keeps the two apart.
 */

import { addDays, compareYmd, datesBetween, diffDays, type Ymd } from '../calendar/ymd';

/** What is being evaluated, and how far the read-only tail around it reaches. */
export interface Horizon {
    from: Ymd;
    to: Ymd;
    evaluableFrom: Ymd;
    evaluableTo: Ymd;
}

/** A measured range, and whether the supplied context covers all of it. */
export interface Window {
    from: Ymd;
    to: Ymd;
    fullyEvaluable: boolean;
}

/**
 * The families of window this module enumerates.
 *
 * ONE member today, deliberately. Rolling is the shape owner decision J settled for
 * `call_frequency_max` and the shape `rolling_hours_max` has by definition; the other window-measured
 * types read PERIOD and WEEK ranges, and those do not come from here at all — they arrive in the
 * evaluation context as `periods[].weeks` with clipped bounds, computed server-side by
 * `Calendar::weeksIn()` (owner decision O), and are wrapped with {@link windowFor}. A second member
 * is added when a type needs one, measured rather than anticipated.
 */
export type WindowKind = 'rolling';

/**
 * The tail must surround the horizon, and the horizon must not run backwards.
 *
 * An `evaluableFrom` after `from` is not a smaller promise — it is a contradiction: the caller is
 * saying the dates it asked to have evaluated are outside what it supplied context for. Left
 * unchecked it makes every window near the left edge quietly partial.
 */
export function assertHorizon(horizon: Horizon): void {
    if (compareYmd(horizon.from, horizon.to) > 0) {
        throw new RangeError(`A horizon ends before it starts: ${horizon.from}..${horizon.to}.`);
    }

    if (compareYmd(horizon.evaluableFrom, horizon.from) > 0) {
        throw new RangeError(
            `evaluableFrom ${horizon.evaluableFrom} is after the horizon start ${horizon.from}: the ` +
                'carry-in tail must reach at least as far back as what is being evaluated.',
        );
    }

    if (compareYmd(horizon.evaluableTo, horizon.to) < 0) {
        throw new RangeError(
            `evaluableTo ${horizon.evaluableTo} is before the horizon end ${horizon.to}: the carry-in ` +
                'tail must reach at least as far forward as what is being evaluated.',
        );
    }
}

/** The emission rule: is this date inside what is being evaluated, as opposed to the tail? */
export function withinHorizon(horizon: Horizon, date: Ymd): boolean {
    return compareYmd(date, horizon.from) >= 0 && compareYmd(date, horizon.to) <= 0;
}

/**
 * The emission rule's OTHER half: does this range touch what is being evaluated?
 *
 * A placement must fall INSIDE `[from, to]`; a window need only TOUCH it, because a window that
 * begins in the carry-in tail and reaches the 1st constrains a duty on the 1st. Task 7 measured
 * that the containment reading is silently correct for eleven types and silently deletes the left
 * edge for eight, so the asymmetry is deliberate and is documented at `locationIsReportable`.
 *
 * It lives here, beside `withinHorizon`, because P2-2's window-located types need the same
 * predicate to decide which windows are worth enumerating at all: a window that cannot touch the
 * horizon can hold no reportable violation. Two copies of it — one deciding what to measure and
 * one deciding what to emit — would disagree at exactly the left edge, which is the one place
 * either matters. `AuditChain::canonical()`'s defect, in the two functions that would grow it.
 */
export function windowTouchesHorizon(from: Ymd, to: Ymd, horizon: Horizon): boolean {
    return compareYmd(to, horizon.from) >= 0 && compareYmd(from, horizon.to) <= 0;
}

/**
 * Wrap a range the context supplied — a week with its clipped bounds, a period, a lookback — and
 * answer the one question this module owns about it: is it fully covered by the tail?
 */
export function windowFor(from: Ymd, to: Ymd, horizon: Horizon): Window {
    if (compareYmd(from, to) > 0) {
        throw new RangeError(`A window ends before it starts: ${from}..${to}.`);
    }

    return {
        from,
        to,
        fullyEvaluable: compareYmd(from, horizon.evaluableFrom) >= 0 && compareYmd(to, horizon.evaluableTo) <= 0,
    };
}

/**
 * Every window of `lengthDays` that can still touch the horizon, earliest first.
 *
 * The enumeration starts `lengthDays - 1` days BEFORE the horizon, because a window that begins in
 * the tail and reaches the 1st constrains a duty on the 1st — that is the horizon-edge case a
 * mid-month corpus never exercises. It stops at the last horizon date, since a window starting
 * after `to` can hold no duty a violation may be located on.
 *
 * The start dates are enumerated through `datesBetween`, so its 550-day cap applies here too and a
 * mistyped year cannot make this loop the memory exhaustion the cap exists to prevent.
 */
export function enumerateWindows(kind: WindowKind, lengthDays: number, horizon: Horizon): Window[] {
    assertHorizon(horizon);

    if (!Number.isInteger(lengthDays) || lengthDays < 1) {
        throw new RangeError(`A window length must be a positive whole number of days; got ${lengthDays}.`);
    }

    switch (kind) {
        case 'rolling': {
            const starts = datesBetween(addDays(horizon.from, -(lengthDays - 1)), horizon.to);

            return starts.map((start) => windowFor(start, addDays(start, lengthDays - 1), horizon));
        }
        default: {
            const unreachable: never = kind;

            throw new RangeError(`Unknown window kind: ${String(unreachable)}.`);
        }
    }
}

/** Days in a window, inclusive at both ends — the denominator a rate is measured against. */
export function windowLengthDays(window: Window): number {
    return diffDays(window.from, window.to) + 1;
}
