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

    test('white text over the illustration still meets AA', async ({ page }) => {
        // The one that matters. Measured against the real dawn render, the illustration's
        // upper-left is pale mint sky — 1.24:1 against white, where AA needs 4.5. The drawn
        // fallback happened to be dark there, so nothing revealed it until a photograph
        // went in. The scrim is what fixes it, and this is what proves the scrim is enough.
        //
        // Composites the source pixel with both scrim ramps rather than screenshotting, so
        // it needs no image-decoding dependency and fails loudly if either ramp is weakened.
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/login');
        await page.locator('.auth-hero-img').waitFor();

        const worst = await page.evaluate(() => {
            const img = document.querySelector('img.auth-hero-img');

            if (!img) {
                return null; // drawn fallback in use; its colours are token-controlled
            }

            const c = document.createElement('canvas');
            c.width = img.naturalWidth;
            c.height = img.naturalHeight;
            const ctx = c.getContext('2d');
            ctx.drawImage(img, 0, 0);

            const INK = [11, 46, 51];
            const lerp = (a, b, t) => a + (b - a) * t;
            // Mirrors .auth-hero-scrim. If the CSS stops matching this, the test is lying.
            const acrossRamp = (fx) => fx < 0.34 ? lerp(0.82, 0.58, fx / 0.34)
                : fx < 0.62 ? lerp(0.58, 0, (fx - 0.34) / 0.28) : 0;
            const upRamp = (fy) => fy > 0.52 ? lerp(0, 0.55, (fy - 0.52) / 0.48) : 0;
            const over = (fg, a, bg) => fg.map((v, i) => v * a + bg[i] * (1 - a));
            const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
            const vsWhite = ([r, g, b]) => 1.05 / (0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b) + 0.05);

            const hero = document.querySelector('[data-testid="auth-hero"]').getBoundingClientRect();
            const targets = [...document.querySelectorAll('aside h2, aside p')]
                .filter((el) => !el.closest('.auth-glass') && el.textContent.trim());

            let worst = Infinity;

            for (const el of targets) {
                const b = el.getBoundingClientRect();

                if (!b.width || !b.height) continue;

                for (let i = 0; i <= 12; i++) {
                    for (let j = 0; j <= 4; j++) {
                        const fx = (b.left + (b.width * i) / 12 - hero.left) / hero.width;
                        const fy = (b.top + (b.height * j) / 4 - hero.top) / hero.height;
                        const px = Math.max(0, Math.min(c.width - 1, Math.round(fx * (c.width - 1))));
                        const py = Math.max(0, Math.min(c.height - 1, Math.round(fy * (c.height - 1))));
                        const d = ctx.getImageData(px, py, 1, 1).data;

                        let col = over(INK, upRamp(fy), [d[0], d[1], d[2]]);
                        col = over(INK, acrossRamp(fx), col);
                        worst = Math.min(worst, vsWhite(col));
                    }
                }
            }

            return Number.isFinite(worst) ? Number(worst.toFixed(2)) : null;
        });

        expect(worst, 'no measurable text found over the hero').not.toBeNull();
        expect(worst, `worst white-on-illustration contrast is ${worst}:1`).toBeGreaterThanOrEqual(4.5);
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
