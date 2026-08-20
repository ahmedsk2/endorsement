# Area invariants

Split out of `CLAUDE.md` on 2026-08-07 so that file stays small enough to be read in full
every session. **Nothing here is optional and nothing has been reworded** — these are the
same rules, moved.

**Read the section for the area you are about to touch, before you touch it.** Each one
exists because something broke, and most say what broke. Many are enforced by
`tests/Feature/Build/*`, which fails the build — but a guard tells you *that* you are wrong,
never *why*, and the why is what stops you re-introducing it a different way.


---

## Legacy import

- The legacy import is one-way, read-only against its source, idempotent (provenance
  keyed), audited — and only the owner runs it against production.


---

## Calendar and dates

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


---

## Rota

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


---

## Clinics

- **A clinic is CONFIGURATION, and `App\Support\Clinics\ClinicWriter` is the only writer of
  `clinics` and `clinic_attendees`** (`ClinicWritersAreSingularTest`). Its owning unit is a `units`
  row and a foreign key — never a code string in a column, never a hardcoded list, never a `match`
  on `'WARD'`; the screen offers `clinic_owner = true AND active = true` from a query, so a fifth
  department that ticks that box gets a clinic screen with no code change. **WARD is the sole
  clinic owner** (owner Decision B, P1b 2026-08-09; `ReferenceSeeder` on cold start, and
  `2026_08_15_120002_correct_ward_clinic_owner` because the upgrade path left it `false` and unit
  profile columns are written on CREATE only). Neither table soft-deletes and **there is no
  `destroy()` route at all** — a clinic that stopped running is deactivated (UN-04) — and the one
  path that hard-deletes a `clinics` row is `DemoDepartment::remove()`, which is ledger-scoped.
  `name`, `location` and `note` are PLAIN text, never purified server-side (the
  `handovers.extra_fields` contract), so every consumer escapes on render.

- **`clinics.weekday` is a plain ISO-8601 integer — Monday = 1 … Sunday = 7 — and Carbon's
  `dayOfWeek` (Sunday = 0) is NOT it.** Ordering and labelling the department's week are
  `Calendar` concerns and go nowhere else: `Calendar::weekdayColumns()` returns the seven columns
  already rotated to `weekStartIsoDay()` (itself DERIVED from `weekend_days` — there is no
  `institutions.week_start` column and never was), each flagged `weekend`, and both the clinic form
  and the map consume that one array. A department that changes its weekend re-orders its clinic
  map with no stored value changing. `resources/js` names no day: `CalendarIsTheOnlyConverterTest`
  carries a repo-wide QUOTED-WHOLE-WORD needle for the seven names in any of the three JS string
  delimiters, with no allow-list — the bare substrings were measured and rejected, `Mon` matching
  `Month`. The vocabulary lives in `lang/en/calendar.php` beside `hijri_months` (AR-07), not as a
  `const`, and `golden.json`'s `weekday_columns` block is the contract P2's mirror must satisfy.

- **`clinic_attendees` holds the RULE; attendance is resolved at READ time and never stored.**
  CL-02's refinement is a MODE on the clinic (`rotators` default, `levels`, `named`) rather than a
  bag of include/exclude rules with an unstated precedence, and the mode moves only through
  `ClinicWriter::setAttendees()` together with its rows, in one transaction, so `levels` mode can
  never briefly hold a person row. `App\Support\Clinics\ClinicRoster::forDate()` answers *"who does
  this clinic come down to on this day"* from the master rota, on demand — so moving somebody on
  the rota moves them on the clinic with **no write anywhere**, and a snapshot would have needed
  every rota, vacation and level writer to know about clinics. A person on vacation is returned
  **unmarked** (the leave tables are never queried at all); a departed or deactivated person is
  returned and FLAGGED, never dropped — an invisible one hides a cell somebody has to clear.
  **CL-05's map has no date and therefore shows the RULE, not a roster** — resolving a Tuesday cell
  as of today reports the wrong day with complete confidence — and it ships no person-shaped value
  at all behind `cap:clinics.view`, a key seeded to every position. Munawib §5's footnote
  contemplates that map being link-public; **D7 overrides it** (design §1.2), and the whole
  `cap:clinics.view` group is asserted GET-only over the router.


---

## Department setup

