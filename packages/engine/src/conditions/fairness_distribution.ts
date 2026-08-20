/**
 * `fairness_distribution` — CG-07: *"Even spread across colleagues | quantity; tolerance"*.
 *
 * **P2 Task 9 landed the parameters, the tolerance rule and the preview; Task 19 lands the
 * predicate.**
 *
 * ## The FIRST cohort-located type, and what that member costs
 *
 * `Location`'s third member carries `personKeys`, a `scopeLabel` and no date at all, which is the
 * honest shape for *"this population is unevenly loaded"*. Two consequences follow and neither is
 * visible in the union:
 *
 *  - **`evaluate()`'s emission rule cannot help.** A cohort location is ALWAYS reportable, because
 *    it has no date to test. Every duty this type counts is therefore filtered to the horizon here,
 *    in the predicate, rather than being left to the rule that catches a placement in the tail.
 *  - **`scopeLabel` is text a scheduler reads**, so it comes from the message table like every
 *    other sentence. It is most of a cohort violation's meaning: *"unevenly loaded"* against the
 *    whole department and against the four R1s on PICU are different statements and the badge has
 *    nothing else on it to tell them apart.
 *
 * `contributing` is OPTIONAL on this member and is supplied on every finding regardless, empty
 * included — Task 15's distinction one member along: absent means a type forgot to say, `[]` means
 * it said none.
 *
 * ## The comparison is over the SCHEDULE, and no carry-in tail enters it
 *
 * `needsCarryIn: false`, and the registry records why: last month's load is a different period's
 * fairness question, answered against that period's own eligible-day denominator. So only
 * `schedule.duties` are counted. `priorDuties` is still resolved for the STRANGER check, because a
 * duty naming somebody the context does not describe cannot be judged by anything.
 *
 * ## The quantity keys on `Slot.tallyKey`, which this package treats as opaque
 *
 * SL-01 owns the vocabulary and P3 stores it; nothing here validates the string. A slot with no
 * `tallyKey` matches no quantity. That makes a mistyped quantity the likeliest way this rule ever
 * goes quiet, and a quiet rule is indistinguishable from an even schedule — so a quantity nothing
 * tallies is REPORTED through `coverage()` rather than answered with silence.
 *
 * ## A float comparison, with the epsilon stated rather than discovered
 *
 * A pro-rated share is a division, so a schedule sitting exactly on its tolerance can land either
 * side of it on a floating-point remainder — and D4 gives this package two runtimes, where "the
 * same input answered differently" is the one thing a pure function may not do. The comparison is
 * therefore against `tolerance + `{@link COMPARISON_EPSILON}, which is far below any difference a
 * real roster can produce and far above the error a division of small integers can accumulate.
 *
 * ## Owner decision Q, answered 2026-08-20, and the floor that is not decoration
 *
 * The tolerance is **proportional at 10% with a floor of 1**:
 * `tolerance = max(1, ceil(0.1 × proRatedTarget))`. {@link toleranceFor} is the only definition of
 * that in this repository, and Task 19's predicate calls it rather than repeating it.
 *
 * The floor must not be dropped as a simplification. A bare 10% is STRICTER than the absolute-1
 * default it replaced, for every target under ten: a tenth of a four-weekend target is 0.4, which
 * floors to a tolerance of ZERO, so the condition would reject any unevenness at all on a small
 * roster — the opposite of the slack the answer was chosen for. With the floor it behaves as
 * intended in both regimes: *within one* where a tenth is meaningless, and real proportional slack
 * above ten.
 *
 * ## WHERE THE FLOOR ACTUALLY BITES, measured rather than assumed (P2 Task 9)
 *
 * The decision's justification — *"0.4 floors to a tolerance of zero"* — describes rounding DOWN,
 * and the formula it states rounds UP. With `ceil`, `0.1 × 4` is already 1, so the `max(1, …)`
 * changes the answer at exactly one place: a pro-rated target of **zero**, which is a real input
 * (a person whose eligible days are all leave, or a quantity with no duties in the schedule at
 * all). This was found by PLANTING the floor's removal and watching the suite stay GREEN, which is
 * the finding: an expected share of 4 does not distinguish the two formulas and cannot prove the
 * floor. `toleranceFor(0)` is what does, and it is asserted.
 *
 * **The floor therefore stays, and this paragraph is why.** It is one character from becoming
 * load-bearing across the whole under-ten range again — `Math.round(0.1 * 4)` is 0, and `round` is
 * the more natural thing for the next author to reach for. Deleting a redundant-looking guard
 * beside a rounding mode nobody wrote down is exactly how the regime the answer overrode comes
 * back.
 *
 * ## Why the preview prints two worked numbers rather than one applied number
 *
 * Decision Q requires the preview to state the tolerance **as a number, never as `10%`** — a reader
 * told `10%` on a four-duty target predicts 0.4, and would expect the condition to permit nothing.
 *
 * The applied number depends on the pro-rated target, which depends on the SCHEDULE: how many
 * duties of the quantity exist, and each person's share of the eligible days. `ConditionPreview`
 * receives the condition and the context and — correctly — not the schedule: CG-04 previews a RULE
 * on the gate screen, before any draft exists, and a preview that changed as a draft was edited
 * would be a different artifact. So the sentence prints the tolerance FUNCTION as two worked points
 * spanning both regimes ({@link PREVIEW_EXAMPLE_SHARES}), which removes the mis-prediction the
 * decision names without pretending to a number nothing has computed yet. The applied number
 * belongs in the violation's own explanation, where the target is known, and Task 19 owes it there.
 *
 * ## The base is pro-rated, not raw
 *
 * From `eligibleDays` (owner decision Q's unchanged half). Raw counts flag the person on leave as
 * under-loaded, and a solver's fix for that is to overload the few days they were available.
 *
 * ## The two modes answer different questions and may not contradict each other
 *
 * `deviation` names every person outside their own allowance, one finding each. `spread` measures
 * only the distance between the busiest and the quietest and produces ONE finding naming that pair
 * — decision Q's own note is that it *"says nothing about WHO to fix"*, which is why `deviation` is
 * the default; it does say which TWO, and that is what makes the badge actionable at all.
 *
 * **`spread`'s allowance is DERIVED from `deviation`'s and is not a second number.** It is the
 * widest gap deviation mode would have permitted between those two people — each one's own
 * tolerance, added — so a schedule clean under `deviation` is clean under `spread` by construction.
 * The alternative, a threshold of its own, lets one mode of one rule call a draft fair while the
 * other calls it unfair, with nothing on either screen able to adjudicate. Recorded as an INFERENCE
 * rather than a citation: no document states it, and the alternative was rejected on that argument.
 *
 * ## PLANTED
 *
 * `personInScope` answering `true` — the standing FIRST plant, and it bites harder on a cohort type
 * than on a window one because the scope decides the DENOMINATOR as well as who is judged; the
 * expected share replaced by a raw `total / cohortSize`; `excludeExternal` ignored;
 * {@link toleranceFor}'s floor removed; the comparison relaxed to greater-or-equal; and `spread`'s
 * allowance replaced by a single person's tolerance. Each went red naming its own case.
 */

