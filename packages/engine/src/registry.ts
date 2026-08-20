/**
 * The catalog registry: one entry per CG-07 type key, and what the engine may assert about it.
 *
 * ## What an entry is allowed to say
 *
 * Three of the twenty-three catalog rows carry a class; §30 makes `class` a FIELD on the condition
 * row; CG-01 lists it per condition and CG-02 rank-orders the soft ones. So class is AUTHORED DATA
 * and the engine reads `Condition.class` — a `defaultClass` per entry would have hardcoded a class
 * for nineteen types that have none. {@link RegistryEntry.catalogDefault} therefore records CG-07's
 * markings as DOCUMENTATION the engine never applies, and `evaluate()` stamps severity from the
 * condition row. A comment saying so would rot; a field with a test cannot.
 *
 * ## The count is DERIVED, never declared
 *
 * `catalog-parity.test.ts` parses CG-07's table out of `docs/munawib/SPEC.md` and compares it
 * against {@link CATALOG} in both directions. No number appears in this file, on purpose: the
 * catalog's size has been miscounted repeatedly in this repository, in writing, and a number
 * written down here would be a fourth chance to get it wrong.
 */

import type {
    ConditionEvaluator,
    ConditionPreview,
    LocationKind,
} from './contract/types';
import type { JsonSchema } from './contract/schema';
import * as clinicConflict from './conditions/clinic_conflict';
import * as consecutiveMax from './conditions/consecutive_max';
import * as dowRestriction from './conditions/dow_restriction';
import * as eligibility from './conditions/eligibility';
import * as fairnessDistribution from './conditions/fairness_distribution';
import * as minGap from './conditions/min_gap';
import * as onboardingGrace from './conditions/onboarding_grace';
import * as overlapBlock from './conditions/overlap_block';
import * as postDutyExclusion from './conditions/post_duty_exclusion';
import * as rollingHoursMax from './conditions/rolling_hours_max';
import * as sameUnitConflict from './conditions/same_unit_conflict';
import * as targetPerPeriod from './conditions/target_per_period';
import * as unwantedDayBlock from './conditions/unwanted_day_block';
import * as vacationBlock from './conditions/vacation_block';

/**
 * Which way a type pushes, and it exists for a product hazard rather than for tidiness.
 *
 * CG-05 makes Hard block publishing and AU-02 makes the solver never violate it, so a Hard FLOOR
 * or TARGET turns a staffing shortage into AU-07 infeasibility plus a publish block instead of a
 * ranked warning — a materially different behaviour, reachable from one drag on a gate screen. A
 * Hard CAP is safe, because a violation is always attributable to a placement somebody can move.
 * P3's gate warns before a floor is set Hard. The engine still never overrides the row.
 */
export type ConditionDirection = 'cap' | 'floor' | 'target' | 'block' | 'spacing' | 'equity';

/** CG-07's own class markings, recorded. Never applied — see the module docblock. */
export type CatalogDefault = 'hard' | 'soft-top';

/** One catalog row, as the engine knows it. */
export interface RegistryEntry {
    typeKey: string;

    /**
     * Whether this engine implements the row.
     *
     * `false` on exactly one entry, and the absence is a DECISION rather than an oversight — the
     * same device `UnitMerge::REFERENCES` uses for a table a merge deliberately leaves alone and
     * Task 5's coverage manifest uses for an unasserted fixture block.
     */
    implemented: boolean;

    /**
     * Why an unimplemented row is unimplemented, with its citations, so grepping this file answers
     * the question without opening the spec. Present exactly when `implemented` is `false`.
     */
    notImplementedBecause?: string;

    evaluate?: ConditionEvaluator;
    preview?: ConditionPreview;
    paramsSchema?: JsonSchema;

    /**
     * The one class the engine may assert, and it is present on `overlap_block` ALONE — CG-07
     * marks that row "Hard, built-in" and no other row states a class the engine could assert.
     */
    assertedClass?: 'hard';

    /** CG-07's marking, recorded as documentation. Read by nothing; asserted to be read by nothing. */
    catalogDefault?: CatalogDefault;

    direction: ConditionDirection;

