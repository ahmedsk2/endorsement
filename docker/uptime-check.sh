#!/usr/bin/env bash
#
# Is the site actually reachable from outside? For ONE customer instance.
#
# Installed on the host as /usr/local/bin/endorsement-uptime-check, run by cron every five
# minutes, WITH THE INSTANCE SLUG AS AN ARGUMENT:
#
#   */5 * * * * /usr/local/bin/endorsement-uptime-check qch
#
# Deliberately on the HOST and against the PUBLIC URL, because the failure it exists for is
# the one the container cannot see: on 2026-07-27 the app was healthy and answering its own
# healthcheck the whole time while Traefik dialled a network the proxy is not on and every
# real request 504'd.
#
# The container's HEALTHCHECK proves the app is alive. This proves a clinician can reach it.
# Those are different questions and only the second one matters at 07:30.
#
# ONE BINARY, per-slug log/state — not N copies. Before this, LOG and STATE were absolute
# literals: two crons sharing one state file each read the OTHER customer's last state, and
# the transition logic below emitted a permanent stream of false CRITICAL/recovered pairs —
# the log IS the outage record, and corrupting it is worse than having no monitor at all. The
# slug is a REQUIRED argument with no default URL fallback either: a hardcoded public URL is
# how a customer-2 monitor would silently end up watching customer 1's site instead of its
# own.
#
# Logs a line on every state CHANGE, not every run — a log that says "ok" 288 times a day
# is a log nobody reads, and the transition is the thing worth finding later.
set -uo pipefail

slug="${1:-}"
[ -n "$slug" ] || { echo "usage: endorsement-uptime-check <instance-slug>" >&2; exit 2; }

CONF_FILE="/etc/endorsement/${slug}.conf"
# Optional here (unlike the backup sync): URL can be supplied directly via the environment
# for testing, so a missing config file is not itself fatal — only a missing URL is.
[ -r "$CONF_FILE" ] && . "$CONF_FILE"

URL="${URL:-${PUBLIC_URL:-}}"
[ -n "$URL" ] || { echo "no URL for instance $slug (set PUBLIC_URL in $CONF_FILE)" >&2; exit 2; }

LOG="/var/log/endorsement-${slug}-uptime.log"
STATE="/var/lib/endorsement-${slug}-uptime.state"

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
        unknown:*) printf '%s [%s] monitoring started, site is %s (HTTP %s)\n' "$now" "$slug" "$new" "$code" >> "$LOG" ;;
        *:down)    printf '%s [%s] CRITICAL site unreachable (HTTP %s) — clinicians cannot sign in\n' "$now" "$slug" "$code" >> "$LOG" ;;
        *:up)      printf '%s [%s] recovered (HTTP %s)\n' "$now" "$slug" "$code" >> "$LOG" ;;
    esac

    printf '%s' "$new" > "$STATE"
fi

# A daily heartbeat, so a silent log can be told apart from a stopped cron.
if [ "$(date +%H:%M)" = "07:00" ]; then
    printf '%s [%s] heartbeat: %s (HTTP %s)\n' "$now" "$slug" "$new" "$code" >> "$LOG"
fi

[ "$new" = "up" ]
