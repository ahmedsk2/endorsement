import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { assertLocalhost, login, readBack } from './fixtures.js';

/**
 * MR-06's round trip, walked by the administrator it was written for: export the year, import it
 * back, and find the rota exactly as it was — then point a CHANGED file at the same year and see,
 * before confirming anything, precisely which dated work it would destroy.
 *
 * WHY A BROWSER AND NOT MORE PHPUnit. `RotaImportTest` proves the engine over twenty-five cases,
 * including a full export/re-import fingerprinted before and after; `RotaImportScreenTest` proves
 * the three routes. Neither can see the three things below, and each of them could be false while
 * every one of those tests stays green — which is the exact shape of P1d-1's and P1d-2a's own
 * findings:
 *
 *  1. **The file actually makes the trip.** The export is a streamed download with a BOM and
 *     neutralised cells; the import is a multipart upload read back by `CsvRosterReader`. Only a
 *     real browser downloading a real file and posting it back proves the two ends meet — a PHP
 *     test hands `RotaImport` bytes it constructed itself.
 *  2. **The screen is reachable by clicking.** P1d-1's journey found a whole editing action that
 *     had no control anywhere in the UI, because every test called its endpoint directly. The
 *     import is reached from the Master Rota screen, beside the two files it reads back.
 *  3. **The preview writes nothing, proved against the SERVER.** The destructive half below is
 *     previewed and deliberately NOT confirmed, and the rota is then re-read from the server to
 *     show it never moved. "The preview is read-only" asserted by reloading the rota is a
 *     different claim from the same words asserted inside the process that would have written it.
 *
 * THE DESTRUCTIVE HALF IS NEVER COMMITTED, AND THAT IS BOTH THE POINT AND A FIXTURE CONTRACT.
 * "Sees what it would destroy BEFORE confirming" is the requirement; and `rota-read.spec.js`
 * runs after this file against the same sqlite database and asserts E2eSeeder's exact coverage
 * arithmetic (21 assigned days, 35 not assigned, one gap, two unassigned people). A committed
 * overwrite here would fail that spec for a reason nobody reading it could diagnose. The no-op
 * round trip in the first half is safe for the same reason it is worth asserting: it writes
 * nothing at all.
 *
 * THE FIXTURE IS E2eSeeder's, AND SYNTHETIC PERMANENTLY. `ereader` is "E2E Rota Reader", R1, with
 * a DELIBERATE 7-day gap in Block 1 — PICU 2026-08-01..2026-08-07 inside a block that runs to
 * 2026-08-14. That gap is exactly the "dates somebody chose" an overwrite would discard, which is
 * why it is the cell this spec points a changed file at.
 */
const YEAR = '2026-2027';
const ROTA = `/admin/rota?year=${YEAR}`;
const IMPORT = '/admin/rota/import';

/** The export's column order, from `RotaExport::ASSIGNMENT_HEADERS`. */
const COL = { position: 1, periodEndsOn: 4, shortName: 5, unitCode: 7, startsOn: 8, endsOn: 9 };

const HANDLE = 'ereader';

/** A scratch path outside the repository — nothing this spec writes belongs in the tree. */
const scratch = (name) => path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'rota-import-')), name);

/**
 * The desktop table's cell for one person and one column. Finding 15: `MasterRota.vue` renders
 * BOTH a mobile card and a desktop row carrying identical testids, so every lookup is scoped
 * through the row's own `data-row-id` inside `table tbody`.
 */
async function gridCell(page, name, columnIndex) {
    const row = page.locator('table tbody tr[data-row-id]').filter({ hasText: name });
    await expect(row).toBeVisible();

    const rowId = await row.getAttribute('data-row-id');
    const columns = await row.locator('td[data-col-key]')
        .evaluateAll((tds) => tds.map((td) => td.getAttribute('data-col-key')));

    return page.locator(`table tbody tr[data-row-id="${rowId}"] td[data-col-key="${columns[columnIndex]}"]`);
}

/** Upload a file and let the SERVER answer for it. Nothing is committable until it has. */
async function previewFile(page, file) {
    await page.getByTestId('import-file').setInputFiles(file);
    await page.getByTestId('import-preview').click();
    await expect(page.getByTestId('import-preview-section')).toBeVisible();
}

/**
 * The preview row for ONE CELL, which needs the handle AND the block: the unit of outcome is the
 * (person, period) cell, so a person planned across a year has one row per block and a
 * handle-only filter is a strict-mode violation waiting for the second one. Found empirically —
 * `ereader` has cells in both of E2eSeeder's blocks, and the first shape of this helper resolved
 * to two elements.
 */
const previewRow = (page, handle, blockLabel) =>
    page.locator('[data-testid^="import-row-"]')
        .filter({ hasText: handle })
        .filter({ hasText: blockLabel });

