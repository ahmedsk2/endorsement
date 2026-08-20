/**
 * CG-04: *"Plain-language preview text auto-generated from parameters"* — the dispatcher.
 *
 * `preview(condition, context)` resolves the condition's type key against the registry and renders
 * that type's sentence from that condition's own parameters. The sentences live in `messages.ts`;
 * the per-type assembly lives beside each type's parameters in `conditions/`; this file only routes
 * and refuses.
 *
 * ## It refuses in three distinguishable ways, and none of them is silence
 *
 *  - {@link UnknownConditionTypeError} — no catalog row carries that key at all.
 *  - {@link UnimplementedConditionTypeError} — a real catalog row this engine does not implement,
 *    carrying the registry's stated reason.
 *  - {@link NoPreviewForConditionTypeError} — a row this engine implements that has no preview yet.
 *
 * The third is the one worth spelling out. A gate screen rendering an empty cell, or the raw type
 * key, where a rule should be described in words is a control that appears to do nothing — rulings
 * 41 and 49's failure shape, one layer inside the engine, and the reason `evaluate()` throws on an
 * unresolvable key rather than skipping it. Eighteen of the twenty-two implemented rows are in that
 * state at P2 Task 9 and each is filled in by the task that lands its predicate;
 * `preview.test.ts`'s coupling check is what stops a predicate arriving without one.
 *
 * ## The message table is an argument
 *
 * AR-07 makes translation future work, so the table is passed rather than reached for. `EN` is the
 * default and the only one that exists; `preview.test.ts` proves the argument is real by handing in
 * a second table and watching the sentence change.
 */

import type { Condition, EvaluationContext } from './contract/types';
import { UnimplementedConditionTypeError, UnknownConditionTypeError } from './evaluate';
import { EN, type PreviewMessages } from './messages';
import { CATALOG, indexCatalog, type RegistryEntry } from './registry';

/** A catalog row this engine implements, whose plain-language preview has not been written yet. */
export class NoPreviewForConditionTypeError extends Error {
    constructor(
        public readonly typeKey: string,
        public readonly conditionId: string,
    ) {
        super(
            `Condition "${conditionId}" names the catalog type "${typeKey}", which this engine ` +
                'implements but has no plain-language preview for. A gate screen describing a rule with ' +
                'a blank or with its own type key is a control that appears to do nothing, so this is ' +
                'refused rather than answered.',
        );
        this.name = 'NoPreviewForConditionTypeError';
    }
}

/**
 * Render against a caller-supplied catalog.
 *
 * Exported for the same reason `evaluateWith()` is: the dispatcher's own properties had to be
 * assertable against entries a test controls, and P3 gets a second use out of it when it previews
 * a restricted catalog on the gate screen.
 */
export function previewWith(
    catalog: readonly RegistryEntry[],
    condition: Condition,
    context: EvaluationContext,
    messages: PreviewMessages = EN,
): string {
    const entry = indexCatalog(catalog).get(condition.typeKey);

    if (entry === undefined) {
        throw new UnknownConditionTypeError(condition.typeKey, condition.id);
    }

    if (!entry.implemented) {
        throw new UnimplementedConditionTypeError(
            condition.typeKey,
            condition.id,
            entry.notImplementedBecause ?? 'No reason is recorded in the registry, which is itself a defect.',
        );
    }

    if (entry.preview === undefined) {
        throw new NoPreviewForConditionTypeError(condition.typeKey, condition.id);
    }

    return entry.preview(condition, context, messages);
}

/** CG-04, against the shipped catalog. */
export function preview(
    condition: Condition,
    context: EvaluationContext,
    messages: PreviewMessages = EN,
): string {
    return previewWith(CATALOG, condition, context, messages);
}
