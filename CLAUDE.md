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
- **Any write path touching `institutions`' calendar columns or a `holidays` row must call
  `App\Support\Calendar::flush()`.** `Calendar::settings()`/`::activeHolidays()` are memoised in
  statics for the life of the process; before P1b Task 10, `flush()` had NO production caller at
  all, so the redirect that followed a save would have rendered from the pre-save memo — the
  admin presses Save, the row changes, and the page still shows the old value. Guarded at source
  level by `tests/Feature/Build/CalendarWritersFlushTest.php` (same file-must-also-call-flush()
  discipline as the SQL-injection/dark-mode guards above), which has itself been observed
  failing against a deliberately non-flushing throwaway file before being trusted. Also from
  P1b Task 10: a calendar-month period system must begin on the FIRST of a month
  (`App\Support\PeriodGenerator::assertMonthAligned()`, called from both `months()` itself and
  the calendar-settings FormRequest — one definition, two consumers, never re-typed) or
  `months()` mislabels every period; and `period_type`/`academic_year_start` hard-lock, both
  server- and client-side, the moment any `periods` row exists (Decision D) — the refusal names
  the unlock ("delete this academic year's periods first"), and P1b Task 11 is what makes that
  unlock actually work.
- **A master-rota assignment is always a date-bounded span** (`master_rota_assignments`, one row
  per span, `starts_on`/`ends_on` NOT NULL on every row — no nullable range meaning "the whole
  period", no parent/child span pair). Overlaps for one person in one period are refused by the
  model; gaps are allowed and counted, never silently invisible. `App\Support\Rota\RotaAssignment`
  is the only writer, `RotaWritersAreSingularTest` proves it.
- **`vacations` carries no `period_id`, deliberately.** A vacation crosses period boundaries and
  is not a rotation — it overlays whatever unit the master rota already has a person on, and it
  must survive a department regenerating or switching its period system. Which period(s) it
  touches is a range intersection computed at read time, never a foreign key.
  `App\Support\Rota\VacationBooking` is the only writer, same guard.
- **Deleting an academic year's periods is refused while any `master_rota_assignments` row
  references it** (`PeriodController::destroy()`). Neither rota table soft-deletes — this is
  schedule structure, not a clinical row, and the hash-chained `audit_log` is the history; a
  mistaken clear is a real delete with no undo in the UI.
- **There is NO publish gate on the master rota** (owner decision, 2026-08-10). No `status`
  column, no draft state, no publish action, no "visible from" date. `/rota` always shows the
  current rota. This closes design §14's open item rather than deferring it: the option was
  listed as open until the owner answered it, and it is no longer. `rota.manage` defaults
  **Administrator-only** (same owner decision, **reversing** what P1d-1 shipped on 2026-08-09);
  `rota.view` is unchanged, seeded for every authenticated member, which is exactly why the read
  view must not leak contact data. An instance that already seeded the old Chief Resident grant
  **keeps it** — `AccessControlSeeder` applies each (position, capability) default once via
  `applied_role_defaults` and never re-asserts, so the remedy is an operator un-tick on Admin →
  Access Control (`docs/RUNBOOK-DEPLOY.md`), and **there is no migration and must not be one**:
  revoking a capability an administrator may since have kept deliberately is precisely what that
  seeder's design refuses to do.
- **`App\Support\Rota\AvailabilitySummary` is the ONE computation behind MR-07**, a pure fold over
  `RotaGrid`'s output that touches no model and issues **zero** queries
  (`AvailabilitySummaryTest::test_it_issues_no_query`), fed the same way by the editor and the
  read view (`AvailabilitySummaryParityTest`) so the two screens cannot disagree about how many
  R2s are on PICU in Block 11. It counts `uncovered_days` and `people_with_a_gap` **separately**,
  because a gap is a legal state (P1d owner decision 3) and 26 uncovered days is one person's whole
  block *or* 26 people missing a day each — rounding that difference away is what §35's
  "availability summaries match reality" forbids. `people_with_a_gap` and `unassigned_people` are
  disjoint, so they add up. The field is `stale_people`, a HEADCOUNT — it was `stale_assignments`
  until the adversarial review found it counting cells while its label said assignments, and
  "assignment" already means a `master_rota_assignments` row everywhere else here. **Order matters:**
  the summary is computed over the FULL grid including stale rows, and the read view filters rows for
  display afterwards; filtering first loses `stale_people` entirely and silently.
- **Every bulk rota operation goes through `App\Support\Rota\RotaFill`** and writes through
  `RotaAssignment`/`VacationBooking` like everything else — `RotaWritersAreSingularTest` fails the
  build for a second writer, with no allow-list entry. `plan()` and `apply()` share ONE `analyse()`;
  `apply()` re-derives inside its own transaction and trusts nothing the client sent. The whole set
  is validated and authorized before the first mutation — a refusal refuses the **whole** operation,
  never "412 of 780 applied". **A target cell carrying a split is SKIPPED unless that cell is
  explicitly confirmed** (per cell, absent means false), because a blanket fill destroying deliberate
  split work is silent data loss; a "cell carrying a split" is any span set other than empty or
  exactly one span covering the period end to end, never `count > 1`. **One `rota_fill` audit row per
  OPERATION**, ids and counts only, written **after** the transaction commits — never one per cell,
  which would serialise the chain tail and put hundreds of findings in one alert body. `rota_fill` is
  on `AuditAnomalies`' single-occurrence watch list and is the first rota action there; the five
  per-cell actions (`rota_assign`, `rota_split`, `rota_clear`, `vacation_book`, `vacation_cancel`)
  deliberately are not, and `AuditAnomaliesTest` asserts both halves. The commit is pinned to a
  `RotaFill::digest()` over the plan's own state projection (the source cell, and per target what it
  currently holds and what would be written over it) — deliberately **excluding** outcomes and the
  operator's confirmations, so ticking a box does not invalidate the pin or force a re-preview.
