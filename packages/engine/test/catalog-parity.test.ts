import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { CATALOG } from '../src/registry';

/**
 * The catalog registry against CG-07's own table, in BOTH directions (P2 Task 8).
 *
 * ## Why this guard exists at all
 *
 * The catalog's size has been miscounted repeatedly, in this repository, in writing. The table is
 * 22 data rows carrying 23 distinct type keys — `count_max / count_min` is one row with two — and
 * exactly one row is marked `(Stage 5)` inside its own parameters cell, which is where D13's
 * "all 21 types" comes from: 22 rows − 1 = 21, and 23 keys − 1 = 22 implemented. Nothing in the
 * spec states that arithmetic, which is precisely why it kept being re-derived and re-derived
 * differently.
 *
 * So the number is not written down here either. It is DERIVED from the table, every run, and
 * compared against the registry both ways. A twenty-fourth row appearing in the spec fails the
 * build until somebody classifies it; a registry entry with no row behind it fails the build too.
 * That is the `UnitMergeCoversEveryUnitReferenceTest` device — a second SOURCE rather than a second
 * implementation — and it is the only thing that stops the count drifting a fourth time.
 *
 * ## Three parities, not one
 *
 * The key set is the obvious one. The other two are what make the registry's *classifications*
 * derived rather than asserted:
 *
 *  - The rows the spec marks `(Stage 5)` must be exactly the entries carrying `implemented: false`.
 *    If the spec ever un-marks that row, the build fails until somebody decides to implement it.
 *  - The rows whose parameters cell carries a class marking — `(Hard default)`, `(top soft
 *    default)`, `(Hard, built-in)` — must be exactly the entries carrying `catalogDefault`, AND the
 *    recorded value must match the marking. Decision E makes `catalogDefault` documentation the
 *    engine never applies, and documentation is exactly the thing that rots; deriving it from the
 *    source it documents is what a field with a test buys over a comment.
 *
 * PLANTED, both directions, at Task 8. A 23rd row was appended to the real `SPEC.md` table and the
 * spec→registry direction went red naming the unregistered key; `min_gap` was then deleted from the
 * registry and the registry→spec direction went red naming it. `(Stage 5)` was moved onto a second
 * row, and the Stage-5 parity went red naming both. All reverted. See the commit for the output.
 */

const SPEC_PATH = join(import.meta.dirname, '..', '..', '..', 'docs', 'munawib', 'SPEC.md');

const CG_07_HEADER = '| Type key | Meaning | Key parameters |';

/** One data row of CG-07's table, cells trimmed, the type-key cell split on its slash. */
export interface CatalogRow {
    typeKeys: string[];
    meaning: string;
    parameters: string;
}

/**
 * Parse CG-07's table out of a markdown document.
 *
 * Located by its HEADER rather than by line number, deliberately: the footnote P2 Task 1 added
 * under this very table shifted every line below it by two dozen, and shipped citing the old
 * numbers. A guard anchored on a line number would have been wrong the day it was written.
 */
export function parseCatalogRows(markdown: string): CatalogRow[] {
    const lines = markdown.split(/\r?\n/);
    const headers = lines
        .map((line, index) => ({ line: line.trim(), index }))
        .filter((entry) => entry.line === CG_07_HEADER);

    if (headers.length !== 1) {
        throw new Error(
            `Expected exactly one CG-07 catalog table header, found ${headers.length}. The guard ` +
                'locates the table by its header row; if the header changed, point it at the new one.',
        );
    }

    const start = (headers[0] as { index: number }).index;
    const separator = (lines[start + 1] ?? '').trim();

    if (!/^\|[\s\-:|]+\|$/.test(separator)) {
        throw new Error(`The row after the CG-07 header is not a table separator: "${separator}".`);
    }

    const rows: CatalogRow[] = [];

    for (let index = start + 2; index < lines.length; index += 1) {
        const line = (lines[index] ?? '').trim();

        if (!line.startsWith('|')) {
            break;
        }

        const cells = line.split('|').slice(1, -1).map((cell) => cell.trim());

        if (cells.length !== 3) {
            throw new Error(`CG-07 row ${index + 1} has ${cells.length} cells, not 3: "${line}".`);
        }

        rows.push({
            typeKeys: (cells[0] as string).split('/').map((key) => key.trim()),
            meaning: cells[1] as string,
            parameters: cells[2] as string,
        });
    }

    return rows;
}

/** A parameters cell marked `(Stage 5)` — the one thing that keeps a row out of P2's build set. */
const isStageFive = (row: CatalogRow): boolean => /\(Stage 5\)/.test(row.parameters);

/** `(Hard default)`, `(top soft default)`, `(Hard, built-in)` — and not `(Stage 5)`. */
function classMarking(row: CatalogRow): 'hard' | 'soft-top' | null {
    const marking = /\(([^)]*\b(?:Hard|soft)\b[^)]*)\)/i.exec(row.parameters)?.[1];

    if (marking === undefined) {
        return null;
    }

    return /hard/i.test(marking) ? 'hard' : 'soft-top';
}

