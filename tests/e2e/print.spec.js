import { test, expect } from '@playwright/test';
import { assertLocalhost, login, readBack, today } from './fixtures.js';

/**
 * Print smoke — the A4 sheet renders per unit with its UnitProfile column schema, and the
 * rich-text list markers survive into the print DOM (the build guard checks the CSS; this
 * checks the living page).
 */
const DATE = today();

test.describe('printable A4 sheet', () => {
    test.beforeAll(({ }, testInfo) => assertLocalhost(testInfo.project.use.baseURL));

    test('WARD print carries the ward schema; PICU carries its own', async ({ page }) => {
        await login(page);

        // Seed one WARD row through the real sheet so the print page has content.
        await page.goto(`/endorsement/WARD/${DATE}`);
        await expect(page.getByTestId('add-row')).toBeVisible();
        await page.waitForLoadState('networkidle');
        if ((await page.locator('[data-testid="handover-row"]').count()) === 0) {
            await page.getByTestId('add-row').click();
            await expect(page.locator('[data-testid="handover-row"]').first()).toBeVisible();
        }

        await readBack(page, `/endorsement/WARD/${DATE}/print`);
        const wardHeaders = await page.locator('[data-testid="print-table"] thead th').allTextContents();
        expect(wardHeaders).toEqual(['Room', 'Unit', 'MRN', 'Name', 'Age', 'Diagnosis List', 'Clinical Condition', 'Management', 'To be followed']);
        await expect(page.getByTestId('print-signoff-head')).toContainText('Consultant Oncall');
        await expect(page.getByTestId('print-signoff')).not.toContainText('Consultant Receiving');

        // PICU (unsigned, empty is fine — the header block still renders its labels).
        await readBack(page, `/endorsement/PICU/${DATE}`);
        await expect(page.getByTestId('add-row')).toBeVisible();
        await page.waitForLoadState('networkidle');
        if ((await page.locator('[data-testid="handover-row"]').count()) === 0) {
            await page.getByTestId('add-row').click();
            await expect(page.locator('[data-testid="handover-row"]').first()).toBeVisible();
        }

        await readBack(page, `/endorsement/PICU/${DATE}/print`);
        const picuHeaders = await page.locator('[data-testid="print-table"] thead th').allTextContents();
        expect(picuHeaders).toEqual(['Bed', 'MRN', 'Name', 'Diagnosis List', 'Clinical Condition', 'Plan Of Care', 'New events']);
        await expect(page.getByTestId('print-signoff-unsigned')).toContainText('NOT SIGNED OFF');
    });
});
