import { test, expect } from '@playwright/test';
import { assertLocalhost, login, readBack } from './fixtures.js';

/**
 * CLAUDE.md, verbatim: "Autosave is never fire-and-forget: per-field save-on-blur, UI reflects
 * the server response, e2e asserts persistence after reload — never the indicator alone." The
 * master rota's grid is Task 8's per-cell save; this file is that discipline applied to it, and
 * — per this task's own instruction — to the split editor (Task 9) and vacation booking (Task
 * 10) too, since all three write through the same `back()`-and-re-render round trip and any one
 * of them could look right on screen and be gone after a reload.
 *
 * E2eSeeder seeds "E2E Rota Resident" and one academic year (2026-2027) of exactly two periods,
 * Block 1 (2026-08-01..2026-08-14) and Block 2 (2026-08-15..2026-08-28) — the SAME boundary
 * VacationEndpointTest's straddling-vacation test exercises, and 2026-08-12 is the same
 * Wednesday CalendarTest/GoldenFixtureTest already prove snaps to the department week
 * 2026-08-09..2026-08-15 under the [5,6] Friday/Saturday weekend default. Reusing those exact
 * dates here pins this spec to facts already proven elsewhere rather than asserting a new one.
 *
 * Every row/column is addressed by its own `data-row-id`/`data-col-key` attribute, discovered
 * from the DOM rather than assumed — never nth-child, because the grid re-sorts by level group
 * and by name (finding-driven: `AppLayout.vue`/`Units.vue` precedent).
 */
const YEAR = '2026-2027';
const ROTA = `/admin/rota?year=${YEAR}`;