- **The rota exports as TWO files and imports through `App\Support\Rota\RotaImport`.**
  An assignments file (one row per span) and a vacations file (one row per vacation) —
  `rota-<year>.csv` and `vacations-<year>.csv`, one `RotaExport::filename()` so the two cannot drift
  into two conventions — on two `cap:rota.manage` GET routes, never one file mixing two row shapes
  and never a zip. A person is identified by
  `short_name` (the app-wide unique human handle) plus `full_name`: **no email, no phone, no
  `person_id`** — ids are instance-local, and D11 makes cross-instance identity a non-question.
  The importer **invents nothing** — an unknown handle, unit or `(academic_year, position)` pair is
  a named skip, there is no create path for any of the three, and `app/Support/Rota/RotaImport.php`
  is in `RosterNeverMintsCredentialsTest::SCANNED_FILES` so it cannot grow one for `users` either
  (that list brings the bare `->save()` needle with it, not only `User::create(`). Its unit of
  outcome is the **(person, period) CELL, not the line** — `RotaAssignment::split()` replaces a
  whole span set, so two lines describing two halves of one period are one outcome, and a cell whose
  spans do not all resolve is skipped WHOLE rather than half-applied. `SKIP_DUPLICATE` exists on the
  vacations file only, because assignments are keyed on (person, period) and REPLACE while a vacation
  has no natural key, so the same file imported twice would otherwise double every leave row.
  `week`-granularity leave snaps through `VacationBooking::snap()` — the same code path as the
  booking screen, never a parallel rule re-typed in the importer (owner decision, 2026-08-10) — and
  the preview reports the adjustment.
- **`access.manage` must always have an active holder, and the ONE guard is
  `App\Support\AccessManageGuard::guarding($couldLose, $field, $write)`** (rulings 44 and 45). It
  holds the whole shape in one body — open the transaction, run the write, ask the oracle, throw to
  unwind — because a door that asks without a transaction refuses a write it already committed, and
  a door that opens one and forgets to ask is what ruling 45 measured **six** times. Every door
  calls it: both override surfaces through `CapabilityGrant::applyForUser()`, plus
  `users/{u}/active`, `users/{u}/unbind`, `users/{u}/position`, `people/{p}` and
  `people/bulk`'s `set_active`. The guard **asks the oracle rather than deriving the answer**:
  `holdersOf()` is consulted INSIDE the transaction, after the write, and an empty answer throws
  (audit rows are written after the commit, so nothing claims it happened). Never re-derive it —
  **the danger is neither the word `deny` nor the word `Administrator`**: clearing a per-user
  *grant* that was somebody's only claim strips the capability just as completely (an omitted key is
  how that API spells it), and a position-0 account that has been DENIED the capability is cover to
  a role check and none at all to the real question. Phrased over the HOLDER SET, never over the
  actor — do **not** shorten it to "you may not deny yourself", which is only equivalent while
  `capabilitiesFor()` (which consults neither `users.active` nor the person link) and `holdersOf()`
  (which consults both) agree, and nothing enforces that. It is a **POSTCONDITION, not a causal
  test** ("is it held after this write", not "did this write take it"): one five-query answer
  instead of two, and in an already-unheld world it still permits every recovery — promotion to 0
  grants it by role default, reactivation passes null and is never asked — while refusing what keeps
  it broken. `$couldLose` null means "no account can be affected", which is both sound
  (`holdersOf()` inner-joins `people`) and the reason `RosterImport`'s per-row loop pays **nothing**:
  `SKIP_HAS_ACCOUNT` means no importable row ever has an account. `AccessManageLockoutTest` pins all
  of it — five doors, the permitted direction, and both halves of the cost — verified by planting a
  disabled guard and watching 13 of 19 cases go red.