test.describe('rota import — a year exported, imported back, and a changed file refused a silent overwrite', () => {
    test.beforeAll(({ }, testInfo) => assertLocalhost(testInfo.project.use.baseURL));

    test('an exported year re-imports as a no-op, and a changed file shows what it would destroy first', async ({ page }) => {
        await login(page);
        await readBack(page, ROTA);

        // The cell this journey is about, as it stands before anything is imported. Captured whole
        // rather than as a list of substrings: "unchanged" is a claim about everything in it.
        const block1 = await gridCell(page, 'E2E Rota Reader', 0);
        await expect(block1).toContainText('PICU');
        await expect(block1).toContainText('7d unassigned');
        const before = await block1.innerText();

        // --- 1. The export, as a real download. -------------------------------------------------
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByTestId('export-assignments').click(),
        ]);

        const exported = scratch('rota.csv');
        await download.saveAs(exported);

        // The bytes an operator actually receives — BOM included, which is why this is read as a
        // buffer-backed string and written back the same way rather than re-encoded.
        const original = fs.readFileSync(exported, 'utf8');
        expect(original).toContain(HANDLE);

        // --- 2. The screen is reachable by CLICKING, not by typing its URL. ---------------------
        await page.getByTestId('import-link').click();
        await expect(page).toHaveURL(new RegExp(`${IMPORT}$`));

        // --- 3. Import it back, unedited. -------------------------------------------------------
        await previewFile(page, exported);

        // Every cell in the file already says what the rota says. The two counts that matter are
        // the destructive ones, and both are the SERVER's own.
        //
        // READ OFF THE NUMBER'S OWN ELEMENT, exactly, never as a substring of the sentence:
        // `toContainText('0 to add')` is satisfied by "10 to add", so a count assertion a
        // ten-times-larger number passes is not a count assertion at all.
        await expect(page.getByTestId('import-count-create')).toHaveText('0');
        await expect(page.getByTestId('import-count-replace')).toHaveText('0');

        // Non-vacuity: a preview of an empty file would also show "0 to replace". The rows have to
        // be there, and none of them may be a write.
        const rows = page.locator('[data-testid^="import-row-"]');
        expect(await rows.count()).toBeGreaterThanOrEqual(4);
        await expect(page.getByTestId('import-outcome').filter({ hasText: 'Will be' })).toHaveCount(0);

        // Nothing was destructive, so nothing was warned about.
        await expect(page.getByTestId('import-replace-warning')).toHaveCount(0);

        await page.getByTestId('import-commit').click();
        await expect(page.getByTestId('import-result')).toBeVisible();
        await expect(page.getByTestId('import-applied')).toHaveText('0');

        // THE RELOAD. Everything above is a fact about a screen; this asks the server for the rota
        // again and reads the cell back.
        await readBack(page, ROTA);
        const afterRoundTrip = await gridCell(page, 'E2E Rota Reader', 0);
        await expect(afterRoundTrip).toContainText('PICU');
        await expect(afterRoundTrip).toContainText('2026-08-07');
        await expect(afterRoundTrip).toContainText('7d unassigned');
        expect(await afterRoundTrip.innerText()).toBe(before);

        // --- 4. A file that would overwrite the deliberate gap. ---------------------------------
        // One line changed: `ereader`'s Block 1 span becomes NICU for the WHOLE block, which would
        // discard both the unit somebody chose and the dates they chose it for.
        const lines = original.split(/\r?\n/);
        let touched = 0;

        const changed = lines.map((line) => {
            const cells = line.split(',');

            if (cells[COL.shortName] !== HANDLE || cells[COL.position] !== '1') return line;

            cells[COL.unitCode] = 'NICU';
            cells[COL.endsOn] = cells[COL.periodEndsOn];
            touched++;

            return cells.join(',');
        }).join('\n');

        // The edit is a fixture assumption about the export's own column order; if that ever moves,
        // this spec must fail HERE rather than silently previewing an unmodified file and asserting
        // nothing.
        expect(touched, 'the exported column order moved — this spec edited nothing').toBe(1);

        const overwriting = scratch('rota-overwriting.csv');
        fs.writeFileSync(overwriting, changed, 'utf8');

        await readBack(page, IMPORT);
        await previewFile(page, overwriting);

        // THE DESTRUCTIVE CASE, LEGIBLE BEFORE ANYTHING IS CONFIRMED. Not "1 cell would change" —
        // the unit and the dates that would be discarded, and the ones that would replace them.
        await expect(page.getByTestId('import-count-replace')).toHaveText('1');
        await expect(page.getByTestId('import-replace-warning')).toBeVisible();

        const row = previewRow(page, HANDLE, 'Block 1');
        await expect(row).toHaveCount(1);
        await expect(row.getByTestId('import-outcome')).toHaveText('Will be replaced');
        await expect(row.getByTestId('import-current')).toContainText('PICU');
        await expect(row.getByTestId('import-current')).toContainText('2026-08-01');
        await expect(row.getByTestId('import-current')).toContainText('2026-08-07');
        await expect(row.getByTestId('import-proposed')).toContainText('NICU');
        await expect(row.getByTestId('import-proposed')).toContainText('2026-08-14');

        // The line number of the row in their own file, so the operator can go and fix it there.
        await expect(row.getByTestId('import-lines')).not.toBeEmpty();

        // --- 5. Walk away without confirming, and the rota has not moved. -----------------------
        // Asserted against the SERVER, not against the screen that declined to write: a preview
        // that had quietly written would look identical up to this line.
        await readBack(page, ROTA);
        const untouched = await gridCell(page, 'E2E Rota Reader', 0);

        // The cell is still a SPLIT: one dated span, and the gap under it. Asserted this way
        // rather than as `not.toContainText('NICU')`, which was the first shape of this line and
        // could not tell what it claimed to: an overwritten cell renders a plain unit `<select>`
        // whose options list every unit in the department, so "NICU is not in this cell" is true
        // of a split cell and false of an EMPTY one, for reasons that have nothing to do with the
        // import. Found by planting the defect below rather than by reading the line.
        await expect(untouched.locator('li.channel-bar')).toHaveCount(1);
        await expect(untouched).toContainText('PICU');
        await expect(untouched).toContainText('2026-08-07');
        await expect(untouched).toContainText('7d unassigned');
        expect(await untouched.innerText()).toBe(before);
    });
});
