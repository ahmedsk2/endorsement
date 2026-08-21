/**
 * `we_pairing` — CG-07: *"Weekend pairing convention | preferred pairs; fallbacks"*.
 *
 * **The twenty-second and last implemented key in the catalog.**
 *
 * ## Owner decision Z: pairs of DAYS, and `fallbacks` does NOT ship
 *
 * A "preferred pair" is a pair of ISO WEEKDAYS — Friday then Saturday for this department — and the
 * rule is that the pair is covered as a BLOCK by the same person rather than split between two.
 * That is what gives everybody else a genuinely free weekend, which is the whole point of a pairing
 * convention. The competing reading, named pairs of PEOPLE, needs a person-pair store that exists
 * nowhere in this tree; if the owner prefers it, the store is a P3 migration and this predicate is
 * unchanged in shape.
 *
 * `fallbacks` is not in the params schema, and the absence is a DECISION rather than an omission.
 * An ordered list of acceptable alternatives produces no violation when one is used — it produces a
 * worse-but-acceptable placement, which is WB-04 fitness ordering and AU-02's rank-weighted penalty
 * terms. That is exactly the split owner decision P already makes for `eligibility`'s *"auto-fill
 * order"*, applied consistently. §4.3's open *"shared definition of what 'preference broken' means"*
 * is therefore resolved as: **a violation is raised when the honoured pairing is not the preferred
 * one**, with no third answer to reach for.
 *
 * ## A SPLIT is a violation; a GAP is not, and the two are one comparison apart
 *
 * A pair whose two days are held by different people is split. A pair with ONE day covered and the
 * other held by nobody is short a person, which is a coverage requirement — SL-03's templates, P3 —
 * and not a pairing preference. Reporting it here would make this type the second implementation of
 * something P3 owns, and it would fire on every genuinely half-staffed weekend a department already
 * knows about. Both readings are in the corpus on ONE world, so an implementation comparing the two
 * days without checking that both are covered fails rather than looking like a stricter reading.
 *
 * ## Per SLOT, because two slots on one weekend are two pairings
 *
 * A department running a day and a night slot has two weekends to pair, not one, and a person
 * holding both days of the day slot has honoured it whatever happens on the night slot. Comparing
 * the whole day's roster instead would report a split on every weekend with more than one slot.
 *
 * ## CG-01's scope decides WHO the rule can see, and that is the honest reading
 *
 * Holders are resolved among people the scope selects. A weekend split between an in-scope person
 * and an out-of-scope one therefore shows one covered day and one uncovered, which is a gap rather
 * than a split — the rule was asked about a population and answers about that population. The
 * alternative, judging a pair by everybody and then filtering the finding, makes a rule scoped to
 * R1s report on an R3, which is the opposite failure to the one a scope usually hides.
 *
 * ## The emission rule cannot help, so CG-03 is kept by the ENUMERATION
 *
 * A cohort location carries no date, so `evaluate()`'s rule is unconditionally true for one — the
 * same fact `fairness_distribution` and `holiday_equity` record. A pair lying WHOLLY in the
 * carry-in tail would produce a finding nothing could drop, which is exactly what CG-03 forbids.
 * {@link candidateStarts}'s bounds are what prevent it, and they are the SOLUTION SET of
 * {@link windowTouchesHorizon} for a two-day pair rather than an approximation of it — proved by
 * planting the explicit check and watching it stay green, because it could never be taken. See that
 * function's docblock, and the property in `conditions.test.ts` that keeps the derivation honest.
 *
 * ## Duty→date reading: ANCHOR DATE, and the tail is read
 *
 * `DUTY_DATE_READING.we_pairing`. A Friday-night call running to Saturday morning is one FRIDAY
 * call and covers the Friday half of the pair; it does not cover the Saturday. `needsCarryIn: true`,
 * because a weekend straddling the month boundary is one weekend and the half in the tail is the
 * only thing that says whether it was covered as a block or split.
 *
 * The weekday of a date comes from `isoWeekdayAt`, which prefers the precomputed day vector and
 * falls back to `ymd.ts`'s arithmetic — the one permitted fallback, and this type needs it for the
 * same reason `clinic_conflict` does: a pair beginning the day before the horizon starts on a date
 * the vector does not describe.
 *
 * ## PLANTED, AND ONE OF THEM WAS DEAD CODE RATHER THAN AN UNASSERTED RULE
 *
 * Red: `personInScope` answering `true` — the standing FIRST plant; the weekday pair matched on any
 * two consecutive dates rather than on the named one; the both-days-covered check removed, so a gap
 * reports as a split; the comparison taken across the whole day rather than per slot; the holders
 * on the two days compared as sets that may never be equal; {@link candidateStarts} started at
 * `horizon.from`, so the weekend straddling the boundary disappears, and separately widened by a
 * week, which inflates `evaluatedWindows` with pairs nobody may see; the scope label replaced by a
 * constant; the no-occurrence coverage row suppressed; and both sentences replaced by literals.
 *
 * Green a SECOND time, at the far end: **`candidateStarts` stopping one day early**. No case had a
 * preferred pair beginning on its LAST horizon date, so the right-hand edge — the seam case's
 * mirror, where the Saturday arrives in `followingDuties` — was unasserted. Closed by
 * `we-pairing-the-weekend-that-runs-into-the-month-after-the-horizon`, which is also only the
 * second corpus case in the package to supply a `followingDuties` the contract says must never be
 * assumed empty.
 *
 * Green, and it was NOT a corpus gap: **the explicit `windowTouchesHorizon` check inside the scan**.
 * It could never be taken — see {@link candidateStarts} — so it is deleted rather than fixtured, and
 * the property tying its reason to the bounds lives in `conditions.test.ts`.
 *
 * ## The P2-2 review's second green plant here, EXAMINED AND KEPT
 *
 * Narrowing `slotKeys` from the union of both days to the first day alone stays green, and it is a
 * true observation with a false conclusion. The scan is not carrying one dead line; it is carrying
 * a symmetric PAIR — the union, and the `first.length === 0` half of the gap guard — and removing
 * either one alone is harmless while removing both changes nothing at all. Planted in every
 * direction: dropping `first.length === 0` goes RED, dropping `second.length === 0` goes RED, and
 * dropping the union's second day is the only one that survives, because a slot filled on Saturday
 * alone then never enters the loop instead of entering it and being refused as a GAP.
 *
 * It is kept BECAUSE it is symmetric. The answer must not depend on which of a pair's two days the
 * enumeration happens to start from, and a reader checking that reads one expression rather than
 * reconstructing it from which branch is unreachable. `conditions.test.ts` now asserts a gap on
 * each side, so the symmetry is a measured property rather than a spelling.
 */

