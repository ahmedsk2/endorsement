# Data protection & security posture

Paediatric Endorsement — Qatif Central Hospital. Processes **health data about children**:
name, MRN, date of birth and clinical narrative. Under SDAIA PDPL that is *sensitive
personal data*, so the bar is the strictest of PDPL + NCA ECC-1 + (as a reference floor)
HIPAA Security Rule and GDPR Art. 32.

This file records what the SYSTEM does. The items marked **owner** are deployment or
governance work that no code change can do for you.

## What the application already enforces

| Control | Where |
|---|---|
| Every route behind authentication; every route behind a `cap:` capability **except** the admin-user and signature endpoints, which carry `auth` and are gated in-controller by design (deny-wins, per-user overrides) | `app/Support/AccessControl.php`, `routes/web.php:91-99,144-148` |
| Second factor: authenticator app or emailed one-time code; **required for privileged accounts in production** | `EnforceTwoFactor`, `EmailOtpChallengeController` |
| Email address confirmed before an account is activated (signed, expiring links) | `EmailVerificationController` |
| One password policy everywhere — 8+, mixed case, number, symbol; 3-month expiry; reuse refused | `app/Support/PasswordPolicy.php` |
| Login lockout with timing-equalised user lookup (no account enumeration) | `AuthenticatedSessionController` |
| Deactivation revokes live sessions on the next request | `EnsureAccountActive` |
| Append-only, hash-chained audit log + `audit:verify` command + forensic indexes | `AuditLog`, `AuditVerify` |
| **Reads** of patient data audited, not only writes (`endorsement_view`, `endorsement_print`) | `EndorsementController` |
| No PHI in URLs, logs, audit details, exception reports, flashed input, or push payloads | `bootstrap/app.php` (`dontFlash`, QueryException redaction), audit call sites |
| Rich text sanitised on write (HTMLPurifier allow-list) — stored-XSS defence | `RichTextSanitizer`, `SanitizedHtml` |
| Signature images: private disk, re-encoded through GD, uploaded only for oneself, served only to authenticated holders of `endorsement.view`, immutable/content-addressed, and never emitted for an unsigned day | `SignatureStore`, `SignatureController` |
| A clinician's handwritten signature is applied only by that clinician, or by an Administrator / Chief Resident acting for them (owner ruling, see below) | `EndorsementController::resolveSignature()` |
| Secrets encrypted at rest: TOTP secrets, recovery codes, SMTP password, VAPID private key | model casts, `AppSettings` |
| Session cookie encrypted, `Secure` in production, `SameSite=strict`, 60-minute idle lifetime | `config/session.php` |
| CSP, HSTS (TLS only), X-Frame-Options DENY, nosniff, `Referrer-Policy: no-referrer`, COOP/CORP, Permissions-Policy, `X-Robots-Tag: noindex` | `SecurityHeaders` |
| The origin accepts 80/443 **only from Cloudflare's published ranges**, so the edge cannot be bypassed; asserted by `scripts/verify-live.sh` | OCI security list, 2026-07-27 |
| The application's database user holds `SELECT, INSERT, UPDATE, DELETE` only — a compromised app cannot alter or drop the clinical schema | MySQL grants; see `docs/RUNBOOK-DEPLOY.md` |
| `Cache-Control: no-store` on **every authenticated response** | `SecurityHeaders` |
| Rate limits: login, password reset, OTP issue/verify, and a 240/min brake on authenticated clinical traffic | `routes/*`, `AppServiceProvider` |
| Trusted-proxy handling so TLS is detected behind a load balancer | `bootstrap/app.php` |
| Clinical rows soft-deleted; accounts deactivated, never deleted (attribution survives) | migrations, `UserManagementController` |
| Legacy import is read-only against its source, idempotent, audited, counts-only output | `LegacyImport`, `LegacyReconcile` |

## Encryption at rest — the layered decision

**Question asked: "should we encrypt the whole database?"**
**Answer: not first, and possibly not at all — do these in order.** MySQL TDE protects
against exactly one thing (someone walking off with the data files) and introduces a real
risk of *permanent data loss* if the keyring is lost. Cheaper layers cover more.

1. **Encrypted backups — TOOLING DONE, RUN IT ON THE SERVER.** `php artisan backup:run`
   dumps, gzips, encrypts (`openssl enc -aes-256-cbc -pbkdf2`), **verifies the archive
   decrypts and decompresses**, shreds the plaintext dump and prunes old archives. It is
   scheduled nightly. The archive format is openssl-standard on purpose: recovery needs
   only openssl and the passphrase, never this application. Verified locally end to end
   (encrypted archive -> restored database -> ciphertext still in the PHI columns).
   **owner: set `BACKUP_PASSPHRASE`, run it on the server and locally, keep copies
   in-Kingdom, test a restore quarterly — docs/RUNBOOK-BACKUP.md.**
