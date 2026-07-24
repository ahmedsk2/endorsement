# Four-Unit Endorsement System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the standalone four-unit (PICU/NICU/SCBU/WARD) endorsement system specified in `docs/superpowers/specs/2026-07-24-endorsement-system-design.md`, cloned from the hardened reference app and extended with four-unit support, mobile/PWA/compliance features, and a lossless legacy import.

**Architecture:** Laravel 13.x + Inertia + Vue 3 + Vite + Tailwind 4. One `handovers` table partitioned by `unit_id`; a `UnitProfile` value object is the single source of per-unit variation. Foundation (auth, capabilities, audit chain, design tokens) is cloned verbatim from the reference; endorsement module is cloned then generalised.

**Tech Stack:** PHP 8.4 (local), pragmarx/google2fa, ezyang/htmlpurifier, minishlink/web-push, PHPUnit + SQLite (tests), Vitest, Playwright.

**Clone convention used throughout this plan:** `REF` = `C:\Users\ahmed\Documents\PICU Registry and Endorsement\laravel`, `LEGACY` = `C:\Users\ahmed\Documents\PICU Registry and Endorsement`. "Port `REF\x` → `y`" means: read the reference file at execution time, copy it, then apply ONLY the listed modifications. The reference file is the code; do not re-invent it. Every ported test must run RED first when its subject is absent/stubbed, then GREEN.

**Spec rulings bind this plan.** Where a task conflicts with the spec, the spec wins (see spec §15 rulings index).

---

## Phase 1 — Scaffold

### Task 1.1: Laravel skeleton + front-end toolchain

**Files:** entire new app skeleton at repo root (composer create-project writes it), `package.json`, `vite.config.js`, `resources/js/app.js`, `.env`, `.gitignore`

- [ ] `composer create-project laravel/laravel .tmp-app` then move contents into repo root (repo already contains docs/ and .git/)
- [ ] `composer require inertiajs/inertia-laravel pragmarx/google2fa ezyang/htmlpurifier`
- [ ] `npm install @inertiajs/vue3 vue@3 @vitejs/plugin-vue tailwindcss @tailwindcss/vite @fontsource/ibm-plex-sans @fontsource/ibm-plex-mono`
- [ ] Configure `vite.config.js` with vue + tailwind plugins (mirror `REF\vite.config.js`)
- [ ] `.env`/`.env.example`: `APP_TIMEZONE=Asia/Riyadh`, sqlite for local dev, session driver database, cache database. NO real secrets ever.
- [ ] `phpunit.xml`: sqlite `:memory:` (mirror `REF\phpunit.xml`)
- [ ] Root Inertia setup: `resources/js/app.js`, `resources/views/app.blade.php` (mirror REF)
- [ ] Verify: `php artisan test` green (example test), `npm run build` produces `public/build/manifest.json`
- [ ] Commit: `chore: scaffold Laravel + Inertia + Vue + Tailwind 4`

### Task 1.2: Design tokens + light-only build guard (test first)

**Files:** Create `tests/Feature/Build/CompiledCssIsLightOnlyTest.php`, `resources/css/app.css`, `docs/DESIGN-TOKENS.md`

- [ ] Port `REF\tests\Feature\Build\CompiledCssIsLightOnlyTest.php` (asserts compiled CSS has no `prefers-color-scheme: dark`; asserts print list markers survive). Run: RED (no build/tokens yet or placeholder CSS)
- [ ] Port `REF\resources\css\app.css` verbatim (tokens in `@theme`, `.readout`, `.channel-tag`, `.channel-bar*`, unlayered `:focus-visible`), then ADD three unit hues + bars:
```css
--color-unit-nicu: #7c5cb8;  /* violet family, AA-checked */
--color-unit-scbu: #b7791f;  /* caution-adjacent amber, AA-checked */
--color-unit-ward: #0f8a6a;  /* ok-family green, AA-checked */
```
and `.channel-bar-nicu/-scbu/-ward` variants beside `.channel-bar-picu`
- [ ] Copy `LEGACY\docs\DESIGN-TOKENS.md` → `docs/DESIGN-TOKENS.md`; append a "Unit hues" section documenting the four unit tokens
- [ ] `npm run build`; run test: GREEN
- [ ] Commit: `feat: Monitor-in-daylight tokens with four unit hues + light-only guard`

