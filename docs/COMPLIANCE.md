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
- An anomaly sweep that alerts on repeated `access_denied` or repeated failed second
  factors (the detection half of breach readiness).
- Enable Dependabot or Renovate in the repository settings.

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