import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    ConditionScope,
    Finding,
    Person,
    SkippedWindow,
    ViolationMessages,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import type { Duty } from '../duty/interval';
import { slotIndex } from '../duty/order';
import { withinHorizon, type Horizon } from '../duty/windows';
import { dutyStreams, personInScope, rosterFor } from './support';

/** `fairness_distribution`'s parameters. */
export interface FairnessDistributionParams {
    quantity: string;
    mode: 'deviation' | 'spread';
    excludeExternal: boolean;
}

/**
 * `tolerance` is absent from this schema, and its absence is a DECISION.
 *
 * CG-07's parameters cell names it, and owner decision Q's superseded default made it an authored
 * number defaulting to 1. The answer replaced the number with a RULE — proportional, floored — and
 * a rule and a number cannot both be authoritative. Leaving the key here as an optional override
 * would mean a department could set a tolerance of 0 and re-acquire the exact defect the floor
 * exists to prevent. If an override is ever wanted it is additive, and it arrives with a floor.
 */
export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        quantity: {
            type: 'string',
            description: "SL-01's tally key — weekends, nights, holidays. Opaque: P3 owns the vocabulary.",
        },
        mode: {
            enum: ['deviation', 'spread'],
            description: 'deviation names WHO is over or under; spread measures only the widest gap.',
        },
        excludeExternal: {
            type: 'boolean',
            description: 'Whether people flagged external are left out of the comparison.',
        },
    },
    required: ['quantity', 'mode', 'excludeExternal'],
    additionalProperties: false,
};