import { addDays, datesBetween, type Ymd } from '../calendar/ymd';
import type { JsonSchema } from '../contract/schema';
import type {
    Condition,
    ConditionEvaluator,
    ConditionPreview,
    ConditionScope,
    Finding,
    SkippedWindow,
    ViolationMessages,
} from '../contract/types';
import { assertValidAgainst } from '../contract/validate';
import type { Duty } from '../duty/interval';
import { windowTouchesHorizon, type Horizon } from '../duty/windows';
import { carryInLeftEdge, dayIndex, dutyStreams, isoWeekdayAt, personInScope, rosterFor } from './support';

/** One preferred pairing: two ISO weekdays, the second falling on the date after the first. */
export interface PreferredPair {
    first: number;
    second: number;
}

/** `we_pairing`'s parameters, normalised. `fallbacks` is absent by decision — see the docblock. */
export interface WePairingParams {
    preferredPairs: PreferredPair[];
}

/**
 * The pair is an OBJECT rather than a two-element array, and that is a measured choice.
 *
 * `preview.test.ts`'s probe generator builds an array's low probe as a ONE-element list, so an inner
 * array carrying `minItems: 2` would be refused by the very schema the matrix is probing and the
 * matrix would report a crash instead of an ignored parameter. Naming the two ends also removes the
 * only real ambiguity in the shape: which day comes first.
 *
 * ISO integers, exactly as `dow_restriction` takes them. There is no name-to-number table in this
 * package and there is deliberately never going to be one — AR-07 keeps the names in
 * `lang/en/calendar.php` and owner decision X keeps the week's shape in the context.
 */
