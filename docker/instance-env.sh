#!/usr/bin/env bash
#
# Resolve ONE customer stack's containers, or refuse.
#
#   eval "$(sudo bash docker/instance-env.sh <coolify-app-uuid>)" && \
#   sudo docker exec -u app "$APP" php artisan migrate --force
#
# Why this exists: the previous runbook selected the database container with
# `docker ps -qf ancestor=mysql:8.4 | head -1`. With two customer stacks up that is an
# arbitrary choice, and if both customers use the default MYSQL_DATABASE/MYSQL_USER names,
# a GRANT/migrate/REVOKE aimed at the wrong customer's clinical database reports success.
#
# It prints APP, DB, DBNAME and DBUSER. It deliberately does NOT print the root password —
# read that from the container as the runbook shows, so it never reaches a terminal that is
# scrolled back through.
set -uo pipefail

uuid="${1:-}"

refuse() {
    printf 'unset APP DB DBNAME DBUSER; false\n'
    printf 'instance-env.sh REFUSED: %s\n' "$1" >&2
    exit 1
}

[ -n "$uuid" ] || refuse "no Coolify app UUID given. Usage: instance-env.sh <uuid>"

app=$(docker ps -qf "name=app-${uuid}")
db=$(docker ps -qf "name=db-${uuid}")

[ "$(printf '%s\n' "$app" | grep -c .)" = "1" ] || refuse "expected exactly one running app container matching name=app-${uuid}"
[ "$(printf '%s\n' "$db"  | grep -c .)" = "1" ] || refuse "expected exactly one running db container matching name=db-${uuid}"

dbname=$(docker exec "$db" printenv MYSQL_DATABASE)
dbuser=$(docker exec "$db" printenv MYSQL_USER)

# Without `-e`, a failed `printenv` above leaves dbname/dbuser empty and the script would
# otherwise exit 0, printing `DBNAME=; DBUSER=;` — failing closed only because MySQL happens
# to reject an empty database name. A guard whose whole job is refusing ambiguity should not
# delegate that to MySQL's parser.
[ -n "$dbname" ] && [ -n "$dbuser" ] || refuse "could not read MYSQL_DATABASE/MYSQL_USER from the db container"

# Say out loud which customer is about to be operated on. Correct selection is necessary;
# VISIBLE selection is what stops the wrong one being operated on confidently.
printf 'instance-env.sh: app=%s db=%s database=%s user=%s\n' \
    "$(docker inspect -f '{{.Name}}' "$app")" "$(docker inspect -f '{{.Name}}' "$db")" "$dbname" "$dbuser" >&2

printf 'APP=%s; DB=%s; DBNAME=%s; DBUSER=%s\n' "$app" "$db" "$dbname" "$dbuser"
