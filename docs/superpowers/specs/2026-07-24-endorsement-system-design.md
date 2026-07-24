# Paediatric Endorsement System — Design Specification

**Date:** 2026-07-24
**Status:** Approved by owner (brainstorm 2026-07-24)
**Project root:** `C:\Users\ahmed\Documents\endorsement`

---

## 1. Purpose and scope

A standalone shift-handover ("endorsement") system for a hospital's paediatric services, covering four units — **PICU, NICU, SCBU, WARD** — each a first-class unit with its own day index, editable handover sheet, per-day sign-off, and printable A4 sheet.

**In scope:** endorsement/handover only, plus the authentication, access-control, audit, and design-system foundation it needs; a one-way legacy data import; mobile-first UI, PWA install, handover-time reminders, and a missed-days compliance view.

**Out of scope (permanently, for this project):** patient registry, scoring, KPI dashboards (beyond the missed-days counter specified in §10.3), nursing sheets, offline editing, hard enforcement of data entry (no blocking, no mandatory clinical fields), email digests.

### Reference codebases (read-only; never modified)

| Reference | Location | Role |
|---|---|---|
| Legacy procedural PHP | `C:\Users\ahmed\Documents\PICU Registry and Endorsement` (repo root) | Deployed production system; **behavioural specification** for all four units. Row tables carry the real misspelling `patintsendorcement`. PICU file names are lowercase. |
| Laravel re-platform | same repo, `laravel/` | Hardened modern implementation, deliberately PICU-only (decision "G1"). **Clone source** for foundation and endorsement module. |

Where the two disagree, the rulings in this spec are final (they were decided explicitly by the owner; each is marked **[RULING]** below).

---

## 2. Stack

- **Laravel 13.x** (matching the reference app's actual `laravel/framework ^13.8`, so cloned controllers, middleware registration, casts, and tests port verbatim) **[RULING]**
- Inertia + Vue 3, Vite, Tailwind 4
- PHPUnit for backend tests (reference tests port unchanged), Vitest for JS component tests, Playwright for end-to-end journeys
- Database: MySQL in production; SQLite for tests (as in the reference)
- Fresh git repository in the project root; the tree stays clean and deployable after every commit

---

## 3. Unit model

`units` table seeded with all four units. `EndorsementController::UNIT_CODES = ['PICU', 'NICU', 'SCBU', 'WARD']`; unknown or retired unit codes 404 (lowercase URL codes keep resolving, as in the reference).

A single **UnitProfile** value object (PHP, exposed to Vue via Inertia props) is the sole source of per-unit variation:

| | PICU | NICU | SCBU | WARD |
|---|---|---|---|---|
| Row identity columns | bed, mrn, patient_name | + dob | + dob | age, bed (labelled "Room"), ward_unit ("Unit/Speciality"), mrn, patient_name (no dob) |
| Consultant sign-off fields | by + to | by + to | by + to | single field labelled **"Consultant Oncall"**, stored in `consultant_by_*`; consultant-receiving hidden **[RULING]** |
| Print column 4 label | Plan Of Care | Plan Of Care | Plan Of Care | Management |
| Print column 5 label | New events | To be followed | To be followed | To be followed |
| Hue token | `--color-unit-picu` (existing value) | `--color-unit-nicu` (minted) | `--color-unit-scbu` (minted) | `--color-unit-ward` (minted) |

- `/endorsement` renders a **four-unit chooser**: one card per unit with its hue bar, today's census count, and today's status (signed / in progress / no sheet), plus a banner when any unit is unfilled past handover time. No unit is privileged. **[RULING]**
- Navigation has four unit entries plus the chooser.
- All handover reads and writes are unit-partitioned: every query scopes by `unit_id`, and bare-row-ID endpoints verify the row's unit is an enabled unit (generalising the reference's `assertPicuRow`).

---

## 4. Data model

### Cloned foundation tables (verbatim from the reference)

