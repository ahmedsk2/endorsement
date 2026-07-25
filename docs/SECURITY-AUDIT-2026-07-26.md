# Security Audit — Paediatric Endorsement System

**Target** `https://endorse.towardpcc.com` — Laravel 13 + Inertia/Vue 3 clinical shift-handover
application holding paediatric PHI for four units (PICU, NICU, SCBU, WARD).
**Infrastructure** Coolify + Traefik on a single OCI ARM64 VM (me-riyadh-1); dedicated MySQL 8.4
container on an internal-only bridge network.
**Scope** One hospital, one maintainer, single tenant.
**Report date** 2026-07-26 · **Scans run** 2026-07-25 / 2026-07-26 · **Correlation prefix** `SPC-RPT-`

> **This is a guidance document, not an attestation.** It is a static-analysis roll-up of
> *candidates* plus authorised read-only live HTTP probing. It is not a penetration test, not a
> certification, and not a compliance pass. "Coverage %" below means **check coverage** — the
> proportion of a framework's requirements that some check in this sweep actually looked at —
> never a statement that the requirement is met. No fix in this report has been applied.

---

## 1. Executive summary

### Counts by severity (open findings, after de-duplication)

| Severity | Count |
|---|---|
| Critical | **0** |
| High | **11** |
| Medium | **31** |
| Low | **29** |
| Informational | **17** |
| **Total open** | **88** |
| Remediated since the scans ran (commit `1175b3c`) | 9 |

110 raw findings arrived from seven domains. 15 were merged as duplicates seen from different
angles, 9 were confirmed fixed and deployed, and 2 owner-acknowledged operational items were added
for completeness — leaving 88 open.

### Overall risk rating: **MODERATE**

Not low, and not high. The reasoning matters more than the label:

- **There is no remaining unauthenticated path to PHI.** The one Critical in this sweep
  (host-header poisoning of the password-reset link, `SPC-CODE-001`) is fixed and deployed. The
  transport tier is genuinely strong (Grade A−: enforcing CSP with a clean `script-src`, HSTS,
  TLS 1.2/1.3 with mandatory forward secrecy, `SameSite=Strict` encrypted sessions). There is **no
  SQL injection surface anywhere in the repository** — verified, not assumed. There are **no
  committed secrets**, and the production dependency trees are **clean of known CVEs**.
- **The residual risk concentrates in one place: the evidentiary integrity of the signed
  handover.** Three controls that look complete each have a structural hole. A clinician's stored
  signature can be applied to a sheet by a colleague. The audit chain can be recomputed by anyone
  with database write access — which the application's own credential has. And the day lock
  protects the day, but not the memory of what the day said. That cluster is the single most
  important thing in this report.
- **The second concentration is operational, and the owner already knows about it**: the only
  backup lives on the machine it backs up, and the signature images are not in it at all.

For a single-clinician maintainer, the honest reading is: *the code is in better shape than most
production clinical systems; the gaps are in what happens when something goes wrong* — a
compromised account, a restore, a key rotation, a dispute at an M&M review.

### Top five open items, in priority order

Priority = normalised severity × exploitability × reachability **for this deployment**.

| # | ID | One line | Sev |
|---|---|---|---|
| 1 | `SPC-RPT-001` | Forwarded-header trust is a wildcard — the login lockout can be bypassed by rotating `X-Forwarded-For`, and every `audit_log` IP is forgeable | High |
| 2 | `SPC-RPT-002` | A clinician can attach a colleague's stored handwritten signature to a handover that colleague never approved | High |
| 3 | `SPC-RPT-003` | The only backup lives on the machine it backs up — VM loss or ransomware takes the record and its backups together | High |
| 4 | `SPC-RPT-004` | The audit hash chain is unkeyed and unanchored — it can be rewritten or truncated and still report "chain intact" | High |
| 5 | `SPC-RPT-005` | The application's MySQL user holds `ALL PRIVILEGES` — "append-only audit log" and "clinical rows never hard-deleted" are convention, not enforcement | High |

**Why this order.** `RPT-001` is first because it is the only item reachable by an unauthenticated
attacker on the internet: it removes the *only* brute-force control on `/login` (the route
deliberately carries no route-level throttle), and `/register` is open and confirms which staff
usernames exist. `RPT-002` is second because it is live, needs nothing more than an ordinary
clinical session, and damages the one artefact the system exists to produce. `RPT-003` is third on
consequence rather than likelihood — it is the only single event that can end the system. `RPT-004`
and `RPT-005` compound each other and are what turn any compromise from *detectable* into
*deniable*.

**Deliberately ranked lower than upstream scored them:** the tenant-blindness of `institution_id`
(`SPC-RPT-047`) was reported as Medium; on a single-tenant install with one institution it is
**latent**, not live, and is ranked Low. The GitHub Actions tag-pinning gap was reported High; on a
**private** repo whose workflow references **no secrets and no cloud credentials**, the realistic
blast radius is source exfiltration, so it is ranked Medium (`SPC-RPT-028`).

### Not assessed — recorded honestly, not as passing

| Domain | Status | Why |
|---|---|---|
| Infrastructure-as-Code (`/sec-iac`) | **NOT ASSESSED** | No Terraform, CloudFormation, Kubernetes or Helm in the tree. The OCI VM, Coolify host configuration, Traefik router policy, host firewall and Coolify RBAC were therefore **never examined**. This is not "no findings" — it is *no coverage*. |
| AI / LLM (`/sec-ai`) | **NOT ASSESSED** | No LLM SDKs, no prompt templates, no vector stores, no model endpoints. Genuinely not applicable to this codebase. |
| Mobile (`/sec-mobile`) | **NOT ASSESSED** | No Android or iOS target. The PWA service worker *was* reviewed under the code and web domains. |

Additional coverage gaps inside the domains that *were* assessed are listed in §7.

---

## 2. Method — stated honestly

**What actually ran.** Static source analysis (full manual reads plus ripgrep-class pattern
libraries) across seven domains, plus **authorised read-only live HTTP** against production:
24 GET/HEAD requests and 14 TLS handshakes. No fuzzing, no credential attacks, no rate-limit
probing, no smuggling payloads, no writes. No file in the repository was modified.

**Tools that were NOT available on this machine — so most domains fell back to pattern analysis:**

| Tool | Status | Consequence |
|---|---|---|
| `semgrep`, CodeQL | not on PATH | **No automated source-to-sink taint proof.** Every data-flow claim in this report was traced by hand. |
| `gitleaks`, `trufflehog` | not on PATH | Secret detection was provider-prefix regex over the **current tracked tree only**. Git history was never swept (`gitleaks --log-opts=--all`). |
| `psalm` / `phpstan` / `larastan` | not in `require-dev` | No type-level or nullability analysis. |
| `trivy`, `grype`, `hadolint`, `dockle`, `snyk` | not on PATH | **No image-layer, OS-package or Dockerfile-lint scan.** The app image is not built on this machine, so there was no artefact to scan. |
| CIS-CAT Pro | not run | No MySQL or Docker benchmark attestation; CIS references are mappings, not scored results. |

**Dependency CVE coverage.** The *code* domain ran no dependency auditor and said so. The
**supply-chain domain did**, with live advisory data: `composer audit --locked` (Composer 2.10.2)
returned **zero advisories** across 122 packages, and `npm audit --omit=dev` returned **zero
vulnerabilities** across 73 production packages. Both clean. Six *abandoned* PHP packages were
found (see `SPC-RPT-030`), and six high advisories exist in the **dev-only** npm tree, which never
ships. **The container OS layer and the PHP runtime remain unscanned** — that is the real
dependency gap (`SPC-RPT-083`).

**Dynamic tools that would confirm the tentative findings.**

