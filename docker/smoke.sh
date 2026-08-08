#!/usr/bin/env bash
#
# Production smoke test. Run ON the deployment host, against a throwaway copy of the REAL
# production stack — never against the live one.
#
#   bash docker/smoke.sh
#
# It brings up `docker-compose.production.yml` itself, under a separate compose project
# name and its own volumes, with generated throwaway credentials. That matters: an earlier
# version of this script ran `docker run mysql:8.4` by hand and therefore could not see a
# fatal error in the compose file — `--default-authentication-plugin`, which MySQL 8.4
# removed, made mysqld abort at boot and the first real deployment failed. Test the
# artifact you ship, not an approximation of it.
#
# What it answers that the PHP/JS suites structurally cannot:
#   - the image builds and boots, and MySQL accepts the compose file's flags;
#   - migrations apply against a real MySQL (not sqlite);
#   - /up returns 200 — it runs with NO session middleware, so any global middleware that
#     touches $request->user() 500s there while every real page still renders;
#   - php-fpm runs as the user that owns storage/ (else signature uploads fail silently);
#   - the database is NOT reachable from the shared proxy network.
#
# Everything is torn down on exit, including on failure.
#
# A second concurrent run (e.g. this script and a customer's throwaway drill stack, or two
# people running it at once) must not collide on the compose project name — `cleanup()`'s
# `down -v --remove-orphans` would tear down the OTHER run's volumes mid-test. Pass both
# PROJECT and PORT to run more than one at a time.
set -euo pipefail

cd "$(dirname "$0")/.."

PROJECT="${PROJECT:-endorse-smoke}"
PORT="${PORT:-9911}"
ENVFILE="$(mktemp)"
OVERRIDE="$(mktemp --suffix=.yml)"
COMPOSE="docker compose -p $PROJECT -f docker-compose.production.yml -f $OVERRIDE --env-file $ENVFILE"

cleanup() {
    $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true
    rm -f "$ENVFILE" "$OVERRIDE"
}
trap cleanup EXIT

# Throwaway credentials, in a 0600 temp file, deleted on exit. Alphanumeric only: docker
# compose interpolates env values, so a '$' would be silently mangled.
rnd() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 40; }
umask 077
cat > "$ENVFILE" <<EOF
APP_NAME=Paediatric Endorsement
APP_URL=https://endorse.towardpcc.com
APP_KEY=base64:$(openssl rand -base64 32)
MYSQL_DATABASE=endorsement
MYSQL_USER=endorse
MYSQL_PASSWORD=$(rnd)
MYSQL_ROOT_PASSWORD=$(rnd)
BACKUP_PASSPHRASE=$(rnd)
EOF

# The real stack publishes nothing (Traefik reaches it over the coolify network), so expose
# the app on loopback just for this test.
cat > "$OVERRIDE" <<EOF
services:
  app:
    ports:
      - "127.0.0.1:${PORT}:8080"
EOF

# Present on the deployment host; created here so the file is testable anywhere.
docker network inspect coolify >/dev/null 2>&1 || docker network create coolify >/dev/null

echo "--- build and start ---"
$COMPOSE up -d --build 2>&1 | tail -4

printf 'waiting for app'
# Probe /up, not /login: the health route needs no database, so it answers before the
# migrations exist. Polling a session-backed page here just burns the whole timeout.
for _ in $(seq 1 60); do
    curl -sf "http://127.0.0.1:${PORT}/up" >/dev/null 2>&1 && break
    printf '.'; sleep 3
done
echo

APP="$($COMPOSE ps -q app)"
DB="$($COMPOSE ps -q db)"

fail=0
check() { # check <label> <expected> <actual>
    if [ "$2" = "$3" ]; then echo "  ok   $1 ($3)"; else echo "  FAIL $1 (want $2, got $3)"; fail=1; fi
}