/** The two expected shares the preview works through: one either side of where the floor stops biting. */
export const PREVIEW_EXAMPLE_SHARES: readonly number[] = [4, 40];

/**
 * Owner decision Q's tolerance: `max(1, ceil(0.1 × proRatedTarget))`.
 *
 * One definition, called by the sentence and by the predicate. Two would be two answers to *"how
 * uneven is too uneven"*, and the one that appeared on the gate screen would not be the one that
 * blocked a publish.
 */
export function toleranceFor(proRatedTarget: number): number {
    return Math.max(1, Math.ceil(0.1 * proRatedTarget));
}

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): FairnessDistributionParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `fairness_distribution on condition "${condition.id}"`);

    return condition.params as unknown as FairnessDistributionParams;
}

/** CG-04's sentence, with the allowance as a number at both regimes. See the module docblock. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const params = readParams(condition);

    return messages.fairnessDistribution({
        quantity: params.quantity,
        mode: params.mode,
        excludeExternal: params.excludeExternal,
        examples: PREVIEW_EXAMPLE_SHARES.map((share) => ({ share, allowance: toleranceFor(share) })),
    });
};

/**
 * The slack a float comparison is given, and it is stated rather than discovered.
 *
 * An expected share is `total × eligible / E`, so a schedule sitting EXACTLY on its tolerance can
 * fall either side of the comparison on a remainder in the last bits. D4 gives this package two
 * runtimes and the same input answered differently in each is the one thing a pure function may not
 * do. A billionth of a duty is below anything a roster can express and far above what a division of
 * small integers accumulates.
 */
export const COMPARISON_EPSILON = 1e-9;

/** One person's standing in the comparison: what they hold, what was expected, and by how much. */
export interface Standing {
    person: Person;
    actual: number;
    expected: number;
    deviation: number;
    tolerance: number;
    duties: Duty[];
}

/**
 * Who this rule compares: CG-01's scope, then owner decision Q's `excludeExternal`.
 *
 * The scope is read at the horizon's START, which is `count_max`'s *"at the window's start"* one
 * type along — a cohort type's window is the whole schedule, so its start is the horizon's. The
 * alternative, resolving per date and asking whether any date matched, makes a promotion part-way
 * through the month move somebody in or out of a comparison they were mostly not part of.
 */
function cohortFor(
    roster: readonly Person[],
    horizon: Horizon,
    scope: ConditionScope | undefined,
    excludeExternal: boolean,
): Person[] {
    return roster.filter(
        (person) =>
            personInScope(person, horizon.from, scope) && !(excludeExternal && person.external),
    );
}

/** How many days of the horizon this person could actually have been scheduled on. */
function availableDaysIn(person: Person, horizon: Horizon): number {
    return person.eligibleDays.filter((date) => withinHorizon(horizon, date)).length;
}

/** The one label a cohort violation carries, built from the condition's own scope. */
function scopeLabelFor(scope: ConditionScope | undefined, messages: ViolationMessages): string {
    return messages.cohortScopeLabel({
        unitKeys: scope?.unitKeys ?? [],
        levelKeys: scope?.levelKeys ?? [],
        personKeys: scope?.personKeys ?? [],
    });
}

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const slots = slotIndex(context.slots);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const horizon = schedule.horizon;
    const cohort = cohortFor(roster, horizon, condition.scope, params.excludeExternal);
    const scopeLabel = scopeLabelFor(condition.scope, messages);
    const skipped: SkippedWindow[] = [];

    // The horizon ONLY, and by the ANCHOR date. A cohort location has no date for `evaluate()`'s
    // emission rule to test, so a duty in the carry-in tail would otherwise be counted into a
    // finding nothing could drop — CG-03 enforced by the type, because it cannot be enforced for it.
    const countedFor = (person: Person): Duty[] =>
        schedule.duties.filter(
            (duty) =>
                duty.personKey === person.key &&
                withinHorizon(horizon, duty.date) &&
                slots.get(duty.slotKey).tallyKey === params.quantity,
        );

    const holdings = cohort.map((person) => ({ person, duties: countedFor(person) }));
    const total = holdings.reduce((sum, held) => sum + held.duties.length, 0);
    const available = new Map(cohort.map((person) => [person.key, availableDaysIn(person, horizon)]));
    const denominator = [...available.values()].reduce((sum, count) => sum + count, 0);

    if (total === 0) {
        skipped.push({
            from: horizon.from,
            to: horizon.to,
            reason: messages.fairnessNoQuantitySkip({
                quantity: params.quantity,
                from: horizon.from,
                to: horizon.to,
            }),
        });

        return { findings: [], coverage: { evaluatedWindows: 0, skipped } };
    }

    if (denominator === 0) {
        skipped.push({
            from: horizon.from,
            to: horizon.to,
            reason: messages.fairnessNoDenominatorSkip({ from: horizon.from, to: horizon.to }),
        });

        return { findings: [], coverage: { evaluatedWindows: 0, skipped } };
    }

    const standings: Standing[] = holdings.map(({ person, duties }) => {
        const expected = (total * (available.get(person.key) as number)) / denominator;

        return {
            person,
            duties,
            actual: duties.length,
            expected,
            deviation: duties.length - expected,
            tolerance: toleranceFor(expected),
        };
    });

    const findings =
        params.mode === 'deviation'
            ? deviationFindings(standings, params.quantity, scopeLabel, messages)
            : spreadFindings(standings, params.quantity, scopeLabel, messages);

    return { findings, coverage: { evaluatedWindows: 1, skipped } };
};

