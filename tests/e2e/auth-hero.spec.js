import { test, expect } from '@playwright/test';

/**
 * The signed-out hero's geometry.
 *
 * These exist because of a real bug: the mobile curve put the white region below 54% while
 * the form column started at ~19%, so the username and password fields rendered in `ink`
 * over the dark illustration. Every unit test passed — the failure is purely positional,
 * and only a real layout can see it.
 *
 * The invariant, in one sentence: form text must never sit on the illustration.
 */
const CURVE_WHITE_STARTS_AT = {
    // The narrow path's white edge tops out at 24% and meets the sides at 32%.
    narrow: 0.32,
    // The wide path's white edge reaches furthest left (38%) at mid-height.
    wide: 0.38,
};

test.describe('signed-out hero', () => {
    test('on a phone, the form sits below the curve rather than on the illustration', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/login');

        const username = page.locator('#login-username');
        const box = await username.boundingBox();
        const whiteStarts = 844 * CURVE_WHITE_STARTS_AT.narrow;

        expect(box.y).toBeGreaterThan(whiteStarts);
    });

    test('on a phone, the sign-in button is still above the fold', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/login');

        const button = page.getByRole('button', { name: /sign in/i });
        const box = await button.boundingBox();

        expect(box.y + box.height).toBeLessThan(844);
    });

    test('on a desktop, the form clears the curve horizontally', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/login');

        const box = await page.locator('#login-username').boundingBox();

        expect(box.x).toBeGreaterThan(1440 * CURVE_WHITE_STARTS_AT.wide);
    });

    test('the decorative curve is hidden from assistive technology', async ({ page }) => {
        await page.goto('/login');

        await expect(page.locator('[data-testid="hero-curve"]')).toHaveAttribute('aria-hidden', 'true');
    });

    test('the fields are still reachable by accessible name', async ({ page }) => {
        // The redesign moved a lot of markup; the label binding must survive it.
        await page.goto('/login');

        await expect(page.getByLabel('Username')).toBeVisible();
        await expect(page.getByLabel('Password')).toBeVisible();
    });

    test('the scene leans toward the pointer', async ({ page }) => {
        // The positive control for the test below. Without this, "nothing moved under
        // reduced motion" would pass just as happily if nothing ever moved at all.
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/login');

        const layer = page.locator('.auth-hero-layer').first();
        const before = await layer.evaluate((el) => getComputedStyle(el).transform);

        await page.mouse.move(120, 120);
        await page.mouse.move(1300, 800);
        await page.waitForTimeout(150);

        const after = await layer.evaluate((el) => getComputedStyle(el).transform);

        expect(after).not.toBe(before);
    });

    test('nothing drifts when the visitor asks for reduced motion', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/login');

        const layer = page.locator('.auth-hero-layer').first();
        const before = await layer.evaluate((el) => getComputedStyle(el).transform);

        await page.mouse.move(200, 200);
        await page.mouse.move(1200, 700);
        await page.waitForTimeout(120);

        const after = await layer.evaluate((el) => getComputedStyle(el).transform);

        expect(after).toBe(before);
    });

    test('the page does not scroll sideways at any common width', async ({ page }) => {
        for (const width of [390, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/login');

            const overflows = await page.evaluate(() => document.body.scrollWidth > window.innerWidth);

            expect(overflows, `horizontal overflow at ${width}px`).toBe(false);
        }
    });
});
