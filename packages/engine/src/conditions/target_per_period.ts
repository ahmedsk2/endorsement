/**
 * `target_per_period` — CG-07: *"Per-level targets with modifiers | level→target; modifiers"*.
 *
 * **P2 Task 9 landed the parameters and the preview; P2-2 Task 16 lands the predicate.**
 *
 * ## Owner decision M, answered 2026-08-20: a matching modifier REPLACES the target
 *
 * An ordered list of `{ when, target }`; **first match wins**; **replace, not adjust**. The reason
 * to keep replace once implementers start finding `-2` more natural to write is that a delta
 * grammar lets two modifiers compound to a target below zero silently, and a reader cannot see the
 * resulting number without doing the arithmetic themselves. Replace makes the effective target
 * readable at every branch — which is exactly what CG-04's preview has to print, and it prints it.
 *
 * ## The predicate vocabulary is CLOSED to two
 *
 * `vacationWeeksAtLeast` and `periodWeeksAtMost`, and nothing else. The spec gives one example and
 * no syntax, and an open predicate language is CG-09's builder — Stage 4, and explicitly *"no
 * free-form scripting"*. The second predicate exists because `institutions.block_weeks` defaults to
 * twelve four-week blocks and a **five**-week block 13, and one vacation predicate cannot say so.
 * A `when` carrying both matches when both hold; the ORDER is what resolves two modifiers that
 * could each match.
 *
 * ## Levels are a MAP, not a scope
 *
 * Owner decision K: CG-01's `scope` selects the POPULATION and a level-keyed map supplies per-level
 * VALUES. Keys are level CODES, never `levels.id` — owner decision G, on `RotaExport`'s stated
 * ground that ids are instance-local. A level with no entry in the map has no target, which is a
 * different statement from a target of zero and is left as one.
 *
 * The person's level is read at the PERIOD START (owner decision M's unchanged half); Task 16 owns
 * that read.
 */

import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    Finding,
    Period,
    Person,
    SkippedWindow,
    Vocabulary,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { slotIndex } from '../duty/order';
import {
    carryInLeftEdge,
    dutiesIn,
    dutyStreams,
    levelKeyAt,
    midWindowJoinSkip,
    periodWindows,
    personInScope,
    rosterFor,
    vacationWeeksIn,
    wholeWindowVerdict,
} from './support';

/** One modifier's predicate. Both members are optional; a `when` carrying both needs both to hold. */
export interface TargetModifierWhen {
    vacationWeeksAtLeast?: number;
    periodWeeksAtMost?: number;
}

/** One modifier: the predicate, and the target that REPLACES the level's own when it matches. */
export interface TargetModifierParam {
    when: TargetModifierWhen;
    target: number;
}

/** `target_per_period`'s parameters. */
export interface TargetPerPeriodParams {
    targets: Record<string, number>;
    modifiers: TargetModifierParam[];
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        targets: {
            type: 'object',
            additionalProperties: { type: 'integer', minimum: 0 },
            description: 'Level CODE to duties per period. A level absent from the map has no target.',
        },
        modifiers: {
            type: 'array',
            description: 'Ordered. The first whose predicate holds replaces the target outright.',
            items: {
                type: 'object',
                properties: {
                    when: {
                        type: 'object',
                        properties: {
                            vacationWeeksAtLeast: { type: 'integer', minimum: 1 },
                            periodWeeksAtMost: { type: 'integer', minimum: 1 },
                        },
                        additionalProperties: false,
                    },
                    target: { type: 'integer', minimum: 0 },
                },
                required: ['when', 'target'],
                additionalProperties: false,
            },
        },
    },
    required: ['targets'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): TargetPerPeriodParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `target_per_period on condition "${condition.id}"`);

    const params = condition.params as {
        targets: Record<string, number>;
        modifiers?: TargetModifierParam[];
    };

    return { targets: params.targets, modifiers: params.modifiers ?? [] };
}

