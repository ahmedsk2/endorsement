# Open decisions — things I could not settle without you

Written 2026-07-27. Each of these is genuinely a judgement call, not work I skipped. They
are ordered by what it costs you to leave them open.

---

## 1. Cloudflare SSL/TLS mode — Full, or Full (strict)?

**Cost of leaving it:** possibly none, possibly an unauthenticated last hop.

The record is proxied now, so Cloudflare terminates TLS and re-connects to the origin. I
proved that hop is encrypted — the origin 302s HTTP to HTTPS, so **Flexible** would loop
forever and the site would be dead, and it isn't. But **Full** and **Full (strict)** are
indistinguishable from outside, and only strict validates the origin's certificate.

The API token here cannot read zone settings (`9109 Unauthorized`), so this needs a human
with the dashboard open. It is one screen: SSL/TLS → Overview.

---

## 2. Where should operational alerts go?

**Cost of leaving it:** the system detects a failed backup and tells nobody.

Two independent channels now exist and neither has a destination:

- **In-app** — `OpsAlert` emails on a failed backup, a broken audit chain, a failed
  retention sweep, or an anomaly. It needs SMTP configured *and* an address in
  **Admin → Settings → Operational alerts to**.
- **On the host** — `/usr/local/bin/endorsement-uptime-check` runs every five minutes
  against the public URL and writes to `/var/log/endorsement-uptime.log`. It has no
  notification channel at all, because the sensible options are a decision:
  - a healthchecks.io-style dead-man ping (free, external, needs an account);
  - `msmtp` on the host relaying to the same SMTP you configure in-app;
  - or nothing, and you read the log after an incident.

The host one matters more than it sounds: during the 2026-07-27 outage the in-app alerting
would have been unable to send, because the app was the thing that was unreachable.

---

## 3. Signature-by-proxy — is this the workflow you want?

**Cost of leaving it:** a printed handover can carry a clinician's handwritten signature
for a shift they never attended.

Today one clinician can name another as the endorser, and the system freezes that person's
stored signature image onto the sheet. I restricted **who** can be named (active Residents
and Chief Residents only), which closes the forgery-of-arbitrary-accounts hole. What I did
not change is the model itself, because it is a clinical question, not a technical one:

**what is a signature on that sheet meant to assert?** That a handover happened between two
named people, or that each named person personally attested?

If the latter, the fix is a counter-sign step: the named endorser confirms from their own
session before their signature is applied. That is real work and it changes how handover
is done at the bedside, so it is yours to call.

---

## 4. Unit scoping — should a NICU resident be able to read PICU?

**Cost of leaving it:** every clinical account can read and print all four units' entire
history, which is a minimum-necessary finding under PDPL and NCA ECC-1.

I suspect this is intentional — residents rotate, cover happens, and a hard boundary would
get in the way at 03:00. But it should be a **recorded decision** rather than an accident,
because an auditor will ask. If you confirm it is intended, I will write it into
`docs/COMPLIANCE.md` as an accepted deviation with your reasoning, which is a much better
answer than silence.

---

## 5. The greeting copy

**Cost of leaving it:** none. Purely taste.

The signed-out page now greets by shift. Between midnight and 05:00 it says **"Still on
nights"**. I liked it — it acknowledges the person actually reading it at that hour — but
it is more familiar in tone than anything else in the product, and you may want plain
"Good evening" throughout. One line to change.

It also shows **"Next handover 07:30"** publicly. Shift times are pinned to the ward wall
so I judged this not to be sensitive, but it is operational detail visible to anyone on the
internet and you may prefer it hidden until sign-in.

---

## 6. Container hardening — worth a migration?

**Cost of leaving it:** defence-in-depth only; it changes nothing unless an attacker
already has code execution.

nginx and php-fpm masters still run as root. Dropping them needs `USER app` at the image
level, because under Docker the container's stdout is owned by whoever starts PID 1 and
both daemons open their error log by path at startup. That also changes how the existing
named volumes are owned, so it is a deliberate migration with a verification step rather
than a one-line change. Two auditors flagged it; I attempted it, could not make it work
safely in one pass, and reverted rather than leave it half-done. Full write-up in
`docs/SECURITY-AUDIT-2026-07-26.md`.

---

## Still yours, unchanged from the checklist

Rotate the two Coolify tokens · `php artisan user:create-admin` (TOTP, not email codes) ·
`APP_KEY` and `BACKUP_PASSPHRASE` into your password manager, in two different places ·
SMTP and VAPID · an object-lock rule on the `endorsement-backups` bucket · restrict
`/register` · the PDPL governance set.
