import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import path from 'node:path';

/**
 * Build the harness's throwaway world: a fresh sqlite DB, migrated and seeded, plus the
 * e2e identity. Runs ONCE per `npm run test:e2e` invocation, before the web server starts.
 */
export default function globalSetup() {
    const db = path.resolve('./database/e2e.sqlite');

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
}
