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
| Every route behind authentication **and** a capability (`cap:`), deny-wins, per-user overrides | `app/Support/AccessControl.php`, `routes/web.php` |
| Second factor: authenticator app or emailed one-time code; **required for privileged accounts in production** | `EnforceTwoFactor`, `EmailOtpChallengeController` |
| Email address confirmed before an account is activated (signed, expiring links) | `EmailVerificationController` |
| One password policy everywhere — 8+, mixed case, number, symbol; 3-month expiry; reuse refused | `app/Support/PasswordPolicy.php` |
| Login lockout with timing-equalised user lookup (no account enumeration) | `AuthenticatedSessionController` |
| Deactivation revokes live sessions on the next request | `EnsureAccountActive` |
| Append-only, hash-chained audit log + `audit:verify` command + forensic indexes | `AuditLog`, `AuditVerify` |
| **Reads** of patient data audited, not only writes (`endorsement_view`, `endorsement_print`) | `EndorsementController` |
| No PHI in URLs, logs, audit details, exception reports, flashed input, or push payloads | `bootstrap/app.php` (`dontFlash`, QueryException redaction), audit call sites |
| Rich text sanitised on write (HTMLPurifier allow-list) — stored-XSS defence | `RichTextSanitizer`, `SanitizedHtml` |
| Signature images: private disk, re-encoded through GD, served only to authenticated holders of `endorsement.view`, immutable/content-addressed | `SignatureStore`, `SignatureController` |
| Secrets encrypted at rest: TOTP secrets, recovery codes, SMTP password, VAPID private key | model casts, `AppSettings` |
| Session cookie encrypted, `Secure` in production, `SameSite=strict`, 60-minute idle lifetime | `config/session.php` |
| CSP, HSTS (TLS only), X-Frame-Options DENY, nosniff, `Referrer-Policy: no-referrer`, COOP/CORP, Permissions-Policy | `SecurityHeaders` |
| `Cache-Control: no-store` on **every authenticated response** | `SecurityHeaders` |
| Rate limits: login, password reset, OTP issue/verify, and a 60/min brake on authenticated clinical reads | `routes/*`, `AppServiceProvider` |
| Trusted-proxy handling so TLS is detected behind a load balancer | `bootstrap/app.php` |
| Clinical rows soft-deleted; accounts deactivated, never deleted (attribution survives) | migrations, `UserManagementController` |
| Legacy import is read-only against its source, idempotent, audited, counts-only output | `LegacyImport`, `LegacyReconcile` |

## Encryption at rest — the layered decision

**Question asked: "should we encrypt the whole database?"**
**Answer: not first, and possibly not at all — do these in order.** MySQL TDE protects
against exactly one thing (someone walking off with the data files) and introduces a real
risk of *permanent data loss* if the keyring is lost. Cheaper layers cover more.

1. **Encrypted backups — do this first.** There is currently no backup at all, which is a
   bigger risk than any of this. `mysqldump | gzip | age -r <key>` nightly, restore tested
   quarterly, keys held offline. **owner**
2. **Provider volume/disk encryption.** Free, zero key-loss risk, covers the same threat as
   TDE. Get the provider's written confirmation (AES-256) — that statement is your audit
   evidence. **owner**
3. **Harden the DB path.** `bind-address=127.0.0.1`, a least-privilege app user (no FILE /
   SUPER / GRANT), a separate migration user, TLS between app and DB if they are on
   different hosts, and `INSERT`+`SELECT` only on `audit_log` so append-only is enforced by
   the engine rather than by convention. **owner**
4. **Application-level column encryption** — the defence-in-depth step that survives a
   stolen dump *and* a compromised DB account. This codebase never searches or sorts
   `mrn` / `patient_name` / `dob`, so Laravel's `encrypted` cast fits with almost no
   breakage. **Not yet applied** — see "Open items" below.
5. **MySQL InnoDB TDE (whole database).** Only worth enabling if you move to managed MySQL
   where it is a provider default, or an auditor names it as a required control. Doing it
   by hand on a VPS buys little over layer 2 and adds a keyring you can lose.

What the regulators actually require: PDPL Art. 19 and its Implementing Regulations
require security measures *appropriate to the sensitivity of the data*; NCA ECC 2-8-3
names encryption of data at rest. Neither mandates TDE specifically — layers 1–4,
documented, satisfy them.

> **Cross-border warning:** storing backups outside Saudi Arabia is a PDPL Art. 29 transfer,
> and health data attracts the strictest treatment. Keep backups in-Kingdom.

## Open items before go-live

**Code (recommended next):**
- Apply `encrypted` casts to `handovers.mrn`, `patient_name`, `dob` (+ optionally the four
  rich-text fields), widen those columns, and ship a backfill command. Blast radius is
  small — no query filters or sorts on them today. Blind indexing is deliberately *not*
  needed unless cross-day patient search is ever added.
- Schedule `audit:verify` (hourly) with alerting on failure, plus an anomaly sweep
  (repeated `access_denied`, repeated failed second factors).
- CI running `composer audit`, `npm audit`, the three test suites, and Dependabot/Renovate.

**Owner / governance (PDPL):**
- Appoint and publish a **data protection officer**; register with SDAIA if required for
  your processing volume.
- **Records of processing (ROPA)**, a **privacy notice** for staff and patients, and a
  **DPIA** for this system (health data + children = high risk).
- **Retention schedule** per table (handovers per the MOH medical-records schedule; audit
  log ≥ 12 months hot; pending registrations 30 days) and a disposal mechanism.
- **Breach procedure**: SDAIA notification within 72 hours; who decides, who writes it.
- **Data-subject-rights** procedure (access, correction, erasure where lawful).
- Restrict `/register` to the hospital network or replace it with admin invitations.
- Confirm hosting is in-Kingdom, and that backups are too.