/**
 * One modifier's predicate as a clause. Both members render, joined, when both are present.
 *
 * A predicate that rendered only its first member would silently describe a narrower rule than the
 * one being enforced — the shape of preview defect that is invisible precisely because the sentence
 * still reads correctly.
 */
export function clauseFor(when: TargetModifierWhen, messages: Vocabulary): string {
    const clauses: string[] = [];

    if (when.vacationWeeksAtLeast !== undefined) {
        clauses.push(messages.vacationWeeksAtLeast(when.vacationWeeksAtLeast));
    }

    if (when.periodWeeksAtMost !== undefined) {
        clauses.push(messages.periodWeeksAtMost(when.periodWeeksAtMost));
    }

    // Both the degenerate clause and the joiner come from the table, and the joiner is `conjoin`
    // rather than a second `' and '`: a modifier has exactly two possible members, so this is
    // `conjoin`'s two-item case and a local joiner would be a second definition of one connective.
    return clauses.length === 0 ? messages.anyPeriodClause() : messages.conjoin(clauses);
}

/** CG-04's sentence: the base target per level, and the effective target at every branch. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const params = readParams(condition);

    return messages.targetPerPeriod({
        targets: Object.keys(params.targets)
            .sort()
            .map((levelKey) => ({ levelKey, target: params.targets[levelKey] as number })),
        modifiers: params.modifiers.map((modifier) => ({
            clause: clauseFor(modifier.when, messages),
            target: modifier.target,
        })),
    });
};

/**
 * Which modifier applies, and therefore what the target actually is (P2-2 Task 16).
 *
 * Owner decision M in three lines: an ordered list, FIRST MATCH WINS, and the matching modifier
 * REPLACES the level's own number rather than adjusting it. The reason to keep replace, once an
 * implementer starts finding `-2` more natural to write, is that a delta grammar lets two modifiers
 * compound to a target below zero silently, and a reader cannot see the resulting number without
 * doing the arithmetic themselves.
 *
 * **A level absent from the map has NO target and is not judged at all** — `null`, not zero. That
 * is the module docblock's *"a different statement from a target of zero"*, and it is the one place
 * the two readings are a whole department apart: the map names the levels the rule is about, so a
 * consultant with no entry would otherwise be told to take zero duties and flagged for every one
 * they took. A modifier cannot rescue that either — a modifier replaces a LEVEL's target, and a
 * level with no target has nothing to replace.
 */
export function effectiveTarget(
    params: TargetPerPeriodParams,
    levelKey: string | null,
    person: Person,
    period: Period,
): { target: number | null; modifier: TargetModifierParam | null } {
    const base = levelKey === null ? undefined : params.targets[levelKey];

    if (base === undefined) {
        return { target: null, modifier: null };
    }

    for (const modifier of params.modifiers) {
        if (modifierApplies(modifier.when, person, period)) {
            return { target: modifier.target, modifier };
        }
    }

    return { target: base, modifier: null };
}

/**
 * Decision M's CLOSED predicate vocabulary, both members, and a `when` carrying both needs both.
 *
 * `vacationWeeksAtLeast` counts through `vacationWeeksIn`, which is `AvailabilitySummary`'s rule
 * carried rather than a second one; `periodWeeksAtMost` reads the period's own week count, and it
 * exists because `institutions.block_weeks` defaults to twelve four-week blocks and a FIVE-week
 * block 13, which one vacation predicate cannot say.
 *
 * A `when` naming neither matches always. That is a legitimate row rather than a malformed one —
 * the schema admits it and `anyPeriodClause()` is the sentence for it — so it is answered rather
 * than refused, and the ordering is what stops it swallowing every modifier below it.
 */
export function modifierApplies(when: TargetModifierWhen, person: Person, period: Period): boolean {
    const vacation =
        when.vacationWeeksAtLeast === undefined ||
        vacationWeeksIn(person, period) >= when.vacationWeeksAtLeast;
    const length = when.periodWeeksAtMost === undefined || period.weeks.length <= when.periodWeeksAtMost;

    return vacation && length;
}

