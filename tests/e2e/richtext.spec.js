import { test, expect } from '@playwright/test';
import { assertLocalhost, login, readBack, today } from './fixtures.js';

/**
 * The handover rich text survives the round trip — THE BUG THAT WAS LIVE IN PRODUCTION.
 *
 * `document.execCommand` is bracketed with `styleWithCSS`, and the setting decides whether
 * the browser emits TAGS (`<b>`, `<i>`, `<u>`) or CSS declarations (`font-weight`,
 * `font-style`, `text-decoration-line`). The server-side HTMLPurifier allow-list accepts
 * the tags and three of the properties — but NOT `font-style` and NOT
 * `text-decoration-line`. Legacy ran every command with `styleWithCSS` ON, so italic and
 * underline were emitted in exactly the form the sanitizer threw away, and were silently
 * lost on every save.
 *
 * No unit test can see this: the markup is produced by the BROWSER's execCommand
 * implementation, and jsdom has none. The round trip has to be driven through a real
 * engine, and the assertion has to be made after a RELOAD — the editor keeps the pre-save
 * DOM, so the screen looks correct right up until the next shift opens the sheet.
 *
 * Each format is checked twice after the reload: the markup came back, AND the engine
 * actually renders it (getComputedStyle), which is what the next shift's clinician sees.
 */
const DATE = today();
const SHEET = `/endorsement/PICU/${DATE}`;

/**
 * Each test creates and owns exactly ONE handover row, addressed by its server id — the
 * census is re-sorted by bed on every read, so "the last row" is a moving target.
 */
let rowId = null;

const rowLocator = (page) => page.locator(`[data-testid="handover-row"][data-row-id="${rowId}"]`);

