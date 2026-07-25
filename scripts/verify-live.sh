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
echo "$cert" | grep -q "CN=$HOST" || bad "certificate CN" "does not match $HOST"
echo "$cert" | grep -qi "Let's Encrypt" || note "certificate issuer" "not Let's Encrypt (check expectations)"

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
