# Paediatric Endorsement System

A departmental clinical platform. **Endorsement** (shift handover, holds PHI) is what exists
today. **Rota** (duty scheduling, holds none) is planned, P1 onward — see the design doc,
`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`.

Endorsement covers handover ONLY: no registry, no scoring, no KPI dashboards (beyond the
missed-days counter), no nursing sheets. Units are CONFIGURATION, not code — PICU, NICU,
SCBU and WARD are seed data for the QCH institution.

## How to work here

Read this section every time. It is the method; the rules below are the constraints.

### Evidence, not impression

- **Verify by EXIT CODE.** `php artisan test | tail -3` returns *tail's* status, so
  `&& git commit` gates on nothing — that is how a red suite reached `main` on 2026-07-27.
  Capture it: `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"`.
- **A passing test must be shown capable of failing.** Plant the defect, watch it go red,
  revert. Three assertions shipped vacuous in one day: a signature fixture that did not match
  the content-addressed path the code requires, a `robots.txt` check that matched its own
  comment, and an e2e seeder gap that silently redirected every browser spec.
- **Green tests are not a deployed system.** Check the deployed behaviour after deploying. A
  Cloudflare trust fix once passed every test, deployed healthy, and changed nothing at all —
  the compose default it edited was dead code, because the platform sets that variable
  explicitly. And the repo is not the host: `/usr/local/bin/endorsement-*` are hand-installed
  copies that drift (see `docs/RUNBOOK-DEPLOY.md`).
- **Never claim done without the output that proves it.** "Should work" is not a result.

### Genuine code only

- No placeholder, no stub returning a plausible value, no test asserting what the code just
  did. If something cannot be verified, say so plainly instead of asserting it.
- **Fix at the CAUSE.** Three separate bug reports — a PNG rendered over the whole page, saves
  landing on the wrong screen, and phantom sign-outs — were one defect: `back()` resolving
  against a session value Inertia never updates. One fix closed all three; thirty-five edits
  would have closed none.
- When you are wrong, correct it in a sentence and continue. Do not re-litigate or apologise
  at length.

### Fast and complete

- Batch independent tool calls into one message. Filter every command — `tail -3`, `grep -c` —
  and never dump a failing suite into context.
- Do not re-read a file you just edited, or re-derive a fact already established this session.
- Do the WHOLE ask. If part is unsafe or blocked, finish everything else and say exactly what
  you left and why — scaling the work down is the owner's call, not yours.
- Prefer the smallest correct change. Then deploy, verify, and state what remains.

### Before editing an area

This file holds the rules that bind EVERY task. Per-area invariants — rota, clinics, calendar,
invitations, access control, demo, setup, legacy import — live in **`docs/INVARIANTS.md`**.
**Read that file's section for the area you are about to change, before you change it.** Most
are enforced by `tests/Feature/Build/*`, but a guard tells you *that* you are wrong, never
*why*, and the why is what stops you reintroducing it sideways.

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

These bind every task. Area-specific invariants are in `docs/INVARIANTS.md` (index below).

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

- **`tests/fixtures/roster/` is synthetic, and stays that way.** No real staff list — names,
  emails, phone numbers of actual QCH personnel — belongs in this repository at any time, in a
  fixture or anywhere else. `RosterImport`/`CsvRosterReader`'s test corpus is built to exercise
  specific failure shapes (duplicate emails, an unknown level code, Arabic names, a row that
  collides with an existing account), not to resemble a real department. The first import
  against a real staff list is the owner's to run, against production, having previewed it
  first — never a fixture checked into this repo.

- Per-unit custom field values (`handovers.extra_fields`, design §6.2 "Ceiling 2") are plain
  text and are NEVER purified server-side (unlike the four rich-text fields). Every consumer
  must escape on render — `{{ }}` interpolation / `:value` binding in Vue, never `v-html`.

- Never add a key allow-list to `App\Casts\EncryptedJson`. Its keys are map keys inside one
  column, not model attribute names, so `ExtraRowFields`' mass-assignment reason for an
  allow-list does not apply — and an allow-list keyed on `unit_field_definitions` would
  actively delete a value from history the moment its definition is retired. A clinical value
  must survive the removal of the definition that produced it.

- **Every CSV write goes through `App\Support\Csv::stream()`; every CSV read goes through
  `App\Support\Roster\CsvRosterReader`** (P1c). A cell whose first character is `=`, `+`, `-`,
  `@`, TAB or CR is executed as a formula by Excel, LibreOffice and Google Sheets on open —
  `Csv::neutralise()` prefixes it with an apostrophe on write, and the reader strips exactly one
  leading apostrophe back off on read, or export → re-import silently renames an affected cell
  on every round trip. `tests/Feature/Build/CsvInjectionTest.php` asserts the pairing, not
  just the write side.

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

