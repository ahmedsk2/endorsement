/**
 * `count_max` and `count_min` — CG-07: *"Duty caps/floors per window | kinds; levels; count;
 * window (period/week)"*.
 *
 * **The first window-located types in the catalog, and the module the other seven measure like.**
 *
 * ## TWO registry keys, ONE evaluator and ONE params schema
 *
 * Owner decision B, measured rather than assumed: CG-01 and §30 store one `typeKey` per condition
 * row, CG-04's preview text differs by direction, a department will enable a cap without a floor,
 * and Task 8's parity guard derives two keys from CG-07's slash-separated cell — so a single key
 * would fail the build. They are not one entry. They are also not two predicates: the counting is
 * one function and only the comparison differs, and two copies of *"which duties land in this
 * window for this person"* would disagree only at a clipped week, which is the one place it matters.
 *
 * ## Owner decision K: the count is PER PERSON, and `levels` is a SCOPE FILTER
 *
 * Not a cohort total. A cap of two, applied to a week holding three duties for one person and two
 * for another, is one violation and not two — and an implementation summing across the population
 * is green on every world where one person holds them all, which is most small fixtures. `levels`
 * says which people the rule applies to and INTERSECTS CG-01's `scope`; it never turns the count
 * into a per-level tally.
 *
 * ## The windows are `period` and `week`, and `day` is NOT added
 *
 * Owner decision K again. SPEC Appendix B routes the owner's own words *"Per-level, per-unit nightly
 * counts"* to §14 SL-03 — coverage templates, which are P3 — so a per-day cap here would build
 * SL-03 twice, in two places, with two vocabularies. The week is the DEPARTMENT's, arriving in the
 * context as `periods[].weeks` with clipped bounds (owner decision O), never recomputed and never a
 * literal.
 *
 * ## The asymmetry that decides everything else: a cap may under-count, a floor may not
 *
 * A window the engine can only see part of makes both directions under-count. For a CAP that is
 * harmless — a count that is too low never exceeds a limit, so there is no false positive. For a
 * FLOOR it is a false positive EVERY time: the missing duties are exactly the ones that would have
 * met the floor. So (owner decision L):
 *
 *  - a **cap** evaluates every window that touches the horizon, including a clipped one;
 *  - a **floor** evaluates only a window that is fully inside `[evaluableFrom, evaluableTo]` AND
 *    whose left part the supplied history actually reaches, and the rest are reported through
 *    `coverage()`.
 *
 * **A silently dropped window is a guard that looks green**, which is why the drop is reported at
 * all. The two skip shapes are deliberately different rows: a window clipped by the evaluable range
 * is named individually, because which window it was is the actionable half; a window dropped
 * because no history reaches before the 1st is covered by {@link carryInLeftEdge}'s single row,
 * because the answer is the same for every one of them and one row per window would repeat one fact
 * until a reader stopped reading them. `evaluatedWindows` falling is the other half of that
 * statement and is why the pair is read together.
 *
 * ## Duty→date reading: ANCHOR DATE
 *
 * `DUTY_DATE_READING.count_max` / `.count_min`. A Friday-night call running to Saturday morning is
 * ONE Friday call, counted in the window Friday falls in and in no other. The occupied-interval
 * reading would count it twice at a week boundary, which is the shape Decision A's table exists to
 * prevent a type picking silently.
 *
 * ## PLANTED — twelve mutations, and the two that STAYED GREEN are the reason this list exists
 *
 * Each narrowing was deleted in turn and the suite watched. Red, each naming its own case: the
 * `levels` filter answering true; `kindMatches` answering true; `window` hardcoded to `'period'`
 * and, separately, to `'week'` (both directions, because one fixture catches only one of them);
 * raw week bounds in place of the clipped ones; the floor evaluating a partial window; the floor
 * treating unsupplied history as zero; the mid-window-join suppression removed; the count
 * aggregated across the cohort; the floor iterating duties rather than the roster; and the
 * emission rule's window branch rewritten as containment.
 *
 * **Two stayed GREEN, and neither is visible in review:**
 *
 *  1. **`personInScope` deleted from this type changed nothing** — no count case set CG-01's
 *     `scope` at all, so the narrowing every other type is asserted on was unasserted here. That is
 *     P2-1 review's thirteen-instance finding reappearing in the first type of P2-2, and it is
 *     closed by `count-max-the-scope-and-the-levels-list-intersect`, which needs three people
 *     because owner decision K's sentence is that `levels` INTERSECTS the scope rather than
 *     replacing it.
 *  2. **Moving both filters from the window's START to its END changed nothing** — no case held a
 *     person whose level moved inside a window. A window-located type has to CHOOSE a date where a
 *     placement-located one simply uses the duty's, and owner decision M fixes that choice at the
 *     period start. Closed by `count-max-the-level-filter-is-read-at-the-window-start`, whose two
 *     people are mirror images so that either direction of the mistake turns one row into the other.
 */

