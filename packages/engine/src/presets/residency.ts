/**
 * `preset:residency` — CG-08's residency defaults, as STRUCTURE ONLY (owner decision AB).
 *
 * ## Why there is not a single number in this file
 *
 * CG-08 says the bundle is *"seeded from the prototype's proven values"*. **D14 makes the prototype
 * "idea curation only — not a code ancestor, not a data source", and D15 records that the
 * pseudonymised export it depended on "is no longer available"** (P2 finding 13). So the values are
 * an OWNER INPUT and not a lookup, and there is nothing in this repository to read them from.
 *
 * A wrong residency default is worse than an absent one, because it looks authoritative on a gate
 * screen and an absent entry and a guessed one are indistinguishable there — only the first is
 * safe. *"A stub returning a plausible value"* is what CLAUDE.md forbids outright.
 *
 * ## So what IS known, and how each entry earned its place
 *
 * The bundle's SHAPE. SPEC Appendix A's requirements line names the conditions this department
 * arranges its rota under — *"Automatic arrangement under modifiable, importance-ranked conditions
 * (spacing, monthly caps, weekday/weekend distribution, vacations, unwanted days, clinic–post-call)
 * → §15, §17"* — and each of those six phrases maps onto one catalog row. `onboarding_grace` is the
 * seventh and comes from Decision H's own sentence about it: *"N has never been stated by any owner
 * and the residency preset ships it absent rather than guessed"*, which places the type in this
 * bundle and its number outside it. Every entry carries the phrase it came from, so a reader can
 * check the mapping rather than trust it.
 *
 * ## `awaiting` is DERIVED, and two entries await nothing
 *
 * Each list is the type's own `PARAMS_SCHEMA.required`, compared against it in `presets.test.ts`
 * rather than restated — a hand-written copy agrees until a schema gains a parameter, and then this
 * file reads as complete while a department fills in a form missing a field.
 *
 * `vacation_block` and `unwanted_day_block` publish EMPTY schemas: they take no parameters at all,
 * so they await nothing and could have shipped as installable rows. They do not, because the bundle
 * is structure-only: **a preset a department installs half of is a preset that looks finished on a
 * gate screen.** Their class would also have to be invented — CG-07 marks the two of them, but
 * Decision E makes those markings documentation the engine never applies, and §30 makes `class` a
 * field on the condition row that the department authors.
 *
 * ## And `clinic_conflict`'s variant is awaited even though this department has answered it
 *
 * The owner settled the variant for QCH on 2026-08-20 (post-call only). A preset is department-
 * independent configuration shipped in a package every customer runs, so it does not carry one
 * department's answer — D11's boundary is the database, and this file is on the other side of it.
 */

import type { Preset } from './types';

export const RESIDENCY: Preset = {
    key: 'preset:residency',
    title: 'Residency defaults',
    describes:
        'The seven condition types a residency rota is arranged under, named with their parameters ' +
        'left empty. Every number in this bundle is owner input; none is guessed and none is here.',
    conditions: [],
    drafts: [
        {
            typeKey: 'min_gap',
            awaiting: ['value', 'unit'],
            because:
                "SPEC Appendix A's requirements line names spacing among the conditions a rota is " +
                'arranged under. Both the figure and which of the two readings it is measured in ' +
                '(hours end-to-start, or days between start dates) are the department to state.',
        },
        {
            typeKey: 'count_max',
            awaiting: ['count', 'window'],
            because:
                "SPEC Appendix A's requirements line names monthly caps. The window is stated " +
                'rather than assumed: the department block and the department week are different ' +
                'numbers, and block 13 is five weeks long where the rest are four.',
        },
        {
            typeKey: 'composition',
            awaiting: ['targets'],
            because:
                "SPEC Appendix A's requirements line names weekday/weekend distribution. The " +
                'targets are per level and per bucket, so the shape is known and every figure in it ' +
                'is not.',
        },
        {
            typeKey: 'vacation_block',
            awaiting: [],
            because:
                "SPEC Appendix A's requirements line names vacations. The type takes no parameters " +
                'at all, so it awaits nothing and is still a draft: this bundle installs nothing, ' +
                'and the class of the row is authored on the gate screen rather than here.',
        },
        {
            typeKey: 'unwanted_day_block',
            awaiting: [],
            because:
                "SPEC Appendix A's requirements line names unwanted days. Parameterless, like the " +
                'vacation block beside it, and a draft for the same reason. The days themselves ' +
                'arrive in the evaluation context from the caller; P2 stores none (owner decision R).',
        },
        {
            typeKey: 'clinic_conflict',
            awaiting: ['variant'],
            because:
                "SPEC Appendix A's requirements line names clinic–post-call. The variant is awaited " +
                'even though this department has chosen one, because a preset is shipped to every ' +
                'customer and one department answer is not a default for the others.',
        },
        {
            typeKey: 'onboarding_grace',
            awaiting: ['days'],
            because:
                'Decision H names this type and its missing number together: N has never been ' +
                'stated by any owner, and the residency preset ships it absent rather than guessed. ' +
                'A grace period that is too long silently excuses a real breach.',
        },
    ],
    limitations: [],
    pending: {
        awaits:
            'Every number in this bundle: the spacing figure and its unit, the cap per window and ' +
            'which window, the per-level weekday/weekend targets, the clinic variant, and the ' +
            'onboarding grace in days. CG-08 would have taken them from the prototype; D14 makes it ' +
            'idea curation rather than a data source and D15 records that its export is gone.',
        from: 'The department owner, or a residency programme policy document if one is produced.',
        lastCheckedOn: '2026-08-20',
    },
};
