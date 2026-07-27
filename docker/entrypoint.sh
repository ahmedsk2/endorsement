#!/bin/sh
set -e

# Boot-time preparation. NOTE WHAT IS ABSENT: `php artisan migrate`.
#
# Production migrations are the owner's to run (project rule), so a container restart —
# which can happen unattended, at 3am, during an outage — must never alter the schema of a
# clinical database. Deployment order is: deploy image -> owner runs migrate --force.

cd /var/www/html

# Writable paths (a mounted volume arrives owned by root).
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
         storage/logs storage/app/private/signatures storage/backups bootstrap/cache
chown -R app:app storage bootstrap/cache 2>/dev/null || true

if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is not set. It decrypts the PHI columns — a container without it"
    echo "       would read every patient identifier as ciphertext. Refusing to start."
    exit 1
fi

# Rebuild the caches against the REAL environment (they are env-specific, so baking them
# into the image would freeze build-time values).
#
# AS `app`, NOT AS ROOT. These bootstrap the framework, which means they `require` files out
# of bootstrap/cache — a directory chowned to app:app a few lines above. Running them as
# root turns "can write as app" into "executes as uid 0 on the next restart", and restarts
# happen unattended. That is the whole of SPC-RPT-006.
#
# This is not the change that failed on 2026-07-26: that was `USER app` at the image level,
# which broke supervisord's stdout handling. Dropping privileges for these four commands is
# unrelated to PID 1 — it was working, and was reverted as collateral damage.
su-exec app php artisan config:cache
su-exec app php artisan route:cache
su-exec app php artisan view:cache

# Fail fast if the app cannot reach its database, rather than serving 500s to a ward.
php -r '
$tries = 0;
while ($tries < 30) {
    try {
        (new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE")),
            getenv("DB_USERNAME"),
            getenv("DB_PASSWORD")
        ));
        exit(0);
    } catch (Throwable $e) {
        $tries++;
        sleep(2);
    }
}
fwrite(STDERR, "FATAL: database unreachable after 60s\n");
exit(1);
'

# supervisord stays root, and so — for now — do the nginx and php-fpm MASTERS: only the
# scheduler is declared `user=app`. (An earlier revision claimed all three were dropped.
# That was written for a change that was reverted; the daemons' `user=app` lines went with
# it. What IS unprivileged: the php-fpm pool workers, the scheduler, and the artisan
# commands above. The container also drops all capabilities but a minimal set, so what root
# means in here is much narrower than it sounds.)
#
# supervisord cannot be dropped. Under Docker the container's stdout is a root-owned pipe,
# and supervisord opens /dev/stdout on behalf of each child before forking — as `app` that
# fails with EACCES ("making dispatchers") and nothing starts at all. Verified, not assumed.
# Sending the logs somewhere writable instead would trade a supervisor that forks and waits
# for the loss of container logs, which is a bad trade on a system whose scheduled jobs
# escalate by logging.
# Say so — loudly — if the schema is behind the code.
#
# This does NOT migrate. Production migrations are the owner's (a container that migrates
# at boot can alter a clinical schema during an unattended 3am restart). But a deploy that
# ships a migration and is never followed by `migrate --force` leaves the app throwing
# column-not-found errors with nothing anywhere explaining why, and the container reporting
# itself perfectly healthy. Ten lines of warning now saves an hour of confusion later.
# Also su-exec'd: `migrate:status` bootstraps the framework exactly like the cache commands
# above, so leaving this one as root would keep the escalation path open through the back
# door. The earlier attempt covered the three cache calls and missed this one.
pending=$(su-exec app php artisan migrate:status --pending 2>/dev/null | grep -c 'Pending' || true)

if [ "${pending:-0}" -gt 0 ]; then
    echo "============================================================"
    echo "WARNING: ${pending} migration(s) are PENDING."
    echo "The code in this image expects a schema the database does not have."
    echo "Expect errors until you run, from a shell in this container:"
    echo "    php artisan migrate --force"
    echo "Migrations are deliberately never run automatically — see docs/RUNBOOK-DEPLOY.md."
    echo "============================================================"
fi

exec "$@"