`users` (member_name login, position tinyint, active flag, pass_exp_date, encrypted TOTP columns, soft deletes), `positions` (0 Administrator, 1 Nurse, 2 Charge Nurse, 3 Consultant, 4 Resident), `institutions` (kept; nullable FKs) **[RULING]**, `pending_registrations`, `capabilities` / `role_capabilities` / `user_capabilities` / `applied_role_defaults`, `audit_log` (append-only, hash-chained: `prev_hash`, `hash`), `sessions`, `cache`, `password_reset_tokens`.

### `handovers`

Reference schema with two changes: **no `draft` column** **[RULING]**, **plus lossless-import provenance**.

- id; institution_id FK nullable; unit_id FK; handover_date date
- bed, mrn, patient_name (strings, nullable)
- dob datetime nullable (NICU/SCBU); age string nullable, ward_unit string nullable (WARD) — kept for all units, surfaced per UnitProfile
- disease, details, plan, nevent — TEXT, rich HTML, sanitised on write (§7)
- author_user_id FK nullable (last author)
- **legacy_source_table** string nullable, **legacy_id** unsigned bigint nullable **[RULING]** — unique together when present
- timestamps, softDeletes; index (unit_id, handover_date)

### `handover_signoffs`

Reference schema verbatim, plus the same provenance pair:

