# 8. Foundation

### Authentication (cloned whole from the reference, identity re-platformed in P0c)

Identity is two tables (P0c/D3 reversed): `people` is the roster and the name/role of record, no
credential columns; `users` is purely the account, linked 1:1 via `person_id`. A roster-only
person has no `users` row and cannot authenticate by construction — see `docs/spec/04-data-model.md`.

Login by member name + password; per-(name+IP) rate limiter (5 attempts) with a
**timing-equalised** bcrypt verify when the user doesn't exist; inactive accounts get a fixed
message; 3-month password expiry forces a change before session creation; remember-me;
forgot/reset resolves by joining through `person_id` to `people.email` (`member_email` is now a
read-through accessor onto it, not a stored column — P0c/D9, owner decision 2026-08-08; reset
rotates remember_token and deletes the user's session rows); hand-rolled TOTP 2FA
(pragmarx/google2fa, confirm-before-active enrolment, 8 recovery codes, encrypted casts, per-user
challenge limiter + replay cache).

**Accounts are created by invitation only** — self-registration was closed 2026-07-27 (owner
decision): `GET /register` now redirects to the login page (`RegisteredUserController::closed()`)
instead of 404ing. An Administrator or Chief Resident issues one address + one role as a
single-use, expiring, revocable link (`Admin\InvitationController::store()`); redemption
(`Auth\InvitationAcceptController::store()`) CLAIMS the invitation's linked `people` row rather
than inserting a second one, writes a fresh-hashed `users` row, active immediately — no approval
step. The pre-invitation `pending_registrations` queue is frozen, no live writer since 2026-07-27,
but its `approve()`/`reject()` path still serves rows older than that date; approval there still
copies the pending row's already-hashed password verbatim, never rehashing (exactly like
`LegacyImport`) **[RULING]** — this no longer applies to invitation redemption, which always
hashes fresh.

`EnsureAccountActive` middleware re-checks `active` on every request and revokes live sessions immediately on deactivation.

### Access control (cloned whole)

Data-driven capabilities × role defaults × per-user grant/deny, **deny wins**, unknown key denied; `AccessControl` support class with generation-counter cache bust (Cache::add-then-increment, database-store safe); `cap:` middleware (403 + `access_denied` audit); applied-once role-default seeder (`applied_role_defaults`, so admin revocations are never resurrected); Admin → Access Control page with self-lockout guard.

**Capability catalog (complete):** `endorsement.view`, `endorsement.edit`, `endorsement.reopen`, `endorsement.compliance`, `profile.manage`, `users.manage`, `users.manage_residents`, `access.manage`, `settings.manage`, `structure.manage`, `people.manage`, `rota.view`, `rota.manage`.

**Role defaults:** Administrator — all. Charge Nurse, Consultant, Resident — view + edit + profile.manage + `rota.view`. Chief Resident — view + edit + profile.manage + `rota.view` + `users.manage_residents` + `rota.manage`. Nurse (position 1, RETIRED) — no defaults. `endorsement.reopen` and `endorsement.compliance` default **Administrator-only**, grantable per role or per named user. `structure.manage` (units, levels, calendar, periods, holidays — Munawib UN-*, LV-01, ST-02) also defaults **Administrator-only**, added P1b 2026-08-09. `people.manage` (the roster: people, levels, promotion, roster import — Munawib PE-\*, LV-02…04, ST-04) also defaults **Administrator-only**, added P1c 2026-08-09. `rota.view` (read the master rota — Munawib MR-05) defaults to **every seeded position**, because a rota residents cannot read fails the requirement it exists for. `rota.manage` (edit assignments and vacations — Munawib MR-02/MR-03/MR-06) defaults to **Administrator and Chief Resident**; Munawib §5 also grants it to its Scheduler persona, which maps to no role here except Chief Resident, who owns the master rota (owner decision, P1d 2026-08-09). Both added P1d 2026-08-09. Capabilities are **global**, not unit-scoped **[RULING]** (unit-scoped keys can be added to the same catalog later without schema change).

### Security bootstrap

- **New `SecurityHeaders` middleware** (the reference delegates this to deploy config; this project makes it first-class and tested): CSP, HSTS (on HTTPS), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
- CSRF on every state-changing request (framework default, no exemptions); writes are POST/PATCH/DELETE only; sessions database-driven, http_only, same_site lax, secure in production; `APP_DEBUG=false` in production with no trace/SQL leakage.
- **Audit log:** append-only, hash-chained (`hash = sha256(prev_hash . canonical)`, serialised with a row lock). Detail carries ids/field-names/counts **only — never PHI**. **New `audit:verify` artisan command** walks and verifies the chain (the reference writes the chain but cannot check it).
- **PHI rules:** no patient name/MRN/DOB in URLs, query strings, logs, audit details, exception messages, or notification payloads. Eloquent/bindings only — no concatenated SQL. Every route behind auth + a capability.
- Secrets: owner-managed only. Never requested, printed, or committed. Production migrations and live-DB changes are prepared and documented, executed by the owner.

---
