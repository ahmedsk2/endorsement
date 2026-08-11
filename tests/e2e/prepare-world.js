import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import path from 'node:path';

/**
 * Build the harness's throwaway world: a fresh sqlite DB, migrated and seeded, plus the
 * e2e identity.
 *
 * This runs as the FIRST HALF OF THE WEB SERVER COMMAND, not as Playwright's `globalSetup`,
 * and that is the whole point. It used to be `globalSetup`, whose docblock asserted it ran
 * "before the web server starts". It does not: Playwright launches `webServer` and waits for
 * its URL to answer BEFORE global setup runs. With no database the app 500s, so the URL never
 * answers, so global setup never runs — a deadlock that resolves itself only as a 60-second
 * timeout with no migration output to explain it.
 *
 * It was invisible for as long as it existed because `database/e2e.sqlite` is gitignored but
 * NOT deleted between runs: every developer machine had one left over from the first run that
 * happened to work, and the suite has never once been exercised from a clean checkout. CI is
 * a clean checkout every time, and it failed on the first run it was allowed to attempt
 * (2026-08-11, after a three-day billing block on Actions).
 *
 * Reproduce the failure before changing any of this: `rm database/e2e.sqlite && npm run test:e2e`.
 */
const db = path.resolve('./database/e2e.sqlite');

// Laravel's sqlite driver will not create the file, and `migrate:fresh` on a missing file
// prompts (or fails under --no-interaction). Create it empty first — this is also what makes
// the run genuinely fresh rather than incremental.
writeFileSync(db, '');

const env = {
    ...process.env,
    APP_ENV: 'local',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: db,
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
};

const run = (cmd) => execSync(cmd, { env, stdio: 'inherit' });

run('php artisan migrate:fresh --force --seed');
run('php artisan db:seed --force --class=E2eSeeder');
