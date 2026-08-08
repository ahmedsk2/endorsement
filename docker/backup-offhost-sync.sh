#!/usr/bin/env bash
#
# Copy the nightly encrypted archives OFF the machine that produced them, for ONE customer
# instance.
#
# Installed on the host as /usr/local/bin/endorsement-backup-sync and run by cron at 02:05,
# after backup:run (01:30) has finished, WITH THE INSTANCE SLUG AS AN ARGUMENT:
#
#   5 2 * * *  /usr/local/bin/endorsement-backup-sync qch
#
# Deliberately on the HOST rather than in the app container: a backup process that lives
# inside the thing being backed up dies with it, and the container has no object-storage
# credentials by design (see SPC-TM-020 — the web tier should not hold more secrets than it
# needs).
#
# ONE BINARY, per-slug config/log — not N copies. Two customers on one host sharing this
# script but with hardcoded paths meant customer 2's cron either silently copied customer 1's
# volume (paste error) or fought customer 1's log/state files. The slug is a REQUIRED
# argument with NO default: a default would let a misconfigured customer-2 cron silently
# point at customer 1's volume, which is exactly the failure this removes — failing loudly at
# 02:05 with a non-zero exit is recoverable, and the dead-man's switch below then alarms.
#
# Everything it moves is already encrypted with BACKUP_PASSPHRASE, which is NOT on this host
# and NOT in Object Storage. Losing the bucket therefore leaks nothing; losing the
# passphrase loses the backups. That separation is the whole design.
set -euo pipefail

slug="${1:-}"
[ -n "$slug" ] || { echo "usage: endorsement-backup-sync <instance-slug>" >&2; exit 2; }

CONF_FILE="/etc/endorsement/${slug}.conf"
[ -r "$CONF_FILE" ] || { echo "no config at $CONF_FILE" >&2; exit 2; }

# 0600, root-only. Supplies: PROJECT_UUID, RCLONE_CONF, DEST, HEARTBEAT_FILE (optional).
# shellcheck source=/dev/null
. "$CONF_FILE"

: "${PROJECT_UUID:?PROJECT_UUID missing from $CONF_FILE}"
: "${RCLONE_CONF:?RCLONE_CONF missing from $CONF_FILE}"
: "${DEST:?DEST missing from $CONF_FILE}"
HEARTBEAT_FILE="${HEARTBEAT_FILE:-}"

# Derived, never pasted: the volume prefix is the Coolify app UUID and differs per stack.
# Pasting it is how customer 2's sync job ends up copying customer 1's volume.
SRC="/var/lib/docker/volumes/${PROJECT_UUID}_endorsement-backups/_data"
LOG="/var/log/endorsement-${slug}-backup-sync.log"

# Slug prefixed so a shared syslog (or this log tailed alongside another instance's) is
# still readable at a glance.
log() { printf '%s [%s] %s\n' "$(date -Is)" "$slug" "$1" >> "$LOG"; }

if [ ! -d "$SRC" ]; then
    log "FAILED: source volume $SRC is missing"
    exit 1
fi

# copy, not sync: `sync` would DELETE remote objects once the local 14-archive retention
# prunes them, which would make the off-host copy no better than the on-host one against
# ransomware that simply waits. Old objects age out under the bucket's own lifecycle rule.
if rclone --config "$RCLONE_CONF" copy "$SRC" "$DEST" --transfers 2 --checksum >> "$LOG" 2>&1; then
    count=$(rclone --config "$RCLONE_CONF" lsf "$DEST" 2>/dev/null | wc -l)
    log "ok: off-host copy complete, $count objects in $DEST"
else
    log "FAILED: rclone copy returned non-zero — the clinical record has NO off-host copy tonight"
    exit 1
fi

# Freshness check, scoped to THIS instance's destination — DEST is per-customer (per-slug
# bucket, owner decision 2026-08-08), so a sync job that silently stops here cannot be masked
# by another customer's fresh upload landing in a shared directory.
newest=$(rclone --config "$RCLONE_CONF" lsl "$DEST" 2>/dev/null | sort -k2,3 | tail -1 || true)

if [ -z "$newest" ]; then
    log "FAILED: destination is empty after a successful copy"
    exit 1
fi

log "newest object: $newest"

# Dead-man's switch. Every failure path above exits non-zero BEFORE this line, so the ping
# is only sent when an archive genuinely reached the off-host destination and was seen
# there. The monitoring service alarms when the ping stops — which is the only way to be
# told about a backup that silently stopped running, as opposed to one that failed loudly.
#
# The URL is a secret (anyone holding it can suppress the alarm), so it lives in the
# per-instance root-only config file rather than in this repo or in a crontab line. Absent,
# this is a no-op: the sync is not made to depend on the monitoring of it.
if [ -n "$HEARTBEAT_FILE" ] && [ -r "$HEARTBEAT_FILE" ]; then
    url=$(tr -d '[:space:]' < "$HEARTBEAT_FILE")

    # A file containing the literal placeholder is NOT a configured heartbeat, and the
    # difference matters: a monitoring service that never hears from you looks identical
    # whether the backup stopped or the URL was never real. It happened on the first
    # attempt — the paste kept the placeholder — and a non-empty check called it configured.
    case "$url" in
        https://*|http://*) : ;;
        *)
            log "heartbeat NOT configured: $HEARTBEAT_FILE does not contain a URL (found ${#url} characters). Backups are unaffected; nothing is watching them."
            url=""
            ;;
    esac

    if [ -n "$url" ]; then
        # Never let monitoring fail the thing it monitors: short timeout, errors swallowed,
        # and the URL kept out of the log because it is a credential.
        if curl -fsS --max-time 15 -o /dev/null "$url" 2>/dev/null; then
            log "heartbeat sent"
        else
            log "heartbeat FAILED to send (the backup itself succeeded)"
        fi
    fi
else
    log "no heartbeat configured (HEARTBEAT_FILE unset or unreadable)"
fi
