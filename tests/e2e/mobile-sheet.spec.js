import { test, expect } from '@playwright/test';
import { assertLocalhost, login, readBack, today } from './fixtures.js';

/**
 * The mobile journey (spec §9) — residents fill the endorsement from their phones, so the
 * card layout must be able to complete the core loop on a phone viewport: open the sheet,
 * edit a field, and have it PERSIST across a reload. Same discipline as every other spec:
 * the optimistic indicator proves nothing; the reload does.
 */
const DATE = today();
const SHEET = `/endorsement/NICU/${DATE}`;

test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

test.describe('mobile card sheet', () => {
    test.beforeAll(({ }, testInfo) => assertLocalhost(testInfo.project.use.baseURL));

    test('a plan edited in a phone-viewport card survives save + reload', async ({ page }) => {
        await login(page);
        await page.goto(SHEET);
        await expect(page.getByTestId('add-row')).toBeVisible();
        await page.waitForLoadState('networkidle');

        const before = await page.locator('[data-testid="handover-card"]').evaluateAll(
            (cards) => cards.map((c) => c.dataset.rowId),
        );

        await page.getByTestId('add-row').click();

        let rowId = null;
        await expect.poll(async () => {
            const after = await page.locator('[data-testid="handover-card"]').evaluateAll(
                (cards) => cards.map((c) => c.dataset.rowId),
            );
            rowId = after.filter((id) => !before.includes(id))[0] ?? null;

            return rowId;
        }).not.toBeNull();

        const card = page.locator(`[data-testid="handover-card"][data-row-id="${rowId}"]`);
        await expect(card).toBeVisible();

        // The desktop table is display:none below md — the card is the working surface.
        await expect(page.locator(`[data-testid="handover-row"][data-row-id="${rowId}"]`)).toBeHidden();

        // NICU's profile adds the DOB field to the card.
        await expect(card.locator('label', { hasText: 'DOB' })).toBeVisible();

        // Edit the plan through the card's rich-text editor, then blur to save.
        const editor = card.getByTestId('rte-editor').nth(2); // disease, details, PLAN, nevent
        await editor.click();
        await expect(editor).toBeFocused();
        await page.keyboard.type('wean CPAP overnight');
        await page.getByRole('heading', { name: /handover/i }).first().click();
        await expect(card.getByTestId('rte-status')).toBeVisible();

        // PERSISTENCE, not the indicator: reload and re-read.
        await readBack(page, SHEET);
        const cardAfter = page.locator(`[data-testid="handover-card"][data-row-id="${rowId}"]`);
        await expect(cardAfter.getByTestId('rte-editor').nth(2)).toContainText('wean CPAP overnight');

        // Retire the row through the app's own control (the card's remove button).
        await cardAfter.getByRole('button', { name: /remove patient/i }).click();
        await expect(cardAfter).toHaveCount(0);
    });
});