| Finding | Confirming tool |
|---|---|
| `RPT-001` forwarded-header spoofing | **Burp Suite Intruder** — 20 `POST /login` for one username, each with a different `X-Forwarded-For`; confirm none trips the lockout, then read back `audit_log.ip`. |
| `RPT-002`, `RPT-012`, `RPT-015` access control | **Burp Suite / OWASP ZAP** authenticated two-account testing (a Charge Nurse session against a Consultant's id). |
| `RPT-014` blind SSRF | **Burp Collaborator** — register a subscription pointing at a Collaborator host, trigger `endorsement:remind`, watch for the callback. |
| `RPT-004` audit-chain rewrite | Non-production copy: delete the last 5 rows, run `php artisan audit:verify`, watch it report the chain intact. |
| `RPT-005` DB grants, `RPT-021` TLS | `SHOW GRANTS FOR 'endorse'@'%'`; `SHOW STATUS LIKE 'Ssl_cipher'` from the app's own connection. **CIS-CAT Pro** for a full MySQL attestation. |
| `RPT-006`, `RPT-022`–`025`, `RPT-083` container | **trivy** (`vuln,secret,misconfig`), **hadolint**, **dockle**, **docker-bench-security** against the live host. |
| `RPT-054` TLS suites | **testssl.sh** or **Qualys SSL Labs** for the full cipher matrix. |
| `RPT-076` request smuggling | **smuggler.py** or Burp HTTP Request Smuggler — **against a staging replica only**, never production. |
| `RPT-028`/`029` CI | **zizmor** or **actionlint**; `gh api repos/…/actions/permissions/workflow`. |
| `RPT-068` provenance | **cosign** / **slsa-verifier** — deferred: there is currently no artefact to sign. |
| SQL injection | **sqlmap** — *no candidate sink exists to point it at* (`SPC-RPT-080`). |
| Mobile (MobSF, Frida), API spec fuzzing (schemathesis) | **N/A** — no mobile target, no OpenAPI document. |

**Everything marked `tentative` stays `tentative`.** 21 of 23 threat-model findings were tentative
by design — that domain reasons about structure, and its conclusions want a facilitated human
threat-modelling session, not a scanner.

---

## 3. Already fixed and deployed — commit `1175b3c`

These were open when the scans ran and are **closed now**. They are recorded here so the delta is
auditable, and they do **not** appear in the open findings below.

| Original ids | What was fixed |
|---|---|
| `SPC-CODE-001` | `X-Forwarded-Host` is no longer trusted, and `trustHosts` was added (loopback allow-listed so the container `HEALTHCHECK` still passes). This closed the **only Critical** in the sweep — unauthenticated password-reset link poisoning leading to Administrator takeover. |
| `SPC-API-001` · `SPC-CODE-003` · `SPC-TM-001` *(validation half)* | Sign-off endorser ids are now validated against the same population the picker offers — active Residents / Chief Residents for the endorser fields, Consultants for the consultant fields. **The design half of `SPC-TM-001` is NOT fixed and remains open as `SPC-RPT-002`.** |
| `SPC-CODE-004` | `AppSettings` no longer caches decrypted secrets — only ciphertext is cached, decryption is per read. Neither SMTP nor VAPID was ever configured, so nothing was exposed. |
| `SPC-API-006` · `SPC-API-009` · `SPC-TM-014` | `newDay` now calls `assertDayUnlocked` and requires `date_format:Y-m-d`, so rows can no longer land inside a signed day and a handover day can no longer be manufactured for an arbitrary past date. |
| `SPC-TM-008` | Approval is refused unless `email_verified_at` is set; verification now carries across and arms `pass_exp_date`, so the rotation policy actually fires for approved accounts. |
| `SPC-DATABASE-003` | MySQL binary log disabled (`--skip-log-bin`) — removes the rolling 30-day unencrypted copy of every row change. |
| `SPC-DATABASE-009` | `shred()` now overwrites the whole file, and the `.gz` intermediate is shredded too. *(Residual: file mode and the catch-before-finally ordering — `SPC-RPT-062`, Low.)* |
| `SPC-CONTAINER-007` | Test-fixture signature PNGs no longer ship in the image. |

---

## 4. De-duplication — where independent domains agreed

Independent corroboration is signal. Where two or three domains found the same defect from
different angles, that is *stronger* evidence, not duplicate noise. Identity key: normalised file
path + line + weakness class + symbol. On merge the highest severity, the strongest confidence and
the union of refs and source domains were kept.

| Merged as | Source ids | Domains that found it independently |
|---|---|---|
| `SPC-RPT-001` | `SPC-CODE-002`, `SPC-WEB-002`, `SPC-CONTAINER-009` | **code, web, container** — three angles: the throttle key, the deployment header chain, the shared Docker network. |
| `SPC-RPT-002` | `SPC-TM-001` *(design half)*, `SPC-CODE-003`, `SPC-API-001` | **code, api, threat-model** — the validation half is fixed; the design question is not. |
| `SPC-RPT-004` | `SPC-DATABASE-002`, `SPC-CODE-006`, `SPC-TM-003` | **database, code, threat-model** — scored medium / high / critical upstream; re-banded **High** here. |
| `SPC-RPT-006` | `SPC-CONTAINER-001`, `SPC-CONTAINER-002` | container ×2 — root at PID 1, and root executing app-writable PHP at boot. Same fix, one finding. |
| `SPC-RPT-008` | `SPC-DATABASE-004`, `SPC-TM-011`, part of `SPC-CODE-007` | **database, threat-model, code** |
| `SPC-RPT-012` | `SPC-CODE-012`, `SPC-API-005`, `SPC-TM-004` | **code, api, threat-model** — info / medium / high upstream; **Medium** here. |
| `SPC-RPT-013` | `SPC-DATABASE-005`, part of `SPC-CODE-007` | database, code |
| `SPC-RPT-014` | `SPC-CODE-005`, `SPC-API-003` | code, api |
| `SPC-RPT-015` | `SPC-API-002`, `SPC-TM-016` | api, threat-model |
| `SPC-RPT-022` | `SPC-CONTAINER-003`, `SPC-CONTAINER-004` | container ×2 — two one-line compose flags, one review. |
| `SPC-RPT-026` | `SPC-CONTAINER-010`, `SPC-TM-020` | container, threat-model |
| `SPC-RPT-027` | `SPC-CONTAINER-011`, `SPC-SUPPLYCHAIN-004` | container, supply-chain |

Distinct findings that merely share a file were **not** merged — `SPC-RPT-004` (audit chain) and
`SPC-RPT-005` (DB grants) compound each other but are separate defects with separate fixes.

---

## 5. Findings

### 5.1 High (11)

---

#### `SPC-RPT-001` — Forwarded-header trust is a wildcard: the login lockout is bypassable and every audit IP is forgeable
**Aliases** `SPC-CODE-002`, `SPC-WEB-002`, `SPC-CONTAINER-009` · **Domains** code, web, container ·
**CWE** 348, 290, 307, 807, 441 · **CVSS 3.1** 7.5 `AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:L/A:N` ·
**Confidence** firm · **Tier** Immediate

`bootstrap/app.php` sets `trustProxies(at: '*')` and `docker-compose.production.yml:54` hard-codes
`TRUSTED_PROXIES: "*"`. With a wildcard proxy list Symfony trusts the *entire* `X-Forwarded-For`
chain, so `$request->ip()` resolves to the left-most, fully client-supplied entry. Traefik
**appends** rather than overwrites, so the value survives to the app.

Two consequences, both live:

1. **The login lockout is the only brute-force control on `/login`** — `routes/auth.php` deliberately
   omits route-level throttling so the controller can word the message — and it is keyed on
   `member_name + $request->ip()` with `MAX_ATTEMPTS = 5`. Incrementing a spoofed header gives a
   fresh bucket on every request. Effective limit: unlimited. Usernames are semi-public (they ship
   to every Access Control page and every staff picker) and `/register` confirms which ones exist
   (`SPC-RPT-040`). This is a realistic unauthenticated path to a valid clinical session.
2. **Every `audit_log.ip` is attacker-chosen** — for `login`, `logout`, `endorsement_view`,
   `endorsement_print`, `access_denied`, `endorsement_signoff_reopen`, `user_role_change`. The chain
   hash covers `ip`, so the trail verifies as intact while carrying fabricated provenance. That is
   precisely the property PDPL Art. 19 and ECC-1 event-logging rely on.

Compounding it, `app` sits on the **shared external `coolify` network**, so any other container on
that host can reach `app:8080` directly, bypassing Traefik entirely and forging
`X-Forwarded-Proto: https` as well. (The `db` container is correctly on `internal` only — the same
reasoning should now be applied to the app's ingress.)

**Fix.**
```php
// bootstrap/app.php — pin to the actual proxy CIDR, not the world
$middleware->trustProxies(
    at: explode(',', (string) env('TRUSTED_PROXIES', '172.18.0.0/16')),
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```
Find the real subnet with `docker network inspect coolify -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}'`,
set `TRUSTED_PROXIES` to it in Coolify, and narrow `docker/nginx.conf:29-30` to the same CIDR
(dropping the broad `10.0.0.0/8` + `172.16.0.0/12`). Then add a **second, IP-independent limiter**
so a spoofed address can never reset an account's own budget:

```php
$accountKey = 'login-account:'.Str::lower($data['member_name']);
if (RateLimiter::tooManyAttempts($accountKey, 10)) { /* 429 / ValidationException */ }
// on failure: RateLimiter::hit($accountKey, 900);   on success: RateLimiter::clear($accountKey);
```

**After the change, re-run the production smoke test** and confirm the response still carries
`Strict-Transport-Security` and a `secure` session cookie — `isSecure()` staying true is exactly
the regression the wildcard was guarding against.

---

#### `SPC-RPT-002` — A clinician can attach a colleague's stored handwritten signature to a sheet that colleague never approved
**Aliases** `SPC-TM-001` (design half), `SPC-CODE-003`, `SPC-API-001` · **Domains** code, api,
threat-model · **CWE** 345, 602, 862 · **CVSS 3.1** 7.1 `AV:N/AC:L/PR:L/UI:N/S:U/C:L/I:H/A:N` ·
**Confidence** confirmed · **Tier** Immediate (owner ruling) · **Status** OPEN — the validation half
was fixed in `1175b3c`; this is the part that was not

The id-validation gap is closed: submitted endorser ids are now constrained to the same population
the picker offers. What remains is the **design** question, and it is the more important half.

`EndorsementController::updateSignoff` still writes
`$signoff->{$field.'_signature_path'} = $chosen?->signature_path` for `endorsed_by` / `endorsed_to`.
So any holder of `endorsement.edit` can name *another* eligible clinician as the endorser, and that
clinician's genuine handwritten signature image is frozen onto the record and rendered on the
printed A4 by `signatureUrl()`. The named party never authenticates, never consents, and is never
notified. Once `sign_off` is submitted the day is locked and only the separate `endorsement.reopen`
capability can reverse it.

The codebase itself states the stakes — `SignatureController`'s docblock calls a signature "a
forgeable credential and personal data", and `updateSignoff`'s docblock says "this is a medico-legal
record". This is the artefact that becomes evidence at an M&M review.

**Existing mitigations, which are real:** `signed_off_by_user_id` records the actual actor, the print
shows "Signed off *time* by *name*", names and paths are frozen at write time, and the signature
files are content-addressed and immutable. So the *actor* is provable — what is not provable is that
the *named endorser* agreed.

**Fix — owner ruling required.** The technically clean answer:

- Apply a signature image **only when the signing actor is the named endorser**. For any other named
  party, print the typed name plus "named by *actor*" and leave `*_signature_path` null.
- Let the named endorser counter-sign from their own session to upgrade the typed name to their
  signature.
- Notify the named endorser when they are named.

If the ward workflow genuinely requires one clinician to sign on another's behalf (which is a real
clinical pattern), then record that as an explicit, documented ruling in `docs/COMPLIANCE.md` — with
the compensating control that the print already names the true actor — rather than leaving it
implicit. **A signature that any colleague can attach is not a signature**, and an assessor will ask
which of these two positions the system holds.

---

#### `SPC-RPT-003` — The only backup lives on the machine it backs up
**Alias** `SPC-TM-012` · **Domain** threat-model · **CWE** 1188 (availability / recovery) ·
**Confidence** confirmed · **Tier** Immediate · **Status** OPEN — owner-acknowledged

`backup:run` writes its verified, encrypted dump into a Docker volume **on the same OCI VM that
hosts the database**. VM loss, volume corruption, ransomware or an OCI-side incident takes the
clinical record and every one of its backups together. There is no second copy anywhere.

This is on the owner's checklist and is not a new discovery. It is ranked third because it is the
only single event in this report that can end the system, and because the fix does not depend on
anyone else.

**Fix.** Sync each archive to a second **in-Kingdom** location (OCI Object Storage in me-riyadh-1)
with a **retention lock / object-lock** so ransomware cannot delete it. Alert when the newest
archive is older than **26 hours** — a silently-stopped backup is the failure mode that actually
happens. Pair with `SPC-RPT-007`: the archive must include the signature images. Rehearse one
restore per quarter, as `docs/RUNBOOK-BACKUP.md` already requires.

---

#### `SPC-RPT-004` — The audit hash chain is unkeyed and unanchored: it can be rewritten or truncated and still report "chain intact"
**Aliases** `SPC-DATABASE-002`, `SPC-CODE-006`, `SPC-TM-003` · **Domains** database, code,
threat-model · **CWE** 345, 354, 778, 327 · **CVSS 3.1** 6.8 `AV:L/AC:L/PR:H/UI:N/S:U/C:N/I:H/A:N` ·
**Confidence** confirmed · **Tier** Short-term

`AuditLog::record()` computes `hash('sha256', prev_hash . canonical)` where every input is a column
of the same table. `AuditVerify` recomputes with the identical public formula. There is no secret,
no HMAC key, and no value that lives outside the database. Three attacks pass `audit:verify`
cleanly:

1. **Edit and recompute** — alter or delete a row, recompute `prev_hash`/`hash` forward. The formula
   is in this repository, and per `SPC-RPT-005` the application's own credential holds `UPDATE` on
   `audit_log`. No separate database compromise is needed; any app-level compromise suffices.
2. **Tail truncation** — `DELETE FROM audit_log ORDER BY id DESC LIMIT n` leaves a valid *prefix*.
   `AuditVerify` walks from row 1 and stops at the first mismatch, so a truncated chain verifies
   perfectly. Nothing anywhere records the expected row count or head hash.
3. **Wholesale replacement** — rebuild from row 1.

`endorsement_view` and `endorsement_print` rows are the *only* record of who read a child's
handover. An attacker who can reach the app or the database can erase the evidence of their own
access while the integrity checker keeps reporting the trail as intact.

**Re-banding note.** Upstream this was scored Medium (code), High (database) and Critical
(threat-model). **High** is the honest landing: it does not itself grant access or disclose data, so
it is not Critical; but it is trivially reachable from the application's own credential, so it is
more than Medium.

**Fix — three additive changes.**
1. **Key the chain.** Add `AUDIT_HMAC_KEY` (32+ random bytes, owner-managed, **not** `APP_KEY`, never
   stored in the DB) and switch both `AuditLog::record()` and `AuditVerify` to
   `hash_hmac('sha256', $prevHash.$canonical, $key)`. Keep `hash_equals` for the comparison — it is
   already correct. **Do not retro-hash existing rows**: record the cutover row id and have the
   verifier apply the old formula below it. Retro-hashing *is* a chain rewrite and destroys the
   evidentiary value of what you already have.
2. **Anchor the head externally.** Have `audit:verify` emit `rows=<count> head=<hash>` and write that
   line to an append-only sink off the box — minimally folded into the nightly backup record and
   mailed to the owner. Truncation then shows up as a count that went backwards.
3. **Enforce append-only at the engine** — see `SPC-RPT-005`. `REVOKE UPDATE, DELETE ON
   audit_log FROM 'endorse'@'%'` is a one-line change that makes in-place rewriting impossible from
   the app identity *before* the HMAC lands.

Fix `SPC-RPT-057` (timezone-dependent canonicalisation) in the same change — it needs the same
cutover-aware verifier.

---

#### `SPC-RPT-005` — The application's MySQL user holds `ALL PRIVILEGES`: append-only and no-hard-delete are convention, not enforcement
**Alias** `SPC-DATABASE-001` · **Domain** database · **CWE** 269, 732 · **Confidence** firm ·
**Tier** Short-term

The `db` service passes only `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD`. There is no
`docker-entrypoint-initdb.d` mount and no `GRANT` script anywhere in the repository, so the official
MySQL entrypoint runs `GRANT ALL ON <db>.* TO '<user>'@'%'` — including `UPDATE`, `DELETE`, `DROP`,
`ALTER`, `TRUNCATE` on every table, with a `%` host wildcard.

Every guarantee the system advertises about its own data lifecycle is therefore enforced only by
application code that the same credential can bypass: `AuditLog`'s "append-only trail", `Handover`'s
soft deletes, "accounts deactivated, never deleted". The project's own `docs/COMPLIANCE.md:53-56`
already lists this fix as pending and owner-assigned — which is exactly the kind of thing an
assessor finds in five minutes.

**Fix.** Mount an init script *and* run it once as root against the existing volume (the initdb hook
only fires on an empty datadir):

```sql
REVOKE ALL PRIVILEGES ON `endorsement`.* FROM 'endorse'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `endorsement`.* TO 'endorse'@'%';
REVOKE UPDATE, DELETE ON `endorsement`.`audit_log`          FROM 'endorse'@'%';
REVOKE DELETE         ON `endorsement`.`handovers`          FROM 'endorse'@'%';
REVOKE DELETE         ON `endorsement`.`handover_signoffs`  FROM 'endorse'@'%';
REVOKE DELETE         ON `endorsement`.`users`              FROM 'endorse'@'%';
FLUSH PRIVILEGES;
```

Then create a **separate migration credential** holding `CREATE/ALTER/DROP/INDEX/REFERENCES`, used
only by the owner-run `php artisan migrate --force`. The entrypoint deliberately does not migrate,
so this is clean. Note `DataRetention` needs `DELETE` on `sessions`, `login_otps` and
`pending_registrations` — the grant above preserves it.

**Rollback:** capture `SHOW GRANTS` into the runbook first; `GRANT ALL` restores the prior state
instantly with no data change. Exercise on `docker/smoke.sh` before production.

---

#### `SPC-RPT-006` — The container runs as root, and root executes app-writable PHP on every boot
**Aliases** `SPC-CONTAINER-001`, `SPC-CONTAINER-002` · **Domain** container · **CWE** 250, 269, 732,
427 · **CVSS 3.1** 7.8 `AV:L/AC:L/PR:L/UI:R/S:C/C:H/I:H/A:H` · **Confidence** firm ·
**Tier** Short-term

The Dockerfile creates the `app` user (uid 1000) but **never issues `USER`**. Request handling *is*
non-root — the php-fpm pool is pinned to `user = app` and nginx workers drop via `user app;` — but
everything above the workers runs as uid 0: the entrypoint, supervisord (PID 1), the php-fpm master,
the nginx master, and every `php artisan *:cache` at boot. `docker/smoke.sh` asserts "php-fpm user ==
app", which passes on a pool *child* while the master is still root, so the existing test does not
cover this.

The escalation loop is specific: `bootstrap/cache` is `app:app` mode 775 — writable by exactly the
uid that serves HTTP — and Laravel bootstraps by `require`-ing PHP out of that directory
(`PackageManifest` → `bootstrap/cache/packages.php`; `LoadConfiguration` → `bootstrap/cache/config.php`).
An attacker with file write as `app` plants a payload; the entrypoint's `chown` re-owns but does not
validate or remove it; the container restarts (it restarts often and unattended — `restart:
unless-stopped`, three failed healthchecks, an OOM kill, a Coolify redeploy, a host reboot); and
root's `php artisan config:cache` executes the payload as uid 0. The attacker does not even have to
trigger the restart, only wait for one.

From there: the full default capability set, a writable root filesystem, and `no-new-privileges`
unset — nothing between root-in-container and an attempt on the host that also runs coolify-proxy
and every other app.

**Fix — feasibility already verified against this stack** (port 8080 is >1024 so no
`CAP_NET_BIND_SERVICE`; supervisord and nginx pidfiles are already relocated to `/tmp`):

```dockerfile
RUN chown -R app:app /var/lib/nginx /var/log/nginx 2>/dev/null || true
USER 1000:1000
```
Then remove the now-redundant `user app;` from `docker/nginx.conf:1` and `user=app` from
`[program:scheduler]` (a non-root supervisord aborts when it cannot setuid). Add `user: "1000:1000"`
under `app:` in the compose file as belt-and-braces, tighten `chmod -R 750 storage bootstrap/cache`,
and mount `bootstrap/cache` + `storage/framework` as **tmpfs** so a planted payload cannot survive
to the restart that would execute it. Add a `smoke.sh` assertion that PID 1 is not root.

---

#### `SPC-RPT-007` — Signature files are outside the backup: a restore silently weakens every historical attestation
**Alias** `SPC-TM-017` · **Domain** threat-model · **CWE** 459 · **Confidence** confirmed ·
**Tier** Immediate

`backup:run` dumps the **database only**. `handover_signoffs` stores the *path* to the frozen
signature, not the bytes. After a restore onto fresh storage, every signed sheet renders **without
its signatures while still claiming to be signed** — and nothing reports the discrepancy.

This compounds `SPC-RPT-003`: the one recovery path the system has produces a record that is
quietly weaker than the one it replaced, on exactly the artefact this system exists to produce.

**Fix.** Include `storage/app/private/signatures` in the nightly archive; extend
`assertPlausibleDump()` to verify the signature tree is present; and add a check that reports any
`handover_signoffs` row whose `signature_path` no longer resolves. Cheap, and it should ship with
`SPC-RPT-003`.

---

#### `SPC-RPT-008` — A wrong `APP_KEY` renders ciphertext as clinical text, and autosave then destroys the plaintext
**Aliases** `SPC-DATABASE-004`, `SPC-TM-011`, part of `SPC-CODE-007` · **Domains** database,
threat-model, code · **CWE** 311, 390, 636, 778 · **Confidence** firm · **Tier** Short-term
*(and **before** any `APP_KEY` rotation)*

All three casts (`EncryptedString`, `EncryptedDateTime`, `SanitizedHtml`) share
`try { return Crypt::decryptString($value); } catch (\Throwable) { return (string) $value; }`.
The rationale is sound clinical-safety reasoning and is documented: one bad row must not 500 the
whole sheet and hide every other child on the unit. The *silence* is the problem, and it produces a
destructive path:

1. `APP_KEY` is rotated, or a container starts with the wrong key.
2. `decryptString` throws MAC-invalid. The cast hands the clinician the **raw base64 envelope as if
   it were the Problem List** — no error, no log line, no alert. `bootstrap/app.php` suppresses
   `QueryException` detail and nothing else reports it.
3. Autosave is **per-field on blur**. The next edit re-encrypts the displayed envelope, so the stored
   value becomes `Encrypt(old-envelope)` — recoverable only while the **old** key is still held.

There is no `APP_PREVIOUS_KEYS` population and no `phi:reencrypt` command, so key rotation is
currently a cliff. The cast also cannot distinguish "legacy plaintext row" from "wrong key", and it
silently swallows the truncated-ciphertext case (`SPC-RPT-058`).

**Fix — keep the fallback, make it honest.** In a trait shared by all three casts:

```php
$decoded = json_decode(base64_decode($value, true), true);
$looksEncrypted = is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac']);

if ($looksEncrypted) {
    // Key/integrity failure — NOT a legacy plaintext row.
    Log::critical('phi_decrypt_failed', ['table' => $model->getTable(), 'column' => $key, 'id' => $model->getKey()]);
    return '[unreadable — encryption key mismatch, do not edit this field]';   // and refuse to overwrite
}
Log::warning('phi_plaintext_read', [...]);   // PHI-free: ids and column names only
```

Add `php artisan phi:verify-encryption` (scan `handovers.{mrn,patient_name,dob,disease,details,plan,nevent}`
with the same envelope test, report **counts** per column, exit non-zero if any are bare) and
schedule it daily beside `audit:verify`. Add a boot-time canary that decrypts one known row so an
`APP_KEY` problem surfaces at deploy time rather than at a bedside. Populate
`config/app.php` `previous_keys` as part of any rotation.

---

#### `SPC-RPT-009` — No revision history: reopen → rewrite → re-sign leaves the attested content unrecoverable
**Alias** `SPC-TM-002` · **Domain** threat-model · **CWE** 778, 345 · **Confidence** tentative ·
**Tier** Medium-term

`updateRow` audits `row=<id>` only — no before/after, no digest — and no revisions table exists.
After an adverse event, a holder of `endorsement.reopen` can reverse the lock, rewrite the clinical
narrative and re-sign. The trail proves *that* an edit happened; it retains nothing about *what was
originally attested*.

The day lock (`TB6`) is the medico-legal integrity boundary of this system, and it protects the day
but not the memory of what the day said.

**Fix — PHI-free by construction.**
- An append-only `handover_revisions` table using the same encrypted casts.
- **Per-field SHA-256 digests in the audit detail** — a digest is not PHI, so this satisfies the
  no-PHI-in-audit-details rule while making divergence provable.
- A **whole-sheet content hash stamped at sign-off**, so "is this what was signed?" is answerable
  without storing any narrative in the log.

The digest-only version is the cheap 80% and can ship on its own.

---

#### `SPC-RPT-010` — Remember-me outlives the session on shared ward workstations; no step-up re-auth at sign-off or reopen
**Alias** `SPC-TM-006` · **Domain** threat-model · **CWE** 613, 384 · **Confidence** tentative ·
**Tier** Short-term

`Auth::login($user, $remember)` is called with no `setRememberDuration`, so the Laravel recaller
cookie persists far beyond the deliberately-tuned 60-minute idle session — on a **shared nursing
station PC**. Anyone who sits down afterwards is that clinician. Neither `signoff.update` nor
`signoff.reopen` re-proves identity before writing to the medico-legal record.

**Fix.** Set a short explicit remember duration (or disable remember-me entirely for
`endorsement.edit` holders), and require Laravel's `password.confirm` within N minutes before
`signoff.update` and `signoff.reopen`. Pair with `SPC-RPT-041` (idle blur on the census view).

---

#### `SPC-RPT-011` — 2FA is required of administrators but not of the clinicians who write and sign the record
**Alias** `SPC-TM-007` · **Domain** threat-model · **CWE** 308 · **Confidence** tentative ·
**Tier** Short-term

`EnforceTwoFactor::PRIVILEGED` omits `endorsement.edit`. So a Resident whose name and handwritten
signature print on a medico-legal document authenticates with **a password alone** — and, until
`SPC-RPT-001` is fixed, that password can be guessed without limit. The control is calibrated to
*administrative blast radius* rather than *evidentiary weight*, which is the wrong axis for this
system.

**Fix.** Either extend `PRIVILEGED` to `endorsement.edit` with a staged rollout (enrolment grace
window, then enforcement), or — lighter and arguably better targeted — require the second factor
specifically **at sign-off**. Note that email-delivered OTP is a weak second factor here
(`SPC-RPT-038`): prefer TOTP for anyone who signs.

---

### 5.2 Medium (31)

| ID | Aliases · domains | Finding | Fix (short) |
|---|---|---|---|
| `SPC-RPT-012` | `CODE-012`, `API-005`, `TM-004` · code, api, tm | **No per-unit scoping** — `endorsement.view/edit` are global; `resolveUnit()` checks the code is one of four but never compares it to the viewer. A SCBU clinician can read and print every PICU handover back to the earliest legacy record. Every read *and* print is audited, and the clinical limiter caps bulk extraction. | **Owner ruling.** Residents rotate between units, so this is probably intentional — if so, record it in `docs/COMPLIANCE.md` as an accepted minimum-necessary deviation. Otherwise: a `user_units` assignment checked in `resolveUnit()`, or a `endorsement.view_all_units` capability required for any unit other than `preferred_unit_id`. |
| `SPC-RPT-013` | `DATABASE-005`, `CODE-007` · database, code | **`SanitizedHtml` fallback returns unsanitised HTML** into a `v-html` sink. HTMLPurifier runs only in `set()`; the decrypt-failure path returns raw bytes verbatim to `Print.vue:112` and the editor's `innerHTML`. Latent: every current write path goes through the cast (verified in `EndorsementController` and `LegacyImport`), and CSP `script-src 'self'` blocks classic payloads. | One line: `catch (\Throwable) { return RichTextSanitizer::clean((string) $value); }`. A row this cast did not write has never been through the allow-list. Add a regression test writing a raw `<script>` via the query builder and asserting it comes back inert. |
| `SPC-RPT-014` | `CODE-005`, `API-003` · code, api | **Blind SSRF** — `endpoint` is validated as `string\|max:2000` only, then handed to `WebPushSender::sendOneNotification()` from inside the app container, which sits on both the shared and internal networks. Blind (encrypted body, only `isSuccess()` inspected), but 404/410 deletes the row, giving a boolean oracle for internal probing. | `url:https` plus a push-provider host allow-list (`fcm.googleapis.com`, `updates.push.services.mozilla.com`, `*.push.apple.com`, `*.notify.windows.com`); reject RFC1918 / link-local / `169.254.169.254`; add a guard in `WebPushSender` for rows already stored; purge non-allow-listed rows; add `cap:profile.manage` to the push group. |
| `SPC-RPT-015` | `API-002`, `TM-016` · api, tm | **Any `endorsement.view` holder can download any colleague's signature image by user id.** The guard is `viewer !== target && !can('endorsement.view') → 403`; every clinical role holds that capability, so ids 1..N are enumerable. The route is unthrottled and signature reads are not audited. The legitimate on-sheet case is already served by `/signatures/file/{hash}`. | Restrict `show()` to the viewer's own signature; render sheets exclusively via `file/{hash}`; audit signature reads; throttle the route. |
| `SPC-RPT-016` | `WEB-001` · web | **nginx-served responses carry no security headers** — `SecurityHeaders` is Laravel middleware, so static files, `/offline.html` and every nginx 403/404 bypass it. `/offline.html` is a full **HTML document** with no CSP and no anti-framing. `/manifest.webmanifest` is served as `application/octet-stream` with no `nosniff`. Dotfile deny works correctly (`/.env` → 403, no leak). | A shared `docker/security-headers.conf` included at server level **and re-included in every `location` that declares its own `add_header`** (nginx does not inherit). Fix the manifest MIME. Add a deployed-surface assertion: `GET /offline.html` must return `X-Frame-Options: DENY`. |
| `SPC-RPT-017` | `DATABASE-006` · database | **Encrypted PHI has no context binding.** Laravel's `{iv, value, mac}` envelope authenticates the *value* correctly (encrypt-then-MAC, `hash_equals` before decrypt) but nothing binds a ciphertext to its table, column or row. `mrn` and `patient_name` use the same key and cast, so their ciphertexts are interchangeable — copy child A's identifiers onto child B's row and it decrypts cleanly, the MAC verifies, and the sheet shows the wrong child against the wrong bed, narrative and plan. **That is a patient-safety failure mode, not only a confidentiality one.** | Prefix the plaintext with `table|column|` before encrypting and verify after decrypting (no new key, no schema change). Ship tolerant-`get()` first, then tighten. Stronger: AES-256-GCM with the context string as AAD. |
| `SPC-RPT-018` | `DATABASE-007` · database | **Quasi-identifiers left in plaintext** beside the encrypted ones: `bed`, `age`, `ward_unit`. `(unit, handover_date, bed)` identifies one child in one cot on one day; on NICU/SCBU an `age` of "3 days" plus `handover_date` reconstructs the very DOB that `dob` was encrypted to protect. None is used in any `WHERE`, `ORDER BY` or `GROUP BY`. | Widen to `text` (additive), add `EncryptedString` casts, extend `PhiEncryptionAtRestTest` to require all three illegible. Leave `users.member_email` plaintext (unique index + reset routing key). Keep `handover_signoffs.*_name` legible — attestation data. |
| `SPC-RPT-019` | `DATABASE-008` · database | **Backup archive: PBKDF2 at OpenSSL's 10,000-iteration default and AES-256-CBC with no MAC.** `-pbkdf2` is present (good) but `-iter` is absent. OWASP guidance is 600,000. The passphrase is accepted on an emptiness test only. Integrity on restore is `assertPlausibleDump()` looking for two substrings. | `-pbkdf2 -iter 600000 -md sha512` in **both** encrypt and verify; a detached `openssl dgst -sha512 -hmac` beside the archive, verified before decrypting; reject passphrases under 32 chars. **Critical operational note: the iteration count is not stored in the OpenSSL format** — changing `-iter` breaks every prior archive with an error that looks exactly like a wrong passphrase. Encode the parameters in the filename and record the cutover in `docs/RUNBOOK-BACKUP.md`. |
| `SPC-RPT-020` | `DATABASE-010` · database | **Legacy importer upserts users on `member_name`** with `password`, `position` and `active` in the update list — and `admin` is exactly the name `create:admin` and both seeders use. A collision silently replaces a live account's bcrypt hash and role with legacy values, revives soft-deleted accounts and reactivates deliberately-deactivated ones. Fires on every re-run, not just the first. The clinical tables are correctly provenance-keyed; `users` is not. | Add a nullable unique `legacy_member_id`, key the upsert on it, exclude soft-deleted rows, add a pre-flight collision **count** (never names — the command is counts-only by design) that aborts unless `--allow-account-overwrite`, and audit the import (it currently writes no audit row at all while changing credentials). |
| `SPC-RPT-021` | `DATABASE-011` · database | **App→MySQL TLS is neither enforced nor certificate-verified.** No `MYSQL_ATTR_SSL_CA`, no `require_secure_transport`; the backup path passes `--ssl-mode=PREFERRED` / `--ssl-verify-server-cert=0`. **Documented and accepted by the owner** — the compensating controls are real and unusually good (internal-only bridge, no published port, isolation asserted in `docker/smoke.sh`). | If closing it: mount MySQL's auto-generated `ca.pem`, set `MYSQL_ATTR_SSL_CA` (already wired through `config/database.php`), switch the backup to `--ssl-mode=VERIFY_CA`, add TLS options to the entrypoint's PDO probe — **then** turn on `require_secure_transport` last, since that is the flag that can lock the app out. |
| `SPC-RPT-022` | `CONTAINER-003`, `CONTAINER-004` · container | **No `cap_drop`, no `no-new-privileges`** on either service. Because the container also runs as root (`RPT-006`), the default 14 capabilities are *held*: `DAC_OVERRIDE` (bypasses every ownership boundary the image sets up), `SETUID`/`SETGID` (defeats the app/root split), `NET_RAW` (ARP/DNS spoofing on the internal bridge — directly relevant given the unverified DB TLS), `MKNOD`. **Clean result on the higher-severity controls:** no `privileged`, no Docker socket, no host network/PID/IPC, no device passthrough, default seccomp intact. | `cap_drop: [ALL]` on `app`; on `db`, `cap_drop: [ALL]` + `cap_add: [CHOWN, SETUID, SETGID, DAC_OVERRIDE]` (the mysql entrypoint chowns the datadir and drops via gosu). `security_opt: [no-new-privileges:true]` on both. Assert with `docker inspect` in `smoke.sh`. |
| `SPC-RPT-023` | `CONTAINER-005` · container | **Writable container root filesystem** — an attacker at `app` level can drop a webshell in the document root, patch a controller or rewrite the entrypoint, and it persists across restarts. On a clinical system the integrity half matters more than the confidentiality half. The image is already most of the way to read-only (pidfiles in `/tmp`, logs to stdout, sessions/cache/queue in the DB, persistent paths on named volumes). | `read_only: true` plus five tmpfs mounts (`/tmp`, `/var/lib/nginx`, `bootstrap/cache`, `storage/framework`, `storage/logs`). Bonus: it also neutralises the `RPT-006` persistence step. Roll out through `docker/smoke.sh` first — the likely first failure is a path not on the list. |
| `SPC-RPT-024` | `CONTAINER-006` · container | **No memory, CPU or PID limits.** `pm.max_children = 20` × `memory_limit = 256M` ≈ **5 GB** worst case before nginx, the scheduler and the nightly `mysqldump\|gzip\|openssl` pipeline. The host is *not* dedicated — it runs Coolify, coolify-proxy and everything else. The OOM killer does not know the paediatric handover system is the important one. A concurrency burst against `/login` (bcrypt cost 12) is a plausible unauthenticated trigger. **Availability at 07:00 shift change is a clinical safety property.** | Declare `mem_limit`/`cpus`/`pids_limit`/`ulimits` on both services, then make php-fpm fit *inside* the limit (`pm.max_children = 10`) and give MySQL an explicit `--innodb-buffer-pool-size` so it does not autotune against RAM it is not entitled to. The invariant: `max_children × memory_limit < mem_limit`. |
| `SPC-RPT-025` | `CONTAINER-008` · container | **CI never builds or scans the image.** `composer audit` and `npm audit` are correctly gated and run weekly — genuinely good practice — but they only see PHP and JS packages. Nothing ever inspects the Alpine package set, PHP 8.4 itself, `mysql:8.4`, or the Dockerfile. `hadolint` would have flagged `RPT-006` as DL3002 for free on every push. | Add an `image` job: hadolint → `docker build` → `trivy --scanners vuln,secret,misconfig --severity HIGH,CRITICAL --ignore-unfixed` → SARIF upload. Keep it on the existing weekly cron. Expect the first run to fail on pre-existing Alpine HIGHs; triage into a `.trivyignore` with expiry comments rather than lowering the threshold. |
| `SPC-RPT-026` | `CONTAINER-010`, `TM-020` · container, tm | **One container holds the web tier, the scheduler, `APP_KEY` and `BACKUP_PASSPHRASE`.** A web-tier RCE therefore yields the backup passphrase from `/proc/self/environ` **and** the `endorsement-backups` volume — bulk historical PHI, offline, without ever touching MySQL, and quieter than querying it. It also lets the attacker silence `audit:verify`, `backup:run` and `data:retention`; with `stdout_logfile=/dev/stdout` the absence of output is the only signal. | Split the scheduler into its own compose service off the same image, on `internal` only, and move `BACKUP_PASSPHRASE` there. Drop the backups mount from `app` (or make it `:ro`). Add a scheduler heartbeat so suppression is detectable — today a stopped scheduler is completely silent. |
| `SPC-RPT-027` | `CONTAINER-011`, `SUPPLYCHAIN-004` · container, supply-chain | **All four base images float on mutable tags.** `composer:2` is the widest and the highest-leverage — it is the image that resolves the entire PHP dependency tree. Because Coolify rebuilds on the host, two deploys of the *same commit* can produce different images, so an incident cannot reconstruct what was running. | Pin the multi-arch **manifest-list** digest (not a platform digest — the host is arm64), keeping the readable tag in a comment. Pair with Dependabot `package-ecosystem: docker` so pinning does not become staleness. |
| `SPC-RPT-028` | `SUPPLYCHAIN-001` · supply-chain | **Every GitHub Actions step is pinned to a mutable tag** (`actions/checkout@v4`, `shivammathur/setup-php@v2`, `actions/setup-node@v4`). A re-pointed tag executes on the runner with no diff and no lockfile — the tj-actions/changed-files pattern (CVE-2025-30066). `setup-php` is the sharpest edge: third-party, used in both jobs, runs early. | Pin every `uses:` to a full 40-char SHA with the version in a trailing comment; resolve once with `gh api repos/<o>/<r>/commits/<tag> --jq .sha`. Add `zizmor` or `actionlint` to enforce it. **Downgraded from High:** private repo, no secrets in the workflow, no cloud credentials — realistic blast radius is source exfiltration. |
| `SPC-RPT-029` | `SUPPLYCHAIN-002` · supply-chain | **No `permissions:` block** — `GITHUB_TOKEN` scope is inherited from repository settings. If the default is ever "Read and write", every step (including six unpinned actions and 376 packages whose code runs during install/build) holds a token that can push to `main` — the branch Coolify deploys to a live PHI system. *Tentative: the effective default lives in settings, not files.* | `permissions: { contents: read }` at workflow top level; neither job needs more. Confirm with `gh api repos/ahmedsk2/endorsement/actions/permissions/workflow`. |
| `SPC-RPT-030` | `SUPPLYCHAIN-003` · supply-chain | **Six abandoned packages sit in the VAPID signing path** — `fgrosse/phpasn1` (no replacement) and five `web-token/jwt-*` (superseded by `web-token/jwt-library`), all transitive under `minishlink/web-push`. Abandoned **cryptographic and parser** code never gets patched. Operationally worse: bare `composer audit` exits 1 on abandoned packages, so **the CI audit job is red right now** for a reason unrelated to any vulnerability — and a permanently red gate is a gate people learn to delete. | `composer audit --abandoned=report` (advisories still fail the build; abandoned packages print). Then check whether a newer `minishlink/web-push` has migrated; if not, record an accepted-risk entry in `docs/COMPLIANCE.md` naming the six packages, the fact that they execute only in the VAPID path, and a review date. |
| `SPC-RPT-031` | `SUPPLYCHAIN-005` · supply-chain | **Install-time code execution is not actually disabled in the image build.** `.npmrc` sets `ignore-scripts=true` — but the assets stage copies only `package*.json` and `vite.config.js`, so `.npmrc` is never in the build context and `npm ci` runs lifecycle scripts. Separately, `--no-scripts` stops Composer *scripts* but not Composer *plugins*, which execute during resolution regardless. The control the project believes it has is not in force — and the build runs **on the PHI host**. | `COPY package*.json .npmrc vite.config.js ./` (or, harder to lose, `npm ci --ignore-scripts`); add `--no-plugins` to the vendor stage; remove the dead `pestphp/pest-plugin` entry from `allow-plugins`. If the build breaks on `php-http/discovery`, keep it and record the exception rather than reverting silently. |
| `SPC-RPT-032` | `SUPPLYCHAIN-006` · supply-chain | **The production image is built on the production host.** Coolify clones and runs `docker build` on the same VM as the app and the MySQL container holding paediatric PHI, with outbound access to packagist and npmjs. Everything in `RPT-027` and `RPT-031` executes next to the clinical database. There is also **no artefact identity** — nothing is pushed anywhere and no digest is recorded, so "what is running in production" can only be answered by inspecting the host, and rollback *rebuilds* rather than redeploys. | Cheapest meaningful step, no architecture change: after each deploy record the image digest + git SHA in a release log in `docs/`. That alone answers the question PDPL/ECC-1 incident handling asks. Step up when there is appetite: build in Actions, push to private GHCR, switch compose from `build:` to `image: …@sha256:<digest>`. |
| `SPC-RPT-033` | `SUPPLYCHAIN-007` · supply-chain | **Nothing enforces that CI is green before `main` is deployed.** The procedure is "push to main, then click Deploy"; Coolify has no knowledge of the check run, and the audit job is red today. No CODEOWNERS, no branch-protection-as-code, no commit signing. *Tentative — settings are not visible in files.* | Proportionate to a solo maintainer: require status checks (`test` + `audit`) on `main`, block force-push, apply to admins — self-approval is not required so this costs nothing day to day. Enable commit signing. Deploy from a signed tag and record tag + image digest together. **Fix `RPT-030` first** — a required check that always fails will be bypassed. |
| `SPC-RPT-034` | `TM-005` · threat-model | **No detection layer.** The application records exactly the right events — `endorsement_view`, `endorsement_print`, `signoff_reopen`, `access_denied`, `2fa_failed`, `user_role_change` — and **nothing consumes them**. `audit:verify` checks chain integrity, not behaviour. | Do not build a SIEM. Build one rule: **notify the original signer when their sign-off is reversed.** That is the single strongest control against silent post-hoc alteration and it is a few lines. Then an hourly `audit:anomalies` with a handful of explicit thresholds (print volume, out-of-hours access, repeated `access_denied`). |
| `SPC-RPT-035` | `TM-010` · threat-model | **Whole-census printing is unwatermarked, unbounded, and shares one capability with screen reading.** Any `endorsement.view` holder can render the full A4 census for any date and unit, and the printout carries no indication of who produced it. Paper is the exfiltration channel with no downstream technical control. Prints *are* audited. | A `Printed by <name> — <timestamp>` footer (cheapest, highest deterrent value); split `endorsement.print` from `endorsement.view`; volumetric alerting via `RPT-034`. |
| `SPC-RPT-036` | `TM-013` · threat-model | **Single administrator with a login-time second factor and no 2FA reset path.** A lost authenticator with unsaved recovery codes locks the account out entirely; no account can reset another's second factor and no such route exists. Recovery requires container shell + `user:create-admin` — which the owner *does* have, so a break-glass exists. | Warn in the UI while active administrators == 1 and require a second before go-live; add an audited admin-side "reset second factor for user X"; document the break-glass in the runbook. |
| `SPC-RPT-037` | `TM-015` · threat-model | **`reopen_reason` is free text stored unencrypted** — while `reopenSignoff` deliberately refuses to write it into `audit_log` *because* "it is free text and could name a patient", and `bootstrap/app.php` lists `reason` in `dontFlash`. The code treats it as PHI-bearing everywhere except the column it lives in, and it is returned to every `endorsement.view` holder. | Apply the `EncryptedString` cast. Better: a coded reason list plus an optional encrypted free-text note. |
| `SPC-RPT-038` | `TM-018` · threat-model | **The email second factor and the password-reset link land in the same mailbox.** A compromised hospital mailbox yields both factors: reset the password with the emailed link, then read the emailed sign-in code. The channel-independence that makes a second factor a second factor is absent. *(Currently inert — SMTP is unconfigured; this fires the day it is configured.)* | Require **TOTP, not email OTP**, for privileged capabilities and for anyone who signs (`RPT-011`). Notify the address on every reset **and** every second-factor method change. |
| `SPC-RPT-039` | `TM-019` · threat-model | **A Chief Resident can reactivate a Resident an administrator deactivated.** `authorizeTarget` permits any `users.manage_residents` holder to act on any position-4 account, and `setActive` accepts `active=true` as freely as `false`. The model does not distinguish "not yet activated" from "deactivated for cause". | Record `deactivated_by` / `_at` / `_reason`; forbid a scoped manager reversing a full manager's revocation. |
| `SPC-RPT-040` | `TM-021` · threat-model | **`/register` is an unauthenticated write endpoint that also enumerates staff usernames and emails.** Login is enumeration-hardened with a bcrypt timing equaliser; registration is not — `Rule::unique` returns "has already been taken", confirming which staff usernames and hospital addresses exist, at 10/min. Feeds directly into `RPT-001`. **Owner-acknowledged.** | Cloudflare Access or a hospital-IP allow-list, or invitation-only registration. Until then, answer uniformly ("if that account can be created, you will receive an email") and keep the timing flat. |
| `SPC-RPT-041` | `TM-022` · threat-model | **Shoulder-surfing: the 60-minute idle window is a whole shift change.** The full census is displayed on shared screens at nursing stations, with no client-side idle blur, no panic-hide, and `expire_on_close` false. | Idle blur after ~5 minutes; a visible "hide census" control; `SESSION_EXPIRE_ON_CLOSE` on ward devices. Cheap, and clinicians will thank you for the panic-hide. |
| `SPC-RPT-042` | `TM-009` · threat-model | **No account lifecycle.** No `last_login_at` recorded or displayed, no dormancy sweep, no rotation-end date, no periodic access review. Paediatric residents rotate on a schedule; the roster does not. *(Partly mitigated by `1175b3c`: approved accounts now get `pass_exp_date`, so the 3-month expiry finally fires as a backstop.)* | Record and display `last_login_at`; audited auto-deactivation beyond N days idle; capture a rotation-end date at approval; a quarterly access-review export. The export is the artefact an ECC-1 or PDPL assessor asks for. |

---

### 5.3 Low (29)

| ID | Alias · domain | Finding | Fix |
|---|---|---|---|
| `SPC-RPT-043` | `CODE-008` · code | Email-verification route binds an arbitrary `{user}` with no ownership check against the session identity. `signed` means it cannot be forged, only replayed by someone who already has it. | Two lines: `abort(403)` unless `$request->user()?->getKey() === $user->getKey()`. |
| `SPC-RPT-044` | `CODE-009` · code | `CreateAdmin` writes `pass_exp_date = now()->addYear()` while every other write site means "the date the password was set" — so the bootstrap administrator is exempt from rotation for **15 months** vs 3. | `'pass_exp_date' => now()->toDateString()`. |
| `SPC-RPT-045` | `CODE-010` · code | `mail_encryption` accepts `'none'`, permitting plaintext delivery of login OTPs and password-reset links. `mail_host` accepts any string. Admin-gated. | `'in:tls,ssl'` and remove the option from the Settings UI. Consider rejecting RFC1918/loopback mail hosts. |
| `SPC-RPT-046` | `CODE-011` · code | HTMLPurifier's definition cache (`unserialize`d PHP objects) is written to `sys_get_temp_dir()`. *Tentative* — single-tenant container, no second local principal, no gadget chain demonstrated. | Point `Cache.SerializerPath` at `storage_path('framework/htmlpurifier')` with mode 0750, or disable the definition cache entirely (the config is small). |
| `SPC-RPT-047` | `API-004` · api | `institution_id` is written on clinical rows but never used as a query predicate; `units` has no `institution_id` and `code` is globally unique, so `resolveUnit()` is tenant-blind. **Latent — downgraded from Medium: there is one institution.** | Only if a second institution is ever added: `institution_id` on `units`, unique on `(institution_id, code)`, tenancy via a **global Eloquent scope**, never per-query filters. |
| `SPC-RPT-048` | `API-007` · api | `throttle:clinical` covers only the `/endorsement` prefix. `POST /profile/signature` accepts 4 MB and runs `getimagesizefromstring` + `imagecreatefromstring` + `imagepng` with **no rate limit**. | A throttle bucket across the authenticated surface; `throttle:20,1` specifically on the signature upload. |
| `SPC-RPT-049` | `API-008` · api | `GET /endorsement/{unit}` performs a write (`preferred_unit_id`) and records an audit row. `SameSite=Strict` blocks cross-site cookie attachment. | Move the preference write to an explicit PATCH. |
| `SPC-RPT-050` | `API-010` · api | `User $fillable` includes privilege-bearing columns. No reachable over-post exists — every write path uses explicit literal arrays and registration hard-pins `Rule::in([2,3,4])`. Defence in depth only. | Narrow `$fillable`; set `position`/`active`/`password`/`two_factor_*` via `forceFill()` on the admin paths that already own their guards. |
| `SPC-RPT-051` | `API-011` · api | `POST\|DELETE /push/subscriptions` carry `auth` but **no capability gate** — the only routes in the application with neither `cap:` middleware nor an in-controller check. | `cap:profile.manage` on the push group. |
| `SPC-RPT-052` | `WEB-003` · web | Session and CSRF cookies do not use the `__Host-` prefix **despite meeting every requirement already** (Secure, `Path=/`, no `Domain`). A sibling `*.towardpcc.com` host could inject a same-named cookie. Amplified because HSTS is not preloaded. | `SESSION_COOKIE=__Host-endorsement-session`; `protected $cookieName = '__Host-XSRF-TOKEN'`. `SESSION_DOMAIN` **must stay unset** or the browser silently rejects the cookie — which looks like total login failure. Renaming invalidates live sessions: deploy in a quiet window. |
| `SPC-RPT-053` | `WEB-004` · web | `Cross-Origin-Embedder-Policy` absent, so the origin never reaches `crossOriginIsolated`. COOP and CORP are both set. | **Order matters:** fix `RPT-016` first — `/build` assets currently carry no CORP, so enabling `require-corp` before that would break every bundle. Then report-only, then enforce. |
| `SPC-RPT-054` | `WEB-005` · web | TLS 1.2 accepts `ECDHE-RSA-AES128-SHA` (CBC + HMAC-SHA1, Lucky13 family). Forward secrecy is retained and the default handshake selects TLS 1.3 AES-128-GCM; reachable only by a client offering no AEAD suite. TLS 1.0/1.1 are genuinely refused **server-side** (verified with `-cipher ALL:@SECLEVEL=0`, so not a client-side false negative). | Restrict the TLS 1.2 suite list to AEAD only in Traefik's TLS options. Check the oldest ward browser still connects, then verify with testssl.sh or SSL Labs. |
| `SPC-RPT-055` | `WEB-006` · web | HSTS is correctly present, correctly gated on `isSecure()`, and carries `includeSubDomains` — but is not preloaded and uses 1-year `max-age`. A cold first contact is SSL-strippable. | Raise to `63072000` and stop there unless every `towardpcc.com` subdomain (including internal and staging) is provably HTTPS-only — **`preload` + `includeSubDomains` is effectively irreversible for months.** |
| `SPC-RPT-056` | `DATABASE-012` · database | `audit_log` has no retention bound and stores `user_id` + `ip` indefinitely. Two opposite pressures meet and neither is answered in writing: ECC-1 requires ≥12 months; PDPL Art. 18 / GDPR Art. 5(1)(e) require not-longer-than-necessary. It is also the highest-volume table (every read is audited). | Write the schedule down first — 24 months hot clears the ECC-1 floor with margin. Then disposal that does not weaken `RPT-004`: export the segment (id range, count, head hash) to an encrypted off-box archive, record a retained anchor row, then delete; have `audit:verify` start from the anchor. **Keep it a separate command** — do not add it to `data:retention`. |
| `SPC-RPT-057` | `DATABASE-013` · database | Audit canonicalisation uses `toIso8601String()`, whose offset depends on `app.timezone`. `config/app.php` hardcodes `UTC` and **ignores** the `APP_TIMEZONE: Asia/Riyadh` set in the compose file — consistent today only because the env var has no effect. The day anyone reconciles that, every historical row re-canonicalises and hourly `audit:verify` fires `Log::critical` **for the whole table**. A loud, wrong alarm indistinguishable from the real incident it exists to announce. | `->utc()->format('Y-m-d\TH:i:s\Z')` in **both** `AuditLog::record` and `AuditVerify`, with the same cutover-row-id technique as `RPT-004`. Add a test that changes `app.timezone` between write and verify. Then resolve `APP_TIMEZONE` explicitly — delete the ineffective line or honour it, but do not leave a variable that looks effective and is not. |
| `SPC-RPT-058` | `DATABASE-014` · database | The four encrypted rich-text columns were not widened with the others — MySQL `TEXT` caps plaintext near 46 KB after the ~1.4× base64 envelope. `strict => true` turns overflow into a visible error today, and the purifier allows no `img`, so no data-URI inflation. The risk is the failure mode **if strict mode is ever absent**: truncated ciphertext → MAC failure → `RPT-008` renders base64 as the Plan of Care. | `mediumText()` on `disease`, `details`, `plan`, `nevent` (additive). Keep `strict => true`. Write `down()` to check `MAX(LENGTH())` and abort rather than truncate a clinical field. |
| `SPC-RPT-059` | `DATABASE-015` · database | Demo/E2E seeder guards are `APP_ENV`-only. Correct for the shipped stack — but a staging or DR-rehearsal instance restored from **production data** runs with `APP_ENV=staging` and would accept `db:seed --class=DemoSeeder`, creating a position-0 administrator whose password is published in the repo. Restore rehearsals are exactly when someone seeds "to get a login". | A data-shaped second gate that does not depend on `APP_ENV` (abort if imported/real handover rows exist), plus an explicit `ALLOW_DEMO_SEED` opt-in. Document that restore rehearsals set `APP_ENV=production`. |
| `SPC-RPT-060` | `DATABASE-016` · database | Operational tables sit outside the encryption boundary: `cache.value`, `jobs.payload`, `failed_jobs.payload/exception` are plaintext, and `failed_jobs` is **never pruned**. Sessions are correctly encrypted and pruned. PHP traces embed truncated scalar arguments, so an exception in a method taking a patient identifier persists a fragment. *Tentative — no PHI-carrying job identified; push payloads are unit + date + status only.* | Schedule `queue:prune-failed --hours=168` and a cache prune. Add a project rule beside the existing no-PHI rule: **no clinical string may be a job constructor argument or an exception message** — pass ids and re-read through the model, which is already the pattern everywhere else. |
| `SPC-RPT-061` | `DATABASE-017` · database | The legacy importer skips nurses (`position ?? 1 === 1 → continue`) and then, eleven lines later, writes `position => $r->position === null ? 1 : …` — encoding the **retired** position 1 as the fallback, against the explicit CLAUDE.md ruling. Currently unreachable because of the guard above it. Latent role-assignment fallbacks are how retired roles come back. | Skip outright if the legacy position is null or not in `{0,2,3,4,5}` and count it as an expected divergence via the existing `recordCount()`. If such rows must import, land them **inactive** at the lowest live role and report the count. |
| `SPC-RPT-062` | `DATABASE-009` (residual) · database | Residual of a mostly-fixed item. `shred()` now covers the whole file and the `.gz` is shredded (`1175b3c`). Still open: `File::ensureDirectoryExists` creates `storage/backups` at 0755 and `--result-file` writes the dump at 0644 under the default umask (world-readable **inside the container** for its lifetime); and `handle()`'s `catch` still `@unlink`s the plaintext before `finally` tests `is_file()`, so the **failure path** deletes without overwriting. Overwrite-in-place is also unreliable on a COW overlay and SSD-backed volume. | The real fix is to **never write plaintext to disk**: stream `mysqldump \| gzip -9 \| openssl enc …` via `Process::fromShellCommandline()` with `set -o pipefail`. Otherwise: `umask(0077)`, `ensureDirectoryExists($dir, 0700)`, and remove the `@unlink` from the `catch` so `finally` always shreds. |
| `SPC-RPT-063` | `CONTAINER-012` · container | `.build-deps` (gcc, g++, make, autoconf, seven `-dev` packages) is installed in one `RUN` and deleted in a **different** one, so the whole toolchain persists in the earlier layer's tarball. Runtime impact is nil (the union FS does not contain it, and `apk del` removed it from the package database), but 150–250 MB ships forever and a layer-level scan will report CVEs in packages you believe you removed. Also: `2>/dev/null \|\| <retry>` exists to work around an `oniguromo-dev` typo and masks any other apk failure. | Collapse install + compile + `apk del` into one `RUN`; fix the typo and drop the fallback. Keep the `mariadb-connector-c` comment block — that explanation is load-bearing. |
| `SPC-RPT-064` | `CONTAINER-013` · container | 14 apk packages installed with no version constraint, so two rebuilds of the same commit can differ. Cuts both ways — unpinned means rebuilds pick up patches, which with no image-scan gate is currently doing useful work. | **Sequence matters: land `RPT-025` (trivy gate) and `RPT-027` (digest pins) first**, then pin apk versions. If a Dependabot/Renovate refresh loop is not going to exist, **leave these unpinned** — unpinned-plus-frequent-rebuild beats pinned-and-forgotten. Record the decision as a Dockerfile comment either way. |
| `SPC-RPT-065` | `CONTAINER-014` · container | supervisord's default `stopsignal` is `TERM`, which for php-fpm and nginx means *immediate* shutdown; `QUIT` is the graceful drain. So every redeploy, healthcheck restart or OOM restart severs in-flight requests. That collides directly with the project's autosave contract — per-field save-on-blur where the UI must reflect the **server** response. The scheduler is worse: it is a `sh -c` loop, so without `stopasgroup`/`killasgroup` a running `backup:run` is orphaned mid-`mysqldump`, leaving a truncated `.enc` that still looks like a successful backup on disk. PID 1 handling is otherwise correct (exec-form ENTRYPOINT, `exec "$@"`, supervisord reaps). | `stopsignal=QUIT`, `stopwaitsecs=30`, `priority=10/20` on php-fpm/nginx (supervisord stops in reverse priority, so nginx drains first); `stopasgroup=true killasgroup=true stopwaitsecs=60` on the scheduler; `stop_grace_period: 45s` in compose — it **must** exceed the largest `stopwaitsecs`. |
| `SPC-RPT-066` | `CONTAINER-015` · container | All five secrets arrive as plain environment variables — readable at `/proc/<pid>/environ` by any process with the same uid, inherited by every forked child, visible in `docker inspect` and in Coolify's own store. So any code execution as `app` yields `APP_KEY` (which decrypts the PHI columns), `DB_PASSWORD` and `BACKUP_PASSPHRASE` in one read. **The image itself is clean** — no secret in any ENV, ARG or RUN layer, and `.env*` excluded. | Narrow by need-to-know first (`RPT-026` moves `BACKUP_PASSPHRASE` out of the web tier). Then prefer the `_FILE` convention where Coolify allows it — `/run/secrets` is tmpfs, mode 0400, and does not appear in `/proc/*/environ`. Document a rotation runbook; note that rotating `APP_KEY` requires re-encrypting the PHI columns and is **not** a drop-in change. |
| `SPC-RPT-067` | `CONTAINER-016` · container | No `logging:` limits, so the default json-file driver accumulates without bound on the **same boot volume** as the OS, Coolify, the MySQL data volume and the backups. A full disk stops MySQL writes and the nightly backup. The scheduler loop emits a line every 60 s forever even with zero traffic. Log content is correctly PHI-scrubbed (`clean` format logs `$uri` not `$request`). *Tentative — the host daemon may already set a global default.* | Confirm with `docker info` / `/etc/docker/daemon.json`, then declare `max-size: 10m, max-file: 3, compress: true` on both services explicitly — it survives a host rebuild. Filter the scheduler's routine no-op line. |
| `SPC-RPT-068` | `SUPPLYCHAIN-008` · supply-chain | No SBOM, no artifact signing, no SLSA provenance. **Honest priority:** signing and provenance buy little today — there is no artefact to sign, it never leaves the host, and there are no external consumers. **The SBOM is the piece with real near-term value**: it answers "is this clinical system affected by the advisory published this morning" in minutes. | Add `anchore/sbom-action` (cyclonedx-json) to the audit job and retain one SBOM per release alongside the git SHA and image digest. Defer cosign/SLSA until the image is built in CI and pushed to a registry — and **record that deferral with a date** in `docs/COMPLIANCE.md`. A written risk acceptance is worth more to an assessor than an unused signature. |
| `SPC-RPT-069` | `SUPPLYCHAIN-009` · supply-chain | No Dependabot or Renovate. **Owner-acknowledged.** The compensating control is real and unusually well designed: `ci.yml` runs on a weekly cron *specifically* so the audits re-evaluate an untouched codebase against fresh advisory data. What is missing is remediation, not detection. | `.github/dependabot.yml` with `github-actions`, `composer`, `npm` and `docker` ecosystems, weekly. The `github-actions` and `docker` entries are what make `RPT-028` and `RPT-027` **sustainable** — Dependabot bumps a pinned SHA or digest and puts the readable version in the PR title. |
| `SPC-RPT-070` | `SUPPLYCHAIN-010` · supply-chain | Six high advisories (`brace-expansion` DoS chain, GHSA-mh99-v99m-4gvg) exist in the **dev-only** npm tree and are excluded from the gate by `--omit=dev`. Production is clean and the node toolchain never ships. npm's only suggested fix is a semver-major **downgrade**. The real issue is the blind spot: the gate stays green through any future dev-tree advisory, including a compromised build-time package (vite, a vitest plugin, `laravel-vite-plugin`) that **can inject into the assets you ship**. | Keep the blocking gate on production, add a second non-blocking `npm audit --audit-level=high \|\| true` so the dev tree is visible in the log. **Do not** downgrade `@vue/test-utils` to silence it. |
| `SPC-RPT-071` | `TM-023` · threat-model | Availability at shift change rests on a single VM, a single container and a DNS-only (grey-cloud) origin, so the origin IP is exposed with no WAF. **Genuine mitigating control: handover reverts to the pre-existing paper process** — a real and safe fallback, which is why this is Low. | Switch Cloudflare to Proxied with Full (strict); uptime monitoring that alerts on a missed 07:30 window; write the paper fallback and catch-up procedure down so it is a procedure and not an improvisation. |

---

### 5.4 Informational (17)

`SPC-RPT-072` `WEB-000` HTTP posture scorecard: **Grade A−**, held below A only by `RPT-016` and
`RPT-001`. · `SPC-RPT-073` `WEB-007` No OCSP stapling (Let's Encrypt has retired OCSP — recorded for
completeness). **The real item is operational: a 90-day leaf on a clinical system means an ACME
failure is a total outage within three months — monitor expiry at 21 days remaining.** ·
`SPC-RPT-074` `WEB-008` CSP has no `report-to`/`report-uri` and no Trusted Types; a first-party,
PHI-free report collector would give a genuine detective signal. · `SPC-RPT-075` `WEB-009` Duplicate
`Cache-Control` on `/build` (effective behaviour correct; hygiene only). · `SPC-RPT-076` `WEB-010`
Request smuggling assessed **low** — h2 on the edge hop, strict Go/nginx parsers, and crucially
`fastcgi_pass` not `proxy_pass`, so HTTP framing ambiguity cannot propagate to the app tier at all.
*No payloads were sent.* · `SPC-RPT-077` `WEB-011` **SRI correctly not applicable** — zero
third-party origins, all assets content-hashed Vite output, `script-src 'self'`. · `SPC-RPT-078`
`WEB-012` **Cache-deception resistance confirmed** — the `no-store` predicate has a *path* arm, not
just an auth arm, so even unauthenticated 404s under `/endorsement` are non-cacheable. Treat that
line as security-critical. · `SPC-RPT-079` `WEB-013` Header information disclosure minimal — no
version banner, no `X-Powered-By` (suppressed at two independent layers). · `SPC-RPT-080`
`DATABASE-018` **No SQL injection surface** — one `selectRaw` with a constant string; everything else
Eloquent/bound, including the legacy importer. · `SPC-RPT-081` `DATABASE-019` Controls verified
correct — do not regress (see §6). · `SPC-RPT-082` `CONTAINER-017` Healthcheck is shallow: `/up`
reports healthy while MySQL is unreachable, the disk is full, or the scheduler has been dead a week.
· `SPC-RPT-083` `CONTAINER-018` **OS-package and image CVE posture is UNKNOWN** — no scanner
available and the image is not built here. This is a coverage gap, not a clean result. ·
`SPC-RPT-084` `SUPPLYCHAIN-011` Deploy identity well chosen (dedicated ed25519 **read-only,
single-repo** deploy key — stronger than a PAT or a GitHub App for this purpose); gap is lifecycle:
no recorded fingerprint, no rotation trigger, no revocation procedure. · `SPC-RPT-085`
`SUPPLYCHAIN-012` Six high-frequency supply-chain failure modes verified **absent** (see §6). ·
`SPC-RPT-086` Dependency CVE posture: `composer audit` **0 advisories / 122 packages**; `npm audit
--omit=dev` **0 vulnerabilities / 73 production packages**. Closes the `SPC-CODE-013` coverage gap. ·
`SPC-RPT-087` **SMTP and VAPID are unconfigured** (owner-acknowledged) — so email-OTP, password-reset
mail and web push are all currently inert. This *reduces* live exposure for `RPT-014`, `RPT-038` and
`RPT-045`, and *increases* the urgency of fixing them **before** those features are switched on. ·
`SPC-RPT-088` **PDPL governance paperwork outstanding** (owner-acknowledged) — RoPA, DPIA, privacy
notice, breach-notification procedure, data-subject-rights procedure. This is the dominant term in
the PDPL coverage figure in §9.

---

## 6. What is genuinely strong

This is not a courtesy section. This codebase carries real, unusual security investment for a
single-maintainer clinical application, and several controls are better than what large vendor
systems ship. Naming them precisely matters, because **the fastest way to regress them is not to
know they are there.**

**Audit and accountability — the standout.**
- **PHI-free audit details are enforced at the exception handler, not by convention.**
  `QueryException` reporting is reduced to SQLSTATE plus `file:line` with an explicit written
  rationale; bindings are suppressed; `dontFlash` covers `mrn`, `patient_name`, `dob`, `age`,
  `ward_unit`, `disease`, `details`, `plan`, `nevent`, `reason`, `signature_data` and `code`. All
  ~40 `AuditLog::record()` call sites pass ids, capability keys and counts only. The `reopen_reason`
  is *deliberately* never logged — with a comment explaining why. Even the nginx `access_log` format
  drops query strings as a second line of defence, and MySQL runs `--general-log=0` with the reason
  stated in the compose file.
- **Reads are audited, not just writes.** `endorsement_view` **and** a separate `endorsement_print`
  event. Most clinical systems audit writes and call it done; auditing who *read* a child's handover
  is the harder and more useful half, and it is what makes an insider-access investigation possible
  at all.
- **The audit log is hash-chained and the verifier is actually scheduled** — hourly, with a
  `Log::critical` escalation. `hash_equals` is used for the comparison. The chain's weaknesses
  (`RPT-004`, `RPT-057`) are real, but the *existence* of a scheduled, escalating integrity check on
  a self-hosted single-VM app is genuinely uncommon.

**Cryptography and data protection.**
- **Column-level encryption of PHI is correct, not cargo-culted.** Laravel's `Crypt` is AES-256-CBC
  with a random per-value IV and HMAC-SHA256 over `iv‖ciphertext`, verified with `hash_equals`
  **before** decryption — a proper encrypt-then-MAC construction. The searchability trade-off was
  assessed rather than stumbled into: random IVs make `mrn`/`patient_name`/`dob` non-searchable, and
  nothing in the codebase searches or sorts them. The widen-for-encryption migration carries the
  reasoning in the file: a birth date "is a DIRECT IDENTIFIER — with a name it re-identifies a child
  on its own". `PhiEncryptionAtRestTest` reads the **raw columns** to prove illegibility.
- **Session hardening exceeds framework defaults**: `encrypt=true`, `same_site=strict` (not the Lax
  default), `http_only`, `secure` in production, a 60-minute lifetime chosen *for shared ward
  workstations*, and `serialization=json` — which closes the PHP object-injection gadget path
  entirely.
- **Backups are self-verifying.** `backup:run` decrypts, gunzips and runs `assertPlausibleDump()` on
  every archive before calling the run a success. A backup that has never been proven restorable is
  not a backup, and most systems this size never check.

**Authentication.**
- Password handling is exemplary: bcrypt via the `hashed` cast at `BCRYPT_ROUNDS=12`, a
  **constant-time timing equaliser** to defeat username enumeration on login, current-password
  required for both change paths, password reuse rejected, and **every other session for the account
  deleted** on change and on reset.
- 2FA is strong where it applies: TOTP replay protection via a per-user code fingerprint with a 120 s
  TTL, `hash_equals` for recovery codes, a session attempt budget **plus** a persistent per-user
  `RateLimiter` that survives cookie-clearing, and OTPs stored bcrypt-hashed with single-use
  deletion.
- **`EnsureAccountActive` re-checks `active` on every authenticated request and is registered
  *before* `HandleInertiaRequests`** — so a revoked account never even receives its auth props.
  Per-request revocation is a gap in the reference implementation this project was cloned from, and
  it was closed here deliberately.

**Authorization.**
- The **deny-wins capability resolver** applies grants then denies in two explicit passes, resolves
  unknown keys to *denied*, and uses a **generation counter** to invalidate per-user entries without
  a global `Cache::flush()` that would also reset the login rate limiters. That last detail is the
  kind of thing people get wrong for years.
- **Chief Resident containment holds** — position 5 seeds to a narrow set, and `authorizeTarget()`
  403s **and audits** on any non-position-4 target.
- **Route ordering is deliberate and correct**: literal sub-routes declared before the `{unit}` and
  `{date}` wildcards, and `{date}` regex-pinned to `Y-m-d`, so no literal path segment can bind as a
  unit code or a date.

**Signatures.**
- **Content-addressed, immutable signature storage.** Upload does magic-byte validation via
  `getimagesizefromstring`, a full **GD decode/re-encode round trip** that strips EXIF and defeats
  polyglots, dimension and byte caps, a SHA-256 content-addressed filename, and storage on a
  **private** disk. The hash route parameter is validated against a strict 64-char lowercase hex
  regex **before** it reaches any filesystem path, and `SignatureStore::read` additionally requires
  the `signatures/` prefix. No traversal is reachable. `SignatureController` fails closed.

**Injection and code execution.**
- **Zero raw-SQL sinks** — no `DB::raw`, `whereRaw`, `havingRaw`, `orderByRaw`, `DB::statement` or
  `unprepared` anywhere. **Zero PHP code-execution sinks** — no `eval`, `exec`, `system`, `passthru`,
  `proc_open`, `shell_exec` or `unserialize` in application code. `BackupRun` uses `symfony/process`
  with **array** arguments (no shell) and passes the DB password via `MYSQL_PWD` in the environment
  rather than argv, **with an explicit comment about `/proc` and `ps` exposure**. That is someone
  who has thought about this properly.
- **No committed secrets.** `.env` is untracked and was never committed (`git log --diff-filter=A`
  confirms); provider-prefix scans across AWS, GitHub, GitLab, Slack, Stripe, Google, SendGrid, npm
  and PEM returned zero hits; `docker-compose.production.yml` sources every credential from `${…}`
  with an explicit "No secret is ever written into this file" comment. Even `docker/smoke.sh`
  generates throwaway credentials into a `mktemp` file under `umask 077` with a cleanup trap.

**Deployment hygiene.**
- The **db container is on `internal` only**, publishes no host port, and `docker/smoke.sh`
  **actively asserts** both — including the subtle point (with a comment) that you must test for a
  host *binding* rather than for `EXPOSE`d ports, because the mysql image exposes 3306 regardless.
  That is a better-than-typical control.
- **Migrations are deliberately not run at boot**, so a restart cannot alter the clinical schema, and
  the entrypoint **refuses to start without `APP_KEY`** rather than serving ciphertext.
- The **service worker is correctly scoped**: navigations are network-first, only `/build/` and
  `/icons/` are cached, and nothing under `/endorsement` is ever written to a cache — matching the
  server's `no-store` headers.
- **CSRF is intact** with no `except` list anywhere; every state change is POST/PATCH/PUT/DELETE.
- **Supply chain: six high-frequency failure modes verified absent** — both lockfiles committed with
  full integrity data (254/254 npm entries have `integrity`; 122/122 composer entries carry a dist
  commit reference), frozen installs everywhere (`npm ci` / `composer install`, never `npm install` /
  `composer update`), no dependency-confusion exposure (no internal namespace to shadow), no
  typosquats (the one suspicious name, `laravel/pao`, resolves to the official Laravel org), **no
  `${{ }}` expression in any `run:` block** (no pipeline injection), no `pull_request_target` /
  `workflow_run` trigger, no self-hosted runner, and **zero `secrets.` references in the workflow**.
- **`docs/COMPLIANCE.md` names its own gaps and assigns them to the owner.** Several findings in this
  report were already written down there before any scanner ran. That is the single best predictor
  of a system that actually gets fixed.

---

## 7. Coverage gaps inside the domains that were assessed

Stated so the report's silence is not mistaken for assurance.

- **No SAST taint proof.** Every data-flow claim was traced by hand (no semgrep/CodeQL).
- **Git history was never swept for secrets** — only the current tracked tree was pattern-scanned.
- **No image scan of any kind** — OS packages, PHP runtime, compiled extensions, `mysql:8.4`, and
  base-image EOL status are all **unknown**, not clean (`SPC-RPT-083`).
- **Authenticated HTTP behaviour was not observed.** The web audit used no credentials, so post-login
  header coverage was inferred from `SecurityHeaders.php`. The anonymous boundary *is* separately
  confirmed by `scripts/verify-live.sh` (302 to `/login` for all 21 guarded routes, including all
  four print sheets).
- **No database connection was made.** Effective grants, `log_bin`, `require_secure_transport`,
  `Ssl_cipher`, the MySQL patch level and the contents of `failed_jobs` are all inferred from
  committed artefacts — configuration *intent*, not runtime reality.
- **Repository settings are invisible to file analysis** — `GITHUB_TOKEN` default permissions, branch
  protection, required signatures and the deploy key's `read_only` flag (`RPT-029`, `RPT-033`,
  `RPT-084`). Each names the `gh api` call that confirms it.