- **`.env.example` is CI's entire environment AND the `.env` every fresh checkout gets, so a wrong
  line there is wrong in two places.** `.github/workflows/ci.yml` does `cp .env.example .env`, and
  `composer.json`'s `post-root-package-install`/`post-create-project-cmd` copy it whenever `.env`
  is absent. It is NOT the production path — a real deployment is configured through Coolify and
  `docker-compose.production.yml`'s `environment:` block — which is exactly why bad lines in it
  survive review: the file reads as documentation, and its one executing consumer is CI.
  Laravel resolves a PRESENT-BUT-EMPTY key to `''` and an ABSENT key to `env()`'s default — so
  `INSTITUTION_CODE=` made `env('INSTITUTION_CODE', 'QCH')` return `''`, `ReferenceSeeder`
  anchored the deployment on an institution with no code, and **459 of 1446 tests failed in CI on
  a tree green on every developer machine** (2026-08-11; this machine's `.env` predates the keys,
  which is why it was invisible here). The same trap was already known and already guarded for
  `docker-compose.production.yml`'s `${VAR:-default}` (`DeploymentInvariantsTest`, after P0d Task
  9's rehearsal) and never carried across to the file beside it. Two further shapes, both found
  only because the FULL suite was re-run under the fixed template: a NON-empty value neuters a
  default just as completely — `TRUSTED_PROXIES=*` discarded `TrustedProxies::DEFAULT`'s three
  RFC1918 ranges (25 entries → 22) even though the wildcard itself is correctly refused — and a
  template must never pin a default the code computes from the environment, which
  `REQUIRE_2FA_PRIVILEGED=true` did in a file whose own `APP_ENV` is `local`, forcing the 2FA
  challenge on in CI and leaving 384 tests red after the empty keys were fixed. Guarded whole-set
  by `tests/Feature/Build/EnvExampleNeverNeutersADefaultTest.php` (rulings 46-47): a key may be
  shipped empty only on the allow-list with a stated reason (`APP_KEY` must be present-and-empty —
  `key:generate` rewrites the line in place and has nothing to rewrite if it is absent). **CI
  running from `.env.example` is correct and stays** — it is what keeps the template honest, and
  it is why all three defects surfaced at once.

- **A single-writer guard is audited by PLANTING, never by reading its needle list** (rulings 66-71,
  the 2026-08-12 sweep; design §14 item 26 closed). Every guard in `tests/Feature/Build/` was probed
  with a plant of each of seventeen writer shapes, and the ranking that produced is the opposite of
  the one the lists suggest: `PersonActiveHasOneWriterTest` has the tidiest list in the suite and
  named 4 probes of 22 — `$person->active = false; $person->save();` walked straight past it, which
  is review finding 4's original defect in the idiom this codebase uses for a single-column change.
  **Prove every needle you add by planting a file of exactly its shape, then revert; measure every
  needle's cost before adding it; state every residual in the guard's own docblock.** The recurring
  facts: `Model::query()->create(` is a sixth writer shape after ruling 42's three and ruling 50's
  two, and `Model::query(` taken WHOLE is the only needle that spans `::query()->where(…)->delete()`
  or a chain broken across lines — affordable **only** where nothing outside the writer reads the
  table (`UserCapability`, `DemoRow`, `ClinicAttendee`), and withdrawn for `MasterRotaAssignment`
  (9 files), `Person` (12), `Clinic` (5), `Invitation` (5) and `PersonLevel` (4), because the entry
  it buys blinds the guard at `RotaFill`, `RotaGrid`, `ClinicController`, `Promotion` or
  `InvitationStatus` — the files a real second writer is born in. A `->update(['col'` needle reaches
  only a **single-line** call whose **first** array key is that column (ruling 71); the symmetric
  `->create(['col'` family measures zero for a formatting reason, not a safety one, and was not
  bought. Each guard also carries a VACUITY TWIN asserting its control writer still matches a needle
  — per control file where a guard names two, since a list healthy for one and blind for the other
  passes a pooled check and is half a guard.

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