2. **Provider volume/disk encryption.** Free, zero key-loss risk, covers the same threat as
   TDE. Get the provider's written confirmation (AES-256) — that statement is your audit
   evidence. **owner**
3. **Harden the DB path.** `bind-address=127.0.0.1`, a least-privilege app user (no FILE /
   SUPER / GRANT), a separate migration user, TLS between app and DB if they are on
   different hosts, and `INSERT`+`SELECT` only on `audit_log` so append-only is enforced by
   the engine rather than by convention. **owner**
4. **Application-level column encryption — DONE.** `mrn`, `patient_name`, `dob` and all
   four rich-text clinical fields are encrypted at rest (AES-256 via APP_KEY). Rich text is
   sanitised *then* encrypted, so what is stored is already safe to render. A row written
   before encryption still reads back (the casts fall back to plaintext) rather than
   500-ing a whole sheet. Proven by `tests/Feature/Security/PhiEncryptionAtRestTest.php`,
   which reads the RAW columns and requires no identifier to be legible.
   Trade-off accepted: those columns cannot be searched or sorted in SQL. Nothing does.
5. **MySQL InnoDB TDE (whole database).** Only worth enabling if you move to managed MySQL
   where it is a provider default, or an auditor names it as a required control. Doing it
   by hand on a VPS buys little over layer 2 and adds a keyring you can lose.

What the regulators actually require: PDPL Art. 19 and its Implementing Regulations
require security measures *appropriate to the sensitivity of the data*; NCA ECC 2-8-3
names encryption of data at rest. Neither mandates TDE specifically — layers 1–4,
documented, satisfy them.

> **Cross-border warning:** storing backups outside Saudi Arabia is a PDPL Art. 29 transfer,
> and health data attracts the strictest treatment. Keep backups in-Kingdom.

## As deployed (2026-07-25)

Verified on the live system, not inferred from config:

| Property | Evidence |
| --- | --- |
| Hosted **in-Kingdom** — OCI `me-riyadh-1` | PDPL Art. 29 transfer question does not arise |
| TLS: valid Let's Encrypt certificate for `endorse.towardpcc.com`; only TLS 1.2/1.3 negotiate; HTTP 307s to HTTPS; HSTS `max-age=31536000; includeSubDomains` | `curl -sI`, `openssl s_client` |
| Patient database on an **internal-only** docker network, publishing no host port — unreachable from every other application on the host | asserted on each run by `docker/smoke.sh` |
| Database credentials, `APP_KEY` and `BACKUP_PASSPHRASE` held in the platform's encrypted store; Coolify's preview-environment copies deleted | Coolify env store |
| Repository access is a **read-only, single-repo deploy key** — not an account credential | GitHub deploy keys |
| Migrations never run at container boot, so an unattended restart cannot alter a clinical schema | `docker/entrypoint.sh` |
| Nightly encrypted dump verified to be a real dump of this database, not merely a file that decompresses | `BackupRun::assertPlausibleDump`, `tests/Feature/Console/BackupRunTest.php` |
| Zero accounts existed until the owner created the first administrator; no legacy data imported | clean-start decision, 2026-07-25 |

Residual transport note: the app→database hop uses TLS without certificate verification,
because MySQL 8.4 generates a self-signed server certificate. That matches Oracle's own
client default and the hop is a private network no other container can reach; pinning a
generated CA would be the improvement if this ever moves off one host.

## Accepted deviations — decided, not overlooked

Two questions an auditor will ask, answered by the owner on 2026-07-27 and recorded here
so the answer is a decision with reasoning rather than a silence.

### 1. All four units are readable, writable and signable by every clinical account

**The deviation.** There is no unit dimension anywhere in authorization. A holder of
`endorsement.view` / `endorsement.edit` can read, edit, print and sign off any of PICU,
NICU, SCBU and WARD, and the day index has no date floor, so that reaches the full history.
Under PDPL and NCA ECC-1 this is a minimum-necessary finding on its face.

**Why it is intended.** The residents cover all four units *at the same time*, not by
rotation between them — so a boundary would not model the department, it would contradict
it. The owner further ruled that one resident may sign off more than one unit for the same
date when cover requires it, which the schema already permits (sign-off is a per-unit,
per-day row). A hard boundary would produce exactly the failure it was meant to prevent: a
clinician at 03:00 unable to document the child in front of them.

