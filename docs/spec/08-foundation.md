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

**Sign-in is password-based, and there is no passwordless or magic-link login** (owner decision,
2026-08-10). Munawib AC-01's *"email link; password optional"* means the **invitation** is the email
link — it reaches the claim screen once — and the password is what that screen sets. That chain
shipped in P0c and is live; P1c-2 verified it end to end and deliberately implemented no part of
AC-01. The only email link that authenticates anything is the one-time invitation link, and it
authenticates a *claim*, once, not a session.

**Invitation lifetime is configurable, default 7 days, bounded [1, 30], behind `settings.manage`**
(P1c-2, `Invitation::lifetimeDays()`, `app_settings.invitation_lifetime_days`). Seven is a
deliberate override of AC-02's fourteen — a redeemed invitation reaches a child's clinical records.
`LIFETIME_DAYS = 7` is the **default, not the value**: an unset or out-of-bounds setting falls back
to it. It sits on the Settings screen rather than the account console because it is a
credential-exposure parameter, reviewed in one pass beside SMTP and VAPID.

**Resend rotates the token** (`App\Support\Invitations\InvitationIssue`, the only writer of
`invitations`): the superseded row is kept and marked revoked, never deleted, so the old link is
dead and the history of who invited whom survives. Resend is offered singly and in bulk (LV-02, up
to 50, `throttle:6,1`); the bulk path **refuses outright when SMTP is not configured** — the single
path can fall back to flashing the one-time link on screen, and a bulk path has nowhere to surface
N bearer credentials — previews before it confirms, and **sends mail only after the transaction
commits**, because mail cannot be rolled back.

**Claim status is derived, never stored** (`App\Support\Invitations\InvitationStatus`): five states
— none / open / expired / claimed / revoked — folded in that precedence order from
`accepted_at`/`revoked_at`/`expires_at`, plus `hidden` for a target the viewer may not manage. There
is no `person_status` column and none is coming.

**Unbinding an account (AC-03) is `App\Support\AccountUnbind`**: it snapshots
`handover_signoffs.signed_off_by_name` for that account's un-snapshotted rows *before* clearing the
link, then clears the link and deactivates in one act. The account is kept as history and never
deleted, it cannot be reactivated, and there is no rebind action.

`EnsureAccountActive` middleware re-checks `active` on every request and revokes live sessions immediately on deactivation.

### Access control (cloned whole)

Data-driven capabilities × role defaults × per-user grant/deny, **deny wins**, unknown key denied; `AccessControl` support class with generation-counter cache bust (Cache::add-then-increment, database-store safe); `cap:` middleware (403 + `access_denied` audit); applied-once role-default seeder (`applied_role_defaults`, so admin revocations are never resurrected); Admin → Access Control page with self-lockout guard.

**`App\Support\CapabilityGrant` is the only writer of `user_capabilities`** (P1c-2, AC-04). Grants
stay keyed to the **account**; "roles granted per person" is a second **surface** — a panel on the
People screen writing through to that person's linked account — gated **`access.manage`**, never
`people.manage`, because a role-granting control served from the roster console's route group would
let its holder grant themselves the security console. Both doors share one body, so they cannot
drift. **A colleague who leaves and returns on a new account does not regain old roles; an
administrator grants them again.**

**`access.manage` must always have an active holder, and one guard enforces it everywhere**
(rulings 44 and 45, 2026-08-11). `App\Support\AccessManageGuard::guarding()` wraps every write that
could remove a holder — both override surfaces, plus deactivate, unbind, and demotion from either
the account or the roster console, including the bulk selection — in a transaction, asks
`AccessControl::holdersOf('access.manage')` **after** the write, and throws if the answer is nobody,
which unwinds it. It replaced `PositionChange::isLastActiveAdministrator()`, which asked about the
Administrator **role**: a second position-0 account that had been *denied* the capability satisfied
that question while holding nothing, and all six doors were measured emptying the capability with a
302 before this. `AccessControlController::assertNoSelfLockout()` still guards the position-0 role
DEFAULT on the matrix, which is a separate question. Design §14 open item 20 is discharged.

**Capability catalog (complete):** `endorsement.view`, `endorsement.edit`, `endorsement.reopen`, `endorsement.compliance`, `profile.manage`, `users.manage`, `users.manage_residents`, `access.manage`, `settings.manage`, `structure.manage`, `people.manage`, `rota.view`, `rota.manage`.

**Role defaults:** Administrator — all. Charge Nurse, Consultant, Resident — view + edit + profile.manage + `rota.view`. Chief Resident — view + edit + profile.manage + `rota.view` + `users.manage_residents`. Nurse (position 1, RETIRED) — no defaults. `endorsement.reopen` and `endorsement.compliance` default **Administrator-only**, grantable per role or per named user. `structure.manage` (units, levels, calendar, periods, holidays — Munawib UN-*, LV-01, ST-02) also defaults **Administrator-only**, added P1b 2026-08-09. `people.manage` (the roster: people, levels, promotion, roster import — Munawib PE-\*, LV-02…04, ST-04) also defaults **Administrator-only**, added P1c 2026-08-09. `rota.view` (read the master rota — Munawib MR-05) defaults to **every seeded position**, because a rota residents cannot read fails the requirement it exists for. `rota.manage` (edit assignments and vacations — Munawib MR-02/MR-03/MR-06) defaults to **Administrator-only**, grantable per role from the Access Control screen with no code change. Munawib §5 also grants it to its Scheduler persona, which maps to no role here; Chief Resident is the nearest fit and a department that wants it there grants it (owner decision, 2026-08-10). Both added P1d 2026-08-09; `rota.manage`'s default was **reversed** on 2026-08-10 — P1d-1 shipped it to Chief Resident as well, and P1d-2 removed it. Because `AccessControlSeeder` applies each default once and never re-asserts it, an instance that already received the P1d-1 grant keeps it until an administrator un-ticks it; there is no data migration. Capabilities are **global**, not unit-scoped **[RULING]** (unit-scoped keys can be added to the same catalog later without schema change).

### Security bootstrap

- **New `SecurityHeaders` middleware** (the reference delegates this to deploy config; this project makes it first-class and tested): CSP, HSTS (on HTTPS), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
- CSRF on every state-changing request (framework default, no exemptions); writes are POST/PATCH/DELETE only; sessions database-driven, http_only, same_site lax, secure in production; `APP_DEBUG=false` in production with no trace/SQL leakage.
- **Audit log:** append-only, hash-chained (`hash = sha256(prev_hash . canonical)`, serialised with a row lock). Detail carries ids/field-names/counts **only — never PHI**. **New `audit:verify` artisan command** walks and verifies the chain (the reference writes the chain but cannot check it).
- **PHI rules:** no patient name/MRN/DOB in URLs, query strings, logs, audit details, exception messages, or notification payloads. Eloquent/bindings only — no concatenated SQL. Every route behind auth + a capability.
- Secrets: owner-managed only. Never requested, printed, or committed. Production migrations and live-DB changes are prepared and documented, executed by the owner.

---