- **Adding a `unit_id` column obliges you to answer `App\Support\UnitMerge`.** The same shape as the
  invariant above — a cross-cutting obligation nothing checked — and it cost three stranded tables
  over four slices (design §14 item 23, shipped 2026-08-12): a merged-away unit kept its rota spans,
  its clinics and its members' push-reminder opt-ins, the last with **no screen anywhere able to
  repair one**, so those reminders simply stopped. Nothing was broken inside the writer; the rule was
  missing. `UnitMerge::REFERENCES` now names every foreign key that points at `units` with one
  sentence on what a merge does with it, `UnitMergeCoversEveryUnitReferenceTest` derives the real set
  from the LIVE schema and compares in both directions, and **an entry is a decision, not
  documentation** — a table whose answer is "a merge deliberately leaves this" still belongs in the
  map, spelled out. `master_rota_assignments` and `clinics` are re-pointed through
  `RotaAssignment::repointUnit()`/`ClinicWriter::repointUnit()`, never by `UnitMerge` itself: both
  single-writer guards had already refused to allow-list this file *in advance*, on the argument that
  an exemption would blind them exactly where the next offender arrives, and the offender was this
  file (rulings 61–63).

- **A REFUSAL MUST BE FLASHED UNDER A KEY THE RECEIVING SCREEN ACTUALLY RENDERS, and the two halves
  are asserted TOGETHER** (rulings 41 and 49). Three instances in two slices: P1c-2's single resend
  keyed `member_email`; P1e-1's clinic attendee lists keyed `level_ids.N`/`person_ids.N`; and
  `ClinicController::setActive()`'s restart refusal keyed `active`. Each looked correct in review and
  each was a control that appeared to do nothing. Every refusal therefore gets a PAIR of tests — the
  key in PHPUnit, the render site in Vitest — because either alone stays green while the other rots.
  **There is deliberately NO source-level guard for this and the measurement is why** (ruling 49):
  "every flashed key is rendered by SOME screen" is green on the very defect it would exist to catch,
  since `errors.person_ids` is rendered by an unrelated panel on Admin → People; the sound version is
  per-SCREEN, and `back()` chooses its screen from the referrer at runtime, so no substring scan has
  the edge to follow. Do not rebuild it without re-measuring.

- **A validation rule is asked only of the input the chosen MODE reads** (ruling 48). Applying a
  picker rule to a list the request will discard reads as belt-and-braces and is a lockout: the
  discarded list refuses over ids that reach neither the writer, the database, nor any element on
  the screen, and the mode that needs no lists at all (`rotators`) gets refused too — which removes
  the state the row could have been repaired from. Its client twin is the same invariant one layer
  along: **a form seeds from the intersection of what is STORED and what the pickers OFFER, never
  from the stored list alone.** An id with no checkbox is an id nobody can untick. D9 is untouched by
  either — one predicate per field, and the list a mode DOES read still refuses everything that list
  never offered.

- **A single-writer guard must needle `$model->update([...])`** (ruling 50) — it is this codebase's
  house idiom (`UnitController`, `LevelController`, `HolidayController` all write `setActive()` that
  way) and `ClinicWritersAreSingularTest` shipped blind to it, measured green against a plant
  rewriting six columns on both guarded tables. Two families, kept because they fail differently:
  column-qualified (`->update(['weekday'` — catches any variable name, but matches only the array's
  FIRST key) and variable-qualified (`$clinic->update(` — catches any column, which is the only
  reach available over `name`/`active`/`location`/`note`/`unit_id`, each being another table's
  column too). Measure before adding, per ruling 42: `->update(['active'` was written and WITHDRAWN
  because it names six files across five tables and would allow-list `UnitController` and
  `UnitMerge`, the two most likely to grow a real offender. **`RotaWritersAreSingularTest` and
  `PersonLevelsHaveOneWriterTest` still share this hole** (probed, not read); the other three
  single-writer guards close it incidentally.

## Area invariants — `docs/INVARIANTS.md`

Not optional, just not needed for every task. Read the section matching what you are touching:

