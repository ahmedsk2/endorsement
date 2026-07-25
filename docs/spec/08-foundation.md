# 8. Foundation

### Authentication (cloned whole from the reference)

Login by member name + password; per-(name+IP) rate limiter (5 attempts) with a **timing-equalised** bcrypt verify when the user doesn't exist; inactive accounts get a fixed message; 3-month password expiry forces a change before session creation; remember-me; forgot/reset via member_email (reset rotates remember_token and deletes the user's session rows); hand-rolled TOTP 2FA (pragmarx/google2fa, confirm-before-active enrolment, 8 recovery codes, encrypted casts, per-user challenge limiter + replay cache). **Self-registration → `pending_registrations` → admin approval** (approval copies the hash without rehashing) **[RULING]**. `EnsureAccountActive` middleware re-checks `active` on every request and revokes live sessions immediately on deactivation.

### Access control (cloned whole)

Data-driven capabilities × role defaults × per-user grant/deny, **deny wins**, unknown key denied; `AccessControl` support class with generation-counter cache bust (Cache::add-then-increment, database-store safe); `cap:` middleware (403 + `access_denied` audit); applied-once role-default seeder (`applied_role_defaults`, so admin revocations are never resurrected); Admin → Access Control page with self-lockout guard.

**Capability catalog (complete):** `endorsement.view`, `endorsement.edit`, `endorsement.reopen`, `endorsement.compliance`, `profile.manage`, `users.manage`, `access.manage`.

**Role defaults:** Administrator — all. Charge Nurse, Consultant, Resident — view + edit + profile.manage. Nurse — profile.manage only (legacy exclusion preserved). `endorsement.reopen` and `endorsement.compliance` default **Administrator-only**, grantable per role or per named user. Capabilities are **global**, not unit-scoped **[RULING]** (unit-scoped keys can be added to the same catalog later without schema change).

### Security bootstrap

- **New `SecurityHeaders` middleware** (the reference delegates this to deploy config; this project makes it first-class and tested): CSP, HSTS (on HTTPS), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
- CSRF on every state-changing request (framework default, no exemptions); writes are POST/PATCH/DELETE only; sessions database-driven, http_only, same_site lax, secure in production; `APP_DEBUG=false` in production with no trace/SQL leakage.
- **Audit log:** append-only, hash-chained (`hash = sha256(prev_hash . canonical)`, serialised with a row lock). Detail carries ids/field-names/counts **only — never PHI**. **New `audit:verify` artisan command** walks and verifies the chain (the reference writes the chain but cannot check it).
- **PHI rules:** no patient name/MRN/DOB in URLs, query strings, logs, audit details, exception messages, or notification payloads. Eloquent/bindings only — no concatenated SQL. Every route behind auth + a capability.
- Secrets: owner-managed only. Never requested, printed, or committed. Production migrations and live-DB changes are prepared and documented, executed by the owner.

---