import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionOutcome,
    ConditionPreview,
    Finding,
    Person,
    SkippedWindow,
    ViolationMessages,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import type { Duty } from '../duty/interval';
import { slotIndex, type DutyStreams, type SlotIndex } from '../duty/order';
import type { Window } from '../duty/windows';
import {
    carryInLeftEdge,
    dutyStreams,
    kindMatches,
    positionedIn,
    levelFilterMatches,
    midWindowJoinSkip,
    periodWindows,
    personInScope,
    rosterFor,
    wholeWindowVerdict,
} from './support';

/** Which way one condition row of this pair points. Not a parameter — it is the type key. */
export type CountDirection = 'max' | 'min';

/** `count_max`/`count_min`'s parameters, normalised. Both keys read exactly this shape. */
export interface CountParams {
    count: number;
    window: 'period' | 'week';
    kinds: string[];
    levels: string[];
}

/**
 * One schema for both keys.
 *
 * `count` admits ZERO, and that is not an oversight: *"at most 0 night calls"* is how a department
 * bans a kind for a level without a second type, and *"at least 0"* is a floor somebody has
 * deliberately relaxed rather than one they forgot to fill in. `kinds` and `levels` are opaque
 * strings — SL-01's slot vocabulary is stored nowhere in this repository (Decision A) and level
 * CODES rather than ids are owner decision G, on `RotaExport`'s stated ground that ids are
 * instance-local.
 */
export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        count: {
            type: 'integer',
            minimum: 0,
            description: 'Duties per window, per person. A cap is at most this; a floor at least.',
        },
        window: {
            enum: ['period', 'week'],
            description:
                "The department's own period or its own week, from the context. 'day' is NOT a " +
                'member: SPEC Appendix B routes nightly counts to SL-03, which is P3.',
        },
        kinds: {
            type: 'array',
            items: { type: 'string' },
            description: 'Slot kinds the count is taken over. Absent or empty means every kind.',
        },
        levels: {
            type: 'array',
            items: { type: 'string' },
            description:
                'Level CODES this rule applies to, INTERSECTING CG-01 scope (owner decision K). ' +
                'Absent or empty means everybody the scope already selected.',
        },
    },
    required: ['count', 'window'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition, typeKey: string): CountParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `${typeKey} on condition "${condition.id}"`);

    const params = condition.params as {
        count: number;
        window: 'period' | 'week';
        kinds?: string[];
        levels?: string[];
    };

    return {
        count: params.count,
        window: params.window,
        kinds: params.kinds ?? [],
        levels: params.levels ?? [],
    };
}

/** CG-04's sentence for the cap. */
export const previewMax: ConditionPreview = (condition, _context, messages) =>
    messages.countMax(readParams(condition, 'count_max'));

/** CG-04's sentence for the floor, which says the partial-window rule out loud (decision L). */
export const previewMin: ConditionPreview = (condition, _context, messages) =>
    messages.countMin(readParams(condition, 'count_min'));

/**
 * Does this rule apply to this person over this window?
 *
 * Both filters are read at the window's START, which is `target_per_period`'s *"the level at the
 * PERIOD START"* (owner decision M) applied to every window-located type rather than to one. The
 * alternative — resolving per date and asking whether any date matched — makes a promotion part-way
 * through a block apply the rule to a window it covers less than half of, and makes the answer
 * depend on which date the implementation happened to ask about.
 */
function applies(person: Person, window: Window, condition: Condition, params: CountParams): boolean {
    return (
        personInScope(person, window.from, condition.scope) &&
        levelFilterMatches(person, window.from, params.levels)
    );
}

