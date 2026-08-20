/**
 * The preset manifest: what each CG-08 bundle claims to contain, declared BY HAND.
 *
 * ## It is a second declaration on purpose, and it fails in both directions
 *
 * A manifest derived from the presets would agree with them always and catch nothing. This one is
 * written out and compared, so `presets.test.ts` can fail on:
 *
 *  - **a preset naming a type the catalog does not implement** — a bundle seeding
 *    `forbidden_transition`, or a key that has been renamed out from under it, throws
 *    `UnimplementedConditionTypeError`/`UnknownConditionTypeError` the moment a department installs
 *    it, which on a gate screen is a rule that appears configured and cannot run;
 *  - **a type this file claims that the bundle does not carry** — the direction a manifest is
 *    usually written without, and the one that catches a row silently deleted from a bundle. Every
 *    other check in the suite stays green through that: the remaining rows still validate, still
 *    match the spec's figures, still round-trip as data;
 *  - **a row a bundle grew without declaring it here**;
 *  - **a state that is not the state the contents produce** — a bundle whose rows were all deleted
 *    declares `ready` and derives `empty`.
 *
 * That is `UnitMergeCoversEveryUnitReferenceTest`'s device — the live thing and the written claim,
 * compared both ways — and `catalog-parity.test.ts` is the same shape one layer along. **An entry
 * is a decision, not documentation.**
 *
 * ## No count is written down here
 *
 * `covers` is a list, never a number, for `registry.ts`'s recorded reason: this repository has
 * miscounted a catalog in writing more than once, and a number beside a list is another chance to
 * get it wrong.
 */

import type { PresetManifestEntry } from './types';

export const PRESET_MANIFEST: readonly PresetManifestEntry[] = [
    {
        key: 'preset:acgme',
        state: 'ready',
        covers: ['call_frequency_max', 'consecutive_max', 'free_day_min', 'min_gap', 'rolling_hours_max'],
        reason:
            "CG-08's five duty-hour clauses, one per row, with the figures parsed out of the spec " +
            'sentence and compared against these parameters. Soft and active: Hard would block the ' +
            "department's first publish, and inactive would be a control that appears to do something. " +
            'The three clauses it cannot implement are stated on the bundle itself.',
    },
    {
        key: 'preset:residency',
        state: 'structure-only',
        covers: [
            'clinic_conflict',
            'composition',
            'count_max',
            'min_gap',
            'onboarding_grace',
            'unwanted_day_block',
            'vacation_block',
        ],
        reason:
            'Owner decision AB: structure only. CG-08 would have seeded it from the prototype; D14 ' +
            'makes the prototype idea curation rather than a data source and D15 records that its ' +
            'export is gone (finding 13), so every value is owner input. The seven types come from ' +
            "SPEC Appendix A's requirements line and from Decision H's own sentence about " +
            'onboarding grace; each entry carries the phrase it came from.',
    },
    {
        key: 'preset:scfhs',
        state: 'empty',
        covers: [],
        reason:
            'Owner decision AB: present and empty, with a pending block. SPEC §37 still owes the ' +
            'local duty-hour policy in numeric form and §38 records that its mapping onto the ' +
            'catalog is unvalidated, so both the numbers and the type list are awaited. An empty ' +
            'array with nothing beside it would be indistinguishable from a failed load.',
    },
];