- **`PositionChange::isLastActiveAdministrator()` and `wouldLeaveNoActiveAdministrator()` are GONE
  (ruling 45) — do not reintroduce either, or a third predicate shaped like them.** Both asked
  whether another active `people.position = 0` account remained, which stopped implying "somebody
  holds `access.manage`" the day the capability became deniable per account; five doors guarded on
  them and all five were measured emptying the capability with a 302. They were deleted rather than
  kept beside the new guard because every caller was a lockout guard asking the wrong question, and
  a role-shaped check left lying about is an invitation for the next door to ask it again — which is
  how it became five. `PositionChangeTest::test_the_account_console_delegates_to_the_one_definition`
  bans both names across five files, **matched against comment-stripped source**: while this fix was
  being made the controller's own prose mentioned the deleted method and the old substring assertion
  went on passing against a method that no longer existed. `assertMayPlaceAtAdministrator()` (who may
  hand out position 0) and `AccessControlController::assertNoSelfLockout()` (the position-0 role
  DEFAULT keeps `access.manage`) are different questions and both stay.
- **`PersonPresenter` gates BOTH `email` and `phone` behind `viewContact`.** `email` shipped
  ungated until P1d's rota grid became the first consumer holding a narrower capability
  (`rota.view`, every seeded position) than every prior caller (`people.manage`, which also grants
  `viewContact`) — a rota surface reaches a person only through the presenter, never a second
  projection.
- **NO rota surface projects a contact field, for any viewer** (P1d-2 Decision C).
  `RotaGrid::forYear()` takes **no viewer at all** — the parameter was removed so no future caller
  can pass one and expect it to mean something — and calls `PersonPresenter::contactFree()`, which
  is `one()` with the contact question answered "no" at the call site rather than by a department
  toggle. The gate was never sufficient on its own: `PersonPolicy::viewContact()` is
  `people.manage || ContactVisibility::membersMaySeeContact()`, so the **first branch alone** was
  releasing email and phone into `/admin/rota`'s props for its ordinary viewer on a **default**
  department (`contact_visibility = admins`) — not, as the P1d-2 plan's own finding 3 claimed, only
  on one that had opted in. Nothing rendered them, which made it invisible in review rather than
  harmless. Asserted at the most permissive combination the system can produce — an administrator
  holding `people.manage` on a department set to `members` — by
  `RotaReadViewTest::test_the_editor_grid_is_contact_free_too`; the export asserts the same property
  over the file's BYTES.
- **MR-04 — the rota driving on-call eligibility — is Stage 2.** Nothing in the rota infers
  eligibility: no `off_roster` flag, no call-roster derivation, no per-person include/exclude
  override. P1d ships the rota's data and screens and records the hook only. The guard is two scans
  that fail for different reasons (`RotaAccessTest`): four identifier needles over the whole of
  `app/`, and a narrow, case-insensitive eight-needle scan over `app/Support/Rota/` in full plus the
  rota's controllers, form requests and Vue screens. **That second scan strips comments before
  matching**, deliberately departing from `CalendarIsTheOnlyConverterTest`'s prose-matching
  discipline: three rota classes open with a paragraph saying they must never become an eligibility
  computation, so a literal needle scan would fail the build on the rule's own statement and teach
  people to delete it. The stripper therefore needs its own calibration test
  (`test_the_scan_strips_comments_and_still_sees_the_code`) — a stripper that over-reached would
  disable the guard and look exactly like a clean tree.
- **The invitation lifetime is configurable, default 7, bounded [1, 30], behind `settings.manage`**
  (`Invitation::lifetimeDays()`, `app_settings.invitation_lifetime_days`, P1c-2 Task 1). Seven is a
  **deliberate, logged override** of Munawib AC-02's "default 14 days" — recorded in the design
  doc's §1.2 overrides table, not left in an open-items list — because a redeemed invitation
  reaches a child's clinical records, so a forwarded link stays live for half as long.
  `LIFETIME_DAYS` is the DEFAULT, not the value: an unset or out-of-bounds setting falls back to
  it. The clamp is not belt-and-braces over the FormRequest — `app_settings` is a plain key/value
  table an operator can also reach from a database console, and that write never passes a
  validator. The key sits in `AppSettings::KEYS` with **no** entry in `applyOverrides()`'s `$map`
  (it overrides no framework config, exactly like `alert_email`); do not "fix" the omission.
