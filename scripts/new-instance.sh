#!/usr/bin/env bash
#
#   bash scripts/new-instance.sh --slug rgh --host endorse.rgh.example --timezone Asia/Riyadh
#
# Prints the environment block to paste into Coolify's Environment Variables screen, and the
# custody note. It writes NOTHING to disk. Everything it prints is a secret except APP_NAME,
# APP_URL, APP_TIMEZONE, INSTANCE_SLUG, INSTITUTION_*, MYSQL_DATABASE, MYSQL_USER and
# TRUSTED_PROXIES — close the terminal when you are done.
#
# OWNER-RUN. Nothing this script prints is stored, transmitted or logged by it; this
# terminal is the only place any of it has ever existed. Never commit its output.
set -euo pipefail
umask 077

# Alphanumeric ONLY, and this is not cosmetic: Coolify feeds these through `docker compose`,
# which performs $-interpolation on env values, so a password containing '$' is silently
# truncated into something weaker. Same alphabet as docker/smoke.sh's generator.
#
# The trailing `true` matters under `set -e`: `head -c 48` closes its end of the pipe as soon
# as it has enough bytes, so `tr` — still reading an effectively infinite stream from
# /dev/urandom — gets SIGPIPE and exits 141. Under `pipefail` that becomes the pipeline's own
# exit status, and because every call site here is a plain assignment (`x="$(rnd)"`, not text
# inside a heredoc), `errexit` treated that as a real failure and aborted the script before
# printing anything, even though 48 good bytes had already been captured. `true` makes the
# function's own exit status 0 regardless.
rnd() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 48; true; }

# Filename-safe, glob-safe, DNS-label-shaped — the same pattern App\Support\Instance enforces
# in PHP (app/Support/Instance.php Instance::SLUG_PATTERN). It names files on the host and
# objects in the backup bucket, so it is not normalised for you.
SLUG_PATTERN='^[a-z0-9][a-z0-9-]{0,31}$'

slug=""
host=""
timezone=""
institution_code=""
institution_name=""
cloudflare=1

usage() {
    cat >&2 <<'USAGE'
usage: new-instance.sh --slug <slug> --host <hostname> --timezone <tz>
                        [--institution-code <code>] [--institution-name <name>]
                        [--no-cloudflare]

Generates and PRINTS the environment block for a new customer instance. Writes nothing to
disk, transmits nothing. Paste the output into Coolify's Environment Variables screen, then
close the terminal.

  --slug               INSTANCE_SLUG. Required. ^[a-z0-9][a-z0-9-]{0,31}$
  --host                APP_URL's hostname (no scheme). Required.
  --timezone            APP_TIMEZONE, e.g. Asia/Riyadh. Required — get this right before the
                        first clinical write; it moves the handover day boundary afterwards.
  --institution-code    INSTITUTION_CODE. Default: QCH
  --institution-name    INSTITUTION_NAME. Default: Qatif Central Hospital
  --no-cloudflare       This instance is NOT behind Cloudflare; widen TRUSTED_PROXIES
                        accordingly instead of trusting the Cloudflare edge ranges.
USAGE
    exit 2
}

while [ $# -gt 0 ]; do
    case "$1" in
        --slug) slug="${2:-}"; shift 2 ;;
        --host) host="${2:-}"; shift 2 ;;
        --timezone) timezone="${2:-}"; shift 2 ;;
        --institution-code) institution_code="${2:-}"; shift 2 ;;
        --institution-name) institution_name="${2:-}"; shift 2 ;;
        --no-cloudflare) cloudflare=0; shift ;;
        -h|--help) usage ;;
        *) echo "unknown argument: $1" >&2; usage ;;
    esac
done

[ -n "$slug" ] && [ -n "$host" ] && [ -n "$timezone" ] || usage

if ! [[ "$slug" =~ $SLUG_PATTERN ]]; then
    echo "refused: --slug ($slug) must match ${SLUG_PATTERN} — lowercase letters, digits and" >&2
    echo "hyphens, starting alphanumeric, at most 32 characters. It names files on the host" >&2
    echo "and objects in the backup bucket, so it is not normalised for you." >&2
    exit 1
fi

# A slug collision would have two customers writing one host config, one log and one state
# file — this only catches it when run ON THE HOST, where the directory can exist; elsewhere
# it is silently skipped, which is correct (there is nothing to collide with yet).
conf="/etc/endorsement/${slug}.conf"
if [ -r "$conf" ]; then
    echo "refused: $conf already exists — that slug is already in use on this host. Choose a" >&2
    echo "different slug, or remove that file first if you mean to replace the instance." >&2
    exit 1
fi

institution_code="${institution_code:-QCH}"
institution_name="${institution_name:-Qatif Central Hospital}"

app_key="base64:$(openssl rand -base64 32)"
mysql_password="$(rnd)"
mysql_root_password="$(rnd)"
backup_passphrase="$(rnd)"

# Behind Cloudflare, the edge's own ranges are added UNCONDITIONALLY by
# App\Support\TrustedProxies — this variable only controls which additional internal ranges
# are trusted for the hop between Traefik and the app. Never '*': that would trust a
# client-supplied X-Forwarded-For, forging the audit trail and bypassing the login lockout.
if [ "$cloudflare" -eq 1 ]; then
    trusted_proxies='10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
else
    trusted_proxies='10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,no-cloudflare'
fi

# The same domain-separated hash App\Support\Instance::keyFingerprint() computes — so this
# can be written into the custody register (slug <-> fingerprint <-> passphrase location)
# BEFORE the key is ever used, without the register ever holding the key itself.
fingerprint=$(printf 'endorsement-key-fingerprint:%s' "$app_key" | sha256sum | cut -c1-16)

cat <<BLOCK
# ---- paste into Coolify -> this instance's app -> Environment Variables ----
APP_NAME=Paediatric Endorsement
APP_URL=https://${host}
APP_TIMEZONE=${timezone}
INSTANCE_SLUG=${slug}
INSTITUTION_CODE=${institution_code}
INSTITUTION_NAME=${institution_name}
APP_KEY=${app_key}
MYSQL_DATABASE=endorsement
MYSQL_USER=endorse
MYSQL_PASSWORD=${mysql_password}
MYSQL_ROOT_PASSWORD=${mysql_root_password}
BACKUP_PASSPHRASE=${backup_passphrase}
TRUSTED_PROXIES=${trusted_proxies}
# ---- end paste ----

APP_KEY fingerprint (write this into the custody register NOW, before the key is ever used):
  ${fingerprint}

CUSTODY RULE: APP_KEY and BACKUP_PASSPHRASE go in DIFFERENT stores. A backup and the key that
opens it must never sit together — and both are needed to read one, because APP_KEY decrypts
the PHI columns *inside* the dump; BACKUP_PASSPHRASE only opens the archive around them.

Nothing above was written to disk or transmitted anywhere — this terminal is the only place
it has ever existed. Copy it into Coolify and your password manager now, then close this
terminal.
BLOCK