### Task 1.3: Project CLAUDE.md

**Files:** Create `CLAUDE.md`

- [ ] Write CLAUDE.md: project purpose, spec/plan paths, REF/LEGACY read-only pointers, governance rules (PHI, TDD, additive migrations, soft deletes, light-only, secrets owner-managed), toolchain paths (php at `%LOCALAPPDATA%\php84`), test commands
- [ ] Commit: `docs: project CLAUDE.md`

---

## Phase 2 — Foundation (auth, access control, audit, security)

Port order matters: migrations → models → support → middleware → controllers → routes → Vue → tests. Run each ported test suite RED first (port the test, watch it fail against missing class), then port the implementation.

### Task 2.1: Core migrations + models

**Files:** Create migrations (users/positions/institutions/audit_log/access-control tables/pending_registrations), `app/Models/{User,Position,Institution,AuditLog,Capability,PendingRegistration}.php`

- [ ] Port `REF\database\migrations\0001_01_01_000000_*` (users with member_name/position/active/pass_exp_date/2FA columns), `...120002` core tables (audit_log), `...120003` access-control tables, `...160000` applied_role_defaults — as FRESH dated migrations in this repo, content verbatim
- [ ] Port models: `User` (encrypted 2FA casts, passwordExpired(), soft deletes), `AuditLog` (append-only, hash chain writer `record()`), `Capability`
- [ ] Port `REF\database\seeders\ReferenceSeeder.php` → seed positions 0-4, ONE institution, and ALL FOUR units (change from PICU-only; units table migration comes with it)
- [ ] Test first: port `REF\tests\Unit\*` for AuditLog chain if present; else write `tests/Feature/AuditChainTest.php`: two records chain (`hash = sha256(prev_hash . canonical)`), detail never contains a seeded patient name. RED → implement → GREEN
- [ ] Commit: `feat: core schema, users/audit/capability models, four-unit seeder`

### Task 2.2: AccessControl + middleware

**Files:** Create `app/Support/AccessControl.php`, `app/Http/Middleware/{EnsureCapability,EnsureAccountActive}.php`, `bootstrap/app.php` edits, `app/Providers/AppServiceProvider.php` Gate bridge, `database/seeders/AccessControlSeeder.php`

- [ ] Port tests first: `REF\tests\Feature\EnsureCapabilityMiddlewareTest.php`, `AccessControlParityTest.php`, `Admin\AccessControlSeederRespectsRevocationsTest.php`, `Security\ActiveAccountRevocationTest.php`. RED
- [ ] Port `AccessControl.php` verbatim (deny-wins, generation-counter cache); `EnsureCapability` (`cap:` alias, 403 + access_denied audit); `EnsureAccountActive` appended to web group BEFORE HandleInertiaRequests; Gate::before bridge
- [ ] Write `AccessControlSeeder` with THIS project's catalog (7 keys per spec §8) and role defaults; applied-once via applied_role_defaults
- [ ] GREEN → Commit: `feat: capability access control with deny-wins and account revocation`

### Task 2.3: SecurityHeaders middleware (NEW — test first)

**Files:** Create `tests/Feature/Security/SecurityHeadersTest.php`, `app/Http/Middleware/SecurityHeaders.php`

- [ ] Write failing test: GET / returns CSP, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy same-origin, Permissions-Policy; HSTS present when request secure. RED
- [ ] Implement middleware (config-driven CSP allowing self + Vite dev origin in local); append to web group. GREEN
- [ ] Commit: `feat: first-class security headers middleware`

### Task 2.4: Auth stack

**Files:** Create `app/Http/Controllers/Auth/*` (7 controllers), `routes/auth.php`, Vue pages `resources/js/Pages/Auth/*`, `resources/js/Layouts/AppLayout.vue`, `app/Http/Middleware/HandleInertiaRequests.php`