echo "--- containers healthy ---"
check "db health" healthy "$(docker inspect -f '{{.State.Health.Status}}' "$DB" 2>/dev/null || echo missing)"
check "app running" running "$(docker inspect -f '{{.State.Status}}' "$APP" 2>/dev/null || echo missing)"

echo "--- migrate ---"
docker exec "$APP" php artisan migrate --force 2>&1 | grep -viE '^PHP Warning|thecodingmachine' | tail -3

echo "--- seed reference data ---"
docker exec "$APP" php artisan db:seed --force 2>&1 | grep -viE '^PHP Warning|thecodingmachine' | tail -2

echo "--- endpoints ---"
check "GET /up" 200 "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/up")"
check "GET /login" 200 "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/login")"
# An unauthenticated request to a clinical route must bounce to the login form, not render.
check "GET /endorsement (anon)" 302 "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/endorsement")"

# A request body big enough that nginx BUFFERS IT TO DISK.
#
# nginx keeps a body in memory up to client_body_buffer_size (~16k) and spills anything
# larger to /var/lib/nginx/tmp. If that directory is not writable by the worker user, small
# requests succeed and larger ones 500 — which is how a broken signature upload looked like
# an intermittent fault for days rather than a permissions bug. Every check here used tiny
# GETs, so none of them could see it.
#
# Unauthenticated on purpose: a 419/422/302 all prove nginx read the body. Only a 500 fails.
# Via a FILE, not an argument: 200k on the command line overflows argv
# ("Argument list too long"), which is how this check failed the first time it ran.
BIGBODY=$(mktemp)
{ printf 'member_name=smoke&password='; head -c 200000 /dev/zero | tr '\0' 'x'; } > "$BIGBODY"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
       -X POST "http://127.0.0.1:${PORT}/login" --data-binary "@$BIGBODY")
rm -f "$BIGBODY"

# Any of 419/422/302 proves nginx read the body and handed it to PHP. Only 500 (or no
# response at all) means it could not write the temp file.
case "$code" in
    500|000) buffered="REFUSED-$code" ;;
    *)       buffered=accepted ;;
esac
check "large request body buffered to disk" accepted "$buffered"

# And the same property asserted DIRECTLY, because the request-level check above passed
# against a knowingly-broken build once: whether a body spills to disk depends on
# client_body_buffer_size, the container's age and what nginx's root master happened to
# create at startup, none of which a regression test should depend on. Ownership does not
# vary. The worker user must own the tree it writes into.
check "nginx temp dir owned by the worker user" \
    "$(docker exec "$APP" sh -c 'ps -o user,args | grep "[n]ginx: worker" | head -1 | awk "{print \$1}"')" \
    "$(docker exec "$APP" sh -c 'stat -c %U /var/lib/nginx/tmp')"

echo "--- security headers ---"
headers="$(curl -sI "http://127.0.0.1:${PORT}/login")"
for h in content-security-policy x-frame-options referrer-policy x-content-type-options; do
    if echo "$headers" | grep -qi "^${h}:"; then echo "  ok   $h"; else echo "  FAIL $h missing"; fail=1; fi
done

echo "--- scheduler ---"
jobs="$(docker exec "$APP" php artisan schedule:list 2>&1 \
    | grep -cE 'audit:verify|backup:run|data:retention|endorsement:remind' || true)"
check "scheduled jobs" 6 "$jobs"

echo "--- encrypted backup against real MySQL ---"
# backup:run shells out to mysqldump, so ONLY a real MySQL can exercise it — the PHP suite
# runs on sqlite and takes a different branch entirely. This assertion exists because the
# backup reached production broken twice over: Alpine's `mysql-client` is MariaDB's client,
# which verifies TLS against MySQL 8.4's self-signed cert AND (until mariadb-connector-c was
# added) shipped no caching_sha2_password plugin, so it could not authenticate at all. Every
# nightly dump would have failed, silently, on a clinical database.
if docker exec "$APP" php artisan backup:run >"$ENVFILE.backup.log" 2>&1; then
    echo "  ok   backup:run"