test.describe('master rota — per-cell save survives a reload', () => {
    test.beforeAll(({ }, testInfo) => assertLocalhost(testInfo.project.use.baseURL));

    test('a whole-period assignment, a split, a straddling vacation and a clear all persist after reload', async ({ page }) => {
        await login(page);
        await readBack(page, ROTA);

        // The desktop table (this suite runs at the default Desktop Chrome viewport, >= the
        // `lg` breakpoint) carries BOTH the table row and, hidden by CSS, the mobile card for
        // the same person — scoping to `table tbody tr[data-row-id]` avoids a Playwright
        // strict-mode match against the hidden duplicate.
        const row = page.locator('table tbody tr[data-row-id]').filter({ hasText: 'E2E Rota Resident' });
        await expect(row).toBeVisible();

        const rowId = await row.getAttribute('data-row-id');
        const personId = rowId.replace('person-', '');

        const cells = row.locator('td[data-col-key]');
        await expect(cells).toHaveCount(2);

        const colKey1 = await cells.nth(0).getAttribute('data-col-key');   // Block 1
        const colKey2 = await cells.nth(1).getAttribute('data-col-key');   // Block 2
        const periodId1 = colKey1.replace('period-', '');

        const cell = (colKey) => page.locator(`table tbody tr[data-row-id="${rowId}"] td[data-col-key="${colKey}"]`);

        // --- 1. A whole-period assignment (the plain <select>). ------------------------------
        const select1 = cell(colKey1).locator('select');
        await select1.selectOption({ label: 'PICU' });

        // The indicator, THEN the reload — the indicator alone proves nothing (CLAUDE.md).
        // Scoped to the desktop cell: the mobile card carries the SAME testid, hidden by CSS,
        // and an unscoped page-level lookup hits Playwright's strict-mode duplicate-match error.
        await expect(cell(colKey1).getByTestId(`cell-status-${personId}-${periodId1}`)).toHaveText('Saved');

        // Read the value back only AFTER the server confirms the save: the <select> is bound
        // one-way to `props.grid` (never v-model), so while the PATCH is still in flight a
        // re-render from the 'saving' status change snaps it back to its OLD (still-unassigned)
        // value — reading it before "Saved" races that snap-back and is not evidence of anything.
        const picuValue = await select1.inputValue();

        await readBack(page, ROTA);
        await expect(cell(colKey1).locator('select')).toHaveValue(picuValue);

        // --- 2. Open the split editor on the SAME cell; two spans with a deliberate one-day
        //        gap (Aug 14 — owner decision 3: gaps are allowed and counted, never silent). --
        await cell(colKey1).getByTestId(`split-open-${personId}-${periodId1}`).click();
        const splitEditor = page.getByTestId('split-editor');
        await expect(splitEditor).toBeVisible();

        const spanRows = splitEditor.locator('[data-testid="split-span-row"]');
        await spanRows.nth(0).getByTestId('split-span-unit').selectOption({ label: 'PICU' });
        await spanRows.nth(0).getByTestId('split-span-start').fill('2026-08-01');
        await spanRows.nth(0).getByTestId('split-span-end').fill('2026-08-07');

        await splitEditor.getByTestId('split-add-span').click();
        await spanRows.nth(1).getByTestId('split-span-unit').selectOption({ label: 'NICU' });
        await spanRows.nth(1).getByTestId('split-span-start').fill('2026-08-08');
        await spanRows.nth(1).getByTestId('split-span-end').fill('2026-08-13');

        await splitEditor.getByTestId('split-save').click();
        // Waiting for the panel to close waits for the server's response, not an optimistic
        // update — submitSplit() only calls closeSplit() from onSuccess.
        await expect(splitEditor).toBeHidden();

        await readBack(page, ROTA);
        const spanList = cell(colKey1).locator('ul li');
        await expect(spanList).toHaveCount(2);
        await expect(spanList.nth(0)).toContainText('PICU');
        await expect(spanList.nth(1)).toContainText('NICU');
        // The uncovered-day count is the SERVER's own figure, never recomputed on screen.
        await expect(cell(colKey1)).toContainText('1d unassigned');

        // --- 3. Book a week's leave from Block 1's cell. 2026-08-12 (Wed) snaps to the
        //        department's own week, 2026-08-09..2026-08-15 — which straddles Block 1
        //        (ends 08-14) and Block 2 (starts 08-15). -----------------------------------
        await cell(colKey1).getByTestId(`vacation-open-${personId}-${periodId1}`).click();
        const vacationEditor = page.getByTestId('vacation-editor');
        await expect(vacationEditor).toBeVisible();

        await vacationEditor.getByTestId('vacation-granularity-week').check();
        await vacationEditor.getByTestId('vacation-starts-on').fill('2026-08-12');
        await vacationEditor.getByTestId('vacation-ends-on').fill('2026-08-12');
        await vacationEditor.getByTestId('vacation-save').click();
        await expect(vacationEditor).toBeHidden();

        await readBack(page, ROTA);
        await expect(cell(colKey1)).toContainText('2026-08-09');
        await expect(cell(colKey1)).toContainText('2026-08-15');
        await expect(cell(colKey2)).toContainText('2026-08-09');
        await expect(cell(colKey2)).toContainText('2026-08-15');

        // --- 4. Clear the assignment. Clearing is not cancelling leave — the two write through
        //        different tables (Decision C) and must not be entangled. ---------------------
        await cell(colKey1).getByTestId(`clear-cell-${personId}-${periodId1}`).click();
        await expect(cell(colKey1).getByTestId(`cell-status-${personId}-${periodId1}`)).toHaveText('Saved');

        await readBack(page, ROTA);
        // Back to the plain, empty <select> — no spans left to render as a read-only list.
        await expect(cell(colKey1).locator('select')).toBeVisible();
        await expect(cell(colKey1).locator('select')).toHaveValue('');
        // The spans list specifically (`li.channel-bar`) — not `ul li` generally, which would
        // also match the vacation chip's own <li> asserted two lines below.
        await expect(cell(colKey1).locator('li.channel-bar')).toHaveCount(0);
        // The leave from step 3 is STILL there, on both cells it straddles.
        await expect(cell(colKey1)).toContainText('2026-08-09');
        await expect(cell(colKey2)).toContainText('2026-08-09');
    });
});