**Compensating controls**, in the order they actually carry weight:

1. **No account exists without approval.** Registration creates a *pending* record; an
   Administrator or Chief Resident must approve it, and approval is refused outright unless
   the email address is verified (`Admin\UserManagementController`, which audits
   `user_approve_denied_unverified`). Access is granted to known people, one at a time.
2. Reads are audited, not only writes — `endorsement_view` and `endorsement_print` — so
   cross-unit access is attributable after the fact even though it is not prevented.
3. The hourly anomaly sweep counts refusals and bulk printing per user.
4. Accounts are deactivated rather than deleted, and deactivation revokes live sessions on
   the next request.

**What this deviation depends on.** Control 1 is doing most of the work, and it is only as
strong as the path in front of it — which is why that path was closed on the same day. Self
registration is gone: an account now exists only because an Administrator or Chief Resident
invited one named address into one named role (`App\Http\Controllers\Admin\InvitationController`).
The deviation and the account-creation path are coupled, and should be reviewed together if
either changes.

**Reopen this decision if** the department stops covering all four units concurrently,
non-clinical roles are given `endorsement.view`, the system is extended beyond the
Department of Paediatrics, or a data-subject complaint concerns cross-unit access.

### 2. An Administrator or Chief Resident may apply another clinician's signature

**The deviation.** A printed handover sheet may carry a clinician's handwritten signature
applied by somebody else — so the sheet does not, by itself, prove that the named person
personally attested.

**The rule as implemented** (`EndorsementController::resolveSignature()`): a signature
image is applied only when the named clinician *is* the person making the request, or when
that person is an Administrator (0) or Chief Resident (5). Everyone else may still name a
colleague — a handover has two people and the record must say so — but the colleague is
printed as a typed name.

**Why.** Completing records on another's behalf is a real part of how the department works,
and the ward needs a route to a fully-signed sheet. Confining it to two accountable roles
removes the case that has no defensible reading: one resident quietly putting another
resident's handwriting on a sheet.

**What the sheet asserts, stated plainly for the record:** that a handover occurred between
the two named clinicians and was documented by the person named on the "Signed off … by …"
line — not that each named clinician personally signed. Where a signature is withheld, that
attribution line is the whole attestation, which is why it is now snapshotted rather than
resolved live.

**Attributable either way.** The audit trail records `sig_by` / `sig_to` as
`self | proxy | withheld | none`, so a withheld signature can be distinguished from a
clinician who has none on file — something the paper cannot show. No names, no PHI.

## Open items before go-live

**Code — done since the audit:**
- PHI columns encrypted at rest (layer 4 above), proven by a test that reads raw columns.
- `audit:verify` hourly, `backup:run` nightly, `data:retention` nightly — each escalating
  to a critical log line on failure (`routes/console.php`).
- `data:retention`: the disposal mechanism PDPL Art. 18 expects. Dry-run by default;
  removes only expired operational rows (abandoned registrations, dead one-time codes,
  idle sessions) and NEVER clinical records or the audit log.
- CI (`.github/workflows/ci.yml`): all three suites plus `composer audit` and `npm audit`,
  on every push and weekly.

**Code — still open:**
- Nothing. The anomaly sweep now also counts repeated failed second factors — `2fa_failed`
  per user, and `two_factor_email_failed` per source address, since that one is recorded
  before a session exists. It still has **no delivery destination** until SMTP and an alert
  address are configured, which is an owner item below, not a code item.

**Owner / governance (PDPL):**
- Appoint and publish a **data protection officer**; register with SDAIA if required for
  your processing volume.
- **Records of processing (ROPA)**, a **privacy notice** for staff and patients, and a
  **DPIA** for this system (health data + children = high risk).
- **Retention schedule** per table (handovers per the MOH medical-records schedule; audit
  log ≥ 12 months hot; pending registrations 30 days) and a disposal mechanism.
- **Breach procedure**: SDAIA notification within 72 hours; who decides, who writes it.
- **Data-subject-rights** procedure (access, correction, erasure where lawful).
- ~~Restrict `/register`~~ — **done 2026-07-27.** Replaced with admin invitations: an
  Administrator or Chief Resident invites one address into one role, the link is single-use,
  expiring and revocable, and the token is stored hashed. Nothing self-registers.
- Hosting is confirmed in-Kingdom (above). **Backups are not yet off-host**: the nightly
  archive still only exists in a volume on the machine it backs up, which is not a backup.
  Pull it to a second in-Kingdom location per `docs/RUNBOOK-BACKUP.md` and do the restore
  drill once.