- [ ] Port tests first: `REF\tests\Feature\Auth\*` (Login incl. timing-equalised lockout, ChangePassword, PasswordReset, Registration, TwoFactorChallenge, TwoFactorSetup, Logout). RED
- [ ] Port the seven Auth controllers + routes/auth.php verbatim; port HandleInertiaRequests (share auth.user + auth.can + flash, STRIP mrn_match)
- [ ] Port Auth Vue pages + AppLayout; replace registry nav with: Endorsement (chooser), per-unit links, Admin. Branding: "Paediatric Endorsement"
- [ ] GREEN → Commit: `feat: full auth stack (login, expiry, 2FA, reset, registration approval)`

### Task 2.5: Admin pages + audit:verify (NEW)

**Files:** Create `app/Http/Controllers/Admin/{UserManagementController,AccessControlController}.php`, Vue `Pages/Admin/*`, `app/Console/Commands/AuditVerify.php`, `tests/Feature/AuditVerifyCommandTest.php`

- [ ] Port tests first: `REF\tests\Feature\Admin\{UserManagementTest,AccessControlPageTest}.php`. RED → port controllers/pages → GREEN
- [ ] NEW test: `audit:verify` exits 0 on intact chain; corrupting one row's detail makes it exit 1 naming the row id. RED
- [ ] Implement `AuditVerify` command (walk chain, recompute hashes). GREEN
- [ ] Commit: `feat: admin user/access pages + audit chain verification command`

---

## Phase 3 — Single-unit endorsement (PICU), mobile-first

### Task 3.1: Handover schema + rich-text pipeline

**Files:** Create migration `create_handovers_table`, `app/Models/Handover.php`, `app/Casts/SanitizedHtml.php`, `app/Support/RichTextSanitizer.php`, `tests/Feature/HandoverSanitizeTest.php`, `tests/Feature/Endorsement/RichTextRoundTripTest.php`