- **Coolify host configuration** (build pack, webhook state, environment scoping, network topology)
  was read from `docs/RUNBOOK-DEPLOY.md`, not from the live instance. This overlaps the
  **not-assessed IaC domain**.
- **Whether another container currently shares the `coolify` network** with `app:8080` — requires
  host-side `docker network inspect`. This is the one fact that would move `RPT-001` from firm to
  confirmed.
- **Runtime container reality**: actual process uids, live seccomp/AppArmor enforcement, and drift
  between the declared compose spec and what Coolify actually deployed.

---

## 8. Remediation tiers

Rationale is given per tier, not just a bucket. Every item is exercised through
`docker/smoke.sh` or a staging stack before it reaches the live system; production migrations and
live-DB changes stay owner-run.

### Immediate — 0–7 days
*Rationale: reachable now with no privilege, or a single event away from total loss.*

| ID | Action |
|---|---|
| `RPT-001` | Replace `TRUSTED_PROXIES: "*"` with the actual Coolify proxy CIDR in both the compose env and `docker/nginx.conf`; add the IP-independent per-account login limiter. **Re-run the production smoke test afterwards** and confirm HSTS and the `secure` cookie are still emitted. |
| `RPT-003` | Get one encrypted archive off the host tonight — OCI Object Storage in-Kingdom with a retention lock. Add the "newest archive older than 26 h" alert. |
| `RPT-007` | Include `storage/app/private/signatures` in the nightly archive and extend `assertPlausibleDump()`. Ship with `RPT-003` — a backup that restores sheets without their signatures is not a backup of the attestation. |
| `RPT-002` | **Owner decision, not code.** Decide whether one clinician may apply another's signature. Whichever way it goes, write it into `docs/COMPLIANCE.md`. If the answer is no, the code change is small. |