export const PARAMS_SCHEMA: JsonSchema = {
    type: 'object',
    properties: {
        preferredPairs: {
            type: 'array',
            minItems: 1,
            description:
                'The pairs of days a weekend is made of, as ISO weekdays. An empty list would be a ' +
                'pairing convention naming no pairing, so it is refused rather than admitted.',
            items: {
                type: 'object',
                properties: {
                    first: { type: 'integer', minimum: 1, maximum: 7, description: 'ISO weekday of day one.' },
                    second: {
                        type: 'integer',
                        minimum: 1,
                        maximum: 7,
                        description: 'ISO weekday of day two, on the calendar date after day one.',
                    },
                },
                required: ['first', 'second'],
                additionalProperties: false,
            },
        },
    },
    required: ['preferredPairs'],
    additionalProperties: false,
};

/** Read and normalise, refusing anything the schema does not admit. */
export function readParams(condition: Condition): WePairingParams {
    assertValidAgainst(PARAMS_SCHEMA, condition.params, `we_pairing on condition "${condition.id}"`);

    return condition.params as unknown as WePairingParams;
}

/** CG-04's sentence, carrying the two absences a reader would otherwise supply. */
export const preview: ConditionPreview = (condition, _context, messages) => {
    const params = readParams(condition);

    return messages.wePairing({ pairs: params.preferredPairs });
};

/** The one label a cohort violation carries, built from the condition's own scope. */
function scopeLabelFor(scope: ConditionScope | undefined, messages: ViolationMessages): string {
    return messages.cohortScopeLabel({
        unitKeys: scope?.unitKeys ?? [],
        levelKeys: scope?.levelKeys ?? [],
        personKeys: scope?.personKeys ?? [],
    });
}

/**
 * Every date on which a pair COULD begin and still touch the horizon, earliest first.
 *
 * It starts ONE day before `horizon.from` — a pair is two days, so a pair beginning on the last
 * date of the published month reaches the 1st, and that is the weekend a scheduler hits first. It
 * stops at `horizon.to`, since a pair beginning after it lies wholly in whatever follows.
 *
 * ## These bounds ARE `windowTouchesHorizon`, and a plant is what proved it
 *
 * An `if (!windowTouchesHorizon(start, addDays(start, 1), horizon)) continue;` sat inside the scan
 * below, on the argument that a cohort location has no date for `evaluate()`'s emission rule to
 * test and CG-03 therefore has to be kept here. That argument is right and the check was DEAD: for
 * a pair of two days, `to >= horizon.from` holds for every start at or after `horizon.from - 1` and
 * `from <= horizon.to` holds for every start at or before `horizon.to`, which is exactly this
 * range. Deleting it left the whole suite green, and a branch that cannot be taken is a control
 * that appears to do something — the shape this package refuses everywhere else, pointing inward.
 *
 * So the rule is stated ONCE, here, in the bounds that do the work, and `conditions.test.ts` ties
 * the two definitions together as a property: every date this returns satisfies
 * {@link windowTouchesHorizon} for its own pair, and the dates either side of the range do not.
 * Exported for that check, which is the only thing that keeps a derivation like this true after the
 * next edit.
 */
export function candidateStarts(horizon: Horizon): Ymd[] {
    return datesBetween(addDays(horizon.from, -1), horizon.to);
}