test.describe('handover rich text round trip', () => {
    test.beforeAll(({ }, testInfo) => assertLocalhost(testInfo.project.use.baseURL));

    test.beforeEach(async ({ page }) => {
        await login(page);
        await page.goto(SHEET);

        // Wait for the census to have RENDERED before snapshotting it — an Inertia page
        // mid-hydration yields an empty list and every pre-existing row looks "new".
        await expect(page.getByTestId('add-row')).toBeVisible();
        await page.waitForLoadState('networkidle');

        const before = await page.locator('[data-testid="handover-row"]').evaluateAll(
            (rows) => rows.map((r) => r.dataset.rowId),
        );

        await page.getByTestId('add-row').click();

        // The new row is whichever id was not there a moment ago — never an index.
        await expect.poll(async () => {
            const after = await page.locator('[data-testid="handover-row"]').evaluateAll(
                (rows) => rows.map((r) => r.dataset.rowId),
            );
            const fresh = after.filter((id) => !before.includes(id));
            rowId = fresh[0] ?? null;

            return rowId;
        }).not.toBeNull();

        await expect(rowLocator(page)).toBeVisible();
    });

    test.afterEach(async ({ page }) => {
        // Retire the row through the app's own control (soft delete + audit).
        if (rowId === null) return;
        await page.goto(SHEET);

        // Wait for hydration before counting — goto resolves on the un-hydrated shell,
        // where count() is 0, and a cleanup that cannot fail loudly stops running.
        await expect(page.getByTestId('add-row')).toBeVisible();
        await page.waitForLoadState('networkidle');

        const row = rowLocator(page);
        if (await row.count()) {
            await row.getByTestId('delete-row').click();
            await expect(row).toHaveCount(0);
        }
        rowId = null;
    });

    /**
     * Type into a handover cell and apply toolbar commands to the whole of what was typed.
     * `commands` are the toolbar testids (rte-bold, rte-italic, …), applied in order.
     */
    const write = async (page, field, text, commands = [], colour = null) => {
        const cell = rowLocator(page).getByTestId(`cell-${field}`);
        const editor = cell.getByTestId('rte-editor');

        await editor.click();
        // The row was created a moment ago and the editor writes its content imperatively,
        // so it must be confirmed EMPTY and settled before typing.
        await expect(editor).toHaveText('');
        await expect(editor).toBeFocused();
        await page.keyboard.type(text);
        await expect(editor).toHaveText(text);

        for (const cmd of commands) {
            // Ctrl+A inside a contenteditable selects that element's contents only.
            await page.keyboard.press('Control+A');
            await cell.getByTestId(cmd).click();
        }

        if (colour) {
            await page.keyboard.press('Control+A');
            // A native colour input cannot be "clicked through" — setting its value
            // dispatches the same `input` event the picker does.
            await cell.getByTestId('rte-foreColor').fill(colour);
        }

        // Blur out of the field entirely: that is what triggers the save.
        await page.getByRole('heading', { name: /handover/i }).first().click();
        await expect(cell.getByTestId('rte-status')).toBeVisible();

        return cell;
    };

    /** The saved cell, re-read from the server. */
    const reread = async (page, field) => {
        await readBack(page, SHEET);

        return rowLocator(page).getByTestId(`cell-${field}`).getByTestId('rte-editor');
    };

    test('BOLD survives save + reload, in markup and on screen', async ({ page }) => {
        await write(page, 'disease', 'Septic shock', ['rte-bold']);

        const editor = await reread(page, 'disease');
        await expect(editor).toContainText('Septic shock');

        const html = await editor.innerHTML();
        expect(html).toMatch(/<(b|strong)\b|font-weight/i);

        const weight = await editor.locator('b, strong, span[style*="font-weight"]').first()
            .evaluate((el) => getComputedStyle(el).fontWeight);
        expect(Number(weight)).toBeGreaterThanOrEqual(600);
    });

    test('ITALIC survives save + reload — the exact format legacy discarded', async ({ page }) => {
        await write(page, 'details', 'Query meningitis', ['rte-italic']);

        const editor = await reread(page, 'details');
        await expect(editor).toContainText('Query meningitis');

        const html = await editor.innerHTML();
        // `font-style` is NOT in the sanitizer's CSS allow-list, so italic MUST arrive as
        // a tag. Text present but unstyled = italic is being silently stripped again.
        expect(html).toMatch(/<(i|em)\b/i);

        const style = await editor.locator('i, em').first().evaluate((el) => getComputedStyle(el).fontStyle);
        expect(style).toBe('italic');
    });

    test('UNDERLINE survives save + reload — the other format legacy discarded', async ({ page }) => {
        await write(page, 'plan', 'Escalate to consultant', ['rte-underline']);

        const editor = await reread(page, 'plan');
        await expect(editor).toContainText('Escalate to consultant');

        const html = await editor.innerHTML();
        expect(html).toMatch(/<u\b/i);

        const decoration = await editor.locator('u').first()
            .evaluate((el) => getComputedStyle(el).textDecorationLine);
        expect(decoration).toContain('underline');
    });

    test('a BULLETED LIST survives save + reload, and its markers actually render', async ({ page }) => {
        await write(page, 'nevent', 'Line inserted', ['rte-insertUnorderedList']);

        const editor = await reread(page, 'nevent');
        const html = await editor.innerHTML();
        expect(html).toMatch(/<ul\b/i);
        expect(html).toMatch(/<li\b/i);

        // Tailwind's preflight sets `ol,ul{list-style:none}`; the markup alone would pass
        // while every bullet was invisible, so the MARKER is checked in the real engine.
        const marker = await editor.locator('ul').first()
            .evaluate((el) => getComputedStyle(el).listStyleType);
        expect(marker).toBe('disc');
    });

    test('a NUMBERED LIST survives save + reload, and renders decimal markers', async ({ page }) => {
        await write(page, 'nevent', 'Weaned FiO2', ['rte-insertOrderedList']);

        const editor = await reread(page, 'nevent');
        expect(await editor.innerHTML()).toMatch(/<ol\b/i);

        const marker = await editor.locator('ol').first()
            .evaluate((el) => getComputedStyle(el).listStyleType);
        expect(marker).toBe('decimal');
    });

    test('COLOUR ON BOLD survives save + reload', async ({ page }) => {
        // "Bold it, then flag it red" is the routine way an escalation is marked, and it
        // is the case that broke: colouring already-bold text emits `<b style="color:…">`,
        // and a span-only style allow-list dropped the attribute.
        await write(page, 'disease', 'Deteriorating', ['rte-bold'], '#b3261e');

        const editor = await reread(page, 'disease');
        await expect(editor).toContainText('Deteriorating');

        const html = await editor.innerHTML();
        expect(html).toMatch(/color\s*:/i);

        const coloured = editor.locator('[style*="color"]').first();
        const colour = await coloured.evaluate((el) => getComputedStyle(el).color);
        // rgb(179, 38, 30) === #b3261e — exact, because "some colour survived" would pass
        // even if the sanitizer had rewritten the escalation red to the default ink.
        expect(colour).toBe('rgb(179, 38, 30)');

        const weight = await editor.locator('b, strong, [style*="font-weight"]').first()
            .evaluate((el) => getComputedStyle(el).fontWeight);
        expect(Number(weight)).toBeGreaterThanOrEqual(600);
    });
});
