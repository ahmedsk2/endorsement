# Open decisions

Written 2026-07-27. Four of the original six were settled the same day — those are recorded
here as decisions, not deleted, because "we considered it and chose this" is the answer an
auditor wants and a deleted section cannot give it.

---

## STILL OPEN

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

### The origin no longer answers the public internet

Until this afternoon the whole application was served straight from 145.241.105.239 with
Cloudflare cut out of the path, so every WAF rule, rate limit and access policy was optional
for anyone who knew the IP — and the IP is public, because the origin's own certificate is
in the Certificate Transparency logs.

Closed in two steps, in this order, because the reverse order would have taken sites down:

1. **Every hostname is now proxied.** Three were grey-clouded, not the two I first reported
   — `towardpcc.com`, `www`, and `next` — all serving the live TowardPCC site. Each was
   verified to hold a valid publicly-trusted origin certificate first, then flipped, then
   re-checked through the edge.
2. **OCI ingress on 80/443 is restricted to Cloudflare's published ranges.** Port 22 and
   ICMP fragmentation-needed are deliberately left open — the latter is not optional, since
   removing it makes large responses hang rather than fail.

Verified: the bypass now returns nothing, and all eight hostnames still serve. Asserted from
then on by `scripts/verify-live.sh` with `ORIGIN_PUBLIC_IP` set, so a change that reopens
the origin is caught rather than noticed.

**Two consequences worth knowing.** Certificate renewal now depends on the HTTP-01 challenge
arriving *through* Cloudflare, which it does today — but a NEW hostname added grey-clouded
will fail to get a certificate, and the fix is to proxy it. And the origin certificate can
no longer be inspected from a laptop, so that check runs on the host with
`ORIGIN_IP=127.0.0.1`.

### Self-registration is closed — accounts are created by invitation

`/register` accepted anyone on the internet. An Administrator or Chief Resident now invites
one address into one role; the invitee chooses only a username and password, because the
address and the role travel with the invitation. Single use, seven-day expiry, revocable,
token stored hashed, every failure indistinguishable from every other so it is not an oracle
for valid addresses.

This also strengthens the unit-scoping deviation below, which leans on "no account exists
without approval" — that approval now happens before anyone can even reach a form.

The pending-registration queue survives for rows created before today (there are none in
production) and nothing adds to it. Once confirmed empty it can be removed entirely.

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

## DECIDED — 2026-08-08 (P0d, owner decision 3)

### Co-tenancy on the shared `coolify` Docker network — ACCEPTED, with a named trigger

Standing up a second customer (P0d) put a second `app` container on the same external
`coolify` network the first one already sits on — the network Coolify's own reverse proxy uses
to reach every application it hosts. `bootstrap/app.php:73-75` already documents that a
co-tenant container can reach this application directly, bypassing Traefik's host-based
routing, and `docker-compose.production.yml`'s `TRUSTED_PROXIES` covers `172.16.0.0/12`, the
private range those containers sit in. **A compromised neighbour on that shared network could
therefore forge `X-Forwarded-For`** — reviving the forgeable-audit-IP and bypassable-lockout
failure the 2026-07-26 security audit closed, and which CLAUDE.md lists as a standing
invariant that must never regress.

**The database network is not affected.** `internal` is per-stack, bridge-only, publishes no
host port, and customer A's app cannot reach customer B's MySQL under any circumstance — this
decision is scoped strictly to `app`-to-`app` reachability on `coolify`.

**Options considered:** a separate host per customer (closes it completely; real recurring
infrastructure cost, not a code change) or accept the shared-network exposure and document it.
**The owner chose to accept it** rather than provision a second host, given that today there is
exactly one customer holding real patient data.

**TRIGGER, verbatim — revisit before a second customer carries real patient data.** Not a
"revisit periodically": a specific, checkable condition. Recorded identically in
`docs/COMPLIANCE.md` ("Accepted deviations" §3) and `docs/PDPL-PACK.md` (§3.2, §3.3 item 3) so
the same trigger is findable wherever an auditor looks, and the PDPL pack's §3.4 conclusion now
carries an amendment note pointing back at it. It is **not** re-signed for this — the risk was
already owner-accepted with its own named trigger in §3.3, so the amendment brings the
conclusion's wording into agreement with that acceptance rather than making a new decision.

**Two items already open at N=1, now explicitly inherited by every additional customer:** the
missing **object-lock/retention rule on the backup bucket** (write credentials are delete
credentials — see "Still yours, unchanged" below) and the **external HTTP monitor being unbuilt
with its account tied to a personal email** (§ "STILL OPEN" above) are not P0d defects, but
P0d's per-customer bucket and per-customer monitor (owner decision 2, `docs/RUNBOOK-PROVISION.md`
§9) mean each is now an obligation *per instance*, not a single item to close once.

---

## Still yours, unchanged

**Rotate the two Coolify tokens** — the deploy token was still working on 2026-07-27, so
this has not been done since the leak. · `php artisan user:create-admin` (TOTP, not email
codes) · `APP_KEY` and `BACKUP_PASSPHRASE` into your password manager, in two different
places · SMTP and VAPID · an object-lock rule on the `endorsement-backups` bucket · the PDPL
governance set — **now drafted in `docs/PDPL-PACK.md`**, with the technical half filled
in from the code and every judgement marked `[DECIDE]`. What is left there is genuinely
yours: who the DPO is, the clinical retention period, who declares a breach, and how this
system learns that somebody has left.

Note that `user:create-admin` is now the ONLY way to create the first account, since
self-registration is closed and an invitation has to be issued by someone who already has
an account. Do that one first.