- **The department setup checklist holds NO state, anywhere** — no column, no table, no
  `app_settings` key, no session key. `App\Support\Setup\DepartmentSetup::steps()` derives all nine
  steps from the data on every request, so an already-configured department reads as complete with
  no backfill and abandoning the wizard is a non-event. Steps are REQUIRED (derivable, binary) or
  REVIEW (`profile`, `calendar`, `holidays` — **never** marked done, because no query tells a
  reviewed default from an unexamined one; the Hijri offset is the specification's own example).
  `blocked_by` is advisory and never becomes the gate — the target screen refuses on its own terms,
  because a checklist that authorizes is a second authorization boundary. **`Setup*` names belong
  to the per-USER first-login 2FA flow** — `SetupController`, `Setup.vue`, `/setup`,
  `setup.show`/`setup.complete`, `RequireSetup`, `users.setup_completed_at` — and none of them was
  touched: the department wizard is `DepartmentSetup*` at `/admin/setup`, and `/admin/setup` is
  deliberately NOT on `RequireSetup`'s allowed list, so an administrator finishes their own second
  factor before configuring a system holding children's records.

- **A ledgered row is not configuration.** `DepartmentSetup::steps()` asks through
  `DemoLedger::notLedgered()` for `units`, `periods`, `roster`, `clinics` and the clinic step's
  separate "does any unit run clinics" question, or pressing the demo button takes `/admin/setup`
  from two required steps to five on a live department where nothing real was configured. It is a
  SUBQUERY, chosen so the measured ten-query bound holds exactly. The invitations step is a
  different question again — "can anybody get in", i.e. `Invitation::scopeOpen()` **or** accepted —
  because a bare count ticked the box for a link that was revoked or had expired unclaimed, and an
  `open()`-only predicate would un-tick it the moment the whole roster claimed their accounts.


---

## Demo department

- **The demo department is LEDGERED, removable, and may be created in production** (owner ruling,
  2026-08-11) — which is exactly what `DemoSeeder`/`E2eSeeder` are not, and why those two keep
  their production throw and were not modified. Provenance is a JOIN, not a column: `demo_rows`
  `(table_name, row_id)` grouped by a batch UUID, written only by `App\Support\Demo\DemoLedger`.
  **It mints no `users` row**, so a roster-only demo person cannot authenticate by construction
  (P0c) and pressing this on a live instance creates no way in. `DemoDepartment::remove()` refuses
  **WHOLE** — naming `(table, count)` pairs and never a name — the moment a non-ledgered row
  references a ledgered one; the pre-flight's load-bearing clause is *"and not itself ledgered"*,
  without which the demo's own clinic on its own unit would block its own removal forever with
  every refusal test still green. **The asymmetry is the thing to remember: the pre-flight catches
  an unledgered CHILD, and only `DemoRoundTripTest`'s whole-schema count comparison catches an
  unledgered PARENT** — forgetting to ledger a `people` row removes cleanly and leaves five rows
  behind. Both screen actions are preview-then-confirm pinned with `App\Support\Rota\StatePin`
  (`DemoDepartment::planDigest()`/`removalDigest()`), and removal needs the demo unit code typed.

- **`remove()`'s pre-flight runs INSIDE the transaction that deletes, under `lockForUpdate()`, and
  the outer call is only the cheap early refusal** (ruling 59). The database is NOT a backstop here
  the way it is for `PeriodController::destroy()`: of the nineteen inbound keys in
  `DemoReferences::MAP` only **three** are RESTRICT, nine are `ON DELETE SET NULL` and seven
  CASCADE — so a blocker landing after an outside-the-transaction check does not raise, it
  **succeeds**. Measured on this tree: `rows=21`, a clean `demo_department_remove` audit row, and
  `handover_signoffs.endorsed_by_person_id` silently NULLed with `endorsed_by_name` left beside it.
  The refusal is audited from OUTSIDE the transaction in both directions, or the row recording it
  rolls back with what it records. **This is the first defect here whose worst form a SQLite-only
  suite structurally cannot show**: `SQLiteGrammar::compileLock()` returns the empty string and
  SQLite serialises writers, so a plain re-read passes — while on MySQL 8.4 under REPEATABLE READ a
  `DELETE` is a *current* read and sees rows committed after the snapshot the `SELECT` beside it
  still reads. The lock, and the `Schema::hasTable()` loop hoisted out of the transaction body (an
  uncached `information_schema` query per ledgered row on MySQL), are therefore asserted at SOURCE
  level. Nothing in this repository has ever run against MySQL.

- **A blocker is only honest if its remedy exists, and `invitations` was not** (ruling 60).
  Invitations are never deleted anywhere in this product — `revoke()` stamps `revoked_at`, a resend
  supersedes and KEEPS the superseded row, design §14 item 7 records no retention rule at all — so
  an invitation to a demo person pinned the demo in place permanently. `DemoReferences::SWEPT` is
  the subset removal DELETES instead of refusing over; it stays in `MAP` so the schema
  introspection still covers it. **Merely un-blocking it would have been WORSE than the bug**:
  `invitations.person_id` is `nullOnDelete` and `InvitationAcceptController` reads a null
  `person_id` as "create the person at redemption time", so a link left behind would mint a
  brand-new person AND account for whoever still held it. The sweep runs inside the transaction,
  BEFORE the ledgered rows, and is reported on screen, inside the pin and as `swept=N` in the audit
  detail — a cleanup button reaching outside its own ledger says so. `DemoReferences::REMEDIES`
  gives every referencing table its own sentence, consumed by both the refusal message and the
  screen's blocked panel: `users` stays a blocker and names UNBINDING (accounts are deactivated,
  never deleted), `handover_signoffs` says plainly that a sign-off naming a demo person has NO
  remedy and the demo stays, and `reminder_preferences` does not point at a unit merge, being one
  of the three tables design §14 item 23 records that merge as stranding.