### Short-term — 1–4 weeks
*Rationale: high impact, exploitable with effort or from a foothold; each is a bounded change.*

`RPT-004` key the audit chain with `AUDIT_HMAC_KEY` + anchor the head off-box (fix `RPT-057` in the
same change) · `RPT-005` least-privilege MySQL grants, `REVOKE UPDATE, DELETE ON audit_log` first —
it is one line and it partially closes `RPT-004` before the HMAC lands · `RPT-006` `USER 1000:1000`
plus tmpfs `bootstrap/cache` · `RPT-022` `cap_drop: [ALL]` + `no-new-privileges` (two lines each, no
behavioural risk) · `RPT-008` envelope-shape detection so a wrong `APP_KEY` fails closed instead of
destroying plaintext — **this must land before any `APP_KEY` rotation** · `RPT-010` short remember-me
+ `password.confirm` at sign-off and reopen · `RPT-011` second factor for the clinicians who sign ·
`RPT-013` sanitise on the fallback path (one line) · `RPT-014` push-endpoint allow-list · `RPT-015`
signature endpoint scoped to self · `RPT-016` nginx header include · `RPT-024` container resource
limits · `RPT-030` `--abandoned=report` so the CI gate stops crying wolf · `RPT-034` notify the
original signer when a sign-off is reversed — **the single highest-value line in this report** ·
`RPT-040` close or gate `/register` · `RPT-051` `cap:` gate on the push routes.

