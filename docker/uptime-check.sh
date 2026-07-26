#!/usr/bin/env bash
#
# Is the site actually reachable from outside?
#
# Installed on the host as /usr/local/bin/endorsement-uptime-check, run by cron every five
# minutes. Deliberately on the HOST and against the PUBLIC URL, because the failure it
# exists for is the one the container cannot see: on 2026-07-27 the app was healthy and
# answering its own healthcheck the whole time while Traefik dialled a network the proxy is
# not on and every real request 504'd.
#
# The container's HEALTHCHECK proves the app is alive. This proves a clinician can reach it.
# Those are different questions and only the second one matters at 07:30.
#
# Logs a line on every state CHANGE, not every run — a log that says "ok" 288 times a day
# is a log nobody reads, and the transition is the thing worth finding later.
set -uo pipefail

URL="${URL:-https://endorse.towardpcc.com/up}"
LOG=/var/log/endorsement-uptime.log
STATE=/var/lib/endorsement-uptime.state

mkdir -p "$(dirname "$STATE")"

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$URL" 2>/dev/null || echo 000)
now=$(date -Is)

if [ "$code" = "200" ]; then
    new=up
else
    new=down
fi

old=$(cat "$STATE" 2>/dev/null || echo unknown)

if [ "$new" != "$old" ]; then
    case "$old:$new" in
        # First run has no previous state. Calling that "recovered" implies an outage that
        # never happened, and a misleading line is what wastes time during a real incident.
        unknown:*) printf '%s monitoring started, site is %s (HTTP %s)\n' "$now" "$new" "$code" >> "$LOG" ;;
        *:down)    printf '%s CRITICAL site unreachable (HTTP %s) — clinicians cannot sign in\n' "$now" "$code" >> "$LOG" ;;
        *:up)      printf '%s recovered (HTTP %s)\n' "$now" "$code" >> "$LOG" ;;
    esac

    printf '%s' "$new" > "$STATE"
fi

# A daily heartbeat, so a silent log can be told apart from a stopped cron.
if [ "$(date +%H:%M)" = "07:00" ]; then
    printf '%s heartbeat: %s (HTTP %s)\n' "$now" "$new" "$code" >> "$LOG"
fi

[ "$new" = "up" ]