---

## Identity, access and positions

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


---

## Invitations

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

- **Claim status is DERIVED, never stored** (`App\Support\Invitations\InvitationStatus`, P1c-2
  Decision B): five states folded, in precedence order, from `accepted_at`/`revoked_at`/
  `expires_at`, plus `hidden` for a target the viewer may not manage. A stored status column is the
  `person_status` lifecycle enum D3 removed, wearing a different name. It is per-caller `$extra` on
  `PersonPresenter::one()` and **never a base key** — the base map reaches every `rota.view` holder,
  and "who has not claimed their account" is not a fact the whole department gets. It takes Person
  MODELS, not ids: a person with no invitation row has nothing to join a position from, so an
  id-only signature could not scope exactly the people whose state is `none`.


---

## Provisioning and institutions

- **`institutions.code` stays env-only, and this is not a UI omission.** It is
  `ReferenceSeeder`'s `firstOrNew` key, so re-coding a live institution makes the next
  `db:seed --force` — a mandatory step of every deploy — CREATE a second `institutions` row instead
  of updating the first, at which point `Institution::current()` returns null (two active rows means
  no right answer, D11) and every screen reading the department's configuration goes blank. It is
  also provenance already stamped on `users`, `people`, `levels`, `periods` and `clinics`. That is a
  provisioning operation with a migration behind it, not a settings change. `institutions.name` IS
  editable (`/admin/structure/department`, `structure.manage`, audited by field name), and the
  rename survives `db:seed --force` because `ReferenceSeeder` writes `name` on CREATE only —
  asserted, not assumed. `institutions.code` is **not** `App\Support\Instance::slug()`
  (`INSTANCE_SLUG`, which names the backup archive); they are different values.

---

## Engine

*Added 2026-08-20 (P2 Task 1). `packages/engine` is the repository's first TypeScript and the one
place a scheduling rule is allowed to exist.*

- **The catalog is 22 rows / 23 type keys, and P2 implements 21 rows / 22 keys.** `count_max /
  count_min` is one row with two keys; `forbidden_transition` is the one row the catalog marks
  `(Stage 5)` in its own parameters cell. `22 − 1 = 21` is D13's number and `23 − 1 = 22` is the
  implemented key count — the arithmetic lives under CG-07's table in `docs/munawib/SPEC.md` and is
  not to be restated as a bare number anywhere. `forbidden_transition` is **registered with
  `implemented: false`** and its three citations, because an entry is a decision, not documentation.
  **`packages/engine/test/catalog-parity.test.ts` now DERIVES all of that from the table itself**
  (P2 Task 8) and compares it against `src/registry.ts` in both directions, on three axes: the key
  set, the `(Stage 5)` marking, and the three class markings (`overlap_block`, `vacation_block`,
  `unwanted_day_block`) against `catalogDefault` — value included. So a twenty-fourth row in the
  spec fails the build until somebody classifies it, and a registry entry with no row behind it
  fails it too. No count is written in `registry.ts`, deliberately: a number there would be a fourth
  chance to get it wrong. The guard locates the table by its HEADER, never by line number — Task 1's
  own footnote under that table shipped citing §35 and §36 at the lines they occupied *before* the
  footnote's own insertion pushed them down, so `registry.test.ts` asserts the citation TEXT.

