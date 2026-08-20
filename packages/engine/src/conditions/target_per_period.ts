/**
 * `target_per_period` — CG-07: *"Per-level targets with modifiers | level→target; modifiers"*.
 *
 * **P2 Task 9 lands the parameters and the preview; Task 16 lands the predicate.**
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
import type { Condition, ConditionPreview, PreviewMessages } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';

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
export function clauseFor(when: TargetModifierWhen, messages: PreviewMessages): string {
    const clauses: string[] = [];

    if (when.vacationWeeksAtLeast !== undefined) {
        clauses.push(messages.vacationWeeksAtLeast(when.vacationWeeksAtLeast));
    }

    if (when.periodWeeksAtMost !== undefined) {
        clauses.push(messages.periodWeeksAtMost(when.periodWeeksAtMost));
    }

    return clauses.length === 0 ? 'the period is any period at all' : clauses.join(' and ');
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
