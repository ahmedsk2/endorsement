import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { DUTY_DATE_READING } from '../src/duty/interval';
import { CATALOG, registryEntry } from '../src/registry';

/**
 * The registry's own invariants (P2 Task 8). `catalog-parity.test.ts` checks it against CG-07's
 * table; this file checks what the table cannot say.
 *
 * The one that earns its place is the DUTY_DATE_READING cross-check. Task 4 declared, per type key,
 * which of Decision A's three duty→date readings that type uses — an enumeration of the same
 * catalog, written four tasks earlier, in a different file, for a different purpose. Two
 * independent enumerations of one list is exactly the thing that silently diverges, and the check
 * costs three lines. It is bought.
 */

const SPEC_PATH = join(import.meta.dirname, '..', '..', '..', 'docs', 'munawib', 'SPEC.md');
const SRC_DIR = join(import.meta.dirname, '..', 'src');

const implemented = CATALOG.filter((entry) => entry.implemented);
const keysWith = (kind: string): string[] =>
    CATALOG.filter((entry) => entry.locationKind === kind)
        .map((entry) => entry.typeKey)
        .sort();

/** Every `.ts` under `src/` except the registry itself, which is allowed to declare its own fields. */
function engineSourcesExceptRegistry(): string[] {
    return readdirSync(SRC_DIR, { recursive: true, encoding: 'utf8' }).filter(
        (entry) => entry.endsWith('.ts') && entry !== 'registry.ts',
    );
}

describe('the registry as a whole', () => {
    it('carries one entry per catalog key, 22 of them implemented', () => {
        expect(CATALOG).toHaveLength(23);
        expect(implemented).toHaveLength(22);
    });

    it('states a reason exactly when it states an entry is unimplemented', () => {
        const mismatched = CATALOG.filter(
            (entry) => entry.implemented === (entry.notImplementedBecause !== undefined),
        ).map((entry) => entry.typeKey);

        expect(mismatched).toEqual([]);
    });

    it('is reachable by key', () => {
        expect(registryEntry('overlap_block')?.locationKind).toBe('placement');
        expect(registryEntry('no_such_type')).toBeUndefined();
    });
});

describe('forbidden_transition — an absence that is a decision, not an oversight', () => {
    const entry = registryEntry('forbidden_transition');
    const spec = readFileSync(SPEC_PATH, 'utf8');

    it('is registered, unimplemented', () => {
        expect(entry).toBeDefined();
        expect(entry?.implemented).toBe(false);
        expect(entry?.evaluate).toBeUndefined();
    });

    it('carries its three citations, so grepping the registry answers "why is this missing"', () => {
        const reason = entry?.notImplementedBecause ?? '';

        expect(reason).toMatch(/CG-07/);
        expect(reason).toMatch(/§35/);
        expect(reason).toMatch(/§36/);
    });

    /**
     * A citation that no longer resolves is worse than none: it reads as evidence and is not. The
     * three texts are asserted to exist in the spec rather than the three LINE NUMBERS, because the
     * line numbers are exactly what rotted — P2 Task 1's own footnote cited lines 252 and 256 for
     * §35 and §36, and the footnote's own insertion had already pushed them to 276 and 280.
     */
    it('cites text that is actually in the spec today', () => {
        expect(spec).toMatch(/\| forbidden_transition \| Shift A never followed by shift B \| from\/to kinds \(Stage 5\) \|/);
        expect(spec).toMatch(/Stage 5 — Shift mode[^\n]*forbidden transitions/);
        expect(spec).toMatch(/Shift features before Stage 5\./);
    });
});

describe('what each entry declares', () => {
    it('splits the catalog into the seam P2-1 and P2-2 are cut on', () => {
        expect(keysWith('placement')).toEqual([
            'clinic_conflict',
            'consecutive_max',
            'dow_restriction',
            'eligibility',
            'forbidden_transition',
            'min_gap',
            'onboarding_grace',
            'overlap_block',
            'post_duty_exclusion',
            'same_unit_conflict',
            'unwanted_day_block',
            'vacation_block',
        ]);

        expect(keysWith('window')).toEqual([
            'call_frequency_max',
            'composition',
            'count_max',
            'count_min',
            'free_day_min',
            'max_gap',
            'rolling_hours_max',
            'target_per_period',
        ]);

        expect(keysWith('cohort')).toEqual(['fairness_distribution', 'holiday_equity', 'we_pairing']);
    });

    it('leaves P2-1 eleven implemented placement types and P2-2 eleven window and cohort ones', () => {
        const byKind = (kind: string): number =>
            implemented.filter((entry) => entry.locationKind === kind).length;

        expect(byKind('placement')).toBe(11);
        expect(byKind('window') + byKind('cohort')).toBe(11);
    });

    /**
     * A window can straddle the horizon edge BY CONSTRUCTION — `enumerateWindows` starts
     * `lengthDays - 1` days before it, because a window beginning in the tail constrains a duty on
     * the 1st. So a window-located type that claims to need no carry-in has been mis-entered, and
     * this is a derivation rather than a restatement of the data.
     */
    it('needs the carry-in tail for every window-located type', () => {
        const wrong = CATALOG.filter((entry) => entry.locationKind === 'window' && !entry.needsCarryIn).map(
            (entry) => entry.typeKey,
        );

        expect(wrong).toEqual([]);
    });

    it('asserts a class for overlap_block alone — the one row CG-07 calls built-in', () => {
        const asserting = CATALOG.filter((entry) => entry.assertedClass !== undefined);

        expect(asserting.map((entry) => entry.typeKey)).toEqual(['overlap_block']);
        expect(asserting[0]?.assertedClass).toBe('hard');
    });
});

describe('the second enumeration of the same catalog, four tasks earlier', () => {
    /**
     * `DUTY_DATE_READING` (Task 4) and `CATALOG` (Task 8) are two hand-written lists of the same 22
     * keys, in two files, written for two purposes, four tasks apart. Compared in both directions
     * for three lines: a key added to one and forgotten in the other fails the build.
     */
    it('agrees with DUTY_DATE_READING in both directions', () => {
        const declared = Object.keys(DUTY_DATE_READING).sort();
        const registered = implemented.map((entry) => entry.typeKey).sort();

        expect(declared).toEqual(registered);
    });

    it('does not declare a reading for the key it does not implement', () => {
        expect(Object.keys(DUTY_DATE_READING)).not.toContain('forbidden_transition');
    });
});

describe('what the engine may NOT do with a registry entry', () => {
    /**
     * Decision E: `class` is authored data on the condition row, and the engine reads
     * `Condition.class`. `catalogDefault` records CG-07's own markings as DOCUMENTATION the engine
     * never applies, and `assertedClass` is a fact P3's gate screen may use to refuse a relaxation
     * — neither is an input to a severity. A comment saying so would rot; this cannot.
     *
     * PLANTED: `entry.catalogDefault` was read in `evaluate.ts`'s stamping expression and this went
     * red naming the file. Reverted.
     */
    it('never reads catalogDefault or assertedClass anywhere outside the registry itself', () => {
        const readers = engineSourcesExceptRegistry().filter((relative) => {
            const source = readFileSync(join(SRC_DIR, relative), 'utf8');

            return source.includes('.catalogDefault') || source.includes('.assertedClass');
        });

        expect(readers.sort()).toEqual([]);
    });

    it('scans a real set of files, so the check above is not green over nothing', () => {
        const scanned = engineSourcesExceptRegistry();

        expect(scanned).toContain('evaluate.ts');
        expect(scanned).not.toContain('registry.ts');
        expect(scanned.length).toBeGreaterThanOrEqual(8);
    });
});