- **The CG-10 contract widens in exactly one place — `location` — and `evaluate()`'s return type
  does not.** `Location` is a three-member union (placement / window / cohort), authored in full at
  Task 7 before the first predicate, because `max_gap` alone cannot be expressed in
  `{personKey, date, slotKey}` and retrofitting the other two members after eleven types, a schema
  and a corpus is not a thing that happens. `contributing` is MANDATORY on a window violation: WB-03
  badges a cell and WB-04 orders a picker, and neither can act on a range. **A window a floor could
  not honestly evaluate is reported through the sibling `coverage()`, never smuggled into
  `violations[]`** — that separation is what makes CG-10's *"new types are additive"* true, since
  P2-2 adds eleven predicates and touches no shared shape. **Severity and the emission rule are
  stamped centrally by `evaluate()`, once**, from `Condition.class` — a type reports only WHERE and
  WHY — so Decision E's *"the engine never overrides the row"* is structural rather than a rule
  twenty-two files each have to remember. **A type answers ONE call returning findings and coverage
  together**, so `evaluate()` and `coverage()` cannot disagree about a window. **The emission rule is
  asymmetric and that is deliberate**: a placement must fall inside `[from, to]`, a WINDOW need only
  touch it (a window beginning in the tail constrains a duty on the 1st — the containment reading is
  silently correct for eleven types and silently deletes the left edge for eight), and a cohort
  always, having no date. **An unresolvable `typeKey` throws** — `UnknownConditionTypeError` or
  `UnimplementedConditionTypeError` — **even when the condition is switched off**, and an inactive
  condition is reported through `coverage()` with nothing evaluated and the reason stated.

- **Severity is `Condition.class` and nothing else; grading is rank ORDER and no weight** (P2 Task
  9, `packages/engine/src/severity.ts`). `stampViolation()` is the one expression in the package
  that may set `severity`, `rank` and `conditionId`, and `evaluate()` calls it — so *"the engine
  never overrides the row"* is structural rather than a rule twenty-two files each remember.
  `comparePrecedence()` puts hard above all soft, grades soft ascending by CG-02's drag rank, sorts
  an UNRANKED soft row LAST (an unset drag position is not position zero), ignores rank between two
  hard rows (CG-02 offers no gesture that ranks one hard row against another), and **returns only
  `-1`, `0` or `1`** — asserted, because AU-02's *"weighted monotonically by rank"* penalty curve is
  the solver's fact and writing one here would make this its first definition. A `rank` on a hard
  row is carried verbatim and not acted on.

- **CG-04's preview is generated from parameters, through a message TABLE, and refuses in three
  distinguishable ways.** `preview.ts` throws `UnknownConditionTypeError`, `UnimplementedConditionTypeError`
  or `NoPreviewForConditionTypeError` — never a blank or the raw type key, which on a gate screen is
  a rule that appears to do nothing (rulings 41/49, one layer inside the engine). The property that
  holds for the whole catalog is a MATRIX, `PickerParityTest`'s shape: **changing any one parameter
  must change the sentence**, so a parameter added without its preview fails the build. A
  containment check ("the sentence names the value") was tried and rejected — it cannot express a
  boolean, and `excludeExternal` is the parameter a preview is likeliest to drop. **`evaluate`
  implies `preview` and `paramsSchema`, one way**: a preview may land ahead of its predicate, never
  behind it. Three wordings are owner decisions rather than taste and each renders a number a reader
  would otherwise predict wrongly: `min_gap` in `days` carries a worked example on dates (decision
  H's off-by-one), `rolling_hours_max` prints the averaging multiplied out at both scales, and
  `fairness_distribution` prints its tolerance as a NUMBER at both regimes and never as `10%`
  (decision Q). `target_per_period`'s modifier REPLACES and the sentence says so in words (decision
  M) — a delta grammar lets two modifiers compound below zero silently.

- **`fairness_distribution`'s tolerance floor stays, and the reason is measured.** Owner decision Q
  fixes `max(1, ceil(0.1 × proRatedTarget))`, and `toleranceFor()` is its only definition. The
  decision's own justification (*"0.4 floors to a tolerance of zero"*) describes rounding DOWN while
  the formula rounds UP, so with `ceil` the floor changes the answer at exactly one input — a
  pro-rated target of **zero**, which is what a person on leave for a whole period has. Proved by
  PLANTING the floor's removal and watching the suite stay GREEN at an expected share of 4; the
  assertion that catches it is `toleranceFor(0) === 1`. Do not delete the floor as redundant:
  `Math.round(0.1 * 4)` is 0, and `round` is what the next author reaches for.

