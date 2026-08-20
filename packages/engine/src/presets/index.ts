/**
 * CG-08's preset bundles (P2-2 Task 21): the three, the manifest, and the one lookup.
 *
 * A preset is **data the engine can be CALLED with** — not seed data, because the `conditions`
 * table it would seed does not exist until P3 (CG-01/CG-02), and not code, because a bundle that
 * decided anything would be deciding it for every department that installed it. `types.ts` states
 * the rest; `manifest.ts` states what each bundle claims and is compared against it in both
 * directions.
 *
 * The order is the order CG-08 names them in, so the two can be read side by side — `catalog.ts`'s
 * property one layer along, and asserted rather than merely intended.
 */

import { ACGME } from './acgme';
import { RESIDENCY } from './residency';
import { SCFHS } from './scfhs';
import type { Preset } from './types';

export * from './types';
export { PRESET_MANIFEST } from './manifest';

/** The three CG-08 bundles, in the order CG-08 names them. */
export const PRESETS: readonly Preset[] = [ACGME, RESIDENCY, SCFHS];

/**
 * One bundle by key, or `undefined`. Callers decide what an absence means.
 *
 * `registryEntry()`'s shape deliberately: a caller reaching a preset by import path is a caller
 * reaching one the manifest does not know it has.
 */
export function presetFor(key: string, presets: readonly Preset[] = PRESETS): Preset | undefined {
    return presets.find((preset) => preset.key === key);
}
