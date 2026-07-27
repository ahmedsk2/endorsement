# Open decisions

Written 2026-07-27. Four of the original six were settled the same day — those are recorded
here as decisions, not deleted, because "we considered it and chose this" is the answer an
auditor wants and a deleted section cannot give it.

---

## STILL OPEN

### A. The origin answers the public internet, so Cloudflare is bypassable

**This is the largest of the remaining items, and it was found while checking something
else.** Verified 2026-07-27:

```
curl https://endorse.towardpcc.com/login --resolve endorse.towardpcc.com:443:145.241.105.239
→ 200, no cf-ray header
```

That is the live application served straight from the origin with Cloudflare cut out of the
path. The OCI security list allows `0.0.0.0/0` on 22, 80 and 443. The origin IP is not
secret: the Let's Encrypt certificate for `endorse.towardpcc.com` is published in
Certificate Transparency logs, and the site answered on an sslip.io hostname before the real
domain existed.

Consequences: any Cloudflare WAF rule, rate limit or bot protection is optional from an
attacker's point of view, and the plan to put Cloudflare Access in front of `/register` is
defeated before it is built. App-level defences (auth, lockout, CSRF, CSP) all still apply,
so this is not an emergency.

**Why it is not already fixed:** the obvious remedy — restrict ingress to Cloudflare's
ranges — has a blast radius that needs your decision. `towardpcc.com` and `www` are
**grey-clouded**: they resolve straight to 145.241.105.239, so that change takes those two
sites down entirely, not merely their certificate renewals. Options are (a) proxy those
records too, then restrict ingress; (b) restrict ingress and accept the apex moving; or
(c) leave it and treat Cloudflare as performance rather than security.

### B. Cloudflare SSL/TLS mode — Full, or Full (strict)?

**Recommendation: set it, but as a Configuration Rule scoped to `endorse.towardpcc.com`,
not a zone-wide flip.**

Verified: the origin serves a genuine, unexpired Let's Encrypt certificate whose SAN matches
the hostname, so strict **cannot** break this site. But the setting is zone-wide, and
`deploy.towardpcc.com` — your Coolify dashboard, i.e. your route to fixing anything — is
also proxied and its certificate expires **17 Oct**, six days before this one. A zone-wide
flip means the first thing that can 526 on a failed renewal is your management plane.

It is no longer free, and that is worth saying: under Full, a failed renewal is invisible
and harmless; under strict it is a total outage on the expiry date. That failure mode is now
detected rather than diarised — `scripts/verify-live.sh` warns below 21 days when given
`ORIGIN_IP`. Against production today: 87 days.

### C. Where operational alerts go

The **code** defects are fixed (2026-07-27): configuring SMTP in Admin → Settings now
actually selects the mailer, and `OpsAlert`'s "don't attempt delivery before SMTP exists"
guard now works — it had never once engaged. What remains is yours:

1. **An external HTTP monitor on `https://endorse.towardpcc.com/up`, alerting to your
   phone.** This is the one that matters most, and the reason is the 2026-07-27 outage: the
   container reported healthy while every real request 504'd, and in-app alerting could not
   have told you, because the app was the thing that was down. `/up` opens a real database
   connection, so a 200 covers the whole chain. Keep the interval at 3 minutes or slower.
2. **SMTP, plus an address in Admin → Settings → "Operational alerts to".** This is already
   a go-live blocker for a different reason — without SMTP no member of staff can complete
   registration. It is what carries the alerts the external monitor cannot see: a broken
   audit chain, an anomaly, a failed retention sweep.
3. **A backup heartbeat** with a 3-hour grace period, pinged by the off-host sync.

Two things for the password manager: the monitoring account (note the continuity risk — it
is tied to your personal email) and the heartbeat URL, which is a secret.

---

## DECIDED — 2026-07-27

### Unit scoping — no boundary, and now recorded as such

Residents cover all four units concurrently and may sign off more than one on the same date.
Unrestricted access is therefore the intended design, not an oversight. Written up as an
accepted minimum-necessary deviation in `docs/COMPLIANCE.md`, with the registration-approval
gate as the lead compensating control, its dependency on the still-open `/register`
exposure stated, and a named trigger that reopens it. **No code change.**

### Signature-by-proxy — confined to two roles

Administrator and Chief Resident may name any active resident and that person's signature
prints. Everyone else may still name a colleague, but only their own handwriting is applied;
the colleague prints as a typed name. Implemented, tested, and written up in
`docs/COMPLIANCE.md` including what the sheet does and does not assert.

Two adjacent defects went with it: a signature was frozen on every draft save, and an
unsigned day emitted the signature URL to the page. Both closed. `signed_off_by_name` is now
snapshotted, because wherever a signature is withheld that line is the whole attestation and
it was previously resolved live — a deactivated account erased it from every historical
print.

### The greeting — removed

"Still on nights" and its siblings are gone; the line reads **"NEXT HANDOVER 07:30"** alone.
The greeting was a warm second-person phrase set in the 10px uppercase type the design
system uses for measured values, and it told a reader standing in the morning that it was
morning. One line to restore if you disagree.

The handover time stays public — there is no disclosure argument for a shift time while the
hospital's name is set in bold above it. Separately, the signed-out page now refuses
indexing (`X-Robots-Tag`), because it names the hospital, the department and all four units.

### Container hardening — `USER app` rejected, the real finding closed

`USER app` stays rejected; the recurring cost is carrying the `/dev/stderr` workaround
across base-image bumps, not the volume ownership. But the framing was wrong: the finding
was never "the daemons run as root", it was that **root executed app-writable PHP at every
boot**. That is closed — the four boot-time artisan commands now drop to `app`, proven at
runtime by a smoke assertion. `cap_drop: ALL` with a minimal set and `no-new-privileges`
were added alongside. Six comments describing the reverted state as though it had shipped
were corrected.

---

## Still yours, unchanged

**Rotate the two Coolify tokens** — the deploy token was still working on 2026-07-27, so
this has not been done since the leak. · `php artisan user:create-admin` (TOTP, not email
codes) · `APP_KEY` and `BACKUP_PASSPHRASE` into your password manager, in two different
places · SMTP and VAPID · an object-lock rule on the `endorsement-backups` bucket · restrict
`/register` — and note it is now coupled to the unit-scoping deviation above · the PDPL
governance set (DPO, ROPA, privacy notice, DPIA, retention schedule, breach procedure).