- **`intersects()` is the ONLY thing in the package that decides whether two windows overlap, and
  `overlap_block`'s pair scan carries NO pruning.** The obvious optimisation — the duties are sorted
  by start, so stop once a later duty starts at or after this one's end — was written first and made
  the abutting fixture unfalsifiable: swapping `<` for `<=` inside `intersects()` left the suite
  GREEN, because the `>=` in the loop's own stop condition had already skipped the pair. Two
  definitions of the half-open rule, one of them invisible, three lines from the sentence explaining
  the first. Found by planting. The scan is per person over one month; nothing there is worth buying
  with a second copy of the rule. **`overlap_block` is PER PERSON** — a slot filled twice is SL-03
  coverage-template territory and lands in P3 — and an overlapping pair reports at BOTH placements,
  each naming the other, with `evaluate()`'s emission rule dropping whichever sits in the carry-in
  tail.

- **A duty naming a person the context does not describe THROWS, exactly as one naming an unsupplied
  slot does.** Their leave, level and rotation are all unknown, so every placement type would answer
  "no violation" for want of data — a Hard rule passing on incomplete input, which is strictly worse
  than a crash. **CG-01's `scope` narrows and is never carried and ignored**: absent members are no
  filter, present ones narrow together, and unit and level are read AT THE DATE (`support.ts`'s
  `spanKeyAt`, the one definition of "the fact this person holds on this date"). A scope silently
  ignored is rulings 41/49's shape pointing the other way — a control that appears to do LESS than
  it says.

- **`eligibility` is a Hard violation and its "auto-fill order" half does not ship** (owner decision
  P) — ordering produces no violation at all and is WB-04 fitness, P3. The absence is ASSERTED, not
  merely omitted: `PARAMS_SCHEMA` is closed, so a row carrying `autoFillOrder` is refused with the
  schema's own error, and a source scan proves the ordering vocabulary appears nowhere in the
  module's CODE. **That scan strips comments, and it had to be taught to** — the seventh time in
  this phase that a docblock was scanned source, since `eligibility.ts`'s own docblock names the
  identifier while explaining why it is absent. Pinned in both directions, per `SourceScanner`'s
  recorded discipline: the prose is gone and a known code token is still there.

- **A placement type's `evaluatedWindows` counts the placements it COULD have badged**, which is
  not the same as every placement it looped over: a duty of a kind outside `kinds`/`to`, or filling
  a slot absent from `eligibility`'s map, can never appear in a finding, so counting it claims the
  rule examined a cell it is structurally incapable of badging. `eligibility` and `consecutive_max`
  always read it that way; `min_gap` and `post_duty_exclusion` were corrected to (P2-1 review), each
  reading its kind filter ONCE and using it for both the count and the scan. `post_duty_exclusion`
  applies `to` and deliberately not `from`, because `from` selects the ANCHOR and an anchor is not
  the placement a violation is located at.
  A `needsCarryIn` type reports a left-edge skip when no usable history reaches back past
  `horizon.from` — `historyAvailableFrom` being `null`, **or** being real and starting at or after
  `horizon.from`, which is what a first-ever draft has. **The two are DIFFERENT sentences**: the
  reason shipped announcing *"historyAvailableFrom is null"* for both, and a coverage row a reader
  can catch out is one they stop reading. Never when `priorDuties` merely happens to be empty, which
  is the caller saying *"I looked, and there were none"* — conflating that with a gap would report a
  skipped window on every correctly-supplied month.
  STATED RESIDUAL: there is no `futureAvailableTo` counterpart, so the RIGHT edge is not reported.

- **The eleven placement-located types are shipped (P2-1, Tasks 10, 12, 13, 14)** — `overlap_block`,
  `vacation_block`, `eligibility`, `unwanted_day_block`, `onboarding_grace`, `dow_restriction`,
  `clinic_conflict`, `same_unit_conflict`, `min_gap`, `post_duty_exclusion`, `consecutive_max` — each
  with a params schema, a CG-04 preview and corpus cases named for the SHAPE they catch. **Four
  answered owner decisions bind them and are not to be re-derived**: `same_unit_conflict` is reading
  **(a)** (two people rotating on the SAME unit are never on call together, the unit read at the date,
  and day exceptions **LIFT** the ban); `min_gap`'s `hours` measures **end-to-start** and its `days`
  measures **between start dates**, at least N apart; `post_duty_exclusion` anchors on the **END** of
  the first duty and tests the second by **start-in-window**; and `consecutive_max` carries
  `unit: 'days'|'nights'|'hours'` with `transitionMinutes`, where `'hours'` is CG-08's 24 h continuous
  cap on a chain joined across gaps of at most that allowance. **`'nights'` means the slot CROSSES
  MIDNIGHT** — a structural fact, never a kind called `night`, because SL-01's vocabulary is stored
  nowhere in this repository.