/**
 * `deviation`: one finding per person outside their OWN allowance.
 *
 * No early exit and no aggregation into a single finding. WB-04's reason chips order a picker by
 * who is over and who is under, and one finding naming five people is one chip a scheduler cannot
 * act on — which is the same objection decision Q raises against `spread` being the default.
 */
function deviationFindings(
    standings: readonly Standing[],
    quantity: string,
    scopeLabel: string,
    messages: ViolationMessages,
): Finding[] {
    const findings: Finding[] = [];

    for (const standing of standings) {
        if (Math.abs(standing.deviation) <= standing.tolerance + COMPARISON_EPSILON) {
            continue;
        }

        findings.push({
            location: {
                kind: 'cohort',
                personKeys: [standing.person.key],
                scopeLabel,
                contributing: standing.duties,
            },
            explanation: messages.fairnessDeviationViolation({
                quantity,
                actual: standing.actual,
                expected: standing.expected,
                deviation: Math.abs(standing.deviation),
                tolerance: standing.tolerance,
                over: standing.deviation > 0,
                cohortSize: standings.length,
            }),
        });
    }

    return findings;
}

/**
 * `spread`: ONE finding, naming the busiest and the quietest, when the gap between them is too wide.
 *
 * The allowance is the sum of those two people's OWN tolerances, which is exactly the widest gap
 * `deviation` would have permitted between them — see the module docblock for why a threshold of
 * its own was rejected. A cohort of one has a gap of zero and never fires.
 */
function spreadFindings(
    standings: readonly Standing[],
    quantity: string,
    scopeLabel: string,
    messages: ViolationMessages,
): Finding[] {
    if (standings.length === 0) {
        return [];
    }

    let busiest = standings[0] as Standing;
    let quietest = standings[0] as Standing;

    // NO EARLY EXIT: both extremes are found by a full pass, because the pair is the finding and a
    // scan that stopped at the first person over their own tolerance would be `deviation` wearing
    // this mode's name.
    for (const standing of standings) {
        if (standing.deviation > busiest.deviation) {
            busiest = standing;
        }

        if (standing.deviation < quietest.deviation) {
            quietest = standing;
        }
    }

    const gap = busiest.deviation - quietest.deviation;
    const allowance = busiest.tolerance + quietest.tolerance;

    if (gap <= allowance + COMPARISON_EPSILON) {
        return [];
    }

    return [
        {
            location: {
                kind: 'cohort',
                personKeys: [busiest.person.key, quietest.person.key],
                scopeLabel,
                contributing: [...busiest.duties, ...quietest.duties],
            },
            explanation: messages.fairnessSpreadViolation({
                quantity,
                busiest: {
                    personKey: busiest.person.key,
                    actual: busiest.actual,
                    expected: busiest.expected,
                },
                quietest: {
                    personKey: quietest.person.key,
                    actual: quietest.actual,
                    expected: quietest.expected,
                },
                gap,
                allowance,
                cohortSize: standings.length,
            }),
        },
    ];
}