### Medium-term — 1–3 months
*Rationale: conditional, latent, or requiring a design decision or a migration window.*

`RPT-009` revision digests at sign-off · `RPT-012` owner ruling on unit scoping, then record or
implement · `RPT-017` context-bound ciphertext (two-step tolerant rollout) · `RPT-018` encrypt `bed`,
`age`, `ward_unit` · `RPT-019` PBKDF2 600k + detached HMAC (**record the cutover — old archives will
not open with new flags**) · `RPT-020` provenance-keyed user import, before the legacy cutover ·
`RPT-023` read-only root + tmpfs · `RPT-025` trivy + hadolint CI job · `RPT-026` split the scheduler
container · `RPT-027`/`RPT-028` digest and SHA pinning, **with** `RPT-069` Dependabot so pinning does
not become rot · `RPT-029`/`RPT-033` CI permissions and branch protection · `RPT-031` real
`ignore-scripts` in the build · `RPT-035` printed-by footer · `RPT-036` second administrator +
audited 2FA reset · `RPT-037` encrypt `reopen_reason` · `RPT-041` idle blur and panic-hide ·
`RPT-042` `last_login_at` + dormancy sweep + quarterly access-review export · `RPT-052` `__Host-`
cookie prefixes (quiet window — it invalidates live sessions) · `RPT-056` audit retention policy,
written before implemented · `RPT-058` widen the rich-text columns · `RPT-062` stream the backup so
no plaintext ever hits disk.