- **`App\Support\Invitations\InvitationIssue` is the only writer of `invitations`** (P1c-2
  Decision C), and a **resend rotates the token** — the superseded row is kept, `revoked_at` and
  `revoked_by_user_id` set, never deleted. Re-mailing the same token would extend the life of a
  credential that may already be in the wrong hands and make revoking the first link meaningless.
  `InvitationWritersAreSingularTest` proves the single writer. Two real defects the refactor
  surfaced, both worth not reintroducing: the supersede predicate **ignored the clock**, so
  re-inviting somebody stamped `revoked_at` onto a merely *expired* row, rewriting "this expired"
  as "a person killed this" in the claim-status projection; and the superseded set matched on
  **address alone**, so correcting somebody's address and resending left a live link addressed to
  the old mailbox. `InvitationIssue::liveFor()` now matches `person_id = X OR member_email = Y`,
  because the invariant "at most one live link" is per-PERSON. **Bulk resend sends mail AFTER the
  transaction commits, never inside it** — mail cannot be rolled back, and recipients holding live
  links to invitations that do not exist has no recovery; the reverse (rows exist, some mail did
  not go) is visible, reportable per person and fixable by resending.
- **An invitation is authorized against the BOUND PERSON's position, never `invitations.position`**
  (`InvitationIssue::issue()`, adversarial review F1, 2026-08-11). `InvitationAcceptController`
  takes the `person_id !== null` branch for every row this system mints and that branch does **not**
  write `position` — deliberately, because `people.position` has one writer (`PositionChange`) and
  an invitee must not re-rank the roster row they are claiming. So a redeemed account resolves its
  capabilities from the ROSTER (`$user->position` is a read-through accessor; `AccessControl::
  resolve()` joins `role_capabilities` on it) while all four endpoints were checking a column
  nothing downstream consults. The two diverge with **no misuse at any step**: an admin invites a
  new joiner at 4, later corrects that roster row to 0, and the live invitation still reads 4 — a
  Chief Resident may target 4, so resend, bulk resend and (via `Person::matchByEmail()`) invite each
  handed them a link that mints an Administrator. Asserted at the ONE WRITER beside the supersede
  loop and before the transaction opens, so all three doors close together; on `store()`'s create
  branch the person is opened at exactly `$position`, making it a no-op there.
  `InvitationStatus::mayInvite()` already gated the OFFER on `people.position` — offer and write now
  agree (D9). Do **not** "fix" the asymmetry by writing `position` at redemption.
  `tests/Feature/Security/InvitationPositionEscalationTest.php`, whose
  `test_a_redeemed_account_resolves_capabilities_from_the_roster_not_the_invitation` passed on the
  tree *before* the fix — the escalation stated as a fact about the system, not inferred.
- **`BulkResend::positionsToAuthorize()` returns a UNION and is never empty for a non-empty
  selection** (review F3/F4, 2026-08-11): every selected person's `people.position` **plus** the
  live invitations from `InvitationIssue::supersededBy()`. Both halves are load-bearing. The
  invitation half alone returned `[]` for a selection that had all claimed or was never invited —
  and these two endpoints sit in an **`auth`-only** route group (invitations are this codebase's one
  deliberate exception to a `cap:` middleware, the rule being two-tier and position-dependent), so
  the controller's `foreach` asserted nothing and any authenticated account received the plan: per
  person, their invitation state, invitation id and whether they hold an account. **An `auth`-only
  group is only sound while the in-controller pass is guaranteed to assert something.**
  `supersededBy()` is the ONE definition of "the set this operation will supersede", shared by the
  writer and its pre-authorization — they diverged both ways before: the pass matched `person_id`
  alone (so an invitation reached only by the ADDRESS axis fired `assertMayTarget()` from *inside*
  `commit()`'s transaction and the `user_scope_denied` row rolled back with it — P1c-1 finding 12
  through a different door) and had no expiry filter (so a merely aged-out higher-position row
  refused a batch the operator was entitled to run).