/**
 * The shared predicate. `direction` is the only thing the two registry keys disagree about.
 *
 * NO EARLY EXIT anywhere in the window scan or the person scan. A short circuit here would be a
 * second definition of the comparison the module docblock explains — Task 10's finding, where a
 * pruning optimisation made the phase's defining fixture unfalsifiable three lines below the
 * docblock sentence describing the rule it silently re-implemented.
 */
export function evaluateCount(
    direction: CountDirection,
    condition: Condition,
    schedule: Parameters<ConditionEvaluator>[1],
    context: Parameters<ConditionEvaluator>[2],
    messages: ViolationMessages,
): ConditionOutcome {
    const typeKey = direction === 'max' ? 'count_max' : 'count_min';
    const params = readParams(condition, typeKey);
    const slots = slotIndex(context.slots);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const windows = periodWindows(context, schedule.horizon, params.window);

    const findings: Finding[] = [];
    const skipped: SkippedWindow[] = [...carryInLeftEdge(context, schedule.horizon, messages)];

    let evaluated = 0;

    for (const { window } of windows) {
        // A FLOOR refuses both partial shapes and a CAP refuses neither — the asymmetry is owner
        // decision L and it lives in `wholeWindowVerdict`, shared with the two types Task 16 adds.
        if (direction === 'min') {
            const verdict = wholeWindowVerdict(window, context, schedule.horizon, messages);

            if (!verdict.measure) {
                if (verdict.skip !== null) {
                    skipped.push(verdict.skip);
                }

                continue;
            }
        }

        evaluated += 1;

        for (const person of roster) {
            if (!applies(person, window, condition, params)) {
                continue;
            }

            // Owner decision L, per person rather than per window: a floor judges only a window
            // this person actually had. A cap is unaffected — somebody who joined on the 20th and
            // took four calls by the 28th has exceeded a cap of three however short their window.
            const joinSkip = direction === 'min' ? midWindowJoinSkip(person, window, messages) : null;

            if (joinSkip !== null) {
                skipped.push(joinSkip);

                continue;
            }

            const contributing = countedFor(person.key, window, params, streams, slots);
            const location = {
                kind: 'window',
                personKey: person.key,
                from: window.from,
                to: window.to,
                contributing,
            } as const;
            const text = {
                actual: contributing.length,
                count: params.count,
                window: params.window,
                from: window.from,
                to: window.to,
                kinds: params.kinds,
            };

            // TWO push sites rather than one with a ternary on `explanation`, and the guard in
            // `conditions.test.ts` is why: it requires every `explanation:` to BEGIN `messages.`,
            // and it is planted against a ternary of two literals precisely because ten of the
            // eleven sites P2-2's first task migrated were written that way. A ternary of two
            // TABLE calls is indistinguishable to it, and the needle is conservative on purpose —
            // relaxing it to admit this shape would admit the shape it exists to catch. Written
            // out, each site is also where its own comparison lives, which is the only thing the
            // two directions actually disagree about.
            if (direction === 'max' && contributing.length > params.count) {
                findings.push({ location, explanation: messages.countMaxViolation(text) });
            }

            if (direction === 'min' && contributing.length < params.count) {
                findings.push({ location, explanation: messages.countMinViolation(text) });
            }
        }
    }

    return { findings, coverage: { evaluatedWindows: evaluated, skipped } };
}

/**
 * The duties this person holds in this window and this rule counts — the number, and the
 * `contributing` list a workbench badge needs.
 *
 * The anchor-date filter is `positionedIn`'s, shared with the three other window-located types; the
 * only thing this adds is `kinds`, which is the only thing CG-07 gives this row and not those. The
 * TAIL duties are in the list too, because a cap breached by three duties two of which were
 * published last month is a different problem from one breached by three drafted this week, and the
 * list is what says which.
 */
function countedFor(
    personKey: string,
    window: Window,
    params: CountParams,
    streams: DutyStreams,
    slots: SlotIndex,
): Duty[] {
    return positionedIn(personKey, window, streams, slots)
        .filter((positioned) => kindMatches(positioned.slot.kind, params.kinds))
        .map((positioned) => positioned.duty);
}

/** `count_max`. */
export const evaluateMax: ConditionEvaluator = (condition, schedule, context, messages) =>
    evaluateCount('max', condition, schedule, context, messages);

/** `count_min`. */
export const evaluateMin: ConditionEvaluator = (condition, schedule, context, messages) =>
    evaluateCount('min', condition, schedule, context, messages);
