# Paediatric Endorsement System

A departmental clinical platform. **Endorsement** (shift handover, holds PHI) is what exists
today. **Rota** (duty scheduling, holds none) is planned, P1 onward — see the design doc,
`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`.

Endorsement covers handover ONLY: no registry, no scoring, no KPI dashboards (beyond the
missed-days counter), no nursing sheets. Units are CONFIGURATION, not code — PICU, NICU,
SCBU and WARD are seed data for the QCH institution.

## Canonical documents

- Spec: SLICED per section under `docs/spec/` — load ONLY the section you need.
  `docs/spec/15-rulings.md` (tiny) is the rulings index; the old monolith path is now
  just an index of the slices.
- Implementation plan: `docs/superpowers/plans/2026-07-24-endorsement-system.md`
- Design tokens: `docs/DESIGN-TOKENS.md` — "Monitor, in daylight", LIGHT THEME ONLY

## Reference codebases (READ-ONLY — never modify)

- `C:\Users\ahmed\Documents\PICU Registry and Endorsement` — legacy procedural PHP
  (deployed production; the behavioural specification; row table misspelled
  `patintsendorcement` ×4; PICU files lowercase)
- same repo, `laravel\` — the hardened Laravel re-platform this project clones from
  (deliberately PICU-only there; four units here)

## Non-negotiable rules

- **No PHI** (patient name, MRN, DOB) in URLs, query strings, logs, audit_log details,
  exception messages, or push payloads — ids/field-names/counts only.
- **TDD**: failing test first, watch it go red, then implement. Tree deployable after
  every commit. `php artisan test` + `npm run build` green before any commit.
- Never concatenate SQL — Eloquent/bindings only. Every route behind auth + a `cap:`
  capability. Writes are POST/PATCH/DELETE + CSRF.
- Additive, nullable migrations; soft deletes; never retype a column holding real data;
  clinical rows never hard-deleted; accounts deactivated, never deleted.
- Rich text sanitised ON WRITE (`SanitizedHtml` cast → `RichTextSanitizer`); the editor
  sets `styleWithCSS` per-command (ON only for foreColor/hiliteColor). Never copy the
  legacy toolbar JS — its global styleWithCSS caused silent production data loss.
- LIGHT THEME ONLY: any `dark:` utility is a bug (guarded by
  `tests/Feature/Build/CompiledCssIsLightOnlyTest.php`). Semantic classes only
  (`.readout`, `.channel-tag`, `.channel-bar*`) — no raw Tailwind palette classes or
  hex in markup.
- Autosave is never fire-and-forget: per-field save-on-blur, UI reflects the server
  response, e2e asserts persistence after reload — never the indicator alone.
- Secrets are owner-managed: never ask for, print, or commit DB/SMTP/VAPID secrets.
  Production migrations and live-DB changes: prepare + document, the owner runs them.
- The legacy import is one-way, read-only against its source, idempotent (provenance
  keyed), audited — and only the owner runs it against production.
- Per-unit custom field values (`handovers.extra_fields`, design §6.2 "Ceiling 2") are plain
  text and are NEVER purified server-side (unlike the four rich-text fields). Every consumer
  must escape on render — `{{ }}` interpolation / `:value` binding in Vue, never `v-html`.
- Never add a key allow-list to `App\Casts\EncryptedJson`. Its keys are map keys inside one
  column, not model attribute names, so `ExtraRowFields`' mass-assignment reason for an
  allow-list does not apply — and an allow-list keyed on `unit_field_definitions` would
  actively delete a value from history the moment its definition is retired. A clinical value
  must survive the removal of the definition that produced it.
- **ONE DATABASE PER CUSTOMER (D11).** The isolation boundary is the database, not the row.
  `institution_id` is provenance and in-instance grouping — never a query filter; row-level
  tenancy fails open, and the schema is one-way committed against it (several UNIQUE indexes —
  `units.code`, `people.email`, `users.member_name`, `handover_signoffs(unit_id,
  handover_date)`, among others — are institution-blind by design; see D11,
  `docs/superpowers/plans/2026-08-08-p0d-tenancy-provisioning.md`).
  `App\Support\Instance::slug()` is the one token
  that tells two deployments apart: it names the backup archive, scopes that archive's own
  retention sweep, and names the host scripts' config/log/state files — and it must be wired
  through `docker-compose.production.yml`'s `environment:` block with an explicit
  `${VAR:-default}`, not just added to `config/`, or Coolify's Environment Variables screen has
  no effect (P0d Task 9 found this empirically: two new variables shipped in Tasks 1 and 3 with
  no compose passthrough, so setting them did nothing). Operator commands select a stack with
  `docker/instance-env.sh <uuid>`, never by image ancestry.
- `APP_TIMEZONE` is per customer and must be correct BEFORE the first clinical write: `now()`
  moves with it, so the handover day boundary moves under existing rows. (It is not an
  audit-chain hazard — v3 hashes stored datetimes verbatim for exactly that reason.)
- Secrets that pass through Coolify are 48-character ALPHANUMERIC. `docker compose`
  $-interpolates env values, so a `$` in a password is silently truncated into something
  weaker. `APP_KEY` and `BACKUP_PASSPHRASE` are stored in DIFFERENT places — a backup and its
  key never sit together, and both are needed to read an archive.
- **All date logic goes through `App\Support\Calendar`** (Munawib AR-08, P1a). Nothing else
  constructs an `IntlCalendar`/`IntlDateFormatter`, or does date arithmetic — including
  `resources/js`, which receives formatted labels, enumerated date ranges and day types as
  Inertia props and performs none of its own (Decision A: the `packages/engine` mirror design
  §7 once described is deferred to P2, not built twice now). Guarded by
  `tests/Feature/Build/CalendarIsTheOnlyConverterTest.php`, asserted over the whole match set,
  never a `foreach` that stops guarding once the last offender is fixed. `strtotime()` is
  allow-listed for exactly three files, each commented at its site with why: `LegacyImport`,
  `LegacyReconcile`, `Plausibility::plausibleDates()` — all read a FOREIGN system's date
  strings from the frozen legacy import source, never an application date. Two further,
  conceptually separate exemptions that never route through Calendar at all: `AuditChain`
  (v3 hashes the stored datetime byte-verbatim; re-parsing it in the current timezone is the
  exact defect that once made the live system declare its own audit trail tampered) and
  `App\Casts\EncryptedDateTime` (PHI, unqueryable, whose getter can return a string marker for
  foreign ciphertext). Timezone is per-INSTANCE (`APP_TIMEZONE`); the Hijri offset
  (`institutions.hijri_offset_days`) is per-DEPARTMENT — never add an `institutions.timezone`
  column "for symmetry" (D11: one institution per database makes it one fact in two places).
- **A shared, framework-free fixture corpus is the contract between this PHP calendar and
  P2's future TypeScript mirror.** `tests/fixtures/calendar/golden.json`
  (`tests/Feature/Calendar/GoldenFixtureTest.php` asserts it here) is a durable artifact, not
  test scaffolding — P2's `packages/engine` calendar must assert the same file against itself.
  A change to one side not matched on the other is the drift this file exists to catch.
- **An index or unique key led by `institution_id` is a recurring mistake, not a one-off.**
  D11 keeps `institution_id` as provenance/in-instance grouping, never a query filter — but a
  plan-supplied migration snippet has twice proposed one anyway (P1a Task 4's `periods` unique
  index, P1a Task 7's `holidays` index), and both times the mistake was only caught empirically,
  by `InstitutionProvenanceTest::test_no_query_filters_on_institution_id` going red once the
  matching query code was written against the wrongly-shaped index. An index led by a column
  nothing ever filters on is dead weight, and worse, an invitation for a future
  `where('institution_id', ...)` "to match the index" — exactly the drift D11 forbids. When
  adding an index or unique constraint to any table carrying `institution_id`, lead with the
  columns actually filtered/compared on (see `periods_year_position_unique` on
  `(academic_year, position)` and `holidays`' `(active, calendar, month, day)` index for the
  corrected shape), and never with `institution_id` itself.

## Toolchain (this machine)

- PHP 8.4 at `%LOCALAPPDATA%\php84`, Composer shim at `%LOCALAPPDATA%\composer-bin`
  (both on user PATH; prepend to PATH in fresh shells if not picked up)
- `php artisan test` (PHPUnit, sqlite :memory:) · `npm run build` (Vite) ·
  `npm test` (Vitest) · `npm run test:e2e` (Playwright, self-contained world)
- ALWAYS filter verbose output: pipe test runs through `Select-Object -Last 5`
  (PowerShell) or `tail -5`; on failure re-run only the failing filter with
  `--filter <TestName> | Select-Object -First 30`. Never dump a full failing
  suite into context.
- The whole PHP suite runs at `Asia/Riyadh` (`phpunit.xml`'s `APP_TIMEZONE` env), matching
  production — not UTC (P1a Task 3, 2026-08-08). It was genuinely proven green there, not just
  config-flipped: `config(['app.timezone' => ...])` alone does **not** move PHP's default
  timezone, so `now()`/`Carbon::parse()` would not have moved with it either, and that trap
  would have made a false "green" indistinguishable from a real one.
  `tests/Feature/Calendar/DayBoundaryTest.php` is what actually exercises the 00:00–03:00
  UTC/Riyadh disagreement window with PHP's default timezone genuinely moved
  (`TestCase::withTimezone()`), independent of whichever timezone `phpunit.xml` happens to run
  the rest of the suite at.
- **Run test/build commands via Bash, not PowerShell.** PowerShell's PATH on this machine lacks
  `openssl`, so the backup tests self-skip there rather than fail — a false "green" that looks
  identical to a real one unless you know to check for it.

## Domain vocabulary

- The four rich-text fields: `disease` ("Problem List"), `details` ("Clinical
  Condition"), `plan` ("Plan of Care"), `nevent` ("To be followed"; PICU print header
  says "New events"). nevent CARRIES FORWARD on new day (owner ruling).
- Day identity: (unit_id, handover_date). Sign-off is a per-day header row
  (`handover_signoffs`, UNIQUE on that pair); `signed_off_at` = locked.
- Identity is TWO tables (P0c, D3 reversed 2026-08-08): `people` is the roster and the name/role
  of record — `full_name`, `short_name`, `position`, level history (`person_levels`,
  `Person::levelAt()`), `email`/`phone`, `notes`/`constraints` (both plaintext — owner decision
  3), `external`, `active` = may be NAMED. `users` is purely the account — `member_name`,
  `password`, 2FA, signature, `active` = may LOG IN — linked by `users.person_id` (UNIQUE,
  nullable). A roster-only person has **no `users` row and therefore cannot authenticate by
  construction** — that replaced a design that would have needed a gate at twelve separate
  places. Never add a credential column to `people`, and never reintroduce a `person_status`
  lifecycle enum: "claimed" is a join (`Person::hasAccount()`), not a column.
  `people.id` and `users.id` are INDEPENDENT sequences — never compare or copy them positionally.
- Endorsed by/to pickers: active people at position 4 or 5 WHO HAVE A CLAIMED ACCOUNT (D9 — their
  signature is the evidence). Consultant pickers: any active person at position 3, account or
  not — the on-call consultant is a name of record and frequently never logs in. Both the offer
  and the write-side rule come from ONE predicate per field in `App\Support\SignoffPickers`.
  WARD has a single "Consultant Oncall" stored in `consultant_by_*`.
- `SignatureStore` stays keyed on `users`, not `people` — that is what keeps naming separate
  from signing. A named person with no account has no signature; that is a valid, documented
  attestation state (2026-07-27 ruling), not a bug.
- Roles: 0 Admin, 2 Charge Nurse, 3 Consultant, 4 Resident, 5 Chief Resident. Position 1
  (Nurse) is RETIRED — never revive it or reuse the number.
- Unit variation lives in ONE place: the `units` row. `App\Support\UnitProfile` is the value
  object that shape travels in (`$unit->profile()`); it holds no per-unit values. Never
  reintroduce a hardcoded unit list — `Unit::codes()` is the only source, and every lookup
  built from user input goes through `Unit::findByCode()` (the `code` mutator normalizes
  writes, not a query's WHERE value). Units are opt-in `active`. Two known exceptions, pending:
  `resources/js/Layouts/AppLayout.vue` (sidebar nav) and `resources/css/app.css` (hue classes)
  still hardcode the four units — a fifth department gets no nav entry or hue until those move
  to configuration.

## Invariants the 2026-07-26 audit had to restore (don't regress these)

- A picker's write-side validation must match what it OFFERS, PER FIELD since D9. `exists:users,id`
  let any account be named as endorser — and sign-off freezes that person's signature onto
  medico-legal evidence. `App\Support\SignoffPickers` holds one predicate per field (a closure
  over a query builder), applied to both the `Rule::exists` and the offered list, because
  `Rule::exists` runs on the raw query builder and never sees Eloquent's SoftDeletes global scope
  — a predicate written once as Eloquent and once as raw SQL is two predicates that drift.
  `tests/Feature/Endorsement/PickerParityTest.php` asserts it as a matrix (every fixture x all
  four fields).
- `handover_signoffs`' four NAMED roles are `*_person_id`; `signed_off_by_user_id` and
  `reopened_by_user_id` stay `users` — names of record versus actors. `people.id` and `users.id`
  are independent sequences: never move an id between them without a join through
  `users.person_id`.
- `$user->full_name`, `$user->position` and `$user->member_email` are READ-THROUGH ACCESSORS onto
  the linked `Person` (P0c) — none is a real column on `users` any more. Any
  `select()`/`pluck()`/`value()`/constrained `with()` that omits `person_id` loses the accessor's
  ability to resolve, and the read silently returns null — no error, no exception. This broke
  four live sites with zero test coverage before it was caught empirically: the Access Control
  picker, `EndorsementController::staffPickers()`, `InvitationController::openInvitations()`'s
  `with('invitedBy:id,full_name')`, and a test's own `User::where(...)->value('position')`.
  Always carry `person_id` through a narrowed query, or fetch the whole model.
- `users.member_email` is DEAD: it still physically exists, still carries its original UNIQUE
  index, but nothing on any live write path writes it any more — `people.email` is the single
  authoritative address, and the password broker/uniqueness checks resolve through
  `Person::matchByEmail()`/`Person::accountEmailRule()`, not the raw column. The one exception is
  `LegacyImport`'s one-time historical upsert, deliberately left alone. Never read or write the
  raw column directly; dropping it is an open item (design doc §14).
- `TRUSTED_PROXIES` is never `*` (Symfony then takes the client-supplied leftmost
  X-Forwarded-For: forgeable audit IPs, bypassable lockout), and X-Forwarded-Host is never
  trusted (password-reset link poisoning). `trustHosts` must keep loopback — the
  HEALTHCHECK uses 127.0.0.1.
- Never cache decrypted secrets: `CACHE_STORE=database`, so plaintext would land beside the
  encrypted rows. Cache ciphertext, decrypt per read.
- Every clinical write calls `assertDayUnlocked()`. `newDay` was the one that didn't.
- `/up` must prove the DATABASE is reachable (connection, not a table — it has to pass
  before the first migration) or the container reports healthy while every page 500s.
- The audit canonical string has exactly ONE definition (`AuditChain::canonical()`), shared
  by the writer and `audit:verify`. Two copies drifted the day `APP_TIMEZONE` was set and
  the live system announced its whole trail as tampered; nothing had been. Never re-parse a
  stored naive datetime in the *current* timezone — v3 hashes it verbatim for that reason.
  A test that only calls `config(['app.timezone' => ...])` proves nothing: it does not move
  PHP's default timezone, so `now()` and `Carbon::parse()` don't move either.
- Full detail: `docs/SECURITY-AUDIT-2026-07-26.md`, `docs/PRODUCTION-READINESS-2026-07-26.md`.
