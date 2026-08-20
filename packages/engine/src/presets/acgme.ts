/**
 * `preset:acgme` — CG-08's *"Duty-hours (ACGME-style)"* bundle, with its numbers.
 *
 * CG-08, verbatim: *"**Duty-hours (ACGME-style)** bundle — 80 h/week averaged over 4 weeks, call ≤
 * 1-in-3 averaged, 1-in-7 free averaged, 10 h between duties, 24 h continuous cap"*. Five clauses,
 * five rows, five catalog keys — and `presets.test.ts` PARSES those five figures out of that
 * sentence and compares them against the parameters below, so the numbers are sourced rather than
 * transcribed. If the spec's numbers move, this file fails the build until it moves with them.
 *
 * ## Soft, active, and all at one rank
 *
 * **Soft** because setting an untested duty-hours rule Hard on day one blocks the department's
 * first real publish (CG-05 makes Hard block publishing, AU-02 makes the solver never violate it),
 * and CG-05 already contemplates the promotion in the other direction. **Active** because a preset
 * that installs inert is another control that appears to do something.
 *
 * **One rank for all five**, rather than an order of importance across them: CG-02's drag rank is
 * the DEPARTMENT's gesture over its own list, and five distinct ranks here would be five importance
 * judgements no document makes, arriving pre-made on a screen whose whole purpose is to make them.
 * Rank 1 says *these sit above the rows you have written*; the order among them is one drag away.
 *
 * ## The three clauses this bundle cannot implement, stated on the bundle itself
 *
 * Two are Decision H's and are properties of the platform rather than of this engine; the third was
 * found while parameterising `consecutive_max` and is a property of how the type measures. All
 * three are in {@link ACGME.limitations} because a limitation recorded only in a plan is a
 * limitation the person reading the gate screen never sees — and all three collide with Stage 4's
 * acceptance criterion that the compliance report *"reproduces hand-computed results"* (TL-03),
 * which is cheaper to say now than to discover at that gate.
 *
 * ## What is deliberately NOT here
 *
 * No `scope`. A duty-hours rule applies to the population the department chooses, and a bundle
 * arriving with a narrowed one is a rule that quietly does less than it says (rulings 41/49, in the
 * direction that reads as correct in review).
 */

import type { Preset } from './types';

export const ACGME: Preset = {
    key: 'preset:acgme',
    title: 'Duty hours (ACGME-style)',
    describes:
        'The five duty-hour figures CG-08 names, as five soft conditions a department can install ' +
        "and then argue with. Every number is the spec's own; nothing here is a house default.",
    conditions: [
        {
            id: 'acgme-hours-80',
            typeKey: 'rolling_hours_max',
            class: 'soft',
            rank: 1,
            active: true,
            source: 'preset:acgme',
            // 80 a week AVERAGED over four weeks is 320 in any 28 consecutive days — not four
            // weekly caps of 80, and not a mean of four weekly totals. Totals of 100/100/60/60
            // pass one reading and fail another. The type multiplies both numbers together
            // (`effectiveCap`), which is why the averaging is a parameter here and not arithmetic
            // somebody did before writing the file down.
            params: { hours: 80, windowDays: 7, averagingWeeks: 4 },
        },
        {
            id: 'acgme-call-1-in-3',
            typeKey: 'call_frequency_max',
            class: 'soft',
            rank: 1,
            active: true,
            source: 'preset:acgme',
            // DENSITY, not spacing: 1-in-3 over 28 days permits two calls on consecutive days,
            // where a min_gap of three days forbids them. Both ship and they disagree deliberately.
            //
            // OWNER DECISION J, ANSWERED AND OVERRIDING ITS OWN DEFAULT: the denominator is
            // ELIGIBLE DAYS, not calendar days. Days on leave, before a join date and off the
            // roster leave the denominator, so the allowance is `floor(availableDays / 3)` and the
            // rule TIGHTENS as somebody takes leave — a person with 14 days' leave in the window is
            // measured against 14 days and permitted 4 calls rather than 9. That is the intended
            // reading: it protects people from being back-loaded around their own leave. It is also
            // the opposite of what a reader assuming calendar days expects, which is why the type's
            // CG-04 preview names the denominator it used.
            //
            // The window is a count of DAYS rather than "4 weeks": `weekStartIsoDay` is derived
            // from `weekend_days`, so a department editing its weekend would otherwise silently
            // move a duty-hours rule. CG-08's "averaged" carries no number of its own; the four
            // weeks are the first clause's.
            params: { n: 3, windowDays: 28 },
        },
        {
            id: 'acgme-free-day-1-in-7',
            typeKey: 'free_day_min',
            class: 'soft',
            rank: 1,
            active: true,
            source: 'preset:acgme',
            // Averaging multiplies BOTH halves: one free day in seven over four weeks is at least
            // FOUR free days in any 28 consecutive days, not one in 28. `leaveCountsAsFree` is left
            // to the type's own default of true — the standard's day off is a day away from the
            // hospital, and a department that reads it otherwise sets the parameter.
            params: { n: 7, averagingWeeks: 4 },
        },
        {
            id: 'acgme-gap-10h',
            typeKey: 'min_gap',
            class: 'soft',
            rank: 1,
            active: true,
            source: 'preset:acgme',
            // `hours` measures END-to-START (owner decision H), which is what "10 h between duties"
            // means and is a different quantity from the same type's `days`, which measures between
            // START dates. No `kinds`: every duty counts against the rest.
            params: { value: 10, unit: 'hours' },
        },
        {
            id: 'acgme-continuous-24h',
            typeKey: 'consecutive_max',
            class: 'soft',
            rank: 1,
            active: true,
            source: 'preset:acgme',
            // Owner decision V: CG-08's "24 h continuous cap" is `unit: 'hours'` on this type — a
            // CONTIGUOUS chain, where `rolling_hours_max` is a rolling total and `days`/`nights`
            // count dates. The two genuinely differ and no catalog row carries the cap as written.
            //
            // `transitionMinutes` is the one figure in this bundle that no document in this
            // repository states; see the transition clause in `limitations` below for where 240
            // comes from and for how this engine's reading of it differs from the standard's.
            params: { count: 24, unit: 'hours', transitionMinutes: 240 },
        },
    ],
    drafts: [],
    limitations: [
        "SPEC Appendix A's clause that in-house time during home call counts toward the 80 hours is " +
            'EXCLUDED from this figure. There is no timekeeping surface anywhere in this platform and ' +
            'SPEC §36 puts time and payroll out of scope, so there is nothing to count. A department ' +
            'running home call is measured here on its call slots alone.',
        'There is no baseline daytime-hours model in Munawib at all — `master_rota_assignments` ' +
            'records which unit somebody rotates on, never for how long — so 80 hours summed over ' +
            'call slots is a floor, not an audited total. A schedule clean under this rule has not ' +
            'been shown to be under 80 hours; it has been shown that its CALL hours are.',
        'The transition allowance is the one number here the repository does not state: CG-08 drops ' +
            'the clause entirely and Appendix A names it only in words, as limited transition ' +
            'time. 240 minutes is the four additional hours ACGME publishes. Note also that this ' +
            'engine reads the allowance as what JOINS two duties into one measured stretch, so the ' +
            'gap counts INSIDE the 24 h, where the standard permits its four hours on top of them. ' +
            'The difference reports a long stretch the standard might allow, rather than missing one ' +
            'it forbids, which is the right direction for a soft warning — and it is one number for ' +
            'a department that disagrees.',
    ],
};
