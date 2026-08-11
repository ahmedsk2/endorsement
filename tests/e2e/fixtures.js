import { expect } from '@playwright/test';

/**
 * Shared harness for the browser end-to-end specs. Everything here drives the app the way
 * a clinician's browser does — real navigation, real clicks, real keystrokes — and asserts
 * against what the SERVER hands back after a reload. No spec is allowed to conclude
 * "saved" from an optimistic indicator; see readBack() below.
 */

/* ------------------------------------------------------------------ safety ---- */

const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);

/**
 * Refuses to run against anything but loopback. This suite CREATES clinical-shaped rows;
 * pointed at production it would write into a live PHI system.
 */
export function assertLocalhost(baseURL) {
    const host = new URL(baseURL).hostname;
    if (!LOOPBACK.has(host)) {
        throw new Error(
            `E2E refuses to run against non-loopback host "${host}". Local testing only — this suite writes data.`,
        );
    }
}

/* ------------------------------------------------------------------ identity -- */

export const ADMIN = { username: 'admin', password: 'AdminPass123!' };

/**
 * A RESIDENT — position 4, and therefore `rota.view` without `rota.manage` (AccessControlSeeder's
 * ROLE_DEFAULTS; owner decision 2, 2026-08-10, made `rota.manage` Administrator-only). This is the
 * actor MR-05 is written for, and the only one who can prove the read view is genuinely reachable
 * without the editor's capability: an administrator reaching `/rota` proves nothing, because an
 * administrator holds every capability in the catalogue.
 */
export const RESIDENT = { username: 'resident', password: 'ResidentPass123!' };

/* --------------------------------------------------------------------- auth --- */

export async function login(page, who = ADMIN) {
    await page.goto('/login');
    await page.getByLabel(/username/i).fill(who.username);
    await page.getByLabel(/^password/i).fill(who.password);
    await page.getByRole('button', { name: /log ?in|sign ?in/i }).click();
    await page.waitForURL(/\/endorsement/);
}

/* ------------------------------------------------------------ read-only ------- */

/**
 * Watch the browser for the rest of the test. A read screen that writes shows up here as a
 * POST/PATCH/DELETE leaving the page — including one fired by a control nobody thought to look for,
 * which is exactly the class of thing a component-level "no <form>" assertion cannot see.
 *
 * ONE DEFINITION, shared by `rota-read.spec.js` and `clinics.spec.js` (P1e Task 7). It was the
 * former's private helper until the clinic map needed the identical property; two copies would be
 * two definitions of one fact.
 *
 * Attach BEFORE login, and that is a non-vacuity device rather than an oversight: an empty list at
 * the end of a test looks identical whether nothing wrote or the recorder never fired. Signing in IS
 * a POST, so {@see signInAndWatch} proves the recorder works before the journey it is watching
 * begins, and each spec then asserts the list has not grown SINCE.
 */
export function watchForWrites(page) {
    const writes = [];

    page.on('request', (request) => {
        if (request.method() !== 'GET') writes.push(`${request.method()} ${request.url()}`);
    });

    return writes;
}

/** Sign in with the recorder already running; returns `writes` and the mark to compare against. */
export async function signInAndWatch(page, who = ADMIN) {
    const writes = watchForWrites(page);
    await login(page, who);

    expect(writes, 'the write recorder never fired — signing in is itself a POST').not.toEqual([]);
    expect(writes.every((write) => write.includes('/login'))).toBe(true);

    return { writes, mark: writes.length };
}

/* ------------------------------------------------------------ persistence ----- */

/**
 * The core discipline of this suite: throw away the current DOM, re-fetch the page from
 * the server, and read the value back. Anything asserted BEFORE this ran is only evidence
 * about the client — the production bug this harness exists for looked correct on screen
 * and was gone after a reload.
 */
export async function readBack(page, url) {
    await page.goto(url, { waitUntil: 'networkidle' });
}

/* ----------------------------------------------------------------- misc ------- */

/** Today, as the Y-m-d string the endorsement routes are regex-pinned to (LOCAL date). */
export function today() {
    const d = new Date();

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