### Long-term — 3–6 months
*Rationale: hardening, defence in depth, and accepted risks that want documenting rather than fixing.*

`RPT-021` enforced+verified app→db TLS (**accepted today** — deploy client CA first, `require_secure_transport`
last) · `RPT-032` move the build off the PHI host to CI + private GHCR · `RPT-038` TOTP-only for
privileged and signing roles · `RPT-039` deactivation provenance · `RPT-043`–`RPT-050`,
`RPT-053`–`RPT-055`, `RPT-059`–`RPT-061`, `RPT-063`–`RPT-067`, `RPT-070`, `RPT-071` — the remaining
Low items, batched · `RPT-068` SBOM per release now, cosign/SLSA deferred **with a dated written
acceptance** · `RPT-047` tenant scoping **only if a second institution is ever added** · `RPT-084`
deploy-key fingerprint, rotation trigger and revocation procedure in the runbook · `RPT-088` PDPL
governance pack — RoPA, DPIA, privacy notice, breach-notification and data-subject-rights procedures.

---

## 9. Compliance coverage

**Read this correctly.** Coverage % = *requirements this sweep produced at least one check for* ÷
*total requirements*. It is **check coverage**, never a pass rate and never a compliance claim. A
100% row means "we looked at every category", not "every category is satisfied" — the open findings
above are precisely the places where a checked requirement is **not** met.

