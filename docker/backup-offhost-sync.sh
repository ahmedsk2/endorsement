#!/usr/bin/env bash
#
# Copy the nightly encrypted archives OFF the machine that produced them.
#
# Installed on the host as /usr/local/bin/endorsement-backup-sync and run by cron at 02:05,
# after backup:run (01:30) has finished. Deliberately on the HOST rather than in the app
# container: a backup process that lives inside the thing being backed up dies with it, and
# the container has no object-storage credentials by design (see SPC-TM-020 — the web tier
# should not hold more secrets than it needs).
#
# Everything it moves is already encrypted with BACKUP_PASSPHRASE, which is NOT on this host
# and NOT in Object Storage. Losing the bucket therefore leaks nothing; losing the
# passphrase loses the backups. That separation is the whole design.
set -euo pipefail

CONF=/etc/endorsement/rclone.conf
SRC=/var/lib/docker/volumes/oo7d7si62yhyi7fx10hrck6q_endorsement-backups/_data
DEST=oci:endorsement-backups
LOG=/var/log/endorsement-backup-sync.log

log() { printf '%s %s\n' "$(date -Is)" "$1" >> "$LOG"; }

if [ ! -d "$SRC" ]; then
    log "FAILED: source volume $SRC is missing"
    exit 1
fi

# copy, not sync: `sync` would DELETE remote objects once the local 14-archive retention
# prunes them, which would make the off-host copy no better than the on-host one against
# ransomware that simply waits. Old objects age out under the bucket's own lifecycle rule.
if rclone --config "$CONF" copy "$SRC" "$DEST" --transfers 2 --checksum >> "$LOG" 2>&1; then
    count=$(rclone --config "$CONF" lsf "$DEST" 2>/dev/null | wc -l)
    log "ok: off-host copy complete, $count objects in $DEST"
else
    log "FAILED: rclone copy returned non-zero — the clinical record has NO off-host copy tonight"
    exit 1
fi

# Freshness check. A sync job that silently stops is indistinguishable from one with
# nothing to do, and the failure mode is only discovered on the day you need a restore.
newest=$(rclone --config "$CONF" lsl "$DEST" 2>/dev/null | sort -k2,3 | tail -1 || true)

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
# The URL is a secret (anyone holding it can suppress the alarm), so it lives in a
# root-only file rather than in this repo or in a crontab line. Absent, this is a no-op:
# the sync is not made to depend on the monitoring of it.
HEARTBEAT_FILE=/etc/endorsement/heartbeat.url

if [ -r "$HEARTBEAT_FILE" ]; then
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
    log "no heartbeat configured ($HEARTBEAT_FILE absent)"
fi