else
    echo "  FAIL backup:run"; grep -i 'backup failed' "$ENVFILE.backup.log" | head -1; fail=1
fi
rm -f "$ENVFILE.backup.log"
check "archive is openssl-encrypted" "Salted__" \
    "$(docker exec "$APP" sh -c 'f=$(ls -1 storage/backups/*.gz.enc 2>/dev/null | tail -1); [ -n "$f" ] && head -c 8 "$f"')"

echo "--- audit chain verifies against real MySQL ---"
# backup:run above appended a row, so the chain is non-empty by now. This exercises the
# KEYED chain end to end: if the HMAC key derivation differed between write and verify,
# or the hash_version column were missing, this is where it shows.
if docker exec "$APP" php artisan audit:verify >/dev/null 2>&1; then
    echo "  ok   audit:verify"
else
    echo "  FAIL audit:verify"; fail=1
fi

echo "--- process users (masters still root: see the audit addendum) ---"
# php-fpm must run as `app`; left on the base image's www-data it cannot write to storage
# and signature uploads break in production while every page still renders.
check "php-fpm worker user" app \
    "$(docker exec "$APP" sh -c 'ps -o user,args | grep "[p]ool www" | head -1 | awk "{print \$1}"')"

# The scheduler — which is what runs backup:run and audit:verify — is unprivileged.
#
# ACCEPTED, decided 2026-07-27: the nginx and php-fpm MASTERS still run as root. Dropping
# them needs `USER app` at the image level, because under Docker the container's
# stdout/stderr are pipes owned by whoever starts PID 1, and both daemons open their error
# log BY PATH at startup — as a different user that fails with "open() /dev/stderr failed
# (13: Permission denied)" and neither serves at all. The recurring cost is carrying that
# workaround across base-image bumps, not the volume ownership (already uid 1000 and
# trivially reset). Compensated instead by cap_drop + no-new-privileges below, and by
# running the boot-time artisan commands as `app` — which is what actually closes
# SPC-RPT-006. See docs/SECURITY-AUDIT-2026-07-26.md.
check "scheduler user" app \
    "$(docker exec "$APP" sh -c 'ps -o user,args | grep "[s]chedule:work" | head -1 | awk "{print \$1}"')"

# The compensating control, asserted rather than assumed: a capability set that silently
# reverts to the Docker default is worth nothing, and nothing else here would notice.
check "capabilities dropped" "ALL" \
    "$(docker inspect -f '{{range .HostConfig.CapDrop}}{{.}}{{end}}' "$APP")"

# SPC-RPT-006, asserted at runtime rather than by reading the entrypoint. These files are
# written at every boot by `artisan config:cache` and are then `require`d by every request.
# If root wrote them, root is executing PHP out of an app-writable directory, which is the
# whole finding. Owned by `app` is the proof that su-exec took effect.
check "boot caches not written by root" app \
    "$(docker exec "$APP" sh -c 'stat -c %U bootstrap/cache/config.php')"
check "no-new-privileges" true \
    "$(docker inspect -f '{{range .HostConfig.SecurityOpt}}{{if eq . "no-new-privileges:true"}}true{{end}}{{end}}' "$APP")"

echo "--- database isolation ---"
# The DB must be on the internal network ONLY. On the shared proxy network every other app
# on this host could reach the patient database.
check "db on coolify network" false \
    "$(docker inspect -f '{{if index .NetworkSettings.Networks "coolify"}}true{{else}}false{{end}}' "$DB")"
# Must test for a HOST BINDING, not for exposed ports: the mysql image EXPOSEs 3306 and
# 33060, so a length check here counts 2 on a correctly-unpublished container.
check "db publishes no host port" "" \
    "$(docker inspect -f '{{range $p, $c := .NetworkSettings.Ports}}{{if $c}}{{$p}}{{end}}{{end}}' "$DB")"

echo
if [ "$fail" -eq 0 ]; then echo "SMOKE TEST PASSED"; else echo "SMOKE TEST FAILED"; fi
exit "$fail"