- [ ] Migration: spec §4 exactly — reference handovers schema MINUS draft, PLUS legacy_source_table (string nullable) + legacy_id (unsignedBigInteger nullable), unique (legacy_source_table, legacy_id), index (unit_id, handover_date), softDeletes
- [ ] Port tests first: `REF\tests\Feature\HandoverSanitizeTest.php` + the four sanitizer tests from `REF\tests\Feature\Endorsement\EndorsementTest.php` (verbatim Chrome markup round-trip; CSS italic/underline NOT allow-listed; colour-over-bold survives; widened [style] didn't weaken stripping of expression()/behavior:url()/javascript:/position:fixed/onclick/script). RED
- [ ] Port `RichTextSanitizer.php` + `SanitizedHtml.php` verbatim; `Handover` model (casts, soft deletes, no draft). GREEN
- [ ] Commit: `feat: handover schema + write-time rich text sanitisation (proven)`

### Task 3.2: Endorsement routes/controller — index, sheet, rows

**Files:** Create `app/Http/Controllers/EndorsementController.php`, routes in `routes/web.php`, `tests/Feature/Endorsement/EndorsementTest.php`

- [ ] Port test first (index, filters, caps enforced, bad-unit 404, bed natural sort blanks-last, row CRUD, per-field PATCH, audit rows PHI-free). Start with `UNIT_CODES=['PICU']` this phase. RED
- [ ] Port controller: resolveUnit, index, show, storeRow, updateRow (one field per PATCH), deleteRow (SOFT delete — change from reference if it hard-deletes), route order (bare `/rows/{handover}` before `/{unit}`), date regex. Audit every write
- [ ] GREEN → Commit: `feat: PICU day index, sheet CRUD, per-field autosave endpoint`

### Task 3.3: New day + carry dialog (spec §5)

**Files:** Modify `EndorsementController.php` (newDay + a `carrySourceInfo` in show/index payload), tests in `EndorsementTest.php`

- [ ] Tests first: (a) consecutive-day carry copies all fields INCLUDING nevent verbatim; (b) idempotent — second call copies nothing; (c) gap: POST without `carry_choice` returns the last-sheet date payload (dialog data) and creates nothing; (d) gap + `carry_choice=carry` copies from most recent prior day; (e) gap + `carry_choice=blank` creates one empty row; (f) empty history seeds one blank row; (g) audit `endorsement_new_day carried=N`. RED
- [ ] Implement in transaction. GREEN
- [ ] Commit: `feat: carry-forward new day with gap dialog (nevent carries, per ruling)`

### Task 3.4: Sheet UI — RichTextEditor, SaveStatus, mobile-first cards

**Files:** Create `resources/js/Pages/Endorsement/{Index,Sheet}.vue`, `resources/js/Components/{RichTextEditor,SaveStatus,CarryDialog}.vue`, `tests/js/*.test.js`

- [ ] Port `REF\resources\js\Components\RichTextEditor.vue` VERBATIM (styleWithCSS per-command) + `SaveStatus.vue`; port their Vitest suites first (RED against stubs)
- [ ] Port Index.vue + Sheet.vue; add: mobile card layout `<768px` (stacked identity + 4 fields), ≥44px targets, 16px inputs, toolbar docked above keyboard (CSS `position: sticky; bottom: 0` inside focused card), CarryDialog component for gap flow
- [ ] Vitest GREEN; `npm run build` clean; light-only test still GREEN
- [ ] Commit: `feat: mobile-first sheet with save-on-blur and carry dialog`

### Task 3.5: Playwright e2e — persistence doctrine

**Files:** Create `playwright.config.js`, `tests/e2e/richtext.spec.js`, `tests/e2e/mobile-sheet.spec.js`

- [ ] Port `REF\tests\e2e\04-handover-richtext.spec.js` pattern: bold/italic/underline/lists/colour-on-bold survive save + RELOAD; loopback-only fixture guard
- [ ] New mobile spec: 390×844 viewport journey — open sheet, edit plan field, blur, reload, assert persisted text
- [ ] Both GREEN → Commit: `test: e2e persistence proof for rich text + mobile`

---

## Phase 4 — Four units

### Task 4.1: UnitProfile + controller generalisation

**Files:** Create `app/Support/UnitProfile.php`, `tests/Unit/UnitProfileTest.php`; modify `EndorsementController.php`, `tests/Feature/Endorsement/UnitScopeTest.php`

- [ ] `UnitProfile` (spec §3 table as code): per-code identity columns, consultant mode (pair | oncall), print labels, hue class. Unit test: four profiles exact-match spec table. RED → implement → GREEN
- [ ] Tests: all four units 200 on index/sheet; lowercase codes resolve; unknown 404; NICU/SCBU accept + return dob, WARD accepts age/ward_unit, PICU rejects all three (validateRow driven by UnitProfile); rows of unit A 404 via unit-B-agnostic bare-ID guard only when unit disabled — cross-unit bare-ID writes allowed only for enabled units and always audited with unit
- [ ] `UNIT_CODES = ['PICU','NICU','SCBU','WARD']`; validateRow/rowsFor/props driven by UnitProfile
- [ ] GREEN → Commit: `feat: four first-class units via UnitProfile`

### Task 4.2: Chooser landing + nav + per-unit sheet columns

**Files:** Create `resources/js/Pages/Endorsement/Chooser.vue`; modify `Sheet.vue`, `Index.vue`, `AppLayout.vue`, controller `root()`

- [ ] Feature test: GET /endorsement returns chooser payload (4 units × {code,name,today: rows_count, signed_off, has_sheet}). RED → implement → GREEN
- [ ] Chooser.vue: card per unit, channel-bar hue, today status line, "create day" quick action; banner listing units unfilled past handover time (times from config)
- [ ] Sheet.vue/Index.vue render UnitProfile-driven columns (dob picker for NICU/SCBU: date+time input; WARD age text + ward_unit text + "Room" label)
- [ ] Commit: `feat: four-unit chooser landing + per-unit sheet columns`

---

## Phase 5 — Sign-off

### Task 5.1: Signoff schema + model + time parsing

**Files:** Create migration `create_handover_signoffs_table` (spec §4: reference schema + provenance columns + endorsement_time_minutes inline), `app/Models/HandoverSignoff.php`, `tests/Unit/HandoverSignoffTimeTest.php`

- [ ] Port time tests first ('7:30 Am'→450, '13:30'→810, '2:40 PM'→880, '12:00 AM'→0, '13:30 PM'→null, junk→null). RED → port model (TIME_OPTIONS verbatim, parseTimeToMinutes, normalizeTimeLabel). GREEN
- [ ] Commit: `feat: handover signoff schema + legacy-verbatim time handling`

### Task 5.2: Sign-off endpoints + lock + reopen

**Files:** Modify `EndorsementController.php`; create `tests/Feature/Endorsement/{HandoverSignoffTest,ReopenCapabilityTest}.php`

- [ ] Port both reference test suites first, adjusted to rulings: endorser pickers RESIDENTS ONLY (position 4); consultants position 3; WARD single oncall→consultant_by + hidden consultant_to; snapshots survive rename; signing requires endorsed_by; locked day 422s row writes AND signoff edits; reopen needs endorsement.reopen + reason 3-500, reason NEVER in audit; denied reopen audited + 403 names active holders; whereDate lookup gotcha. RED
- [ ] Implement updateSignoff/reopenSignoff + staff picker providers (`ENDORSER_POSITIONS=[4]`, `CONSULTANT_POSITIONS=[3]`); lock checks in updateRow/storeRow/deleteRow/newDay
- [ ] GREEN → Commit: `feat: per-day sign-off with lock and capability-gated reopen`

### Task 5.3: Sign-off UI

**Files:** Create `resources/js/Components/SignoffPanel.vue`; modify `Sheet.vue`, `Index.vue`

- [ ] Vitest: panel renders pickers/time/sign button; signed state renders lock + reopen (only when can_reopen); WARD variant hides receiving consultant. RED → implement → GREEN
- [ ] Index day badges (signed/unsigned), chooser status wired to signoff
- [ ] Commit: `feat: sign-off panel with WARD oncall variant`

---

## Phase 6 — Print

### Task 6.1: Parameterised print page

**Files:** Create `resources/js/Pages/Endorsement/Print.vue`; modify controller `print()`; create `tests/Feature/Endorsement/PrintTest.php`, `tests/js/Print.test.js`, `tests/e2e/print.spec.js`

- [ ] Feature test: print payload per unit carries UnitProfile print schema (column list per spec §11), signoff snapshot names, "Not Selected" fallbacks. RED → implement controller. GREEN
- [ ] Port `REF\...\Print.vue` then parameterise columns/labels from unit prop (spec §11 column sets verbatim); keep @page A4 landscape 8mm, auto-print, :deep longhand list-style, NOT SIGNED OFF stamp, Consultant Receiving fix
- [ ] Vitest: per-unit column headers exact; e2e smoke: print page renders rows + markers for each unit
- [ ] Commit: `feat: per-unit A4 print sheet (fidelity contract)`

---

## Phase 7 — Compliance & PWA

### Task 7.1: /endorsement/today + remembered unit

**Files:** Modify controller + routes; `users` additive migration `preferred_unit_id` nullable FK; test in `EndorsementTest.php`

- [ ] Tests: /endorsement/today with preference redirects to that unit's today sheet (creating nothing); without preference → chooser; visiting a unit sheet stores preference. RED → implement → GREEN
- [ ] Commit: `feat: one-tap today route with remembered unit`

### Task 7.2: PWA shell

**Files:** Create `public/manifest.webmanifest`, `public/sw.js`, `resources/js/registerSw.js`, offline view; modify `app.blade.php`

- [ ] Manifest (name, icons, standalone, start_url `/endorsement/today`); SW caches app shell ONLY (build assets + offline page; network-first for navigations, NO caching of any /endorsement data); offline fallback page states "You're offline — endorsement needs a connection"
- [ ] Feature test: manifest served, SW served, response for /endorsement/* carries `Cache-Control: no-store` (belt-and-braces vs SW). RED → implement → GREEN
- [ ] Commit: `feat: installable PWA shell, no clinical data caching`

### Task 7.3: Push reminders

**Files:** `composer require minishlink/web-push`; migrations `push_subscriptions`, `reminder_preferences`; `app/Console/Commands/SendHandoverReminders.php`; controller `PushSubscriptionController`; profile page opt-in UI; `config/endorsement.php` (handover_times ['07:30','13:30'], remind_delay_minutes 10); `tests/Feature/ReminderTest.php`

- [ ] Tests first: subscription store/delete (auth-only); reminder command selects (unit×date) where no signed signoff exists at handover_time+delay and pushes ONLY to opted-in users of that unit; payload contains unit code, date, status — asserts NO patient fields ever serialised; command idempotent per (unit,date,time) via cache marker. RED
- [ ] Implement (VAPID keys via env — owner generates; command scheduled in `routes/console.php` at configured times Asia/Riyadh)
- [ ] GREEN → Commit: `feat: opt-in web-push handover reminders (PHI-free payloads)`

### Task 7.4: Missed-days view + gap markers

**Files:** Create `app/Support/MissedDays.php`, controller `compliance()` + route `cap:endorsement.compliance`, `resources/js/Pages/Endorsement/Compliance.vue`; modify `Index.vue` (gap rows); `tests/Feature/ComplianceTest.php`, `tests/Unit/MissedDaysTest.php`

- [ ] Unit tests first: range maths (inclusive bounds, default 30 days, future days excluded, per-unit partition); classification no_sheet vs unsigned; missed = no signed signoff (spec §10.3). RED → implement `MissedDays::forRange(unit, from, to)` returning {total_days, missed:[{date, kind}]}. GREEN
- [ ] Feature tests: route 403 without capability (default Admin-only, grantable); payload counts+dates only. RED → controller+page (range picker, per-unit rows, expandable date chips linking to create/open). GREEN
- [ ] Index gap rows between existing sheets with one-tap create (reuses carry dialog)
- [ ] Commit: `feat: missed-days compliance view + day-index gap markers`

---

## Phase 8 — Legacy import

### Task 8.1: Config + fixtures + import command

**Files:** Create `config/legacy.php`, `app/Console/Commands/LegacyImport.php`, `app/Console/Commands/LegacyReconcile.php`, `tests/Feature/LegacyImportTest.php` (sqlite fixture builder for all 8 source tables), `docs/RUNBOOK-IMPORT.md`

- [ ] Port `REF\config\legacy.php` + `REF\app\Console\Commands\LegacyImport.php`, then generalise per spec §12: four-unit source map (incl. ward unit→ward_unit, consultantoncall→consultant_by_name), provenance upsert on (legacy_source_table, legacy_id), users hash-verbatim, data rules (zero/epoch dates→null counted, dob zero→null, junk endorser ids→null FK), signoffs signed_off_at=date 00:00 null signer, sanitise-via-cast
- [ ] Tests first (fixtures seeded with the documented landmines: duplicate (date,mrn,bed) rows, 0000-00-00, 1970-01-01, junk endorsers, blank templates, HTML with script tags): counts land lossless per unit; idempotent re-run changes nothing; provenance filled; nevent imported verbatim; script stripped; WARD oncall mapped. RED → implement → GREEN
- [ ] `LegacyReconcile`: recompute per-unit expected counts WITH modelled divergences (skipped zero-date headers etc.), exit non-zero on unexplained drift; writes counts-only `docs/RECONCILIATION.md`
- [ ] RUNBOOK-IMPORT.md: owner-run steps (read-only grant, env vars, command order, reconcile gate, rollback = re-run harmless). No secrets in docs
- [ ] Commit: `feat: lossless four-unit legacy import + reconcile gate`

---

## Cross-cutting definition of done (every phase)

- `php artisan test` fully green; `npm run build` clean; light-only guard green; no `dark:` utilities, no raw palette/hex in markup; every route behind auth+cap; writes POST/PATCH/DELETE+CSRF; audit rows ids/counts only; tree committed and deployable.