/** The predicate. See the module docblock for every decision in it. */
export const evaluate: ConditionEvaluator = (condition, schedule, context, messages) => {
    const params = readParams(condition);
    const streams = dutyStreams(schedule, context);
    const roster = rosterFor(context, streams);
    const days = dayIndex(context);
    const horizon = schedule.horizon;
    const findings: Finding[] = [];
    const skipped: SkippedWindow[] = [...carryInLeftEdge(context, horizon, messages)];
    const scopeLabel = scopeLabelFor(condition.scope, messages);

    const inScope = new Set(
        roster
            .filter((person) => personInScope(person, horizon.from, condition.scope))
            .map((person) => person.key),
    );

    // `${date}|${slotKey}` -> the duties in-scope people hold there, across all three streams. The
    // tail is in here BY DESIGN and the emission rule is what would normally keep it honest; a
    // cohort location has no date for that rule to test, so the pair filter below does it instead.
    const held = new Map<string, Duty[]>();
    const slotsOn = new Map<string, Set<string>>();

    for (const duty of [...streams.priorDuties, ...streams.duties, ...streams.followingDuties]) {
        if (!inScope.has(duty.personKey)) {
            continue;
        }

        const key = `${duty.date}|${duty.slotKey}`;

        held.set(key, [...(held.get(key) ?? []), duty]);
        slotsOn.set(duty.date, (slotsOn.get(duty.date) ?? new Set<string>()).add(duty.slotKey));
    }

    const holders = (date: Ymd, slotKey: string): Duty[] => held.get(`${date}|${slotKey}`) ?? [];
    const names = (duties: readonly Duty[]): string[] => [...new Set(duties.map((duty) => duty.personKey))].sort();

    let evaluated = 0;

    for (const pair of params.preferredPairs) {
        let occurrences = 0;

        // NO EARLY EXIT over the dates or the slots: every occurrence of every pair is examined,
        // because a month holds several weekends and stopping at the first split would report the
        // shape of the problem rather than the problem.
        for (const start of candidateStarts(horizon)) {
            const finish = addDays(start, 1);

            if (isoWeekdayAt(days, start) !== pair.first || isoWeekdayAt(days, finish) !== pair.second) {
                continue;
            }

            // NO emission-rule check here: `candidateStarts` already IS it — see its docblock, and
            // the property in `conditions.test.ts` that keeps the two from becoming two.
            occurrences += 1;
            evaluated += 1;

            // Every slot filled on EITHER day, sorted so two runtimes report the same order.
            const slotKeys = [
                ...new Set([...(slotsOn.get(start) ?? []), ...(slotsOn.get(finish) ?? [])]),
            ].sort();

            for (const slotKey of slotKeys) {
                const first = holders(start, slotKey);
                const second = holders(finish, slotKey);

                // A GAP is not a SPLIT. One day covered and the other not is a coverage
                // requirement, which SL-03 owns and P3 builds — see the module docblock.
                if (first.length === 0 || second.length === 0) {
                    continue;
                }

                const firstHolders = names(first);
                const secondHolders = names(second);

                if (firstHolders.join('|') === secondHolders.join('|')) {
                    continue;
                }

                findings.push({
                    location: {
                        kind: 'cohort',
                        personKeys: [...new Set([...firstHolders, ...secondHolders])].sort(),
                        scopeLabel,
                        contributing: [...first, ...second],
                    },
                    explanation: messages.wePairingViolation({
                        slotKey,
                        firstDate: start,
                        secondDate: finish,
                        firstHolders,
                        secondHolders,
                    }),
                });
            }
        }

        if (occurrences === 0) {
            skipped.push({
                from: horizon.from,
                to: horizon.to,
                reason: messages.wePairingNoOccurrenceSkip({
                    first: pair.first,
                    second: pair.second,
                    from: horizon.from,
                    to: horizon.to,
                }),
            });
        }
    }

    return { findings, coverage: { evaluatedWindows: evaluated, skipped } };
};
