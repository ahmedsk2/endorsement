/**
 * `same_unit_conflict` — CG-07: *"Pairs never together | unit pairs; day exceptions"*.
 *
 * ## Owner decision U: reading (a), CONFIRMED by the owner rather than inferred
 *
 * **Two people ROTATING ON THE SAME UNIT are never on call together**, and the person's unit on the
 * date comes from the master rota — which `RotaGrid` already answers, so no new store is needed.
 * **Day exceptions LIFT the ban** on the dates they name.
 *
 * The other two readings, named here because `SPEC.md:100` is this type's only occurrence anywhere
 * in the repository and the next reader will ask:
 *
 *  - **Named pairs of PEOPLE never on call together** — what the Meaning cell (*"Pairs never
 *    together"*) reads as. Rejected: it needs a person-pair store that exists nowhere in the tree,
 *    and inventing one in P2 would be building a feature to satisfy a two-word cell.
 *  - **Pairs of UNITS never covered together** — what the parameters cell (*"unit pairs"*) reads as,
 *    and the OPPOSITE predicate over the same input: it forbids a PICU and a NICU person sharing a
 *    night, where reading (a) forbids two PICU people sharing one. Rejected by the owner in favour
 *    of (a), which is also the reading the key name states unambiguously.
 *
 * This was routed to the owner rather than inferred, on the standing precedent of Owner Decision A
 * on `levels.terminal` (2026-08-09): a wrong marker there failed silently in two directions, and so
 * would a wrong reading here — it would either miss every real collision or manufacture one on every
 * cross-unit night.
 *
 * ## The unit is read AT THE DATE, and a person rotating NOWHERE collides with nobody
 *
 * `spanKeyAt()`, like every other dated fact in this package. A person between rotations holds
 * `null`, and `null` is not a unit two people can share — answering "they match" for want of data
 * would put a Hard collision on every pair of people whose spans have a gap.
 *
 * ## `units` narrows WHICH units the ban covers, and it is not CG-01's scope
 *
 * The scope selects the POPULATION the rule applies to; `units` selects the rotations the ban is
 * about. A department that wants "never two people on PICU together, but NICU may double up" needs
 * the second and cannot say it with the first. Absent or empty means every unit.
 *
 * ## Emission is per PLACEMENT, and the subject's scope is what decides
 *
 * Both people get a finding on their own placement, each naming the other, exactly as
 * `overlap_block` reports both halves of an overlapping pair: WB-03 badges cells, a scheduler may
 * move either duty, and badging one and not the other would be choosing for them. A placement whose
 * person the scope excludes gets no finding — the rule does not apply to them — while the colleague
 * it does apply to still gets theirs.
 *
 * ## Duty→date reading: ANCHOR DATE, and no carry-in
 *
 * `DUTY_DATE_READING.same_unit_conflict`. The pair is same-DATE, so nothing outside the horizon can
 * make two people share a date inside it.
 */

import type { Ymd } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type { Condition, ConditionEvaluator, ConditionPreview, Finding } from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import type { Duty } from '../duty/interval';
import { list, personInScope, personIndex, placementsCovered, unitKeyAt } from './support';

/** `same_unit_conflict`'s parameters, normalised — both lists absent means "every unit, no lifts". */
export interface SameUnitConflictParams {
    units: string[];
    exceptDates: Ymd[];
}

export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        units: {
            type: 'array',
            items: { type: 'string' },
            description: 'units.code values the ban covers (owner decision G). Absent or empty means every unit.',
        },
        exceptDates: {
            type: 'array',
            items: { $ref: '#/$defs/Ymd' },
            description: 'Dates on which the ban LIFTS (owner decision U), not dates on which it applies.',
        },
    },
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): SameUnitConflictParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `same_unit_conflict on condition "${condition.id}"`);

    const params = condition.params as { units?: string[]; exceptDates?: Ymd[] };

    return { units: params.units ?? [], exceptDates: params.exceptDates ?? [] };
}

/** CG-04's sentence, through the message table (AR-07). */
export const preview: ConditionPreview = (condition, _context, messages) =>
    messages.sameUnitConflict(readParams(condition));

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context) => {
    const { units, exceptDates } = readParams(condition);
    const people = personIndex(context);
    const findings: Finding[] = [];
    const byDate = new Map<string, Duty[]>();

    for (const duty of schedule.duties) {
        byDate.set(duty.date, [...(byDate.get(duty.date) ?? []), duty]);
    }

    let evaluated = 0;

    for (const [date, duties] of [...byDate.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))) {
        for (const duty of duties) {
            const person = people.get(duty.personKey);

            if (!personInScope(person, duty.date, condition.scope)) {
                continue;
            }

            evaluated += 1;

            const unit = unitKeyAt(person, duty.date);

            if (unit === null || (units.length > 0 && !units.includes(unit)) || exceptDates.includes(duty.date)) {
                continue;
            }

            const partners = [
                ...new Set(
                    duties
                        .filter((other) => other.personKey !== duty.personKey)
                        .map((other) => other.personKey)
                        .filter((key) => unitKeyAt(people.get(key), duty.date) === unit),
                ),
            ].sort();

            if (partners.length === 0) {
                continue;
            }

            findings.push({
                location: { kind: 'placement', personKey: duty.personKey, date: duty.date, slotKey: duty.slotKey },
                explanation:
                    `Also on call with ${list(partners.map((key) => `"${key}"`))} on ${date}, and ` +
                    `${partners.length === 1 ? 'both are' : 'all of them are'} rotating on ${unit}.`,
            });
        }
    }

    return { findings, coverage: placementsCovered(evaluated, []) };
};
