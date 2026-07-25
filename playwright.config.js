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
    globalSetup: './tests/e2e/global-setup.js',
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
        command: 'php artisan serve --host=127.0.0.1 --port=8001',
        url: baseURL,
        reuseExistingServer: false,
        timeout: 60_000,
        env: serverEnv,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