const spec = readFileSync(SPEC_PATH, 'utf8');
const rows = parseCatalogRows(spec);
const specKeys = rows.flatMap((row) => row.typeKeys);
const registryKeys = CATALOG.map((entry) => entry.typeKey);

describe("CG-07's table, parsed from SPEC.md", () => {
    it('is found, and is not empty — a guard over nothing is green for the wrong reason', () => {
        expect(rows.length).toBeGreaterThan(0);
        expect(specKeys).toContain('overlap_block');
    });

    it('carries 22 data rows and 23 distinct type keys, derived rather than declared', () => {
        expect(rows).toHaveLength(22);
        expect(new Set(specKeys).size).toBe(23);
        expect(specKeys).toHaveLength(23);
    });

    it('splits the one row that carries two keys', () => {
        const twoKeyRows = rows.filter((row) => row.typeKeys.length > 1);

        expect(twoKeyRows).toHaveLength(1);
        expect(twoKeyRows[0]?.typeKeys).toEqual(['count_max', 'count_min']);
    });

    it('marks exactly one row (Stage 5), and it is forbidden_transition', () => {
        const marked = rows.filter(isStageFive).flatMap((row) => row.typeKeys);

        expect(marked).toEqual(['forbidden_transition']);
    });
});

describe('the registry against the catalog, in both directions', () => {
    it('registers every key the catalog carries', () => {
        expect([...specKeys].filter((key) => !registryKeys.includes(key)).sort()).toEqual([]);
    });

    it('carries no key the catalog does not name', () => {
        expect([...registryKeys].filter((key) => !specKeys.includes(key)).sort()).toEqual([]);
    });

    it('names each key exactly once', () => {
        expect(new Set(registryKeys).size).toBe(registryKeys.length);
    });

    it('lists them in the catalog own order, so the two can be read side by side', () => {
        expect(registryKeys).toEqual(specKeys);
    });

    it('leaves unimplemented exactly the rows the catalog marks (Stage 5)', () => {
        const stageFive = rows.filter(isStageFive).flatMap((row) => row.typeKeys).sort();
        const unimplemented = CATALOG.filter((entry) => !entry.implemented)
            .map((entry) => entry.typeKey)
            .sort();

        expect(unimplemented).toEqual(stageFive);
    });

    it('records catalogDefault for exactly the rows the catalog marks with a class, and the same value', () => {
        const marked = new Map<string, 'hard' | 'soft-top'>();

        for (const row of rows) {
            const marking = classMarking(row);

            if (marking !== null) {
                for (const key of row.typeKeys) {
                    marked.set(key, marking);
                }
            }
        }

        const recorded = new Map(
            CATALOG.filter((entry) => entry.catalogDefault !== undefined).map((entry) => [
                entry.typeKey,
                entry.catalogDefault as 'hard' | 'soft-top',
            ]),
        );

        expect([...marked.entries()].sort()).toEqual([...recorded.entries()].sort());
        expect(marked.size).toBe(3);
    });
});

describe('the parser itself', () => {
    const table = [
        '| Type key | Meaning | Key parameters |',
        '|---|---|---|',
        '| alpha | Does alpha | one; two |',
        '| beta / gamma | Two keys, one row | count; window |',
        '| delta | Later | kinds (Stage 5) |',
        '',
        'Prose after the table.',
    ].join('\n');

    it('stops at the first non-table line', () => {
        expect(parseCatalogRows(table)).toHaveLength(3);
    });

    it('splits a slash cell and detects the Stage-5 marking', () => {
        const parsed = parseCatalogRows(table);

        expect(parsed[1]?.typeKeys).toEqual(['beta', 'gamma']);
        expect(parsed.filter(isStageFive).flatMap((row) => row.typeKeys)).toEqual(['delta']);
    });

    it('refuses a document with no CG-07 header rather than reporting an empty catalog', () => {
        expect(() => parseCatalogRows('# Nothing here\n')).toThrow(/exactly one CG-07 catalog table header/);
    });

    it('refuses a malformed row rather than half-reading it', () => {
        const broken = ['| Type key | Meaning | Key parameters |', '|---|---|---|', '| alpha | missing a cell |'].join(
            '\n',
        );

        expect(() => parseCatalogRows(broken)).toThrow(/cells, not 3/);
    });

    it('reads a class marking without mistaking (Stage 5) for one', () => {
        expect(classMarking({ typeKeys: ['x'], meaning: '', parameters: '— (Hard default)' })).toBe('hard');
        expect(classMarking({ typeKeys: ['x'], meaning: '', parameters: '— (top soft default)' })).toBe('soft-top');
        expect(classMarking({ typeKeys: ['x'], meaning: '', parameters: '— (Hard, built-in)' })).toBe('hard');
        expect(classMarking({ typeKeys: ['x'], meaning: '', parameters: 'from/to kinds (Stage 5)' })).toBeNull();
    });
});
