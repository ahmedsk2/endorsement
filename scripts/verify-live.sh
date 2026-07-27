#!/usr/bin/env bash
#
# Verify the LIVE deployment from the outside. Read-only: GET requests, no credentials, no
# writes. Safe to run against production at any time.
#
#   bash scripts/verify-live.sh [https://endorse.towardpcc.com]
#
# `docker/smoke.sh` proves the image is sound before it ships; this proves the thing that is
# actually serving the ward is configured correctly — TLS, the unauthenticated access
# boundary, cookie flags, and that nothing sensitive is reachable.
set -u

BASE="${1:-https://endorse.towardpcc.com}"
HOST="${BASE#https://}"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
fail=0

note() { printf '  %-46s %s\n' "$1" "$2"; }
bad()  { printf '  FAIL %-41s %s\n' "$1" "$2"; fail=1; }

echo "=== TLS ==="
cert=$(echo | openssl s_client -connect "$HOST:443" -servername "$HOST" 2>/dev/null \
        | openssl x509 -noout -subject -issuer -enddate 2>/dev/null)
echo "$cert" | sed 's/^/  /'

# Verify the certificate is VALID FOR THIS HOSTNAME rather than string-matching the CN.
# Behind Cloudflare's proxy the served certificate is Cloudflare's — an apex or wildcard
# cert whose CN is not this host — and a CN match would fail on a perfectly correct setup.
# -verify_hostname checks CN *and* SANs, which is the property that actually matters.
if echo | openssl s_client -connect "$HOST:443" -servername "$HOST" \
        -verify_hostname "$HOST" -verify_return_error >/dev/null 2>&1; then
    note "certificate valid for $HOST" yes
else
    bad "certificate" "not valid for $HOST"
fi

if echo "$cert" | grep -q "CN=$HOST"; then
    note "TLS terminated at" "origin (direct)"
else
    note "TLS terminated at" "Cloudflare proxy — confirm SSL mode is Full (strict)"

    # Behind the proxy, the certificate checked above is CLOUDFLARE'S. The one that can
    # take the site down is the ORIGIN's, and nothing was watching it: under SSL mode
    # "Full", a failed Let's Encrypt renewal at the origin is invisible and harmless, but
    # under "Full (strict)" it is a total outage on the expiry date. Replacing a 90-day
    # diary entry with a check that runs itself is the difference between the two modes
    # being a free improvement and being a trap.
    #
    # Since 2026-07-27 the origin accepts 80/443 ONLY from Cloudflare's ranges, so this can
    # no longer be checked from a laptop. Run it ON the host, where the origin is loopback:
    #
    #   ORIGIN_IP=127.0.0.1 bash scripts/verify-live.sh
    #
    # From outside, an unreachable origin is the CORRECT answer — asserted separately below.
    if [ -n "${ORIGIN_IP:-}" ]; then
        origin_end=$(echo | openssl s_client -connect "$ORIGIN_IP:443" -servername "$HOST" 2>/dev/null \
                     | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)

        if [ -z "$origin_end" ]; then
            note "origin certificate" "unreachable at $ORIGIN_IP (expected from outside; run on the host with ORIGIN_IP=127.0.0.1)"
        else
            end_epoch=$(date -d "$origin_end" +%s 2>/dev/null || echo 0)
            now_epoch=$(date +%s)
            days=$(( (end_epoch - now_epoch) / 86400 ))

            if [ "$end_epoch" -eq 0 ]; then
                note "origin certificate expires" "$origin_end (could not parse)"
            elif [ "$days" -lt 21 ]; then
                bad "origin certificate" "expires in ${days}d — renewal is failing; Full (strict) will 526"
            else
                note "origin certificate expires in" "${days} days"
            fi
        fi
    else
        note "origin certificate" "not checked (set ORIGIN_IP=... to enable)"
    fi
fi

# THE PROXY MUST NOT BE BYPASSABLE.
#
# Until 2026-07-27 the origin answered the whole application to anyone on the internet, so
# every Cloudflare WAF rule, rate limit and access policy was optional for an attacker who
# knew the IP — and the IP is public, because the origin's own certificate is in the
# Certificate Transparency logs. OCI ingress on 80/443 is now restricted to Cloudflare's
# published ranges. This asserts that it stayed that way.
#
# Set ORIGIN_PUBLIC_IP to enable; skipped silently otherwise so the script still runs
# anywhere. Do NOT set it when running ON the host, where the origin is reachable by design.
if [ -n "${ORIGIN_PUBLIC_IP:-}" ]; then
    # No `|| echo 000` — curl already writes 000 on a failed connection, and appending a
    # second one produced "000000", which matched nothing and failed the check.
    bypass=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 \
             --resolve "$HOST:443:$ORIGIN_PUBLIC_IP" "https://$HOST/login" 2>/dev/null)

    if [ "${bypass:-000}" = "000" ]; then
        note "origin reachable only via Cloudflare" yes
    else
        bad "origin is directly reachable" "got $bypass bypassing Cloudflare — WAF and rate limits are optional"
    fi