    /**
     * Which member of the `Location` union this type produces. This and {@link needsCarryIn} are
     * what make the P2-1/P2-2 seam and the fixture corpus checkable rather than merely asserted.
     */
    locationKind: LocationKind;

    /** Whether the type is wrong at the horizon edge without `priorDuties`/`followingDuties`. */
    needsCarryIn: boolean;
}

/**
 * Every CG-07 type key, in the catalog's own order.
 *
 * ## The arithmetic is not written down here, it is derived
 *
 * `catalog-parity.test.ts` parses CG-07's table out of `docs/munawib/SPEC.md` and compares it
 * against this array in BOTH directions — the key set, the `(Stage 5)` marking, and the class
 * markings, each derived from the source it documents. The count has been miscounted repeatedly in
 * this repository, in writing, which is why no number appears in this file: a twenty-fourth row in
 * the spec fails the build until somebody classifies it, and an entry here with no row behind it
 * fails it too. A second SOURCE rather than a second implementation, which is
 * `UnitMergeCoversEveryUnitReferenceTest`'s device.
 *
 * The order is the catalog's own so the two can be read side by side, and that is asserted rather
 * than merely intended.
 *
 * ## `evaluate` arrives one task at a time, and `preview` may arrive AHEAD of it
 *
 * Task 10 lands the three Hard placement types; Tasks 12–20 land the rest. Until an entry carries
 * an evaluator, a condition naming its key throws `UnimplementedConditionTypeError` — the honest
 * answer, because a silently ignored Hard rule is a control that appears to do nothing.
 *
 * The two fields are coupled in ONE direction, asserted in `preview.test.ts`: `evaluate` implies
 * `preview` and `paramsSchema`, never the reverse. Four entries carry a schema and a sentence with
 * no predicate behind them yet (Task 9 landed the four whose WORDING an owner decision settles),
 * and that order is deliberate — the predicate then implements a schema that already exists,
 * instead of the schema being back-formed from whatever the predicate happened to read.
 */
