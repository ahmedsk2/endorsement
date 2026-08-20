/**
 * CG-08's preset bundles: the shapes. The three bundles themselves are data files beside this one.
 *
 * ## What a preset can physically BE in P2, and what it is not
 *
 * P2 ships no migration and there is no `conditions` table — P3 builds it (CG-01/CG-02). So a
 * preset is **data the engine can be CALLED with**, and data that ST-01's setup wizard will later
 * IMPORT into `conditions`. It is **not seed data**: calling it a seeder would require the table
 * that does not exist, and *"seeded"* is CG-08's own word, which a later reader will take
 * literally.
 *
 * ## A preset is CONFIGURATION, not code
 *
 * It names type keys and supplies parameters. It carries no predicate, imports nothing but a type,
 * and holds nothing a JSON round trip would lose — `presets.test.ts` asserts all three, the last as
 * a property of the value rather than as a claim about the file. Decision H's sentence is that a
 * preset *"can physically be a JSON data file inside `packages/engine`"*, and the ONE deviation
 * from that is the extension: `contract/schema.ts` is a JSON Schema document in a `.ts` file
 * because a JSON import *"would resolve differently under the bundler, under plain Node and under
 * `tsc`, which is three answers to a question worth none"*, and a preset has exactly the same three
 * consumers. It is a JSON data file by value.
 *
 * ## The three states, and why the empty one is a decision
 *
 * `ready` installs; `structure-only` names types and awaits their numbers; `empty` deliberately
 * carries nothing. **An empty array is indistinguishable from a failed load and from nobody having
 * written it yet**, which is why an unfinished preset must carry {@link PresetPending} and why
 * `manifest.ts` declares the state a second time so the two can be compared. That is
 * `UnitMerge::REFERENCES`'s device: an entry is a decision, not documentation.
 */

import type { Condition } from '../contract/types';

/** What a bundle is: installable, structure awaiting numbers, or deliberately carrying nothing. */
export type PresetState = 'ready' | 'structure-only' | 'empty';

/**
 * A type a bundle contains whose parameters are OWNER INPUT, carried with no values at all.
 *
 * `awaiting` is the type's own `PARAMS_SCHEMA.required`, compared against it rather than restated —
 * a hand-written copy agrees until a schema gains a parameter, and then the structure reads as
 * complete while a department fills in a form missing a field. A type publishing an empty schema
 * awaits nothing and is still a draft: this bundle installs nothing at all (Decision AB).
 */
export interface PresetDraft {
    typeKey: string;
    awaiting: readonly string[];
    /** What puts this type in this bundle — the phrase in the tree it comes from. */
    because: string;
}

/** What an unfinished preset is waiting for, who supplies it, and when somebody last asked. */
export interface PresetPending {
    awaits: string;
    from: string;
    /** `Y-m-d`. A plain string: a preset holds no branded type and calls nothing to make one. */
    lastCheckedOn: string;
}

/**
 * One CG-08 bundle.
 *
 * `limitations` is REQUIRED and may be empty, the distinction `contributing` was corrected into at
 * Task 15: absent would mean a bundle forgot to say, `[]` means it said *none*. `pending` is absent
 * on a ready bundle and mandatory on every other, derived from the state rather than remembered.
 */
export interface Preset {
    key: string;
    title: string;
    describes: string;
    conditions: readonly Condition[];
    drafts: readonly PresetDraft[];
    limitations: readonly string[];
    pending?: PresetPending;
}

/**
 * One line of the manifest, written by hand against the bundle it describes.
 *
 * It is a SECOND declaration on purpose. A manifest derived from the presets would agree with them
 * always and catch nothing; this one is compared in both directions, so a type key claimed by no
 * bundle and a bundle row claimed by no manifest entry each fail the build.
 */
export interface PresetManifestEntry {
    key: string;
    state: PresetState;
    /** Every catalog type key the bundle carries — installable rows and drafts alike, sorted. */
    covers: readonly string[];
    reason: string;
}