- **`onboarding_grace`'s unknown join date is REPORTED, not merely tolerated.** Owner decision T makes
  a missing `joined_at` no violation; P2 Task 1's finding 18 is that the column is written by no
  seeder, factory or demo path anywhere in this repository and production already holds people without
  one — so on the live instance *"no violation"* and *"this rule never ran"* are the same answer.
  Every person whose join date is unknown AND who holds a placement the condition would have judged is
  named in `coverage()` with the placement count; a person with no join date and no duty is not, since
  that noise is what `carryInLeftEdge()` already refuses. A duty BEFORE the join date is a violation
  with its own explanation — the closed-range reading reports nothing for a rota drafted before
  somebody starts, which is when the rule is most needed.

- **`DUTY_DATE_READING` has TWO entries carrying two readings, not one.** `min_gap` (owner decision H)
  and **`consecutive_max`** (owner decision V), each because a parameter of its own picks between them:
  `days`/`nights` count the dates duties start on and `hours` measures a contiguous chain on the
  absolute-minute line, which anchor dates cannot express. Decision A's table predates the `hours`
  unit; this is a correction to it, not a departure from it. `duty-core.test.ts` asserts the LIST.

- **`postDutyWindow()` is the ONE definition of "after this duty"**, in `duty/post-duty-window.ts`,
  shared by `post_duty_exclusion` (hour-granular, `startsWithin`) and `clinic_conflict` (day-granular,
  `postDutyDates`). SL-02 already says post-duty semantics follow slot windows automatically, and on a
  real configuration two implementations disagree — a 24 h call ending Tue 08:00 with a Tue PM clinic
  is a violation under the day reading and clean under an `H = 4` hour one, and a scheduler shown one
  warning and not the other cannot tell which is right. A **zero-length** window answers with the date
  its end instant falls on, which is what keeps the post-call and same-day variants apart without a
  second notion of *"the day after"*. **Clinics are never modelled as slots or duties** to reuse it.

- **`clinic_conflict` needs NO carry-in tail, and that was measured rather than assumed.** Its registry
  entry said otherwise until Task 13. Every finding it produces is located at a DUTY, so one derived
  from a tail duty is dropped by the emission rule before anybody sees it — reading the tail changes no
  output, and the seam fixture the corpus guard demands for such a claim could assert nothing. What it
  reaches past the horizon for is a CLINIC, and clinics are a weekly recurrence carried in the context
  for every weekday. `conditions.test.ts` derives the claim set from the registry in both directions,
  so a type claiming carry-in with no case supplying one fails the build.

- **`support.ts`'s `isoWeekdayAt()` is the one permitted fallback off the day vector**, and it exists
  because a post-duty window opened on the last date of the horizon closes on the day after it, where a
  clinic runs and the violation is located INSIDE the horizon. AR-08 forbids re-deriving the
  DEPARTMENT's facts — `dayType`, the week start, the weekend days — and the ISO weekday of a civil
  date is universal arithmetic `golden.test.ts` already asserts against `golden.json`'s own
  `iso_weekday`. The corpus pins the two answers agreeing on every date every fixture's vector
  describes. `dow_restriction` uses `dayIndex().get()` instead and THROWS on a date the vector omits:
  every date it asks about is inside the horizon.

