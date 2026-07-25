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

exec "$@"
