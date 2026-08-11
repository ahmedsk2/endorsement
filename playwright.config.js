import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

/**
 * Browser-level end-to-end harness.
 *
 * WHY THIS EXISTS. The production data-loss bug this system was built to bury only exists
 * once a REAL rendering engine is in the loop: the rich-text editor's `document.execCommand`
 * output depends on `styleWithCSS`, and jsdom has no execCommand at all. The assertions here
 * are deliberately PERSISTENCE assertions: write, RELOAD, re-read. Fire-and-forget autosave
 * that silently fails to persist is this domain's documented historical failure mode, and an
 * optimistic UI indicator is not evidence of anything.
 *
 * LOCALHOST ONLY. tests/e2e/fixtures.js refuses any non-loopback host. NEVER point
 * ENDORSE_E2E_BASE_URL at production.
 *
 * The harness owns its world: global-setup migrates a throwaway sqlite DB and seeds the
 * e2e identity; the webServer block below serves the app against that DB. Run with
 * `npm run test:e2e` (deliberately OUT of the fast `npm test` Vitest path).
 */
const baseURL = process.env.ENDORSE_E2E_BASE_URL || 'http://127.0.0.1:8001';

const E2E_DB = path.resolve('./database/e2e.sqlite');

const serverEnv = {
    ...process.env,
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: E2E_DB,
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    MAIL_MAILER: 'array',
};

export default defineConfig({
    testDir: './tests/e2e',
    // The specs share one sqlite database and drive the same sheets, so they run serially.
    workers: 1,
    fullyParallel: false,
    // A green run must be green on the first attempt: a retry that passes would hide exactly
    // the kind of flaky-persistence bug this suite exists to catch.
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],
    // No `globalSetup`: Playwright starts `webServer` and waits for its URL BEFORE global
    // setup runs, so a setup step that builds the database can never reach a server that
    // cannot boot without one. The world is built by the first half of the webServer command
    // instead — see tests/e2e/prepare-world.js for the deadlock this replaced.
    use: {
        baseURL,
        // Light only — the design system has no dark mode (docs/DESIGN-TOKENS.md).
        colorScheme: 'light',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        actionTimeout: 15_000,
    },
    webServer: {
        // Build the world, THEN serve it. Both halves are one command because Playwright will
        // not run anything of ours before this one answers on `url`.
        command: 'node tests/e2e/prepare-world.js && php artisan serve --host=127.0.0.1 --port=8001',
        url: baseURL,
        reuseExistingServer: false,
        // Generous because the first half migrates and seeds from nothing on a cold checkout —
        // the case the old 60s bound never actually exercised, since a leftover sqlite file
        // made every local run skip the work it was timing.
        timeout: 180_000,
        env: serverEnv,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