- **`dow_restriction` takes ISO INTEGERS and refuses a day NAME with the schema's own error.** There is
  no name-to-number table in the package and there is deliberately never going to be one (AR-07 keeps
  the names in `lang/en/calendar.php`; owner decision X keeps the week's shape in the context), so a
  ban written with a name would match nothing — a control that appears to do nothing. **The test
  proving the refusal cannot spell one**: `CalendarIsTheOnlyConverterTest`'s quoted-weekday pattern
  scans that file too, so the name is assembled from two literals. Eighth occurrence in this phase of
  *"a docblock is scanned source"*, and the first where the scanned file is the test asserting the very
  rule the scan enforces.

- **A pair scan in this package carries NO early exit.** `overlap_block`'s did and made the phase's
  defining fixture unfalsifiable (Task 10); `min_gap` and `post_duty_exclusion` are written without one
  for that reason, and `post_duty_exclusion` tests BOTH orderings of every pair even though only the
  earlier duty can be the anchor — a provable skip is still a second, invisible statement of the rule
  the fixture exists to test. The scans are per person over one month.

- **The TS calendar mirror is the ONE deliberate second implementation, and
  `tests/fixtures/calendar/golden.json` is its contract in BOTH directions.** §7 Decision A of P1a
  overruled the design doc's own "PHP plus a mirrored package" wording with *"ONE implementation, not
  two"* precisely because two definitions of one fact is the failure class
  `AuditChain::canonical()` already carries a docblock against — two copies drifted the day
  `APP_TIMEZONE` was set and the live system announced its whole audit trail as tampered, and nothing
  had been. P2 knowingly creates the second implementation and pays for it with a fixture asserted
  from both sides: `GoldenFixtureTest` (PHP, shipped) and the mirror's own suite. **`golden.json` is
  an INPUT to P2, not something P2 authors** — its `_purpose` already names this package. The mirror
  therefore implements the smallest possible surface: `Ymd` parse/format, civil-date arithmetic,
  `isoWeekday`, `datesBetween`, `weekdayColumns`, `weekOf`, `isWeekend`, `dayType`. **No Hijri** (ICU
  in the browser is not guaranteed to agree with PHP's, and `Intl.DateTimeFormat` is a forbidden
  needle besides) and **no `weeksIn()`** (`golden.json` has zero coverage of its clipped bounds, so a
  mirror copy would be an unasserted second definition of a per-department fact). Holidays arrive
  already resolved to Gregorian dates and week windows arrive as `periods[].weeks` — the one
  converter resolves both, server-side, once.

- **`App\Support\Calendar` remains the ONLY date converter, and every department-varying fact is a
  PARAMETER of the mirror, never a literal in it.** `weekendDays`, `weekStartIsoDay`, the resolved
  holiday set and `today` all arrive in the evaluation context. A bundled default would be exactly
  the second definition the fixture exists to prevent.

- **The engine holds no `Date`, no instant and no timezone — so there is nothing to allow-list.**
  `CalendarIsTheOnlyConverterTest`'s two `resources/js` scans carry **no allow-list at all,
  deliberately**, over ten date-construction needles and a quoted weekday-vocabulary pattern; the
  same scan extends to `packages/` with the allow-list empty in both directions, and the mirror
  passes on its own merits rather than by exemption. Its date type is a branded `Ymd` string and all
  arithmetic is integer civil-date arithmetic; its time type is minutes from local midnight. A
  Node/Vitest process with `TZ` unset runs at UTC and a browser at +03:00 does not — **an engine with
  no instants cannot have that bug**, which is a stronger guarantee than a test that remembers to set
  `TZ`. "Today" is never computed; it arrives in the context.

- **Intervals are HALF-OPEN, `[start, end)`.** Under a configurable split day/night the night window
  begins exactly when the day window ends, so closed intervals would flag every legal split-call
  department on every single day. Fixtured on the abutting pair, and the plant that proves it is
  swapping one comparison operator.

- **Duty-to-date attribution has THREE readings, each type declares which it uses, and the fatal
  failure is a type picking one silently.** This is the largest source of divergence between two
  implementations of one catalog.
  - **Anchor date** — the whole duty belongs to the calendar date its slot *starts* on:
    `vacation_block`, `unwanted_day_block`, `onboarding_grace`, `dow_restriction`, `clinic_conflict`,
    `same_unit_conflict`, `eligibility`, `consecutive_max`, `count_max`, `count_min`,
    `target_per_period`, `composition`, `max_gap`, `call_frequency_max`, `fairness_distribution`,
    `holiday_equity`, `we_pairing`.
  - **Occupied interval** — the half-open absolute-minute interval: `overlap_block`, `min_gap` in
    hours, `post_duty_exclusion`, `free_day_min`.
  - **Split at midnight** — minutes apportioned to each civil date they fall on: `rolling_hours_max`
    **only**.
  A Friday-night call is **one Friday call**, and it is also twelve Friday hours plus twelve Saturday
  hours in the one type that sums minutes into a day-bounded window. Both are right for their family.
  Asserted as a matrix across every type touching a slot window — the `PickerParityTest` shape, every
  fixture × every type — not case by case.

- **The horizon edge: violations are emitted ONLY when their location falls inside
  `[horizon.from, horizon.to]`, and prior duties are read-only context.** A draft is built one period
  at a time and the preceding period is a different, already-published schedule, so `priorDuties` and
  `followingDuties` sit in the context and are never re-evaluated — which is what keeps CG-03's
  *"never retroactive on published schedules"* intact. Every window-measured and pairwise type is
  fixtured **at the seam**: a corpus of only mid-month cases proves nothing about the case a
  scheduler hits first, on the 1st. A window that cannot be evaluated is reported through
  `coverage()`, never dropped — a silently dropped window is a guard that looks green.

- **`App\Support\Engine` is a READER, and where it lives is forced rather than chosen.** Three live
  source scans see P2's type keys, and none of them has an allow-list P2 may add to:
  `RotaAccessTest::test_nothing_in_the_rota_infers_on_call_eligibility` walks
  **`File::allFiles(app_path())` — every `.php` file under `app/` — with comments NOT stripped** for
  `off_roster`, `offRoster`, `callEligib`, `call_eligib`, so none of those four strings may appear
  anywhere under `app/`, **docblocks included**; its namespace twin globs `app/Support/Rota/*.php`
  for those plus a bare `eligib`, so the `eligibility` type key alone would fail the build there; and
  `ClinicHooksTest` globs `app/Support/Clinics/*.php` for `condition`, `severity`, `violation` and
  five more, so `clinic_conflict`'s reader would fail the build there. **`App\Support\Engine\` is
  clear of all three, and P2 adds no allow-list entry to any of them.** That is not a workaround:
  MR-04's rule is that *the rota* must not infer eligibility and CL-03's is that *the clinic module*
  must not evaluate conditions, and both are satisfied by the crossing living in the engine's own
  namespace and reading those modules' data.

- **"No PHP implementation of the rules exists anywhere" is now ENFORCED, by
  `tests/Feature/Build/RulesLiveOnlyInTheEngineTest.php`** (P2 Task 11). It was prose with nothing
  behind it until then, and that was measured rather than assumed: `app/Support/FakeRules.php` was
  planted carrying `minGap()`, the literals `'min_gap'`, `'severity' => 'hard'` and `'eligibility'`,
  and a `$violations` loop — **`php artisan test` returned rc=0 with 1685 passing.** The guard
  needles the **23 CG-07 type keys DERIVED from `docs/munawib/SPEC.md`'s own table** (located by its
  header, never a line number, so a twenty-fourth row becomes a needle the same day) plus
  `violation`, `severity`, `hard_block`, `soft_block` and `rank_order`, over **`app/`, `routes/` and
  `database/`**, with **comments stripped** — `ClinicRoster`, `AvailabilitySummary` and `RotaFill`
  each say *"MUST NEVER BECOME AN ELIGIBILITY COMPUTATION"* in a docblock, and a guard that fails the
  build on the documentation of its own rule teaches people to delete the documentation
  (`RotaAccessTest`'s recorded departure). **Measured, per ruling 42:** every type key measured ZERO,
  `composition` included — the task's own text predicted it would collide with docblock prose and
  against this tree it does not, and the stripper removes docblocks anyway; `violation` is bought
  CASE-INSENSITIVELY, because the case-sensitive form misses `class Violation`, `$violations` and
  `ViolationChecker`, which are the forms a PHP implementation would actually take. **The allow-list
  is per file AND per needle** — `RosterImport` is exempt from `violation` alone
  (`UniqueConstraintViolationException`) and still scanned for the other twenty-seven; a whole-file
  exemption would blind the guard to a `min_gap` appearing in that file later. `condition` is NOT
  bought (two files, one of them `DepartmentSetup`, which is plausibly adjacent to where P3's gate
  lands) and that is a stated residual, as is `resources/js`, which this needle set is the wrong
  shape to search. **Proved in four directions by planting:** the rule in code → red naming the file
  and four needles; the identical text inside a docblock only → GREEN, the stripper proving it
  strips; a changed table header → the derivation twin red; a stale allow-list entry, a stripper that
  eats code, and a scan pointed at a missing directory → each twin red.

- **"No PHP implementation of the rules exists anywhere" is scoped to rule SEMANTICS, not to data
  access — and the leak risk is the serialiser, not the loader.** Flattening rota spans into a
  per-date unit vector implements no rule. Sending only the "eligible" people would be `eligibility`
  re-implemented as a `where`, and that is the shape to guard against. The context builder writes no
  table, adds no index, filters on no `institution_id`, and carries **no contact field and no free
  text for any viewer** — asserted on the most permissive institution setting the system can produce,
  exactly as the rota read view already asserts it, because `RotaGrid::forYear()` takes no viewer at
  all after a live disclosure. Its query budget is **watched breaching** on a populated fixture
  before it is trusted; a budget measured on an empty grid only ever proves the empty case.

- **Fixtures stay synthetic, permanently.** No real month's duty roster and no real staff list enters
  the engine's corpus at any time. The corpus exercises specific violation shapes — a gap of exactly
  the boundary value, a duty on a period's last day, a run spanning the 31st into the 1st, a person
  whose level changes mid-window — not a plausible department.