export const CATALOG: readonly RegistryEntry[] = [
    {
        typeKey: 'min_gap',
        implemented: true,
        evaluate: minGap.evaluate,
        paramsSchema: minGap.PARAMS_SCHEMA,
        preview: minGap.preview,
        direction: 'spacing',
        locationKind: 'placement',
        // The gap between the last night of month M and the first duty of M+1 is the case a
        // scheduler hits first, and it is measured entirely outside the horizon on one side.
        needsCarryIn: true,
    },
    {
        typeKey: 'max_gap',
        implemented: true,
        direction: 'spacing',
        locationKind: 'window',
        needsCarryIn: true,
    },
    {
        typeKey: 'count_max',
        implemented: true,
        direction: 'cap',
        locationKind: 'window',
        needsCarryIn: true,
    },
    {
        // TWO keys, not one with a direction parameter, and it was measured rather than assumed:
        // CG-01 and §30 store one typeKey per condition row, CG-04's preview text differs by
        // direction, a department will enable a cap without a floor — and a single key would fail
        // the parity guard, because CG-07 names two. They share an evaluator module and a params
        // schema; they are not one entry.
        typeKey: 'count_min',
        implemented: true,
        direction: 'floor',
        locationKind: 'window',
        needsCarryIn: true,
    },
    {
        typeKey: 'target_per_period',
        implemented: true,
        paramsSchema: targetPerPeriod.PARAMS_SCHEMA,
        preview: targetPerPeriod.preview,
        direction: 'target',
        locationKind: 'window',
        needsCarryIn: true,
    },
    {
        typeKey: 'composition',
        implemented: true,
        direction: 'target',
        locationKind: 'window',
        needsCarryIn: true,
    },
    {
        typeKey: 'we_pairing',
        implemented: true,
        direction: 'target',
        locationKind: 'cohort',
        // A weekend straddling the month boundary is one weekend, and the half in the tail is what
        // says whether it was covered as a block or split.
        needsCarryIn: true,
    },
    {
        typeKey: 'fairness_distribution',
        implemented: true,
        paramsSchema: fairnessDistribution.PARAMS_SCHEMA,
        preview: fairnessDistribution.preview,
        direction: 'equity',
        locationKind: 'cohort',
        // Measured over the schedule under evaluation against its own eligible-day denominator.
        // Last month's load is a different period's fairness question.
        needsCarryIn: false,
    },
    {
        typeKey: 'vacation_block',
        implemented: true,
        evaluate: vacationBlock.evaluate,
        preview: vacationBlock.preview,
        paramsSchema: vacationBlock.PARAMS_SCHEMA,
        catalogDefault: 'hard',
        direction: 'block',
        locationKind: 'placement',
        needsCarryIn: false,
    },
    {
        typeKey: 'unwanted_day_block',
        implemented: true,
        evaluate: unwantedDayBlock.evaluate,
        preview: unwantedDayBlock.preview,
        paramsSchema: unwantedDayBlock.PARAMS_SCHEMA,
        catalogDefault: 'soft-top',
        direction: 'block',
        locationKind: 'placement',
        needsCarryIn: false,
    },
    {
        typeKey: 'clinic_conflict',
        implemented: true,
        evaluate: clinicConflict.evaluate,
        preview: clinicConflict.preview,
        paramsSchema: clinicConflict.PARAMS_SCHEMA,
        direction: 'block',
        locationKind: 'placement',
        // CORRECTED AT TASK 13, BY MEASUREMENT. This entry read `true`, on the ground that a clinic
        // on the 1st is judged against a duty in the already-published month before it. That
        // judgement cannot be REPORTED: every finding this type produces is located at a DUTY, so
        // one derived from a duty in the carry-in tail is dropped by evaluate()'s emission rule
        // before anybody sees it (CG-03, never retroactive on published schedules). Reading the
        // tail therefore changes no output at all, and a fixture asserting it would be asserting
        // nothing — which is exactly what Task 14's seam-corpus guard would have required. What
        // this type does reach past the horizon for is a CLINIC, and clinics are a weekly
        // recurrence carried in the context for every weekday, so they are always available.
        needsCarryIn: false,
    },
    {
        typeKey: 'eligibility',
        implemented: true,
        evaluate: eligibility.evaluate,
        preview: eligibility.preview,
        paramsSchema: eligibility.PARAMS_SCHEMA,
        direction: 'block',
        locationKind: 'placement',
        needsCarryIn: false,
    },
    {
        typeKey: 'same_unit_conflict',
        implemented: true,
        evaluate: sameUnitConflict.evaluate,
        preview: sameUnitConflict.preview,
        paramsSchema: sameUnitConflict.PARAMS_SCHEMA,
        direction: 'block',
        locationKind: 'placement',
        // Same-date pairs only. Nothing outside the horizon can make two people share a date in it.
        needsCarryIn: false,
    },
    {
        typeKey: 'dow_restriction',
        implemented: true,
        evaluate: dowRestriction.evaluate,
        preview: dowRestriction.preview,
        paramsSchema: dowRestriction.PARAMS_SCHEMA,
        direction: 'block',
        locationKind: 'placement',
        needsCarryIn: false,
    },
    {
        typeKey: 'post_duty_exclusion',
        implemented: true,
        evaluate: postDutyExclusion.evaluate,
        preview: postDutyExclusion.preview,
        paramsSchema: postDutyExclusion.PARAMS_SCHEMA,
        direction: 'spacing',
        locationKind: 'placement',
        needsCarryIn: true,
    },
    {
        typeKey: 'overlap_block',
        implemented: true,
        evaluate: overlapBlock.evaluate,
        preview: overlapBlock.preview,
        paramsSchema: overlapBlock.PARAMS_SCHEMA,
        // The ONE class the engine may assert, and the only row that states one it could: CG-07
        // calls it Hard AND built-in. The engine still never overrides the condition row — this is
        // a fact P3's gate may refuse a relaxation against, not an input to a severity.
        assertedClass: 'hard',
        catalogDefault: 'hard',
        direction: 'block',
        locationKind: 'placement',
        // A night call on the last of the previous month runs past midnight into the 1st.
        needsCarryIn: true,
    },
    {
        typeKey: 'consecutive_max',
        implemented: true,
        evaluate: consecutiveMax.evaluate,
        preview: consecutiveMax.preview,
        paramsSchema: consecutiveMax.PARAMS_SCHEMA,
        direction: 'cap',
        locationKind: 'placement',
        // A run spanning the 31st into the 1st is one run, and its length is only knowable from
        // the tail.
        needsCarryIn: true,
    },
    {
        typeKey: 'rolling_hours_max',
        implemented: true,
        paramsSchema: rollingHoursMax.PARAMS_SCHEMA,
        preview: rollingHoursMax.preview,
        direction: 'cap',
        locationKind: 'window',
        needsCarryIn: true,
    },
    {
        typeKey: 'free_day_min',
        implemented: true,
        direction: 'floor',
        locationKind: 'window',
        needsCarryIn: true,
    },
    {
        typeKey: 'call_frequency_max',
        implemented: true,
        direction: 'cap',
        locationKind: 'window',
        needsCarryIn: true,
    },
    {
        typeKey: 'onboarding_grace',
        implemented: true,
        evaluate: onboardingGrace.evaluate,
        preview: onboardingGrace.preview,
        paramsSchema: onboardingGrace.PARAMS_SCHEMA,
        direction: 'block',
        locationKind: 'placement',
        // Measured from `joinedAt`, a date on the person. No neighbouring duty enters into it.
        needsCarryIn: false,
    },
    {
        typeKey: 'holiday_equity',
        implemented: true,
        direction: 'equity',
        locationKind: 'cohort',
        // Its history arrives as `priorCredits` and `historyAvailableFrom`, not as prior DUTIES —
        // a lookback of years is not a carry-in tail of days.
        needsCarryIn: false,
    },
    {
        typeKey: 'forbidden_transition',
        implemented: false,
        notImplementedBecause:
            'CG-07 marks this row "(Stage 5)" inside its own parameters cell; SPEC.md §35 names ' +
            'forbidden transitions in the Stage 5 — Shift mode deliverable list, which "starts only ' +
            'on explicit go-ahead"; and SPEC.md §36 "Not doing (and why)" makes "Shift features ' +
            'before Stage 5" a NAMED non-goal, which is decisive — building it here contradicts a ' +
            'stated non-goal rather than merely running ahead of a stage. It is cheap in code, since ' +
            'slot.kind is opaque and the predicate needs no shift substrate; what it has no source ' +
            'for is a shift vocabulary to parameterise, a preset to seed it, a gate screen to offer ' +
            'it, or a real input to prove it against. A type whose only fixture is one its author ' +
            'invented is a stub with tests.',
        // Declared as the shape it WOULD take, so a later implementer inherits a decision rather
        // than a blank. Nothing reads either field while `implemented` is false.
        direction: 'block',
        locationKind: 'placement',
        needsCarryIn: true,
    },
];

/** The one entry for a type key, or `undefined`. Callers decide what an absence means. */
export function registryEntry(typeKey: string, catalog: readonly RegistryEntry[] = CATALOG): RegistryEntry | undefined {
    return catalog.find((entry) => entry.typeKey === typeKey);
}

/**
 * A catalog by type key, refusing a duplicate key.
 *
 * ONE definition of that refusal, shared by `evaluate()`'s resolution and `preview()`'s. Two
 * entries under one key make every lookup arbitrary, and the arbitrary answer is free to differ
 * between this package's two runtimes (D4) and between its two dispatchers — so a condition could
 * be previewed by one entry and evaluated by another, which reads on a gate screen as a rule that
 * does not do what it says.
 */
export function indexCatalog(catalog: readonly RegistryEntry[]): Map<string, RegistryEntry> {
    const byTypeKey = new Map<string, RegistryEntry>();

    for (const entry of catalog) {
        if (byTypeKey.has(entry.typeKey)) {
            throw new RangeError(
                `Two registry entries share the type key "${entry.typeKey}"; every lookup would be ` +
                    "arbitrary, and the arbitrary answer would differ between this package's two runtimes.",
            );
        }

        byTypeKey.set(entry.typeKey, entry);
    }

    return byTypeKey;
}
