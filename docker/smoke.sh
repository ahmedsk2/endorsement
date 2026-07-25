#!/usr/bin/env bash
#
# Production-image smoke test. Run ON the deployment host, against a throwaway MySQL and
# a throwaway app container — never against the live stack.
#
#   docker build -t endorsement-app:test .
#   bash docker/smoke.sh
#
# It answers the questions the unit-test suite structurally cannot: does the built image
# boot, can it reach a real MySQL, do the migrations apply, does the health endpoint the
# container orchestrator polls actually return 200, and is the scheduler registered.
#
# The last one matters: /up runs with NO session middleware, so any global middleware that
# touches $request->user() 500s there while every real page still renders. That shipped
# once and was caught here, not by the 293 unit tests.
#
# Everything is torn down on exit, including on failure.
set -euo pipefail

IMAGE="${IMAGE:-endorsement-app:test}"
PORT="${PORT:-9911}"
NET=endorse-smoke
APP=endorse-smoke-app
DB=endorse-smoke-db

# Ephemeral, in-memory only: these never reach a file, an env, or a log.
APPKEY="base64:$(openssl rand -base64 32)"
DBPASS="$(openssl rand -hex 16)"

cleanup() {
    docker rm -f "$APP" "$DB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup
docker network create "$NET" >/dev/null

docker run -d --name "$DB" --network "$NET" \
    -e MYSQL_ROOT_PASSWORD="$DBPASS" -e MYSQL_DATABASE=endorsement \
    -e MYSQL_USER=endorse -e MYSQL_PASSWORD="$DBPASS" mysql:8.4 >/dev/null

printf 'waiting for mysql'
for _ in $(seq 1 40); do
    docker exec "$DB" mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1 && break
    printf '.'; sleep 2
done
echo

docker run -d --name "$APP" --network "$NET" -p "127.0.0.1:${PORT}:8080" \
    -e APP_NAME="Paediatric Endorsement" -e APP_ENV=production -e APP_DEBUG=false \
    -e APP_KEY="$APPKEY" -e APP_URL=https://endorse.towardpcc.com -e APP_TIMEZONE=Asia/Riyadh \
    -e DB_CONNECTION=mysql -e DB_HOST="$DB" -e DB_PORT=3306 \
    -e DB_DATABASE=endorsement -e DB_USERNAME=endorse -e DB_PASSWORD="$DBPASS" \
    -e SESSION_DRIVER=database -e CACHE_STORE=database -e QUEUE_CONNECTION=database \
    -e SESSION_ENCRYPT=true -e SESSION_SECURE_COOKIE=true \
    "$IMAGE" >/dev/null

printf 'waiting for app'
# Probe /up, not /login: the health route needs no database, so it comes up before the
# migrations exist. Polling a session-backed page here just burns the whole timeout.
for _ in $(seq 1 30); do
    curl -sf "http://127.0.0.1:${PORT}/up" >/dev/null 2>&1 && break
    printf '.'; sleep 2
done
echo

fail=0
check() { # check <label> <expected> <actual>
    if [ "$2" = "$3" ]; then echo "  ok   $1 ($3)"; else echo "  FAIL $1 (want $2, got $3)"; fail=1; fi
}

echo "--- migrate ---"
docker exec "$APP" php artisan migrate --force 2>&1 | tail -3

echo "--- seed reference data ---"
docker exec "$APP" php artisan db:seed --force 2>&1 | tail -2

echo "--- endpoints ---"
check "GET /up" 200 "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/up")"
check "GET /login" 200 "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/login")"
# An unauthenticated request to a clinical route must bounce to the login form, not render.
check "GET /endorsement (anon)" 302 "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/endorsement")"

echo "--- security headers ---"
headers="$(curl -sI "http://127.0.0.1:${PORT}/login")"
for h in content-security-policy x-frame-options referrer-policy x-content-type-options; do
    if echo "$headers" | grep -qi "^${h}:"; then echo "  ok   $h"; else echo "  FAIL $h missing"; fail=1; fi
done

echo "--- scheduler ---"
jobs="$(docker exec "$APP" php artisan schedule:list 2>&1 \
    | grep -cE 'audit:verify|backup:run|data:retention|endorsement:remind' || true)"
check "scheduled jobs" 6 "$jobs"

echo "--- storage is writable by php-fpm ---"
# php-fpm runs as `app`; if the pool were left on www-data this write fails and signature
# uploads break in production while every page still renders.
fpmuser="$(docker exec "$APP" sh -c 'ps -o user,args | grep "[p]ool www" | head -1 | awk "{print \$1}"')"
check "php-fpm user" app "$fpmuser"

echo
if [ "$fail" -eq 0 ]; then echo "SMOKE TEST PASSED"; else echo "SMOKE TEST FAILED"; fi
exit "$fail"
