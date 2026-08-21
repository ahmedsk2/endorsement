/**
 * `eligibility` — CG-07: *"Allowed levels/rotations per slot; auto-fill order | slot→levels/rotations"*.
 *
 * ## Owner decision P: a HARD VIOLATION on a committed assignment, and the ordering half does NOT ship
 *
 * CG-07 leaves the row unmarked while WB-04 says pickers *"exclude hard-ineligible people"*, which
 * makes it Hard in the workbench. The cell's other half — *"auto-fill order"* — produces no
 * violation at all, and a type carrying both would be carrying two contracts: one that answers
 * *"is this placement wrong"* and one that answers *"who should I offer first"*. Ordering is WB-04
 * fitness and lands in P3.
 *
 * **Its absence is asserted rather than merely omitted.** `PARAMS_SCHEMA` is closed, so a condition
 * row carrying `autoFillOrder` is REFUSED with the schema's own error rather than silently ignored
 * — which is the difference between a department learning that this engine does not order pickers
 * and a department believing it does. `conditions.test.ts` asserts both the refusal and that no
 * ordering vocabulary appears in this module at all.
 *
 * ## Owner decision G: stable keys, never database ids
 *
 * Level CODES and `units.code`, both compared as strings. `people.id` and `users.id` are
 * independent sequences, ids are instance-local, and `RotaExport` already refuses them for person
 * identity on exactly that ground. A params file written against one deployment's ids would be
 * silently wrong on another.
 *
 * ## Duty→date reading: ANCHOR DATE, and the level is read AT THE DUTY'S DATE
 *
 * `DUTY_DATE_READING.eligibility`. The mid-window promotion is the case that decides it: the same
 * person is ineligible on the 3rd and eligible on the 4th, and an implementation that resolves the
 * level once per evaluation — at `horizon.from`, which is the natural place to hoist it to — is
 * green on every month in which nobody is promoted, which is most of them.
 *
 * ## A slot with no entry is UNRESTRICTED, and a person with no level fails a level restriction
 *
 * The first is what *"allowed levels per slot"* means: the map names the restricted slots, and a
 * department that has not restricted a slot has not restricted it. The second is the honest reading
 * of an absent fact — a person holding no level on a date cannot be shown to hold an allowed one,
 * and answering "eligible" for want of data is a Hard rule passing on incomplete input.
 *
 * ## Both halves of "levels/rotations" are now falsifiable, and only one of them was
 *
 * P2-1's review found the corpus proving the LEVEL half of everything above and the ROTATION half
 * of none of it. The only rotation case put one person on one unit for the whole year, where
 * resolving the unit once — at `horizon.from`, the natural place to hoist it to — gives the same
 * answer; and no case had a person holding nothing at all on the date, so both `=== null` tests
 * could be inverted into fail-OPEN with 587/587 green. Two cases close it:
 * `eligibility-a-mid-window-rotation-change` and
 * `eligibility-no-level-and-no-rotation-on-the-date-both-fail-closed`.
 *
 * PLANTED, each reverted, each red: `unitKeyAt(person, schedule.horizon.from)`;
 * `level !== null && …`; `unit !== null && …`.
 */

import type { JsonSchema } from '../contract/schema';
import type { Condition, ConditionEvaluator, ConditionPreview, Finding } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import { levelKeyAt, personInScope, personIndex, placementsCovered, unitKeyAt } from './support';

/** What one slot admits. Both members are optional; an empty list restricts nothing. */
export interface SlotAllowance {
    levelKeys?: string[];
    unitKeys?: string[];
}

/** `eligibility`'s parameters: a map from slot key to what that slot admits. */
export interface EligibilityParams {
    allowed: Record<string, SlotAllowance>;
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        allowed: {
            type: 'object',
            description: 'Slot key to what that slot admits. A slot absent from the map is unrestricted.',
            additionalProperties: {
                type: 'object',
                properties: {
                    levelKeys: {
                        type: 'array',
                        items: { type: 'string' },
                        description: 'Level CODES, never levels.id (owner decision G).',
                    },
                    unitKeys: {
                        type: 'array',
                        items: { type: 'string' },
                        description: 'units.code values — the rotation the person is on that day.',
                    },
                },
                additionalProperties: false,
            },
        },
    },
    required: ['allowed'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit — `autoFillOrder` included. */
export function readParams(condition: Condition): EligibilityParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `eligibility on condition "${condition.id}"`);

    return condition.params as unknown as EligibilityParams;
}

/**
 * CG-04's sentence: which slots are restricted, to what, and when the answer is read.
 *
 * The SORT and the two absent-list defaults happen here; the sentence happens in the table. That
 * split is the rule the whole table is built on — a type hands in already-normalised values, and
 * `Object.keys()` order is not a wording decision any language would take differently.
 */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const { allowed } = readParams(condition);

    return messages.eligibility({
        slots: Object.keys(allowed)
            .sort()
            .map((slotKey) => ({
                slotKey,
                levelKeys: (allowed[slotKey] as SlotAllowance).levelKeys ?? [],
                unitKeys: (allowed[slotKey] as SlotAllowance).unitKeys ?? [],
            })),
    });
};

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const { allowed } = readParams(condition);
    const people = personIndex(context);
    const findings: Finding[] = [];

    let evaluated = 0;

    for (const duty of schedule.duties) {
        const person = people.get(duty.personKey);

        if (!personInScope(person, duty.date, condition.scope)) {
            continue;
        }

        const allowance = allowed[duty.slotKey];

        if (allowance === undefined) {
            continue;
        }

        evaluated += 1;

        const location = {
            kind: 'placement' as const,
            personKey: duty.personKey,
            date: duty.date,
            slotKey: duty.slotKey,
        };

        const levelKeys = allowance.levelKeys ?? [];

        if (levelKeys.length > 0) {
            const level = levelKeyAt(person, duty.date);

            if (level === null || !levelKeys.includes(level)) {
                findings.push({
                    location,
                    explanation: messages.eligibilityViolation({
                        facet: 'level',
                        held: level,
                        date: duty.date,
                        slotKey: duty.slotKey,
                        allowed: levelKeys,
                    }),
                });
            }
        }

        const unitKeys = allowance.unitKeys ?? [];

        if (unitKeys.length > 0) {
            const unit = unitKeyAt(person, duty.date);

            if (unit === null || !unitKeys.includes(unit)) {
                findings.push({
                    location,
                    explanation: messages.eligibilityViolation({
                        facet: 'rotation',
                        held: unit,
                        date: duty.date,
                        slotKey: duty.slotKey,
                        allowed: unitKeys,
                    }),
                });
            }
        }
    }

    return { findings, coverage: placementsCovered(evaluated, []) };
};
