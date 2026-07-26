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
php artisan config:cache
php artisan route:cache
php artisan view:cache

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

# supervisord itself stays root, and ONLY supervisord: every program it starts is declared
# `user=app`, so nginx's master, php-fpm's master and the scheduler are all unprivileged.
#
# It cannot be dropped further. Under Docker the container's stdout is a root-owned pipe,
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
pending=$(php artisan migrate:status --pending 2>/dev/null | grep -c 'Pending' || true)

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