| Touching | Read |
|---|---|
| `master_rota_assignments`, `vacations`, periods, `RotaFill`, rota screens/export | §Rota |
| `clinics`, `clinic_attendees`, the clinic map | §Clinics |
| Anything with a date, `Calendar`, holidays, Hijri | §Calendar and dates |
| `invitations`, issuing / resending / redeeming | §Invitations |
| `people`, `users`, positions, capabilities, `access.manage` | §Identity, access and positions |
| `DepartmentSetup`, the setup checklist | §Department setup |
| `demo_rows`, `DemoLedger`, `DemoDepartment` | §Demo department |
| `institutions.code`, provisioning a customer | §Provisioning and institutions |
| `LegacyImport`, `LegacyReconcile` | §Legacy import |

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
- **`phpunit.xml` sets `memory_limit=512M`, and that is not decoration.** The suite crossed PHP's
  stock 128M CLI ceiling somewhere between 1,338 and 1,360 tests (measured, P1c-2 Task 4: green at
  1,338 under 128M, fatal at 1,360, green at both under 512M). It exhausts CUMULATIVELY, so the
  fatal lands on a different test each run and is reported from inside `vendor/` — which reads
  exactly like a real defect and is not one. Do not remove it, and do not chase it with
  `php -d memory_limit=… artisan test`: `artisan test` runs PHPUnit in a **subprocess**, so the
  flag never reaches it. That dead end cost an hour once already.

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
- **`App\Support\PersonPresenter` is the ONLY path from a `Person` to Inertia props** (P1c),
  gated by `App\Policies\PersonPolicy` (`viewContact`/`viewNotes`) and, for `phone` only,
  `institutions.contact_visibility`. **`Person::$hidden = ['phone', 'notes']` is NOT the
  control and never was** — it bites on `toArray()`/`toJson()` only, and every admin screen in
  this codebase builds its props from an explicit map that never touches `$hidden`. A withheld
  contact field is ABSENT from the props array, never `null` — the two look identical on screen
  and a future consumer would eventually render one as the other.
  `tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php` pins the single-projection
  property at source level; a future screen written in the house style
  (`'phone' => $person->phone` inside a `present()` map) would leak the number regardless of
  `$hidden`.
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
  writes, not a query's WHERE value). Units are opt-in `active`, and are administrator-creatable
  from Admin → Structure → Units (P1b Task 4). The two exceptions this file used to flag as
  pending — `resources/js/Layouts/AppLayout.vue`'s hardcoded sidebar array and
  `resources/css/app.css`'s four-hue palette — were closed by P1b Task 3: the sidebar renders
  the shared `nav.units` Inertia prop (`Unit::navList()`), and `Unit::BAR_CLASSES` is an
  eight-entry allow-list (the original four plus four hue-named additions) that both offers the
  colour choice on the units screen and validates it, with `Unit::DEFAULT_BAR_CLASS`
  (`channel-bar-slate`) as the fallback for a unit with none chosen. A fifth department now gets
  a nav entry and a colour with no frontend change.
- The training-level ladder (Munawib LV-01) is seeded `R1, R2, R3, R4, EXT` with explicit
  `display_order` 10/20/30/40/90 (gapped by ten so an `R5` or `R2.5` can be inserted without
  renumbering), `EXT` flagged `external` and last, and is fully administrator-editable from
  Admin → Structure → Levels (P1b Task 8) — a rename survives `db:seed --force`. **There is no
  `levels.terminal` column and no `Level::nextAfter()` method — do not build either.** Owner
  Decision A (2026-08-09, `docs/superpowers/plans/2026-08-09-p1b-structure-admin.md`) rejected
  the inference outright: a wrong terminal marker fails silently in two directions — an
  unmarked top level lets a cohort advance into a level that does not exist, and a
  wrongly-marked middle level graduates a cohort a year early — and removing the inference
  removes the whole failure class. Whatever P1c's annual-promotion screen needs, it takes the
  **target level as explicit operator input**; it is not "the one definition of advance one
  level" reading off a column. `EXT` sits outside the ladder and is never promoted.

## Invariants the 2026-07-26 audit had to restore (don't regress these)

- A picker's write-side validation must match what it OFFERS, PER FIELD since D9. `exists:users,id`
  let any account be named as endorser — and sign-off freezes that person's signature onto
  medico-legal evidence. `App\Support\SignoffPickers` holds one predicate per field (a closure
  over a query builder), applied to both the `Rule::exists` and the offered list, because
  `Rule::exists` runs on the raw query builder and never sees Eloquent's SoftDeletes global scope
  — a predicate written once as Eloquent and once as raw SQL is two predicates that drift.
  `tests/Feature/Endorsement/PickerParityTest.php` asserts it as a matrix (every fixture x all
  four fields).
- **Every value in `$_GET`/`$_POST` is a string OR AN ARRAY, chosen by whoever typed the URL.** A
  `(string)` cast on an array raises `Array to string conversion`, which `HandleExceptions` promotes
  to an `ErrorException` and renders as a 500 — `/rota?q[]=x` was one, and four sibling sites shared
  the shape (`Admin/PeriodController`'s `next_year_start`; `Admin/AccessControlController`'s
  `user_id`, where `User::find(['1'])` returns a *Collection* that is not null and reaches
  `getKey()` as a `BadMethodCallException`; and both `member_email` normalisers, where a
  pre-validation `?string` sink turns a would-be 422 into a `TypeError`). Guard on the SHAPE —
  `is_string()` / `is_numeric()` before the sink — and never with `$request->string()`, which throws
  on array input too. Where the value feeds a FormRequest, let a non-string through untouched so the
  rules reject it the negotiated way. `tests/Feature/Security/ArrayShapedQueryTest.php` asserts a
  negotiated answer AND the echoed prop's type at every site; a guard that swallows a filter into
  `null` where the screen expects `''` is the same bug one layer along.
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