fi

redirect=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "http://$HOST/")
case "$redirect" in 30*) note "http:// redirects" "$redirect";; *) bad "http:// redirect" "got $redirect";; esac

curl -sI --max-time 20 "$BASE/login" > "$TMP/h"
grep -qi '^strict-transport-security:' "$TMP/h" || bad "HSTS" "missing on /login"

echo "=== security headers on /login ==="
for h in content-security-policy x-frame-options x-content-type-options referrer-policy \
         cross-origin-opener-policy permissions-policy; do
    if grep -qi "^${h}:" "$TMP/h"; then note "$h" present; else bad "$h" missing; fi
done
grep -qi "unsafe-eval" "$TMP/h" && bad "CSP" "allows unsafe-eval"

echo "=== session cookie flags ==="
cookie=$(grep -i '^set-cookie:.*session' "$TMP/h" | head -1)
for flag in secure httponly samesite=strict; do
    echo "$cookie" | grep -qi "$flag" && note "cookie $flag" present || bad "cookie $flag" missing
done

echo "=== guarded routes must not render to an anonymous caller ==="
GUARDED=(
  /admin/access-control /admin/users /admin/settings
  /endorsement /endorsement/compliance /endorsement/today
  /endorsement/PICU /endorsement/PICU/2026-07-25
  /endorsement/PICU/2026-07-25/print /endorsement/NICU/2026-07-25/print
  /endorsement/SCBU/2026-07-25/print /endorsement/WARD/2026-07-25/print
  /profile /change-password /user/two-factor
  /signatures/me /signatures/1 /signatures/file/abc123
  /storage/private/signatures/abc.png /two-factor-challenge /email-code
)
for r in "${GUARDED[@]}"; do
    code=$(curl -s -o "$TMP/b" -w '%{http_code}' --max-time 20 "$BASE$r")
    case "$code" in
        200)
            # 200 is only tolerable if it is not a rendered clinical/admin page.
            if grep -qiE 'Problem List|Clinical Condition|Plan of Care|Access Control|data-page' "$TMP/b"; then
                bad "$r" "200 WITH APPLICATION CONTENT"
            else
                note "$r" "200 (no app content)"
            fi ;;
        30*|401|403|404|405|419) note "$r" "$code" ;;
        *) bad "$r" "unexpected $code" ;;
    esac
done

echo "=== public routes ==="
# `/` intentionally redirects (to /endorsement, which sends a guest on to /login).
for r in /login /register /forgot-password /up; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE$r")
    [ "$code" = "200" ] && note "$r" "$code" || bad "$r" "expected 200, got $code"
done
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE/")
case "$code" in 30*|200) note "/" "$code";; *) bad "/" "unexpected $code";; esac

echo "=== no secrets or framework internals in anonymous responses ==="
: > "$TMP/all"
for r in / /login /register /forgot-password /up /endorsement /endorsement/PICU/2026-07-25/print; do
    curl -s --max-time 20 "$BASE$r" >> "$TMP/all"
done
for pat in 'APP_KEY' 'MYSQL_' 'base64:' 'patient_name' 'Whoops' 'vendor/laravel' 'Stack trace'; do
    grep -qi -- "$pat" "$TMP/all" && bad "leak" "found '$pat'" || note "no '$pat'" ok
done

echo "=== nothing sensitive served ==="
for p in /.env /.env.production /.git/config /composer.json /package.json /Dockerfile \
         /docker-compose.production.yml /storage/logs/laravel.log /vendor/autoload.php \
         /telescope /horizon /phpinfo.php /adminer.php /storage/backups/; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE$p")
    [ "$code" = "200" ] && bad "$p" "served 200" || note "$p" "$code"
done

echo
[ "$fail" -eq 0 ] && echo "LIVE VERIFICATION PASSED" || echo "LIVE VERIFICATION FAILED"
exit "$fail"