- **Unbinding an account is `App\Support\AccountUnbind` and nothing else, and it SNAPSHOTS
  `handover_signoffs.signed_off_by_name` before it clears the link.** For any handover signed
  before 2026-07-27 that column is null and the signer's name resolves live through
  `users.person_id` → `people.full_name`, so clearing the link blanks the attestation on
  medico-legal evidence — precisely the failure the freeze migration exists to prevent, reached
  through a different door. The snapshot writes a currently-null column with the value the sheet
  already renders; `whereNull` is what keeps it a snapshot rather than a rewrite. It clears the
  link and deactivates in ONE atomic act (an active-but-unbound account is nameless and
  positionless on every screen with no error at all), never touches `people`, refuses the last
  active Administrator, and an unbound account **cannot be reactivated** — there is deliberately no
  rebind action. `AccountLinkHasOneWriterTest` and `PersonActiveHasOneWriterTest` are two separate
  guards on purpose: deactivating a **person** and retiring an **account** are different acts, and
  `AccountUnbind` needing no entry in the second is what proves they are disjoint.
- **`App\Support\CapabilityGrant` is the only writer of `user_capabilities`** (P1c-2 Decision F,
  `CapabilityWritersAreSingularTest`), and both doors go through it — Admin → Access Control and
  the People screen's roles panel. Grants stay keyed to the ACCOUNT; AC-04's "roles granted per
  person" is a second **surface**, not a second table, so `AccessControl::resolve()`, `holdersOf()`
  and the cache key are untouched. The panel is gated `access.manage`, **never `people.manage`** —
  a role-granting control served from the roster console's own route group would let a holder of
  "who exists and what level they hold" grant themselves the security console. A colleague who
  leaves and returns on a new account does **not** regain old roles; an administrator re-grants
  them. Auto-restoring on re-bind means a departed administrator's grants silently reattach to
  whoever claims that identity next, reviewed by nobody.
- **Placing somebody at position 0 requires `users.manage`** (`PositionChange::write()`, review F2,
  2026-08-11) — and that **falsifies AC-04's stated premise**. Decision F's gate is right, but its
  reason ("hanging a role control off the roster gate *would* create an escalation path") described
  a path that already existed one field to the left: `PersonRequest::POSITIONS` offers 0, position 0
  holds `access.manage` by seeded role default, and `AccessControl::resolve()` reads
  `people.position` — so a `people.manage` holder promoted their own roster row, had their
  capability cache flushed for them by the write itself, and held the security console. Gated in
  `PositionChange::write()`, the single definition all three writers pass through: a rule in the
  FormRequest alone would not reach `RosterImport`, which calls `applyWithoutAudit()` from a
  `cap:people.manage` route and resolves its position column by NAME against `positions` — a CSV
  cell reading "Administrator" never meets a FormRequest rule at all. `applyWithoutAudit()`
  therefore takes the actor **positionally and non-optionally**. It is the **TRANSITION** that is
  gated, not the value: a `people.manage` holder may still edit a sitting Administrator's roster row
  (the edit form submits the position it was rendered with, and refusing that would make every
  administrator's row unsaveable by the console that exists to edit rows); a CREATE is a placement,
  which is what `wasRecentlyCreated` distinguishes. It throws rather than auditing — a refusal
  written from inside `PersonController`'s or `RosterImport`'s transaction would roll back with it.
  The offer narrows to match: `grantable_positions` (`PositionChange::grantableBy()`) is what the
  People screen's two role `<select>`s offer, PLUS whatever the row already holds; the full
  `positions` catalog still ships beside it, because it is what renders an existing Administrator's
  role NAME.
- **CLOSED (rulings 44 and 45): `assertNoSelfLockout()` guards the ROLE matrix only, and never did
  more.** It does not run on the per-user override path, nor on any account-lifecycle door — which
  is why a holder of `access.manage` could deny it to the last account holding it (measured in P1c-2
  Task 6 and deliberately left, because that extraction had to be behaviour-preserving), and why
  five more doors were still open after ruling 44. All six now go through
  `App\Support\AccessManageGuard`; `assertNoSelfLockout()` stays where it is, guarding the position-0
  role DEFAULT, which is a third question again.
  `PersonRolesTest::test_the_two_doors_agree_about_denying_the_last_access_manage_holder` asserts the
  two override surfaces AGREE rather than that either permits, which is what makes it the proof the
  guard reached both. Design §14 open item 20 is discharged.
- **Claim status is DERIVED, never stored** (`App\Support\Invitations\InvitationStatus`, P1c-2
  Decision B): five states folded, in precedence order, from `accepted_at`/`revoked_at`/
  `expires_at`, plus `hidden` for a target the viewer may not manage. A stored status column is the
  `person_status` lifecycle enum D3 removed, wearing a different name. It is per-caller `$extra` on
  `PersonPresenter::one()` and **never a base key** — the base map reaches every `rota.view` holder,
  and "who has not claimed their account" is not a fact the whole department gets. It takes Person
  MODELS, not ids: a person with no invitation row has nothing to join a position from, so an
  id-only signature could not scope exactly the people whose state is `none`.
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
