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
 * ## The empty catalog, and why this file exists before it has entries
 *
 * P2 Task 7 authors this file with `CATALOG` EMPTY, so that Task 8's catalog-parity guard — which
 * derives the key set from CG-07's own table in `docs/munawib/SPEC.md` and compares it in both
 * directions — has something to be red against on its first run. The registry is filled in Task 8.
 */

import type {
    ConditionEvaluator,
    ConditionPreview,
    LocationKind,
} from './contract/types';
import type { JsonSchema } from './contract/schema';

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
 * Every CG-07 type key, in the spec's own order.
 *
 * EMPTY at P2 Task 7, filled at Task 8. `catalog-parity.test.ts` derives the key set from CG-07's
 * table itself and compares it against this array in both directions, so the count cannot drift
 * again and a twenty-fourth row appearing in the spec fails the build until somebody classifies it.
 */
export const CATALOG: readonly RegistryEntry[] = [];

/** The one entry for a type key, or `undefined`. Callers decide what an absence means. */
export function registryEntry(typeKey: string, catalog: readonly RegistryEntry[] = CATALOG): RegistryEntry | undefined {
    return catalog.find((entry) => entry.typeKey === typeKey);
}