- id; institution_id, unit_id FKs; handover_date date; **UNIQUE (unit_id, handover_date)** (fixes legacy's missing uniqueness)
- Four staff pairs, each `*_user_id` FK nullOnDelete + `*_name` **string snapshot frozen at write time**: endorsed_by, endorsed_to, consultant_by, consultant_to
- endorsement_time string (verbatim display label) + endorsement_time_minutes unsigned small int nullable (0–1439)
- signed_off_at timestamp nullable, signed_off_by_user_id FK
- reopened_at, reopened_by_user_id, reopen_reason TEXT
- legacy_source_table, legacy_id (nullable)
- timestamps, softDeletes

### New tables (compliance addendum)

- `push_subscriptions`: user_id FK, endpoint (unique), p256dh, auth, timestamps
- `reminder_preferences`: user_id FK, unit_id FK, unique (user_id, unit_id)

### Schema governance

Additive, nullable migrations; soft deletes everywhere clinical; no destructive change to a column holding real data; clinical rows never hard-deleted; accounts deactivated, never deleted.

---

## 5. Day lifecycle

### Day index (per unit)

Last 30 days newest-first with date-range filter (reference parity), each day showing census count and sign-off badge. **Gap markers**: missing dates between existing sheets render inline as "no endorsement — {date} · create" with one-tap backfill (§10.4).

### New day (carry census forward)

- Target date = requested date or today (Asia/Riyadh). **Idempotent**: if the unit already has rows for the target date, nothing is copied and the user is sent to the existing sheet.
- Carry copies, per source row: institution_id, bed, mrn, patient_name, dob, age, ward_unit, disease, details, plan **and nevent** — nevent carries forward verbatim, per legacy **[RULING]**.
- **Carry dialog** **[RULING]**: when the most recent prior sheet is exactly the day before the target, carry happens silently (the normal flow). When it is **older than yesterday**, a dialog shows "Last endorsement was {date}" and offers **carry that census forward** or **start blank**. Starting blank creates the day with one empty row.
- If nothing exists to carry, the day is created with one blank row so it exists.
- Runs in a transaction; audited as `endorsement_new_day` with unit, date, and carried-row count only.

### Rows

- Add: inserts a blank row for the sheet date. Delete: **soft delete**, audited. Both POST/DELETE + CSRF behind `cap:endorsement.edit`.
- Ordering: natural case-insensitive bed sort (`strnatcasecmp`) with blank beds **last** — legacy's intent, fixing the reference's string-sort regression.
- Editing: **per-field save-on-blur.** Each PATCH carries exactly one field. Status per cell: `saving → saved` (auto-clears after 2.5 s) or persistent `error` ("Not saved", `role=alert`) until the next attempt. Never fire-and-forget: the UI state reflects the server response, and e2e tests assert on **persistence after reload**, never on the indicator alone.

---

## 6. Sign-off and reopen

- Per-day header row in `handover_signoffs`, keyed (unit_id, handover_date).
- **Endorsed By / Endorsed To pickers list active Residents only (position 4)**, per legacy **[RULING]**. **Consultant pickers list active Consultants only (position 3)** (reference behaviour; legacy free text is superseded). Names are snapshotted into `*_name` at write time; a later rename never rewrites a signed sheet.
- WARD shows a single consultant picker labelled "Consultant Oncall" stored in `consultant_by_*`; the receiving-consultant field is hidden for WARD **[RULING]**.
- Time: quick-picks `7:30 Am` and `13:30` kept character-for-character (all four units share them); free text accepted only if parseable, normalised to HH:MM for display and 0–1439 for `endorsement_time_minutes`; unparseable input is a validation error.
- **Signing** requires endorsed_by; stamps `signed_off_at` + `signed_off_by_user_id`; a signed day is **locked** — row writes and sign-off edits return validation errors (422).
- **Reopen** requires the `endorsement.reopen` capability (checked in-controller so the 403 can name the actual active holders), a mandatory reason (3–500 chars), clears `signed_off_at`, sets reopened_at/by/reason, preserves all snapshots. The **reason text is never written to the audit log** (it could name a patient); audit rows carry unit, date, and prior-signature metadata only. Denied attempts are audited too.

Audit actions: `endorsement_new_day`, `endorsement_row_create/_update/_delete`, `endorsement_signoff`, `endorsement_signoff_reopen`, `endorsement_signoff_reopen_denied`, `access_denied`.

---

## 7. Rich text (the critical bug — clone the fix)

- `RichTextEditor.vue` cloned from the reference: `document.execCommand('styleWithCSS', …)` is set **per command** — ON only for `foreColor`/`hiliteColor` (emitting allow-listed `span[style]`), OFF for bold/italic/underline/lists (emitting `<b>/<i>/<u>/<ul>/<ol>` tags). The legacy global `styleWithCSS(true)` emitted `font-style`/`text-decoration-line` CSS that the sanitiser discards — the production silent-data-loss bug. Never copy the legacy toolbar JS.
- Sanitisation **on write** via the `SanitizedHtml` Eloquent cast on disease/details/plan/nevent → `RichTextSanitizer` (HTMLPurifier). Because it lives on the model, it covers controller writes, new-day copies, and legacy import identically.
- Allow-list, exactly: `p,br,b[style],strong[style],i[style],em[style],u[style],ul,ol,li[style],span[style],div[style],h1,h2,h3,font[color]`; `CSS.AllowedProperties = color,background-color,font-weight,text-decoration`.
- **Proof obligations (ported tests):** verbatim Chrome execCommand markup round-trips (bold, italic, underline, both list types, colour, highlight); colour applied over bold/underline survives; `<script>`, event handlers, `expression()`, `behavior:url()`, `javascript:` URIs, `font-style`, and `position:fixed` are stripped; a Playwright journey proves bold/italic/underline/list/colour-on-bold survive a save **and reload** in a real browser.

---

## 8. Foundation

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

## 9. Design system and UI

- "Monitor, in daylight" tokens copied verbatim from `laravel/resources/css/app.css` + `docs/DESIGN-TOKENS.md`: **light theme only** (any `dark:` utility is a bug — enforced by the ported compiled-CSS build test); semantic classes only (`.readout` for every clinical numeral/date/MRN, `.channel-tag` for labels, `.channel-bar` encoding meaning only); IBM Plex Sans/Mono; no raw Tailwind palette classes or hex in markup; borders over shadows; unlayered `:focus-visible` rule kept outside `@layer`.
- Three new unit hues minted (`unit-nicu`, `unit-scbu`, `unit-ward`) with matching `.channel-bar-*` variants, chosen within the token family for AA contrast.
- **Mobile-first from the first sheet build (not retrofitted):** desktop keeps the table; below ~768 px the sheet renders a card per patient (identity line + four stacked rich-text sections) with identical save-on-blur semantics. Touch: ≥44 px tap targets, ≥16 px input font (prevents iOS zoom), formatting toolbar docks above the keyboard on focus, no horizontal scrolling.

---

## 10. Compliance and PWA (the "forgetting" problem)

The owner's stated pain: residents forget to fill the endorsement and whole days are missed. Levers chosen: reminders, one-tap access, and visibility of gaps. Explicitly rejected: hard enforcement (breeds garbage-text workarounds), email digests, offline editing.

### 10.1 PWA

Web manifest + service worker caching the **app shell only — never patient data; no offline editing** (an offline queue is this domain's documented failure mode). Offline shows a clear "you're offline" screen. Installed app opens at `/endorsement/today` → redirects to the current date's sheet for the user's remembered unit (chooser if none).

### 10.2 Reminders — in-app + web push [RULING]

- Scheduled job a few minutes after each handover time (07:30/13:30 Asia/Riyadh, config-driven): for each unit whose today-sheet is missing or unsigned, push to users opted in for that unit.
- Payload strictly `unit + date + status` — never patient data.
- Opt-in per unit on the profile page; VAPID subscriptions in `push_subscriptions`. Works for installed PWAs on iOS 16.4+ and Android; deployment is public HTTPS.
- In-app equivalents: chooser cards show today's per-unit status; a banner appears when a unit is unfilled past handover time.

### 10.3 Missed-days view (the only KPI) [RULING]

One page behind `cap:endorsement.compliance`: a date-range picker (default last 30 days) and one row per unit showing **days without endorsement / total days in range**, expandable to the list of missing dates, each linking to create/open that day. **Definition:** a day is missed when the unit has **no signed sign-off** for that date; the expanded list distinguishes "no sheet at all" from "sheet created but never signed". Counts and dates only — no other metrics, no patient data.

### 10.4 Day-index gap markers

Missing dates between existing sheets render inline in the day index with one-tap backfill; backfill uses the carry dialog (§5), which will offer carry-from-last-sheet or blank.

---

## 11. Print

**Print.vue style for all four units [RULING]** — one parameterised print page, not four templates:

- A4 landscape (`@page { size: A4 landscape; margin: 8mm }`), chrome-free layout, Arial 11px, auto `window.print()` shortly after mount when rows exist.
- Flat columns from UnitProfile. PICU: Bed | MRN | Name | Diagnosis List | Clinical Condition | Plan Of Care | New events. NICU/SCBU: Bed | MRN | Name | DOB | Diagnosis List | Clinical Condition | Plan Of Care | To be followed. WARD: Room | Unit | MRN | Name | Age | Diagnosis List | Clinical Condition | Management | To be followed (no DOB).
- Consultant line label per unit ("Consultant Covering" / WARD "Consultant Oncall") + TIME; footer prints all four endorser/consultant names ("Not Selected" fallback), keeping the reference's corrections: Consultant Receiving is printed, and the legacy "Endorsed By/To" label bug stays fixed. Signed stamp or "NOT SIGNED OFF" line.
- `v-html` is safe here because sanitisation happened on write; `:deep(ul/ol)` longhand `list-style-type` rules restore markers inside `v-html` (the minifier mangles the shorthand).
- Print fidelity is a contract: once approved, the page is not restyled.

---

## 12. Legacy import

`legacy:import` artisan command, modelled on the reference's `LegacyImport`, generalised to four units:

- **Read-only** `legacy` DB connection (SELECT-only grant), one-way; the owner runs it against production — never Claude.
- Source map: `patintsendorcement` → PICU; `nicu_patintsendorcement` → NICU (+dob); `scbu_patintsendorcement` → SCBU (+dob); `ward_patintsendorcement` → WARD (age, unit → ward_unit). Day headers: `endorsement`, `nicu_endorsement`, `scbu_endorsement` (consultant by/to), `ward_endorsement` (**consultantoncall → consultant_by_name**).
- Sections (reference → users → handovers ×4 → signoffs ×4) in per-section transactions, chunked reads ordered by legacy ID.
- **Idempotent on provenance:** upsert keyed (legacy_source_table, legacy_id) — lossless, no natural-key collapse (~2.5k duplicate (date,mrn,bed) rows import intact) **[RULING]**. Blank template rows import as-is.
- Users: bcrypt hashes copied verbatim, never rehashed. Endorser member_ids resolved to users; junk/unresolvable ids → null FK (name snapshot only when resolvable).
- Rich text sanitised by the model cast on import (historical rows predate legacy's sanitiser).
- Data rules: `0000-00-00` and `1970-01-01` dates and zero-datetime dobs → null, counted per unit in reconciliation; WARD's free-text sub-unit imports verbatim into ward_unit; imported signed days get `signed_off_at = date 00:00` with null signer (historically final, therefore locked).
- Output: counts-only `docs/RECONCILIATION.md`; companion `legacy:reconcile` recomputes and exits non-zero on unexplained drift, with modelled expected divergences (skipped zero-dates etc.) per unit. Audited; no PHI in any output.

---

## 13. Testing strategy

TDD throughout: failing test first, red observed, then implementation. Ported reference suites plus new coverage:

- **Feature:** auth (login/lockout/timing/expiry/2FA/reset/registration), access control (deny-wins, cache bust, seeder revocation persistence, self-lockout), audit chain + `audit:verify`, security headers, endorsement (index/sheet/rows/new-day/carry-dialog rules/bed sort/unit 404s/per-unit columns), sign-off (pickers, snapshots, time parsing, lock, reopen capability + reason + audit), sanitiser round-trip + attack corpus, missed-days computation (range boundaries, unit partition, no-sheet vs unsigned), push (subscription CRUD, payload PHI-free, scheduler selection), import (fixtures ×4 units, idempotency, provenance, data rules, reconciliation).
- **Build guards:** compiled CSS is light-only; print sheet keeps list markers.
- **JS (Vitest):** RichTextEditor command/styleWithCSS behaviour, SaveStatus states, Sheet autosave wiring, chooser status logic.
- **E2E (Playwright, loopback-only fixtures):** rich-text formatting survives save + reload; sheet journey on a mobile viewport; print page renders; a11y pass. E2E asserts on persistence, never on UI indicators alone.

---

## 14. Build phases

1. **Scaffold** — Laravel 13 + Inertia/Vue/Tailwind 4, design tokens, light-only build test, git, CLAUDE.md, CI-ready test runner.
2. **Foundation** — auth stack, access control + admin pages, audit chain + `audit:verify`, `SecurityHeaders`, user management.
3. **Single-unit endorsement (PICU)** — schema, day index, sheet (mobile-first), rich text pipeline, save-on-blur, row CRUD, new-day + carry dialog.
4. **Four units** — UnitProfile, per-unit columns/labels, chooser landing, unit hues, unit-partition guards.
5. **Sign-off** — HandoverSignoff, pickers, time handling, lock, reopen + capability, WARD consultant variance.
6. **Print** — parameterised sheet, per-unit fidelity tests.
7. **Compliance & PWA** — PWA shell + `/endorsement/today`, push reminders + preferences, missed-days view, day-index gap markers.
8. **Legacy import** — command, fixtures, reconcile gate, runbook for the owner.

Each phase lands test-first and leaves the app deployable.

---

## 15. Rulings index

| # | Decision | Ruling |
|---|---|---|
| 1 | nevent on new day | Copy forward verbatim (legacy) |
| 2 | Carry source after a gap | Dialog: "Last endorsement was {date}" → carry or start blank; consecutive days carry silently |
| 3 | `/endorsement` landing | Four-unit chooser |
| 4 | Print target | Print.vue style for all four units |
| 5 | WARD consultant | Single "Consultant Oncall" → `consultant_by_*`; receiving field hidden |
| 6 | Endorsed by/to pickers | Active Residents only (position 4) |
| 7 | Import identity | Lossless via legacy_source_table + legacy_id |
| 8 | Draft flag | Dropped |
| 9 | Framework | Laravel 13.x (match reference) |
| 10 | Account creation | Self-register + admin approval |
| 11 | Capability scope | Global |
| 12 | Tenancy | Keep institutions table |
| 13 | Notifications | In-app + web push (no email) |
| 14 | Compliance metric | Missed-days per unit only, date-range selectable, expandable to dates; missed = no signed sign-off |
| 15 | Consultant pickers | Active Consultants (position 3), replacing legacy free text |