| Framework | Unit | Coverage | Checked | Not assessed |
|---|---|---|---|---|
| **OWASP Top 10:2021** | 10 categories | **100%** | 10/10 | — (A06 is *partial*: application dependencies covered, container OS layer not) |
| **OWASP Top 10:2025** | 10 categories | **100%** | 10/10 | — |
| **OWASP API Top 10:2023** | 10 categories | **100%** | 10/10 | — (API9 Inventory is *partial*: no versioned API surface or inventory document exists) |
| **OWASP ASVS 5.0** | 17 chapters | **82%** | 14/17 | V9 Self-contained Tokens, V10 OAuth/OIDC, V17 WebRTC — **not applicable** (none in use). Of *applicable* chapters: 14/14 = 100%. **Requirement-level L1/L2/L3 traceability was NOT computed** — no ASVS checklist tool was run. |
| **CWE Top 25 (2024)** | 25 entries | **76%** | 19/25 | CWE-787, 125, 416, 119, 476, 190 — memory-corruption classes that **do not apply** to PHP/JS. Of applicable entries: 19/19 = 100%. |
| **SDAIA PDPL** | 12 obligation areas | **33%** | 4/12 | **Not assessed:** lawful basis/consent (Art. 5–6), privacy notice (Art. 12), data-subject rights (Art. 13–17), breach notification (Art. 20), records of processing/RoPA (Art. 22), DPIA (Art. 23), DPO appointment (Art. 24), processor agreements (Art. 30–31). **Checked:** minimisation (Art. 10–11, partial), retention & disposal (Art. 18), technical safeguards (Art. 19, extensively), in-Kingdom hosting/transfer (Art. 29). |
| **NCA ECC-1** | 28 applicable subdomains | **46%** | 13/28 | **Domain 1 Governance entirely not assessed** (0/10): strategy, management, policies, roles, risk management, HR security, awareness, cybersecurity audit. Also not assessed: 2-5 Mobile Devices, 2-10 Penetration Testing, 2-12 Incident & Threat Management, 2-13 Physical Security. Domain 5 (ICS) is not applicable. **Checked:** 2-1, 2-2, 2-3, 2-4, 2-6, 2-7, 2-8, 2-9, 2-11, 2-14, 3-1, 4-1, 4-2. |
| **HIPAA Security Rule** *(benchmark only — PDPL governs here)* | 20 standards | **65%** | 13/20 | **Technical Safeguards §164.312: 5/5 (100%)** — access control, audit controls, integrity, authentication, transmission security. **Administrative §164.308: 5/9** — not assessed: assigned security responsibility, awareness & training, security incident procedures, BA contracts. **Physical §164.310: 3/4** — facility access not assessed. **Organizational/Policies §164.314, §164.316: 0/2.** |
| **GDPR Art. 32** *(reference)* | 7 provisions | **71%** | 5/7 | Not assessed: 32(3) codes of conduct / certification; 32(4) persons acting under authority processing only on instruction. Checked: 32(1)(a) encryption & pseudonymisation, (b) CIA + resilience, (c) restore availability, (d) regular testing (*partial* — CI audits and this review, but no penetration test and no evidenced restore rehearsal), 32(2) risk assessment. |