/**
 * The predicate (P2-2 Task 16). Window: the PERIOD. Duty→date reading: ANCHOR DATE.
 *
 * ## It is a TARGET, so it is two-sided, and it declines a window it cannot see all of
 *
 * Over and under are both violations and they carry different sentences, because *"two duties
 * short"* and *"two duties over"* are opposite instructions to whoever reads the badge. And a
 * target false-positives on a partial window exactly as a floor does — the duties it cannot see are
 * the ones that would have met the number — so owner decision L's gate applies unchanged, including
 * its per-person half for somebody who joined part way through the block.
 *
 * ## No `kinds` parameter, deliberately
 *
 * CG-07's cell for this row is *"level→target; modifiers"* and names none, so every duty in the
 * period counts. Adding one would invent a parameter no document states, and a department wanting a
 * per-kind target has `count_max`/`count_min`, whose cell does name `kinds`.
 *
 * ## The level is read at the PERIOD START (owner decision M's unchanged half)
 *
 * A promotion part-way through a block does not retarget the block it happened in. One date, chosen
 * once, rather than an answer that depends on which date an implementation happened to ask about —
 * and `count.ts` follows the same rule for the same reason.
 *
 * ## PLANTED — and ONE STAYED GREEN
 *
 * Red, each naming its own case: an unmapped level defaulting to zero; modifiers ignored entirely;
 * the modifier ADDED to the base rather than replacing it; the LAST match winning instead of the
 * first; `vacationWeeksIn` counting only weeks the leave covers whole; the level read at the period
 * END; the carry-in tail dropped from the count; the two-sided comparison reduced to a cap; and each
 * of the two predicates inverted.
 *
 * **`periodWeeksAtMost` answering `true` unconditionally changed nothing.** The only case
 * exercising it had a five-week block against a limit of five, so the predicate was asserted where
 * it MATCHES and nowhere where it must not — Task 9's species of green plant, a property asserted at
 * the one input where the defect cannot appear, and the third instance of it in this phase. Closed
 * by `target-per-period-a-period-longer-than-the-modifier-allows-does-not-match`, whose block is
 * five weeks against a limit of four. The sibling predicate was already asserted in both directions
 * by accident rather than by design, which is why this one was not.
 */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const slots = slotIndex(context.slots);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const findings: Finding[] = [];
    const skipped: SkippedWindow[] = [...carryInLeftEdge(context, schedule.horizon, messages)];

    let evaluated = 0;

    for (const { window, period } of periodWindows(context, schedule.horizon, 'period')) {
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

            const joinSkip = midWindowJoinSkip(person, window, messages);

            if (joinSkip !== null) {
                skipped.push(joinSkip);

                continue;
            }

            const levelKey = levelKeyAt(person, window.from);
            const { target, modifier } = effectiveTarget(params, levelKey, person, period);

            if (target === null || levelKey === null) {
                continue;
            }

            const contributing = dutiesIn(person.key, window, streams, slots);
            const location = {
                kind: 'window',
                personKey: person.key,
                from: window.from,
                to: window.to,
                contributing,
            } as const;
            const text = {
                actual: contributing.length,
                target,
                levelKey,
                from: window.from,
                to: window.to,
                clause: modifier === null ? null : clauseFor(modifier.when, messages),
            };

            // Two push sites rather than a ternary on `explanation` — `conditions.test.ts`'s source
            // guard cannot tell a ternary of table calls from the ternary of literals it is planted
            // against, and relaxing it to admit the one would admit the other.
            if (contributing.length > target) {
                findings.push({ location, explanation: messages.targetPerPeriodAboveViolation(text) });
            }

            if (contributing.length < target) {
                findings.push({ location, explanation: messages.targetPerPeriodBelowViolation(text) });
            }
        }
    }

    return { findings, coverage: { evaluatedWindows: evaluated, skipped } };
};