**What the low PDPL and ECC-1 numbers actually mean.** They are **not** a technical failure. Every
technical safeguard area those frameworks name *was* assessed, and mostly assessed well. The
shortfall is **governance documentation** — the RoPA, the DPIA, the privacy notice, the
breach-notification procedure, the incident-response plan, the access-review record. That is exactly
the "PDPL governance paperwork outstanding" item the owner already has open (`SPC-RPT-088`), and it
is the highest-leverage compliance work available: it moves PDPL from ~33% to ~75% coverage without
a single line of code.

**Frameworks not mapped in this report, and why:** SLSA and NIST SSDF are referenced inside the
supply-chain findings but not scored as matrices — the build currently has no artefact identity
(`RPT-032`), so a SLSA level would be Build L0/L1 and the number would be misleading without that
context. MASVS is **not applicable** (no mobile target). CIS Benchmarks (Docker, MySQL 8.0/8.4)
appear as *mappings* on individual findings; **no benchmark was scored** because CIS-CAT Pro and
docker-bench-security were not run. NIST CSF 2.0 and AI RMF were not mapped — AI RMF is not
applicable (no AI/LLM component).

---

## 10. SBOM

**Status: NOT GENERATED.** No input envelope carried component-level inventory data, and none is
fabricated here.

What *is* known from the supply-chain domain:

| Ecosystem | Components | Lockfile | Integrity | Known CVEs |
|---|---|---|---|---|
| Composer (PHP 8.4) | 122 (89 prod + 33 dev) | `composer.lock`, committed | 122/122 carry a dist commit reference | **0 advisories** |
| npm (Node 22) | 254 entries (73 production) | `package-lock.json` v3, committed | 254/254 have `integrity`; 253/253 resolve to registry.npmjs.org | **0 in production**; 6 high in the dev-only tree (`RPT-070`) |
| Container OS layer | unknown | n/a | n/a | **UNKNOWN — not assessed** (`RPT-083`) |

Six components are **abandoned** (`fgrosse/phpasn1`; `web-token/jwt-core`, `-key-mgmt`, `-signature`,
`-signature-algorithm-ecdsa`, `-util-ecc`) — see `RPT-030`.

**To produce a real SBOM** (`RPT-068`):
```bash
docker buildx build --platform linux/arm64 -t endorse:audit --load .
trivy image --format cyclonedx -o sbom.cdx.json endorse:audit
# or, source-only, in CI:  anchore/sbom-action  format: cyclonedx-json
```
Retain one SBOM per deployed release alongside the git SHA and the image digest (`RPT-032`). That
combination is what answers "is this clinical system affected by the advisory published this
morning" in minutes rather than an afternoon — and it is what a hospital IT questionnaire asks for.

---

## 11. Sources

| Domain | Envelope | Raw findings | Tools actually used |
|---|---|---|---|
| code | `code.json` | 13 (1C/3H/3M/4L/2I) | manual source review, git grep pattern libraries, vendor source cross-check. *semgrep, gitleaks, trufflehog, psalm/phpstan: unavailable.* |
| api | `api.json` | 11 (0C/1H/4M/6L) | manual source review, read-only live HTTP GET. Envelope reconstructed by the orchestrator (read-only tool set); `API-001`/`API-006` independently re-verified in source. |
| web | `web.json` | 14 (0C/0H/2M/4L/8I) | curl, `openssl s_client`, pattern fallback. 24 HTTP requests + 14 TLS handshakes, GET/HEAD only. |
| database | `database.json` | 19 (0C/2H/9M/6L/2I) | pattern fallback. **No database connection was made.** |
| container | `container.json` | 18 (0C/2H/8M/6L/2I) | pattern fallback. **No image built, pulled, pushed or executed.** *trivy, hadolint, grype, dockle, snyk: unavailable.* |
| supply-chain | `supply-chain.json` | 12 (0C/1H/6M/3L/2I) | **`npm audit` v11.12.1 and `composer audit --locked` against live advisory data**, lockfile integrity walk, pattern fallback. |
| threat-model | `threat-model.json` | 23 (3C/10H/9M/1L) | pattern fallback, source reading, STRIDE across 12 trust boundaries. 21/23 tentative by design; `TM-001/008/014/015/017` independently confirmed in source. |
| **iac** | — | — | **NOT ASSESSED** |
| **ai-llm** | — | — | **NOT ASSESSED** |
| **mobile** | — | — | **NOT ASSESSED** |

All seven envelopes validated cleanly. None was malformed, skipped or partially loaded.

---

## 12. Guidance-only boundary

This report summarises **candidates** identified by static analysis plus authorised read-only live
HTTP. It adds no dynamic evidence of its own.

- It is **not** a penetration test, **not** a certification, and **not** a compliance attestation.
- **Coverage %** in §9 is *check coverage*. It is never a pass rate.
- Findings marked **tentative** — including 21 of the 23 threat-model items — require the dynamic
  tools named in §2 before they are treated as proven. Static files prove configuration **intent**,
  not runtime reality.
- **Absent domains are "not assessed", not "no findings".** IaC in particular means the OCI host,
  the Coolify control plane, Traefik router policy, the host firewall and Coolify RBAC were **never
  examined** — and `SPC-RPT-001` and `SPC-RPT-032` both touch that boundary.
- **No fix in this report has been applied.** Every remediation should be exercised through
  `docker/smoke.sh` or a staging stack first. Production migrations and live-DB changes remain
  owner-run, per project rule.
- No secret value appears anywhere in this document.

*Generated by the security-audit reporter from seven domain envelopes, 2026-07-26.*

---

## Addendum — container privilege drop: attempted, reverted, and why

**Finding:** the Dockerfile declares no `USER`, so PID 1, supervisord and the nginx and
php-fpm *masters* all run as root. Only the php-fpm workers and the scheduler drop to
`app`. Flagged independently by the container scan and the readiness review.

**Attempted 2026-07-26 and reverted the same day.** Two approaches were built and tested
against the real compose stack on the deployment host; both left the container unable to
serve, and the reason is structural rather than a configuration slip:

1. *Drop privileges in the entrypoint* (`su-exec app` before `exec`). PID 1 correctly
   became `app`, and then **nothing started at all**: supervisord opens each child's
   `stdout_logfile` before forking, and those are `/dev/stdout` and `/dev/stderr`, which at
   that point are pipes owned by root. Every program failed with
   `spawnerr: unknown error making dispatchers: EACCES`.

2. *Keep supervisord as root, declare `user=app` on each program.* supervisord then started
   fine, but nginx and php-fpm each open their error log **by path** during startup —
   before reading `error_log` from the configuration — and as a different user that fails:
   `nginx: [alert] could not open error log file ... (13: Permission denied)` and
   `php-fpm ERROR: failed to open error_log (/proc/self/fd/2): Permission denied`,
   exit 78 (`EX_CONFIG`). Chowning the log directories does not help, because the
   unopenable thing is the container's stdout pipe itself, not a file.

**What would actually work:** `USER app` in the image, so Docker creates the container —
and therefore its stdout/stderr pipes — owned by `app` from the outset. That also removes
the entrypoint's ability to `chown` the mounted volumes, which currently arrive owned by
root; named volumes inherit ownership from the image directory on first creation, so a
fresh deployment would be fine, but the **existing** production volumes would need their
ownership checked or reset. That makes it a deliberate migration with a verification step,
not a one-line hardening, and it was not worth doing unverified on a live clinical system.

**Residual risk in the meantime:** this is defence-in-depth. It does not grant an attacker
anything; it raises the impact *if* they already achieve code execution in the web tier.
The related and more valuable control — that `bootstrap/cache`, which Laravel `require`s
PHP out of, is writable by `app` while root re-generates it at boot — is unchanged by the
revert and remains open as `SPC-CONTAINER-002`.

**Kept from the attempt:** the scheduler now waits for a migrated database before starting
(`schedule:work` otherwise crash-looped against a missing `cache` table on a fresh
deployment until supervisord gave up retrying it permanently), and the log directories are
owned by `app`. `docker/smoke.sh` records the gap in place rather than asserting a property
the image does not have.
