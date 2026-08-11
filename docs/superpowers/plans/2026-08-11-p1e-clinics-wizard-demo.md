# P1e — Clinics, setup wizard, demo department

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** the last slice of Munawib Stage 1. P1a gave the department a calendar, P1b gave it
structure, P1c gave it people and accounts, P1d gave it a master rota. Three things remain before
an empty instance can be handed to a department that has never seen it: **clinics** (the one piece
of the department's week the rota does not describe), **a path through the configuration screens**
that does not require knowing which of eleven admin pages to open first, and **a demo department**
somebody can be trained on and then delete without a trace.

**Binding requirements**, quoted verbatim from `docs/munawib/SPEC.md:75` and `:55`:

> CL-01 — Clinic: owning unit, name, weekday, session AM/PM, optional location/note, active.
> CL-02 — Rotators on a unit attach to its clinics by default; per-clinic refinement by level or
> named people. CL-05 — A weekly clinic map (unit × weekday × session) for viewers.

> ST-01 — A setup wizard takes an empty instance to a working department: profile and branding;
> calendar (period type, academic-year start, weekend days, Hijri display, **timezone** … and
> **hijriOffsetDays** …); level ladder; units; slots and coverage templates from a preset;
> conditions from a preset; holidays; roster import; invitations. ST-02 — Every step revisitable
> in Settings. ST-05 — A one-click, clearly-labeled, removable **demo department** seed exists for
> training and for development fixtures.

Plus **D7** held (no anonymous route, ever — including the clinic map, which Munawib §5 lists among
the link-public surfaces; see Decision C), **D11** held (`institution_id` is provenance, never a
filter, never leading an index), **AR-08/ST-06** held (`App\Support\Calendar` is the only converter;
`resources/js` does no date arithmetic), **PE-02** held (`PersonPresenter` is the only path from a
`Person` to Inertia props, and a withheld contact field is *absent*), and **D2** held (units are
configuration; `Unit::codes()` is the only source and `Unit::findByCode()` the only lookup from user
input).

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 3 + Vue 3, PHPUnit 12 (SQLite in-memory,
`APP_TIMEZONE=Asia/Riyadh`), Vitest, Playwright, Tailwind 4 via `@theme`, MySQL 8.4 in production.

**Baseline this plan was written against:** `main` at **`f3d0a22`** (*"fix: the door out of the
access control screen cannot be locked behind you"*).

```
php artisan test   # tests: 1446
npm test           # Test Files 20 passed (20) / Tests 192 passed (192)   — measured 2026-08-11
npm run test:e2e   # 22 test() blocks across 7 spec files                 — counted, not run
npm run build      # ✓ built
```

**Two honesty notes about that baseline, both measured rather than assumed:**

1. **`npm test` (192) and the e2e count (22) were measured on 2026-08-11 and are reliable. The
   PHPUnit run was contaminated and only its TOTAL is trustworthy.** The run reported
   `tests: 1446, failed: 75` — but the working tree was dirty at the time with another worker's
   uncommitted `.env.example` and `DeploymentInvariantsTest` edits (`git diff --stat f3d0a22` →
   two files, 55 insertions). The failures are that tree, not `f3d0a22`. **Re-measure on a clean
   checkout before Task 1 and trust your number over this one.** Five of P1c-1's thirteen
   amendments and one of P1d-2's were stale expected-count arithmetic; every count below is
   arithmetic, not evidence.
2. **`php artisan test` takes ~3 minutes 50 seconds.** Budget for it. Filter (`| tail -5`), and on
   a failure re-run only the failing filter (`php artisan test --filter <TestName> | head -30`).

---

## What this plan is, and is not

**It is** one new configuration surface (clinics), one new read-only viewer surface (the weekly
map), one derived projection of "how far is this department from configured", one ledgered and
provably-removable demo department, and the document corrections all of that invalidates.

**It ships CL-01, CL-02, CL-05, ST-01, ST-02 and ST-05. It does NOT ship CL-03, CL-04 or ST-03**,
and the reasons differ:

- **CL-03 (*"clinics feed conditions"*) is a P2 condition type.** `conditions` does not exist as a
  table, a class or a concept anywhere in this tree. P1e ships the clinic data those conditions
  will read.
- **CL-04 (personal schedules, feeds, the on-now board, morning coverage) is P3.** P1e ships the
  data and **records the hook only** — exactly as P1d did for MR-04. Task 6 guards the absence in
  the same way, comment-stripping included.
- **ST-03 (launch presets) cannot ship in P1e at all, and the P1 plan is wrong to list it.** Both
  named presets — *"Residency on-call (split day/night)"* and *"Residency on-call (24-hour)"* — are
  **slot and coverage-template** presets. `slots`, `coverage_templates` and `conditions` are all
  listed as unbuilt in design §6.3. A "Stage-1 subset" of a slot preset is the empty set. See
  [finding 2](#findings).

**It adds exactly TWO migrations**, both creating new tables, both in the `2026_08_16_*` slot the
P1 plan reserved. It retypes nothing, drops nothing, and touches no clinical table's shape.

**It adds no anonymous route.** D7 holds. The clinic map — which Munawib §5's own footnote names as
one of three link-public surfaces — is `auth` + `cap:clinics.view`. Decision C states the override
and Task 15 records it in design §1.2, which is where a deviation belongs.

**It builds no second implementation of any settings screen.** ST-02 says every wizard step is
revisitable in Settings; Decision D reads that the strict way round — the wizard is a **path
through screens that already exist**, holding no state of its own, so "revisitable" is satisfied by
construction rather than by a second copy.

**It creates no `SetupController`, no `Setup.vue` and no `/setup` route.** All three exist already,
for the per-USER first-login 2FA flow, along with `RequireSetup` middleware and the `setup.show` /
`setup.complete` route names. Decision E is about this collision and it is not cosmetic — see
[finding 4](#findings).

**It seeds no second `institutions` row for the demo department.** D11 forbids the query filter
that would make one meaningful, and `units.code` / `people.email` / `users.member_name` carry
institution-blind UNIQUE indexes by design, so a demo PICU collides with the real PICU. Decision F
states this as a rejected alternative, not an unconsidered one.

**It is TWO branches**, split at a stated seam. See [The split](#the-split-p1e-1-and-p1e-2).

---

## Inherited invariants — stated as things a task must not break

Not preferences. Each has a test that fails, or a live defect that was once caused by breaking it.

1. **Units are configuration, not code.** `Unit::codes()` is the only source of the unit list;
   every lookup built from user input goes through `Unit::findByCode()` (the `code` mutator
   normalises writes, not a query's WHERE value). **A clinic's owning unit is a `units` row and a
   foreign key** — never a code string in a column, never a hardcoded list in a controller, never a
   `match` on `'WARD'`. The clinics screen offers the units where `clinic_owner = true AND active =
   true`, from a query, and a fifth department that ticks that box gets a clinic screen with no
   code change.
2. **All date logic goes through `App\Support\Calendar`**, guarded by
   `CalendarIsTheOnlyConverterTest` over the whole match set with **no allow-list on the JS side**
   (ten needles, matching docblock prose too). A weekday is stored as a plain ISO-8601 integer, but
   *ordering* and *labelling* the department's week are `Calendar` concerns — Decision A says
   exactly which is which and why the boundary sits there.
3. **`resources/js` computes no dates.** The clinic form's weekday `<select>`, the map's column
   headers and the "on this date" roster all receive server-formatted labels and enumerated values
   as Inertia props.
4. **`App\Support\PersonPresenter` is the ONLY path from a `Person` to Inertia props**, gated by
   `App\Policies\PersonPolicy`, and **a withheld contact field is ABSENT from the array, never
   `null`** — the two look identical on screen and a future consumer eventually renders one as the
   other. `ContactFieldsAreProjectedOnceTest` pins it at source level; `Person::$hidden` is not the
   control and never was. **Clinic attendees are people, and this is the surface where it bites:**
   `clinics.view` is seeded to every position (Task 5), so the map reaches the whole department —
   the same shape that made P1d-1's rota grid the first consumer to need
   `PersonPresenter::contactFree()`. Every person-shaped value on any clinic surface goes through
   `contactFree()`, including the *administrator's* named-attendee picker (Task 4 says why).
5. **One writer per table, guarded at source level, in the house style.** `App\Support\Clinics\
   ClinicWriter` is the only writer of `clinics` and `clinic_attendees`;
   `App\Support\Demo\DemoDepartment` is the only writer of `demo_rows`. Each guard scans
   `app/` + `database/` + `routes/` (**`tests/` is out, `database/` is in — factories and seeders
   included, named on the allow-list rather than exempted by directory**, ruling 42), collects
   `$offenders[]`, ends with `assertSame([], $offenders, ...)`, and carries a staleness twin
   (`test_every_allow_listed_file_still_exists`). **Each guard is watched failing against a planted
   violation before it is trusted.**
6. **D11: one database per customer.** `institution_id` is provenance and in-instance grouping,
   **never a query filter**. An index or unique key led by `institution_id` is a recurring mistake
   — it has been proposed twice by plan text and caught twice empirically by
   `InstitutionProvenanceTest`. `clinics` carries `institution_id` as provenance and no index
   touches it; `clinic_attendees` and `demo_rows` carry none at all (the `person_levels` /
   `unit_field_definitions` precedent: a pure child table does not repeat its parent's provenance).
7. **Additive migrations; soft deletes where the row is clinical; never retype a column holding
   real data.** Both new tables are new. **Neither carries soft deletes** — a clinic is schedule
   structure, exactly like `master_rota_assignments`, and the hash-chained `audit_log` is the
   history. And, following `UnitController` / `LevelController` / `HolidayController`, **the clinic
   controller has no `destroy()` at all**: a clinic that stopped running is deactivated (UN-04's
   *"hides forward, never deletes history"*). The one path that hard-deletes a `clinics` row is
   `DemoDepartment::remove()`, which is ledger-scoped and cannot reach a row it did not create.
8. **Every route behind `auth` + a `cap:`.** Writes are POST/PATCH/DELETE + CSRF. The whole
   `cap:clinics.view` group is asserted **GET-only over the ROUTER**, the P1d-2 idiom, so a write
   endpoint cannot arrive on the department-wide surface unnoticed.
9. **No PHI and no personal data in `audit_log.detail`** — ids, field names and counts only. Never
   a person's name, never a clinic's name, never a unit's name, never a filename. Staff personal
   data is covered by the same rule as PHI (`docs/COMPLIANCE.md:120-123`). `AuditLog::record()`
   takes a plain string; the house convention is semicolon-delimited `key=value` built at the call
   site (`"person={$id};period={$id};unit={$id}"`).
10. **LIGHT THEME ONLY. Semantic classes only.** No `dark:` utility (guarded by
    `CompiledCssIsLightOnlyTest`), no raw Tailwind palette class, no hex in markup. There is **no
    `bg-panel-soft` token** — it compiles to nothing. Unit colours come from `Unit::BAR_CLASSES`
    with `Unit::DEFAULT_BAR_CLASS` as the fallback; the clinic map's unit rows reuse them and
    invent no palette.
11. **Free text is escaped on render, never `v-html`.** `clinics.name`, `.location` and `.note` are
    plain text, **not** rich text, and are **never** purified server-side — the same contract as
    `handovers.extra_fields` (design §6.2). Every consumer escapes: `{{ }}` interpolation or
    `:value` binding, never `v-html`. The four `SanitizedHtml` fields are clinical narrative and
    have nothing to do with this.
12. **A bulk operation validates and authorizes the WHOLE set before mutating**, runs in one
    transaction, refuses whole rather than partially, writes **one** summary audit row, and is
    preview-then-confirm **pinned to what the operator saw** — `App\Support\Rota\StatePin` is the
    one definition of such a pin. `DemoDepartment::create()` and `::remove()` are both bulk
    operations by this definition and Tasks 12–14 hold them to it.
13. **Fixtures stay synthetic, permanently.** No real staff list — names, emails or phone numbers
    of actual QCH personnel — belongs in this repository at any time. The demo department's people
    are obviously fictional by construction (Task 12) and the first import against a real staff
    list remains the owner's, against production.
14. **The repository is PUBLIC.** Nothing in this plan or the code it specifies may name real
    infrastructure — no hostnames, no IP addresses, no account identifiers.
15. **`Calendar::flush()`'s production contract.** `CalendarWritersFlushTest`'s `WRITE_NEEDLES`
    include the bare `Institution::current()`, so **any new file that so much as reads that row is
    matched** and must either call `Calendar::flush()` or sit on `ALLOW_LIST` with its reason
    stated at the site. There is already an exact precedent for the shape Task 8 needs:
    `PersonController` is allow-listed because it *"reads and writes `institutions.
    contact_visibility` — a PE-02 policy column, not a calendar one."*
16. **`institution_id` on `people`/`units` is not what you think.** `units` has **no
    `institution_id` column at all** (deliberate — see `2026_08_09_120001`'s docblock). Any demo
    scheme that assumed it could scope units by institution is dead on arrival; Decision F does not
    assume it.

---

## Findings

Measured against the tree at `f3d0a22`, not inferred from the documents.

**1. The P1 plan's own P1e scoping contradicts itself about CL-04.** The split table (line ~597)
lists P1e's binding requirements as *"CL-01…05, ST-01, ST-03 (Stage-1 subset), ST-05"*; the P1e
section heading (line ~2314) says *"CL-01…02, CL-04…05, …"*; and item 3 of that same section says
CL-04's surfaces *"exist only from P3 — P1e ships the data and records the hook."* So CL-04 is in
two headings and out of the body, and CL-03 is in one heading and explicitly excluded in prose
(*"CL-03 is likewise absent from P1e"*). **This plan's binding list is CL-01, CL-02, CL-05 — the
prose, not the tables.**

**2. ST-03 is not shippable in P1 in any subset, and listing it invites half a preset.** Both
ST-03 presets are slot/coverage-template presets. `slots`, `coverage_templates` and `conditions`
appear in design §6.3's table as unbuilt rows with no migration, no model and no controller
anywhere in `app/`. The P1 plan's own item 4 already says the slot/coverage/condition wizard steps
must be *"stated as arriving in P2/P3 rather than presented as empty steps"* — which is precisely
incompatible with ST-03 being a binding requirement of P1e. **ST-03 is dropped from P1e's binding
list**, and Task 9 asserts that no wizard step names a slot, a coverage template or a condition.

**3. `docs/OPEN-DECISIONS.md` item D ("Which units own clinics?") is STALE, and says the opposite
of what shipped.** It states the flag *"stays `false` everywhere"*, that this *"blocks P1e's CL-01
clinic screen"*, and that *"P1e's own first step can simply be ticking it."* All three are wrong.
**Owner Decision B (P1b, 2026-08-09) already ruled that WARD is the sole clinic owner**;
`ReferenceSeeder` writes `clinic_owner => true` for WARD on cold start; and
`2026_08_15_120002_correct_ward_clinic_owner` exists specifically because the upgrade path left it
`false` and `db:seed --force` could not fix it (unit profile columns are written on CREATE only).
`P1bStructureTest::test_ward_alone_is_seeded_as_a_clinic_owner` pins it. **P1e starts with a
clinic-owning unit already configured**; Task 15 moves item D to the decided section.

**4. The name `Setup` is comprehensively taken, and nothing in the P1 plan notices.** `App\Http\
Controllers\SetupController`, `resources/js/Pages/Setup.vue`, `GET /setup` (`setup.show`),
`POST /setup` (`setup.complete`), `App\Http\Middleware\RequireSetup`, `users.setup_completed_at`
(migration `2026_07_27_160001`) and `tests/Feature/Auth/FirstLoginSetupTest.php` all belong to the
**per-user first-login 2FA flow**, which is a completely different thing from ST-01's department
wizard. A task that creates `SetupController` either collides outright or — worse — lands a second
class beside the first and confuses every future reader of both. Decision E names the department
wizard `DepartmentSetup` throughout and leaves `RequireSetup` alone.

**5. ST-01's FIRST step has no screen.** Design §14 item 12 is still open: `institutions.name` and
`institutions.code` are `INSTITUTION_NAME` / `INSTITUTION_CODE`, env-only, read once by
`ReferenceSeeder` on `db:seed --force` (and `name` on CREATE only), so changing either today means
a direct database edit outside the audit trail. The P1 plan lists *"profile and branding"* as if it
threaded to an existing revisitable screen. It does not. Task 8 builds the missing half; Owner
decision 3 covers the other half.

**6. There is no `institutions.week_start` column, and there never was.**
`Calendar::weekStartIsoDay()` **derives** the week start from `weekend_days` — *"the week begins
the day after the LAST configured weekend day, wrapping"* — falling back to Monday for an empty
weekend list (P1d Task 2). This is good news for clinics rather than bad: a department that changes
its weekend re-orders its clinic map immediately, with no stored value changing and no data
migration. Decision A depends on this and Task 1 asserts it.

**7. `DemoSeeder` and `E2eSeeder` are a precedent for *shape*, not for removability, and neither
marks a single row.** `DemoSeeder` creates seven fictional accounts and three PICU handovers;
`E2eSeeder` creates an admin, three residents, two periods, rota spans and a vacation. Both throw
outright in production (`app()->environment('production')`). **Neither writes any provenance
marker at all** — their rows are identifiable only by fixed, documented email addresses and
`member_name`s, which security-audit finding `SPC-RPT-059` already flags as residual risk, and
**neither has a removal path of any kind.** ST-05's "removable" is genuinely unbuilt.

**8. The schema's only existing provenance patterns are narrow, and one of them is the right
model.** `handovers.legacy_source_table` + `legacy_id` (UNIQUE pair, the legacy import's
idempotency key) is *provenance as a key*. `applied_role_defaults` is *"this seed step already ran"
as a ledger table*, and it is exactly why `AccessControlSeeder` never re-asserts a revoked default.
`person_levels.promotion_batch_id` is *"which batch opened this span"* as a UUID with no FK,
because a batch is not a row anywhere. **Decision F is all three, generalised**: a ledger table,
keyed by (table, row id), grouped by a batch UUID.

**9. `PersonPresenter::contactFree()` already exists and is what makes CL-05 safe.** P1d-2 Decision
C added it after the rota grid was found emitting every colleague's email and phone in its Inertia
props on a `contact_visibility = members` department — *"a payload disclosure that nothing displays
is invisible unless somebody reads the props."* The clinic map is the second surface with the same
shape. The P1 plan's dependency line (*"Depends on: P1b, P1c, P1d"*) is right but understates it:
**P1e depends on P1d-2 specifically**, not on P1d generally.

**10. `AuditLog::record()` takes a plain nullable string, not an array.** Signature:
`record(string $action, ?string $detail = null, ?int $userId = null, ?string $ip = null): self`.
It locks the chain tail, builds the canonical string via `AuditChain::canonical()` and hashes
inside one transaction. Event names are `snake_case`, `<subject>_<verb>`. Any task text below that
reads as if `detail` were structured is wrong; build the string inline.

**11. `docs/spec/08-foundation.md` is the capability catalog document**, and
`RotaAccessTest::test_the_catalog_document_lists_both_keys` asserts capability keys appear in it.
A new capability that is not added there fails the build, in a test whose name does not mention
clinics. Task 5 must add `clinics.view` to that file in the same commit.

**12. `clinic_attendees` has a hard schema problem that this codebase already solved once.** A row
must name exactly one of a level or a person, and must not duplicate within a clinic — and a
UNIQUE index over nullable columns does not enforce that on either SQLite or MySQL 8.4 (NULLs
compare distinct). **This is the identical situation `2026_08_14_120002`'s docblock records for
`person_levels`' overlap rule:** *"NO overlap constraint is added at the database level. SQLite
cannot express it, and a partial unique index on MySQL 8.4 would not either. The guarantee lives in
App\Support\LevelAssignment, which `PersonLevelsHaveOneWriterTest` proves is the only writer."*
Decision B follows that precedent exactly rather than inventing a polymorphic `subject_type` column
to get a unique index this codebase has already decided it does not need.

---

## Where the P1 plan, the design doc and the Munawib spec are wrong or thin about this slice

- **The P1 plan's P1e item 5 says *"'removable' is the hard part and needs a provenance marker on
  every row it creates."* The first half is right and the second half is the wrong shape.** A
  marker *column* on every row means an additive column on eight tables, two of which
  (`master_rota_assignments`, `vacations`) are brand new and one of which (`units`) has no
  `institution_id` and no natural place for one; it can be edited to `false` by anyone with a
  database console, after which removal silently misses the row; and it does not *enumerate*, so
  proving removal complete would mean scanning every table anyway. Decision F uses a ledger.
- **The P1 plan says nothing about what happens when a real row has since referenced a demo row**,
  which is the actual hard case and the one that decides whether removal can be partial. Decision F
  answers it: refused whole, naming what holds it — the same shape as `PeriodController::destroy()`
  refusing while any `master_rota_assignments` row references the year.
- **Munawib §5's footnote lists the clinic map as a link-public surface** (*"when link-public, only
  the published schedule, boards, and clinic map are exposed"*). D7 (no anonymous route, ever)
  overrides this outright, and the override belongs in design §1.2's table where a deviation is
  findable, not only in a plan. Decision C; Task 15.
- **Munawib CL-02 says *"refinement by level or named people"* and does not say whether refinement
  narrows or replaces.** Decision B reads it as a **mode** on the clinic — `rotators` (the default),
  `levels`, `named` — rather than as a bag of rules with an unstated precedence, because a bag of
  include-and-exclude rules needs a precedence answer nobody has given and every wrong answer fails
  silently.
- **Munawib ST-01 lists "profile and branding" as one step and never says what branding is.** This
  plan reads it as the department's display name and nothing else. A logo upload into a system
  holding children's PHI is its own security decision (storage path, content-type validation, path
  traversal, and the backup archive's size) and is not smuggled in under "branding" — Owner
  decision 4.
- **Design §6.3's table lists `clinics`, `clinic_attendees` against "CL-01, CL-02" and stops.** It
  never states shape, ownership, writer or whether attendance is resolved or stored — which is the
  load-bearing question of the whole slice. Decision B answers it and Task 15 writes the answer
  back into §6.3, in the same detail the `master_rota_assignments` and `vacations` rows now carry.

---

## Decision A: a clinic's weekday is a plain ISO-8601 integer; ordering and labelling the department's week is a `Calendar` concern

**Stored:** `clinics.weekday` is an `unsignedTinyInteger`, **ISO-8601: Monday = 1 … Sunday = 7.**
Not an enum class, not a string, not a date.

**Why an integer, and why ISO-8601 specifically.** `Calendar::weekendDays()` already returns *"ISO-8601
weekday numbers, Mon=1 … Sun=7"*, `Calendar::weekStartIsoDay()` already returns one, and
`Calendar::isWeekend()` already compares `isoWeekday()` against that list. A clinic's weekday must
be comparable to all three without a translation step. **Carbon's `dayOfWeek` (Sunday = 0) is a
second numbering scheme and using it anywhere near this column would be a third**, which is the
"two definitions of one fact" failure this codebase keeps paying for. The column is documented as
ISO-8601 in the migration, in the model and in the writer's validation, and Task 1's first test is
`test_monday_is_one_and_sunday_is_seven`.

**Why not a PHP enum.** An enum would be a fourth place the seven days are written down (after
`Calendar`, the validation rule and the label map) with nothing keeping them in step, and it buys
nothing an `in_array($iso, range(1, 7), true)` check does not. The house idiom for a small closed
set is a `const` array on the model consumed by *both* the offer and the validation — the
`Institution::CONTACT_VISIBILITIES` / `SignoffPickers` shape — and that is what `Clinic::SESSIONS`
uses for AM/PM. The weekday does not even need that, because `Calendar` already owns the list.

**Is "Tuesday" a `Calendar` concern? Partly, and the boundary is precise.** The *number* is not —
it is a recurrence-rule component, not a date, and no conversion happens when you store it.
**Three things about it are `Calendar`'s and go nowhere else:**

1. **The English label.** `Calendar::weekdayLabel(int $iso): string`.
2. **The order the department's week runs in.** `Calendar::weekdayColumns(): list<array{iso, label,
   short, weekend}>`, rotated to begin at `weekStartIsoDay()`.
3. **Whether a given weekday is a weekend day for this department** — read from `weekendDays()`,
   never recomputed.

Everything else — the form's `<select>`, the map's seven column headers, the sort order of a unit's
clinics — consumes `weekdayColumns()` as an Inertia prop. **`resources/js` receives the ordered,
labelled array and computes nothing**, which is what keeps `CalendarIsTheOnlyConverterTest`'s
allow-list-free JS scan green.

**How this survives a department whose week starts on Sunday.** It survives *by not depending on
the week start at all.* The stored integer is absolute; only presentation rotates. A department on
the QCH default weekend `[5, 6]` (Friday–Saturday) gets `weekStartIsoDay() === 7` and columns
`Sun, Mon, Tue, Wed, Thu, Fri, Sat`; a department on `[6, 7]` gets `1` and `Mon … Sun`. **Changing
`institutions.weekend_days` re-orders every clinic map in the department immediately, with no
stored value changing and no migration** — and Task 1 asserts exactly that as
`test_changing_the_weekend_reorders_the_columns_with_no_stored_value_changing`. (Note finding 6:
there is no `institutions.week_start` column to keep in sync, because the week start is derived.)

**`weekdayColumns()` joins `tests/fixtures/calendar/golden.json`.** That file is a durable contract
with P2's TypeScript mirror, not test scaffolding. CL-03 — *"no post-call on a day with a clinic of
the resident's current specialty"* — is a P2 condition that must map a date to an ISO weekday and
compare it to `clinics.weekday`, **client-side, without a network round trip** (UX-05). The mirror
will therefore need this exact function, and a change on one side not matched on the other is the
drift the fixture exists to catch. Task 1 adds a `weekday_columns` block keyed by weekend
configuration.

---

## Decision B: `clinic_attendees` holds the RULE; the roster is resolved at READ time

**This is the load-bearing decision of the slice.** CL-02 — *"Rotators on a unit attach to its
clinics by default; per-clinic refinement by level or named people"* — can be built two ways, and
they are not close.

### The case for write-time (a snapshot of resolved people)

- **Historical accuracy.** "Who was on Tuesday AM clinic on 3 March" stays answerable forever, even
  after somebody corrects the rota for March.
- **Cheap reads.** No join per map cell; the attendee list is a column read.
- **Explicitness.** The administrator sees the literal list they are committing to, with no
  derivation to misunderstand.

### The case for read-time (the rule is stored; people are resolved on demand)

- **CL-02's own words are a query, not a list.** *"Rotators on a unit"* is `master_rota_assignments`
  filtered by unit and date. A clinic is a **weekly recurrence with no date of its own**; a snapshot
  would be a snapshot as of *what day*? There is no honest answer, and every wrong answer is
  invisible.
- **A snapshot would need three more writers, or would corrupt one.** The rota is edited in bulk:
  `RotaAssignment` (per cell), `RotaFill` (four bulk actions over a whole year), `RotaImport` (a
  two-file CSV). Keeping a snapshot fresh means hooking all three — either three new writers of
  `clinic_attendees`, which `ClinicWritersAreSingularTest` exists to refuse, or `RotaAssignment`
  silently writing a second table, which is worse: **a single-writer guard that passes while the
  writer of table A writes table B is a guard that has stopped meaning anything.**
- **A snapshot is a second definition of the rota.** `AuditChain::canonical()`, `Person::levelAt()`
  and `Calendar`'s memo are all in this codebase because two definitions of one fact drift and the
  drift is silent. A stored copy of "who the rota puts on WARD" *is* a copy of the rota.
- **The consumers are date-parameterised, and a date-free snapshot cannot serve them.** CL-03 asks
  "is there a clinic for this resident's unit **on this date**"; CL-04 asks "what is on **this
  person's** schedule **this week**". Both need resolution as of a date, which is what read-time
  already is and what a snapshot would have to be re-derived into anyway.
- **This codebase already made this exact call once, deliberately.** `vacations` carries **no
  `period_id`**, and P1d Decision C's reason transfers verbatim: *"which period(s) it touches is a
  range intersection computed at read time, never a foreign key"*, so that it survives a department
  regenerating or switching its period system. A clinic outlives a rota year the same way.
- **The rebuttal to historical accuracy is that P1e is not building an attendance register.** CL-01,
  CL-02 and CL-05 are configuration and a map; nothing in Stage 1 records who actually attended.
  CLAUDE.md is explicit that this product has **no registry**. When Stage 2/3 needs "who was
  rostered to clinic on date D" it resolves the rota as of D, and what the rota *used to say* is
  the hash-chained `audit_log`, which is where this system already keeps that answer.

### The decision

**READ-TIME.** `clinic_attendees` stores the refinement **rule**, never a resolved roster.
`App\Support\Clinics\ClinicRoster::forDate(Clinic $clinic, string $date): array` is the one
resolver, and moving the rota moves the clinic with no write anywhere.

### The shape

`clinics.attendee_mode` is one of three, offered and validated from **one** `Clinic::ATTENDEE_MODES`
constant (the `SignoffPickers` / `CONTACT_VISIBILITIES` idiom — a picker's write-side validation
must match what it offers, per field):

| Mode | Meaning | `clinic_attendees` rows |
|---|---|---|
| `rotators` (default) | Everyone the master rota has on the owning unit on that date. CL-02's *"by default"*. | **none** — setting this mode clears them |
| `levels` | Those rotators whose level **on that date** is in the attached set. | one row per level, `level_id` set |
| `named` | Exactly the attached people, **without consulting the rota at all** — the external consultant who attends a clinic and rotates nowhere (PE-03's `people.external`). | one row per person, `person_id` set |

**A mode on the parent, rather than a bag of typed rules, is what makes the child table
homogeneous per clinic and lets the writer enforce it.** It also removes a precedence question
nobody has answered: with include- and exclude-shaped rules in one table, "named person X" and
"exclude person X" is a legal state with two defensible readings, and a wrong reading fails
silently. There is **no exclude rule** — Owner decision 7, additive if ever asked for.

**Uniqueness and the exactly-one-of constraint live in `ClinicWriter`, not the database**, for
finding 12's reason, which is the reason `person_levels`' overlap rule already lives in
`LevelAssignment`: a UNIQUE index over nullable columns enforces nothing on either engine.
`ClinicWritersAreSingularTest` is what makes that a guarantee rather than a hope, and Task 2 plants
a violation to prove it fires.

**`ClinicRoster` subtracts no leave and computes no availability.** A person on vacation is
returned, unmarked. CL-04's *"clinic session subtracts availability"* is the coverage board's job
and is P3; Task 3 pins the boundary as behaviour
(`test_a_person_on_leave_is_still_returned_and_is_not_marked_unavailable`) and Task 6 guards it at
source level, because "who is available for this clinic" is precisely the shape a future reader
will reach into this class to answer.

---

## Decision C: the clinic map is `auth` + a new `clinics.view`, contact-free, GET-only, and never link-public

**Munawib §5's footnote explicitly contemplates the clinic map being exposed without a login**
(*"when link-public, only the published schedule, boards, and clinic map are exposed — never
contacts, requests, or tallies"*). **D7 overrides it: no anonymous route, ever.** The map is
`['auth', 'throttle:clinical', 'cap:clinics.view']`. Task 15 records the override in design §1.2's
table, beside AC-02's invitation lifetime, because a deviation that lives only in a plan is one
nobody finds.

**A new capability key, `clinics.view`, seeded to every position** (0, 2, 3, 4, 5) — the `rota.view`
shape and for the `rota.view` reason: a resident needs to know when their unit's clinic runs.
**Because it reaches everybody, the map is contact-free by construction**, exactly like the rota:
`PersonPresenter::contactFree()` for any person-shaped value, and the whole `cap:clinics.view`
route group asserted **GET-only over the router** so a write endpoint cannot arrive there
unnoticed (`RotaReadViewTest`'s idiom).

**Management stays on the existing `structure.manage`**, and the clinic screen lives at
`/admin/structure/clinics` beside Units, Levels, Calendar, Periods and Holidays. A clinic's entire
payload is a unit, a weekday, a session and a label — that is department structure, and
`structure.manage`'s own catalog description (*"Manage units, training levels, calendar, periods
and holidays"*) extends to it naturally. **One new key rather than two.** The alternative — a
`clinics.manage` mirroring `rota.manage` — was considered and rejected as premature: it buys the
ability to let a Scheduler edit clinics without structure rights, which nobody has asked for, and
it is purely additive on the day somebody does.

---

## Decision D: the wizard is a DERIVED checklist over screens that already exist — it holds no state anywhere

ST-01 lists nine steps; ST-02 says every step is revisitable in Settings. Read strictly, **those two
sentences together say the wizard is a path, not a place**: if a step is a link to the screen that
already owns it, ST-02 is satisfied by construction rather than by a second implementation of
eleven admin pages.

### Server-side wizard state was considered and rejected

A `setup_step` in `app_settings`, or a `setup_progress` table, would buy "resume where you left
off" and hard step gating. It costs:

- **A second definition of one fact.** "Step 4 is done" and "at least one `periods` row exists for
  the current academic year" are the same fact stored twice, and they drift the first time an
  administrator deletes a year from Admin → Structure → Periods. This codebase has rejected exactly
  this three times already: `Person::hasAccount()` is a join and **never** a `person_status` enum;
  `InvitationStatus` is a derived projection and **never** a stored column; the master rota has no
  `status` column at all. This is the fourth.
- **A migration for a department that is already configured.** QCH is configured *today*. A stored
  counter shows it at step 0 and invites an administrator to redo everything; a derived checklist
  shows it complete with no backfill. Task 9 asserts precisely this
  (`test_an_already_configured_department_shows_complete_with_no_backfill`) and it is the single
  clearest argument for derivation.
- **A half-abandoned wizard becomes a thing that needs cleaning up.** With no state, abandonment is
  a non-event: the administrator closes the tab and the department is exactly as configured as it
  was. Reopening `/admin/setup` recomputes from the data. There is nothing to resume, nothing to
  expire and nothing to garbage-collect.

### What the checklist actually is

`App\Support\Setup\DepartmentSetup::steps()` — a pure projection over `exists()` queries, issuing a
**bounded, measured** number of them. Each step carries `key`, `title`, `route`, `kind`, `done`,
`blocked_by` and a one-line `summary` of the current value.

**Steps come in two kinds, and conflating them is how a checklist starts lying:**

- **REQUIRED** — derivable, binary: `levels`, `units`, `periods`, `roster`, `invitations`, and
  `clinics` (optional-but-derivable: satisfied when an active clinic exists **or** no unit owns
  clinics).
- **REVIEW** — **never marked done, deliberately**: `profile`, `calendar`, `holidays`. A department
  whose calendar is entirely default is *configured* and quite possibly *wrong* — ST-01's own
  example is `hijriOffsetDays`, which defaults to `0` where the QCH prototype needed `−1`, and no
  query can tell a reviewed zero from an unreviewed one. Zero holidays is likewise a legitimate
  state. **These steps render their current values inline so review is possible without leaving the
  page, and are labelled "Review" rather than shown as an unticked box.** Inventing a `reviewed_at`
  flag to make them tickable is the stored state this decision rejects, in miniature.

**`blocked_by` is advisory and never becomes the gate.** It names the unmet prerequisite (periods
need `academic_year_start`; roster import needs an active level) by reading the same predicate the
target screen's own server-side validation already enforces. The target screen still refuses on its
own, and Task 9 asserts that a blocked step's route returns *its* refusal, not the checklist's —
because a checklist that becomes an authorization boundary is a second authorization boundary.

**The P2/P3 steps are stated, never shown as empty steps.** `steps()` returns a separate `later`
array — slots and coverage templates, conditions — carrying prose, **no route and no `done` key**,
which Task 9 asserts. ST-01 lists them; P1e says plainly when they arrive rather than rendering two
permanently unticked boxes an administrator will try to click.

---

## Decision E: `Setup` is taken — the department wizard is `DepartmentSetup`, and `RequireSetup` is not touched

Finding 4 lists seven existing artifacts named `Setup`, all belonging to the **per-user first-login
2FA flow**. The department wizard uses different names throughout, and the plan states them so a
task cannot drift back:

| Concern | Existing (per-USER 2FA) | New (per-DEPARTMENT wizard) |
|---|---|---|
| Route | `GET /setup` → `setup.show` | `GET /admin/setup` → `admin.setup` |
| Controller | `App\Http\Controllers\SetupController` | `App\Http\Controllers\Admin\DepartmentSetupController` |
| Page | `resources/js/Pages/Setup.vue` | `resources/js/Pages/Admin/DepartmentSetup.vue` |
| Support class | — | `App\Support\Setup\DepartmentSetup` |
| Gate | `auth` only | `auth` + `cap:structure.manage` |

**`App\Http\Middleware\RequireSetup` is not modified, and `/admin/setup` is deliberately NOT added
to its `ALLOWED` path list.** That middleware redirects any authenticated user with a null
`users.setup_completed_at` to `setup.show`. The consequence is correct and intended: **an
administrator must finish their own two-factor setup before they can configure a department.**
Task 10 asserts it (`test_an_administrator_who_has_not_done_their_own_2fa_is_redirected_away_from_
the_department_wizard`) so that a later reader who finds the redirect surprising sees a test saying
it is the point, rather than "fixing" it by widening an allow-list that exists to make sure the
person configuring a PHI system has 2FA on.

---

## Decision F: the demo department's provenance is a LEDGER, not a column, and removal is refused whole rather than applied partially

ST-05: *"A one-click, clearly-labeled, removable demo department seed exists for training and for
development fixtures."* **Removable is the hard part** (the P1 plan says so and is right), and it
decomposes into three questions the plan must answer separately.

### 1. What is the marker?

**A ledger table, `demo_rows`, keyed `(table_name, row_id)` and grouped by a `batch_id` UUID.**
Provenance is a **join**, not a column — the same call this codebase already makes for
`Person::hasAccount()` (a join, never a `person_status` column) and for `applied_role_defaults`
(a ledger of "this seed step already ran", which is exactly why `AccessControlSeeder` never
re-asserts a revoked default).

**Rejected alternatives, with the reason each fails:**

- **A `demo` boolean column on every table the seeder writes.** Eight tables gain a permanent
  column for a training feature; `units` has **no `institution_id` and no natural provenance
  column at all** (finding 16); the flag is editable to `false` from any database console, after
  which removal silently misses the row; and a column does not *enumerate*, so proving removal
  complete would still mean scanning every table. This is the P1 plan's own suggestion and it is
  the wrong shape.
- **A second `institutions` row, removed by `institution_id`.** **Forbidden by D11**: that column
  is provenance and never a query filter, and the schema is one-way committed against it —
  `units.code`, `people.email`, `users.member_name` and `handover_signoffs(unit_id, handover_date)`
  are institution-blind UNIQUE by design, so a demo PICU collides with the real PICU on insert.
  Worse, it would teach the next reader that `where('institution_id', ...)` is acceptable here.
- **A naming convention (`DEMO-` prefixes).** An administrator renames one row and it is orphaned
  forever, with no way to tell. The name prefix still exists in this design, but for a different
  job — see below.

### 2. How is removal proved complete rather than assumed?

Four mechanisms, layered, and only the third is proof:

1. **`DemoDepartment` is the single writer**, guarded at source level in the house style, so a row
   created outside it is a build failure rather than a silent miss.
2. **The source guard `DemoRowsAreLedgeredTest`** — an early warning, and honest about being
   coarse.
3. **The round trip, which is the actual proof.** `DemoRoundTripTest::test_removal_returns_every_
   table_to_its_pre_seed_row_count` snapshots `count(*)` for **every table derived from
   `Schema::getTableListing()`** — not a hand-written list, which is the thing that goes stale —
   minus a named `EXCLUSIONS` constant; seeds; removes; and asserts `assertSame($before, $after)`.
   A row created outside the ledger cannot survive this, whatever the source guard missed.
   `EXCLUSIONS` is itself asserted: `test_the_exclusion_list_holds_only_append_only_or_framework_
   tables` requires a stated reason per entry and fails if any excluded table is one
   `DemoDepartment` writes. **`audit_log` is necessarily excluded** — it is append-only and
   hash-chained, and both seeding and removing append to it; that is correct behaviour, not
   leakage.
4. **The negative control**, which is what makes the round trip trustworthy:
   `test_a_row_created_outside_the_ledger_makes_the_round_trip_fail` plants an unledgered row and
   watches the count check name it. **Watch it fail before trusting it** — a round-trip test that
   has never gone red is indistinguishable from one that compares nothing.

### 3. What if a real row now references a demo row?

**Removal is REFUSED, whole, naming what holds it — never partial.** This is the
`PeriodController::destroy()` shape (an academic year's periods cannot be deleted while any
`master_rota_assignments` row references them) applied to a bigger set, and it is the bulk-operation
discipline invariant 12 requires: validate the whole set before mutating, one transaction, refuse
whole rather than partially, one summary audit row.

The pre-flight walks the ledger and counts **non-ledgered** inbound references. The cases are real,
not hypothetical:

- a real `handovers` row written on a demo unit during training;
- a real `users` row claimed against a demo person, because an invitation went to a demo address;
- a real `master_rota_assignments` or `vacations` row on a demo unit, period or person;
- a real `clinic_attendees` row naming a demo person on a **real** clinic;
- a real `handover_signoffs` row naming a demo person in one of its four `*_person_id` columns —
  which is medico-legal evidence and must never be reachable by a "clean up the demo" button.

The refusal reports `(table, count)` pairs. **No names, no PHI** (invariant 9), and the operator's
remedy — deal with the real row first — is named in the message.

**The reference map is hand-written and asserted against the live schema**, never trusted:
`test_every_foreign_key_pointing_at_a_demo_table_is_in_the_reference_map` derives the inbound FK
set by introspection and fails when a future migration adds an FK the map does not know about. The
precedent is `ReservedUnitCodesTest::test_the_reserved_list_covers_every_literal_route_segment`,
which derives its list from the registered routes rather than trusting the constant it guards.

### And the human label, which is a different job

**Every demo row is also visibly named as one** — units prefixed `Demo `, people named as obvious
fictions on a reserved, unroutable domain. ST-05 says *clearly-labelled*, and a label must survive
into a CSV export and a printed sheet, where no ledger lookup happens. **The prefix is the human
label; the ledger is the machine truth. Neither substitutes for the other**, and the plan says so
because a reviewer will otherwise ask why both exist.

### Why this one runs in production when `DemoSeeder` refuses to

`DemoSeeder` and `E2eSeeder` both throw outright in production, and correctly: they are unmarked
and unremovable (finding 7), so a row either creates is indistinguishable from a real one forever.
**`DemoDepartment` is ledgered and provably removable, which is exactly what makes it safe where
they are not** — and ST-05's *"for training"* means the live instance, because that is where people
are trained. It is gated `cap:structure.manage`, audited, refuses to run twice, and its removal
path ships **before** its creation route is exposed (Task 13 precedes Task 14). **`DemoSeeder` is
not replaced, not modified, and keeps its production throw**; consolidating the two is a follow-on,
recorded in §14 rather than smuggled in here.

---

## Decision G: ST-01's profile step — `institutions.name` gains a screen, `code` stays env-only and says so

Finding 5: ST-01's first step threads to a screen that does not exist. Design §14 item 12 narrowed
this in P1b (the calendar columns became editable) but left `name` and `code` env-only.

**`institutions.name` becomes editable** at `/admin/structure/department`, `cap:structure.manage`,
audited `institution_profile_update` by field name. It is a display string; nothing keys on it.
`ReferenceSeeder` writes `name` on **create only**, so an administrator's rename survives
`php artisan db:seed --force` — Task 8 asserts that rather than assuming it (the P1b precedent, where
the same property for unit profile columns turned out to be the reason
`2026_08_15_120002_correct_ward_clinic_owner` had to exist as a separate migration).

**`institutions.code` stays env-only**, rendered read-only with the reason on screen. It is
`INSTITUTION_CODE`, it is `ReferenceSeeder`'s `firstOrNew` key, and re-coding a live institution
means the next `db:seed --force` creates a *second* institution row rather than updating the first.
That is a provisioning operation, not a settings change. (Note: `institutions.code` is **not**
`App\Support\Instance::slug()`, which names the backup archive and the host scripts' files and comes
from `INSTANCE_SLUG`. They are different values and the screen must not imply otherwise.)

**One mechanical trap, stated because the guard will fire and the fix is not obvious.**
`CalendarWritersFlushTest::WRITE_NEEDLES` includes the bare `Institution::current()`, so **the new
controller is matched the moment it reads that row**, and the build fails unless it either calls
`Calendar::flush()` or is added to `ALLOW_LIST` with its reason stated at the site. **Take the
allow-list branch**, following the precedent already in that file: `PersonController` is
allow-listed as *"reads and writes `institutions.contact_visibility` — a PE-02 policy column, not a
calendar one."* `institutions.name` is a display column, not a calendar one, and flushing the
calendar memo on a rename would imply a relationship that does not exist.

---

## The split: P1e-1 and P1e-2

**P1e as scoped is two branches.** Fifteen tasks, two new tables, two new capability-bearing
surfaces, a new admin CRUD screen, a new department-wide read surface, a derived projection with
nine steps, and a create/remove lifecycle that must be provably complete. P1c and P1d both split at
comparable size and for the same reason.

| Branch | Tasks | Scope | Deployable at the seam? |
|---|---|---|---|
| **P1e-1 — Clinics** | 1–7 | `Calendar`'s weekday vocabulary; `clinics` + `clinic_attendees` and their one writer; `ClinicRoster`'s read-time resolution; the `structure.manage` CRUD screen; CL-05's map behind a new `clinics.view`; the CL-03/CL-04 absence guard; the e2e journey. | **Yes.** A department can define clinics and everybody can see the weekly map. Nothing in it references the wizard or the demo. |
| **P1e-2 — Wizard and demo department** | 8–15 | The department-profile screen; `DepartmentSetup`'s derived checklist and its screen; `demo_rows` + `DemoLedger`; `DemoDepartment::create()`; `DemoDepartment::remove()` with its reference pre-flight and round-trip proof; the one-click screen and the artisan commands; the documents. | **Yes.** |

**The seam, stated as a dependency in one direction:** *the wizard's checklist has a `clinics` step
and the demo department seeds clinics; clinics need neither the wizard nor the demo.* P1e-1 can
merge and ship on its own. P1e-2 cannot start before it, because Task 9's `clinics` step and Task
12's demo clinic both require `Clinic`, `ClinicWriter` and `ClinicRoster` to exist.

**If P1e-2 itself proves too large**, the fallback seam is **after Task 10**: the wizard (Tasks
8–10) and the demo department (Tasks 11–15) share nothing but the Admin → Structure screen they
both link from. Declared here so the decision is available, not taken here.

---

## Migration ordering

P1e uses `2026_08_16_*`, as the P1 plan reserved, sorting strictly after P1d's `2026_08_15_120004`:

```
2026_08_16_120001_create_clinics_and_attendees_tables   (Task 2 — P1e-1)
2026_08_16_120002_create_demo_rows_table                (Task 11 — P1e-2)
```

Both create new tables. Nothing is retyped, nothing is dropped, no clinical table is touched, and
no index or unique key is led by `institution_id`.

**Check the slot is still free before writing either file.** P1d-1 found its reserved
`2026_08_15_1200*` slots already taken by an unrelated ops-defects branch and had to renumber; the
same branch is the reason `2026_08_15_120002_correct_ward_clinic_owner` exists. `ls
database/migrations/ | tail -5` costs nothing.

The owner runs production migrations (CLAUDE.md). Task 15 supplies the verification queries for
`docs/RUNBOOK-DEPLOY.md`.

---

## Amendments made during execution

*(Empty at plan time. Follow the P0c/P0d/P1a/P1b/P1c/P1d convention: when a task turns up something
this plan's enumeration missed — a site not listed, a test that goes red for a reason the plan did
not predict, a behaviour that differs between SQLite and MySQL or between UTC and Asia/Riyadh —
record it here, dated, with what was found and how it was resolved. Findings caught empirically
rather than by inspection are the ones worth writing down.)*

*The base rate is not low: P1a recorded nine amendments across nine tasks, P1b eight across
thirteen including two real plan errors, P1c thirteen across twelve including three cases of the
plan contradicting its own tests, and P1d-1 roughly a dozen across twelve — **whose single most
repeated class was task text contradicting the plan's own decisions block.** That is why every
decision above is restated inside the task text below rather than left in the block. **Assume this
plan is wrong somewhere too**, and in particular: run `php artisan test` on a clean tree before
touching any file at the start of each task, and trust the measured baseline over this document's
arithmetic — this plan's own PHPUnit baseline was measured on a dirty tree and says so.*

### 2026-08-11 — Task 1

1. **Baseline re-measured on a clean tree, as instructed: `php artisan test` → 1451 passed, 0
   failed** (the plan's contaminated reading said 1446/75-failed). `npm test` → 192, `npm run
   build` → green. Task 1 took it to **1460** (8 `WeekdayVocabularyTest` + 1 `GoldenFixtureTest`).
2. **The weekday names went into `lang/en/calendar.php`, not into a `const` on `Calendar` — a
   deliberate deviation from Task 1's "are plain constants".** The prohibition that sentence is
   really making (*"no `IntlDateFormatter`, no `IntlCalendar`, no date construction"*) is held in
   full: a weekday name is a vocabulary lookup and nothing constructs a date to obtain one. But
   the tree already has exactly one calendar-vocabulary table, `lang/en/calendar.php`'s
   `hijri_months`, whose own docblock states the reason — *"Munawib AR-07: strings are
   externalized from launch so a future locale is translation work, not a rewrite"* — and
   `golden.json`'s `hijri_labels` block already names that file as the source a mirror checks
   itself against. A second calendar vocabulary in a different place, with a different translation
   story, is the "one fact in two places" this codebase keeps paying for; Task 1's own
   justification cites AR-07 while prescribing the shape AR-07 exists to avoid. `label` and
   `short` sit in ONE entry per day rather than two parallel arrays, because two arrays are two
   lengths that can drift and an abbreviation is not always the first three characters of a name
   in another language. **If the owner prefers the constant, it is a one-function change**
   (`Calendar::weekdayStrings()`); the public signatures, the Inertia prop shape and the fixture
   are identical either way.
3. **`golden.json`'s `version` went 1 → 2.** Its marker exists so *"a future shape change is
   visible to BOTH independent consumers … rather than one silently drifting onto a JSON shape the
   other has never seen"* — a whole new top-level block is such a shape, additive or not. The
   mirror is unwritten, so nothing breaks; `GoldenFixtureTest::test_fixture_declares_a_version`
   now pins 2 and records why.
4. **The new block is `weekday_columns: {_description, cases[]}`, not a bare list**, matching
   `parse_rejects`/`hijri_labels` rather than `weeks` — it needed a paragraph of contract prose
   (why CL-03 makes it contractual at all) that a bare list has nowhere to put.
5. **Empirical, and the reason the fixture assertion is `assertSame` on the whole `columns`
   array:** `assertSame` on a PHP array compares key ORDER too. A first draft of
   `test_every_column_says_whether_it_is_a_weekend_day` built its map in *column* order and failed
   against an ISO-ordered expectation that was correct in content — fixed with a `ksort()` and a
   comment, since ORDER is the previous test's subject. The same property is what makes the
   fixture assertion strong: it pins key order and value types, and the array ships to
   `resources/js` verbatim.
6. **The fixture assertion was watched failing against planted drift** (one `weekend` flag flipped
   in the `[5, 6]` case) before being trusted; it named the exact column. Note for whoever plants
   the next one: `git checkout <fixture>` to revert a plant also reverts the task's own
   uncommitted work on that file. Copy the file aside instead.
7. **`CalendarIsTheOnlyConverterTest` stayed green with no allow-list change**, as Task 1 requires
   — but note its JS scan needles for date CONSTRUCTION (`new Date(`, `toLocaleDateString(`, …),
   so it would **not** catch a `.vue` file that hardcoded `['Sun', 'Mon', …]` instead of consuming
   the `weekdayColumns()` prop. Tasks 4 and 5 are where that could actually happen; if it is worth
   guarding, the needle is a weekday-name list under `resources/js`, and it belongs there rather
   than here.
8. Verified against the tree, as instructed: **there is no `institutions.week_start` column**
   (`grep week_start database/migrations app/Models/Institution.php` → nothing).
   `Calendar::weekStartIsoDay()` derives it. Finding 6 is correct.

### 2026-08-11 — Task 2

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1460 passed, 0
   failed**, matching Task 1's recorded number exactly. `npm test` → 192, `npm run build` → green.
   Task 2 took PHPUnit to **1476** (14 `ClinicWriterTest` + 2 `ClinicWritersAreSingularTest`). The
   migration slot was checked first as instructed and **was free** — `ls database/migrations/ |
   tail -5` ended at P1d's `2026_08_15_120004_create_vacations_table.php`, so unlike P1d-1 no
   renumbering was needed. **The e2e suite was re-run clean** (`rm database/e2e.sqlite && npm run
   test:e2e` → **22 passed**) even though no e2e file was touched: a new migration changes the
   schema that self-contained world is built from, so the stale sqlite file would have been a
   green run against a database that no longer matches the tree.
2. **Three of the guard's needles were written, measured against what Task 4 must contain, and
   then WITHDRAWN — ruling 42's "a needle whose cost exceeds its reach is withdrawn on
   measurement, not taste".** The array-key twins `'weekday' =>`, `'attendee_mode' =>` and
   `'clinic_id' =>` all have zero pre-existing matches across app/ + database/ + routes/, so they
   looked free. They are not: Task 4's clinics screen builds Inertia props in this codebase's house
   style, and `'weekday' => $clinic->weekday` inside a `present()` map is a **read**. Keeping the
   needle would force `ClinicController` onto `ALLOW_LIST` — blinding the guard at precisely the
   file where a second writer is most likely to appear. The **property-assignment twins are kept**
   (`->weekday = `, `->attendee_mode = `, `->clinic_id = `, `->session = `), because those are
   unambiguously writes and the `= ` trailing space is what keeps them so. Consequence, stated in
   the guard's own docblock rather than implied away: `$clinic->name = 'x'; $clinic->save();` is
   invisible to this scan, because `->name = ` is also `units`', `levels`', `people`' and
   `holidays`' column.
3. **No `test_every_allow_listed_file_still_matches_a_needle` twin, deliberately, and the reason
   generalises.** One was written first and immediately flagged `ClinicFactory` as stale — because
   `Clinic::factory()->create()` inserts through `Factory::create()`, which appears nowhere in the
   factory's own source. The distinction is real and worth recording: `CalendarWritersFlushTest`
   and `InstitutionProvenanceTest` allow-list **incidental matches**, so an entry matching nothing
   there is definitionally stale; a single-writer guard allow-lists **writers**, and a writer the
   substring scan cannot see is still an honest entry. `RotaWritersAreSingularTest` and
   `PersonLevelsHaveOneWriterTest` both allow-list their factories on exactly that silent basis.
   The `test_every_allow_listed_file_still_exists` twin the plan asks for is present.
4. **`ClinicWriter` takes `institution_id` from the CALLER (`?int $institutionId`), and that is a
   `CalendarWritersFlushTest` avoidance, not a style choice.** Deriving it inside the writer would
   mean `Institution::current()`, which is a `WRITE_NEEDLE` — the file would fail the build unless
   allow-listed for a column with nothing to do with the calendar (invariant 15's trap, arriving
   two tasks earlier than Decision G predicted it). The caller-supplied form is also the existing
   precedent: `LevelController`, `PeriodController` and `HolidayController` all write
   `$request->user()?->institution_id`, and `HolidayController` says in a comment that it does so
   *"not `Institution::current()`"*.
5. **The plan gives `update()` no signature; it is `update(Clinic $clinic, Unit $unit, array
   $attributes)`** — the unit arrives as a MODEL, so resolving it from user input stays the
   caller's job through route-model binding or `Unit::findByCode()` (D2). A `unit_id` key inside
   `$attributes` would have made this writer the place a raw code string gets looked up.
6. **`attendee_mode` is deliberately NOT settable through `create()` or `update()`.** It moves only
   through `setAttendees()`, together with the rows, in one transaction — so `levels` mode can
   never briefly hold a person row, which is one of the three states no engine can refuse.
   `create()` always opens a clinic on CL-02's default, `rotators`, which needs no rows to express.
7. **Four extra tests beyond the plan's ten (14 total):** moving a clinic to another unit is
   allowed but never onto a non-owning one; deactivate-never-delete plus the refusal to revive onto
   a since-retired unit; an attendee id that names nothing is refused in the writer rather than
   surfacing as a raw FK 500 from inside a caller's transaction; and `institution_id` provenance.
   The FK check matters more than it looks — `config/database.php` sets
   `foreign_key_constraints => env('DB_FOREIGN_KEYS', true)`, so SQLite **does** enforce them under
   test, and the failure without this check is an `QueryException`, which is neither of the two
   exception types a controller's catches will be written for.
8. **The guard was watched failing against a planted second writer carrying all four shapes at
   once** (`app/Support/Clinics/PlantedSecondWriter.php`, deleted immediately after): it named
   `Clinic::create(`, `DB::table('clinic_attendees')`, `->attendees()->create(`, and all three
   property assignments, on the one file. Then reverted, re-run, green. Note for the next planter,
   since the file was new rather than edited: `rm` is the revert here, and `git status` must come
   back to exactly the untracked set the task created — there is no `git checkout` to get wrong.
9. **`$table->index('clinic_id')` on `clinic_attendees` duplicates the index MySQL creates
   automatically for the foreign key.** Kept anyway, because `master_rota_assignments` already
   states its own `index('unit_id')` explicitly beside a `constrained()` FK, and a read path should
   not depend on one engine's incidental behaviour. Flagged here so it reads as a decision rather
   than an oversight.
10. **The negative control ruling 42 requires was run too, and it is what makes the property
    needles trustworthy.** A throwaway reader (`$clinic->attendee_mode === Clinic::MODE_NAMED`,
    `$clinic->weekday > 0`, `$clinic->session === 'AM'`, `$clinic->attendees()->get()`) was planted
    and the guard stayed GREEN — so the `= ` trailing space is doing its job and
    `->attendees()->get(` is correctly not a write. Without this half, a needle set that matched
    every mention of a column would look identical to one that matched only writes.
11. **TWO EXISTING SOURCE GUARDS FIRED ON THIS TASK'S FILES, AND BOTH WERE CORRECT TO. Neither was
    predicted by the plan, and both were found only by running the FULL suite** — the targeted
    `--filter` the task text gives (`ClinicWriterTest|ClinicWritersAreSingular|
    InstitutionProvenance`) is green with both defects present, which is the whole argument for the
    unfiltered run the standing rules require.
    - **`AccountLinkHasOneWriterTest`** matched `ClinicWriter` on the array-key-plus-null literal
      for the person column. Its subject is `users.person_id`, where nulling the link makes an
      account nameless on every screen with no error; `clinic_attendees.person_id` is a different
      column on a different table that merely shares a name. Fixed by building the attendee row
      with VARIABLE keys (`[$column => $id, $other => null]`) rather than two literal branches —
      which is also less code — with the reason stated at the site so nobody "clarifies" it back.
    - **`CalendarWritersFlushTest`** matched `ClinicWriter`'s DOCBLOCK, because the paragraph
      explaining that this writer deliberately does **not** resolve the institution row named that
      static with its parentheses, and `WRITE_NEEDLES` scans prose. This is invariant 15's trap
      arriving six tasks earlier than Decision G expects it, through the opposite door: not a file
      that reads the row, but a file that says it doesn't. Fixed by spelling around the call rather
      than deleting the reasoning — the `RotaAccessTest` comment-stripper exists precisely because
      a literal scan otherwise "would fail the build on the rule's own statement and teach people
      to delete it", and `CalendarWritersFlushTest` deliberately has no stripper. **Adding
      `ClinicWriter` to that guard's ALLOW_LIST would have been the wrong repair and would have
      LOOKED right**: its `test_the_allow_list_is_not_stale` twin only checks that an entry still
      matches a needle, so an exemption earned purely by a comment would have survived forever.
    - The generalisable lesson for Tasks 3–15: **a docblock in this codebase is scanned source.**
      Three separate guards (`CalendarIsTheOnlyConverterTest`, `CalendarWritersFlushTest`,
      `AccountLinkHasOneWriterTest`) match prose, and only `RotaAccessTest`'s narrow scan strips
      comments. Naming a forbidden call in order to reject it is a build failure.
12. **Nothing in the task text was wrong against the tree.** The migration slot, the
    `person_levels` precedent it quotes (`2026_08_14_120002`), `Unit`'s `clinic_owner` column,
    `Clinic::SESSIONS`-as-constant, ruling 42's three shapes and `RotaWritersAreSingularTest`'s
    structure all check out as described. The two guard collisions above are interactions the task
    text could not reasonably have foreseen, not errors in it.

### 2026-08-11 — Task 3

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1476 passed, 0
   failed**, matching Task 2's recorded number exactly. `npm test` → 192, `npm run build` → green.
   Task 3 took PHPUnit to **1488** (12 `ClinicRosterTest`). No migration and no e2e file was
   touched, so the e2e suite was not re-run.
2. **`Calendar::ymd()` normalises the date at the entry point — a deliberate deviation from the
   task's "no `DateTime`, no `Calendar` call".** The prohibition that sentence is really making is
   about the COMPARISON (do not build date objects to compare bounds), and that half is held in
   full: nothing below `forDate()`'s first line converts anything. But the ENTRY needed a gate,
   because leniency here fails silently in the worst possible direction — `'2026-7-3'` sorts below
   every stored `Y-m-d` bound, so an unnormalised string resolves to an EMPTY clinic and reads as a
   quiet Tuesday, with no error anywhere. `Calendar::ymd()` costs no query, throws on anything that
   is not a plain `Y-m-d`, and is the module that owns the question. New case
   `test_a_date_that_is_not_a_plain_ymd_is_refused`, beyond the plan's ten.
3. **The span bounds are `whereDate()`, not a raw string comparison in the WHERE clause, and the
   task text is wrong to imply otherwise.** `MasterRotaAssignment` casts both bounds to `date`, and
   MySQL 8.4 round-trips such a column as `'Y-m-d 00:00:00'` — which is exactly the caveat
   `MasterRotaAssignment::booted()` and `Vacation::scopeIntersecting()` each already carry in their
   own docblocks. P1d-2 Decision B's four-way string comparison is for values ALREADY IN PHP (what
   `AvailabilitySummary` does, over an array the grid built); it is not a query idiom, and using it
   as one would have passed under SQLite and failed in production.
4. **Vacation verdict: neither excluded nor flagged — returned, unmarked, and the leave table is
   never queried at all.** Decision B says so in terms ("a person on vacation is returned,
   unmarked"), and CL-04 is P3. The reason it is worth stating as an implementation fact rather
   than a policy: because no leave is read, `ClinicRoster` imports nothing from the leave side, so
   Task 6's `availab`/`subtract`/`coverage` scan over `app/Support/Clinics/` passes on its own
   merits rather than by allow-list. The test asserts the WHOLE key set of a returned row, not a
   handful of named absences — a named-absence assertion cannot catch a field whose name nobody
   thought to list.
5. **Stale verdict: returned and FLAGGED, and the person query carries `withTrashed()`.** The plan
   names only "a retired person"; in this codebase those are two different states — `active =
   false` is deactivated and `deleted_at` is retired (`PersonPresenter` projects them as `active`
   and `retired` separately). Case 8 covers both, and the second is the load-bearing one: a plain
   `whereIn` on `people` silently DROPS a soft-deleted person between the span query and the person
   query, so their occupied cell disappears from the clinic with no error while the span is still
   on the rota. Flagging rather than dropping is P1d-2 Decision D's answer, for its reason — a
   departed colleague on a clinic list reads as cover that is not there, and an invisible one hides
   a cell somebody has to clear.
6. **Retired UNIT verdict: still resolves — an eleventh case beyond the plan's ten.**
   `ClinicWriter` refuses to create or revive a clinic on a retired unit, but a unit may be retired
   UNDER a clinic that already exists, and that is a different question. Resolution is not
   authorization: the same answer case 10 gives an inactive clinic. Pinned as
   `test_a_clinic_whose_unit_has_since_been_retired_still_resolves`.
7. **Query cost measured on a populated unit, per MODE, because "the count" is not one number.**
   Forty people on the ward (ten of them split spans, twenty promoted mid-period, forty on leave,
   ten deactivated): **`rotators` 2 queries, `levels` 5, `named` 2**, bounds pinned at 4 / 7 / 4.
   The plan asks for one figure; three modes are three code paths with three query sets, and a
   single bound would have been the bound of whichever mode the fixture happened to use. **The
   bound was watched failing:** the set-wise level resolver was replaced with a per-row
   `$person->levelAt($on)` and the test went red at **83 queries against 7**, then restored.
8. **Contact-freedom is proved twice, and the second proof is the one that would survive a
   rewrite.** Behaviourally: the whole key set asserted, plus `email`/`phone`/`notes`/`constraints`
   asserted ABSENT (not null) with the department on `contact_visibility = members` AND a
   `people.manage` administrator acting — both branches of `PersonPolicy::viewContact()` true at
   once, which is the combination P1d-2 found the live disclosure under. At source level: `->email`
   was planted inside the projection map and `ContactFieldsAreProjectedOnceTest` named the file
   twice, then reverted and re-run green. Without that second half, "the guard is green" and "the
   guard cannot see this file" look identical.
9. **NO SOURCE GUARD COLLIDED, and that was construction rather than luck.** Task 2 amendment 11's
   lesson was applied before a line was written: the docblock names neither the institution-row
   accessor (a `CalendarWritersFlushTest` WRITE_NEEDLE, which scans prose) nor any of
   `->email`/`'phone'`/`'notes'`/`'constraints'` (`ContactFieldsAreProjectedOnceTest`, likewise
   prose-scanning), and no person-id-plus-null literal appears anywhere.
   `ClinicWritersAreSingularTest` stayed green over `$clinic->attendee_mode ===`, which is the read
   its own docblock predicted for this file BY NAME as the reason the `= ` trailing space is
   load-bearing. Confirmed by the FULL run, per the standing rule — the filtered run the task text
   gives is green either way, which is what made Task 2's two collisions invisible.
10. **`via` is two class constants (`ClinicRoster::VIA_ROTATION` / `VIA_NAMED`), not the bare
    strings the task text writes.** The `Clinic::SESSIONS` / `ATTENDEE_MODES` idiom: a consumer
    comparing against a constant cannot typo one silently, and Task 5's map is the consumer.
11. **`atLevels()` has no empty-set short-circuit, on purpose.** One was written first and removed:
    `Person::levelSpansBetween()` already issues no query for an empty collection, and an empty
    rule set filters everybody out through `in_array()` anyway — so the clause bought nothing but a
    second branch, one half of which described a state `ClinicWriter` refuses to create and no test
    could ever exercise. `Calendar::weekdayStrings()`'s own docblock makes the same call for the
    same reason.
12. **Nothing else in the task text was wrong against the tree.** `PersonPresenter::contactFree()`,
    `Person::levelSpansBetween()`/`levelFromSpans()`, `withExists(['user as has_account'])`,
    `Clinic::ATTENDEE_MODES` and the `clinic_attendees` shape all check out as described; item 3
    above is the one factual correction.

### 2026-08-11 — Task 4

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1488 passed, 0
   failed**, matching Task 3's recorded number exactly. `npm test` → 192, `npm run build` → green.
   Task 4 took PHPUnit to **1508** (19 `ClinicScreenTest` + 1 new
   `CalendarIsTheOnlyConverterTest` case) and Vitest to **202** (10 `Clinics.test.js`). No
   migration and no e2e file was touched, so the e2e suite was not re-run.
2. **The Vitest file is `tests/js/Clinics.test.js`, not the plan's
   `resources/js/__tests__/Clinics.spec.js` — the plan's path would never have run.**
   `vitest.config.js` includes exactly `tests/js/**/*.test.js`; there is no `__tests__` directory
   anywhere in the tree and no `.spec.js` outside `tests/e2e/` (which is Playwright's). A spec at
   the path the task gives would have been silently collected by nothing, and `npm test` would
   have reported the same 192 it always did.
3. **THE HARDCODED-WEEKDAY NEEDLE: ADDED, AND PROMOTED REPO-WIDE rather than kept per file.** Task
   1 amendment 7 flagged the gap and left the call to this task. Measured first, as ruling 42
   requires: a QUOTED WHOLE WORD pattern over all of `resources/js` — the seven names, full or
   three-letter, wrapped in any of the three JavaScript string delimiters — matched **zero files**
   before the clinics screen existed,
   so it costs no allow-list entry and blinds no file — and the bare substrings were rejected in
   the same measurement, because `Mon` matches `Month`, which `Holidays.vue` legitimately says
   twice. It lives in `CalendarIsTheOnlyConverterTest` beside the ten date-construction needles,
   with **no allow-list**, deliberately: a per-file Vitest assertion protects the one file somebody
   remembered to write it in, and Task 5's map would be unguarded by default. Watched failing
   against a plant in a DIFFERENT file (`Holidays.vue`, three day names in two quote styles); it
   named the file and the three strings, then reverted and re-run green. The behavioural half —
   "the picker's options are the prop's labels, in the prop's order" — stays in Vitest, because the
   two fail for different reasons: a component can consume the labels honestly and still sort them
   itself.
4. **TRAP 1 CAUGHT THIS TASK TWICE, IN ITS OWN NEW GUARD.** The first run of `Clinics.test.js` went
   red on `Clinics.vue` for `'Sun','Mon'` and for the raw-markup directive — both from the
   component's own DOCBLOCK, which was explaining that it does not do those things. Spelled around
   (the docblock now says "a literal array of seven day names" and "the raw-markup directive"), not
   allow-listed, per Task 2 amendment 11's lesson. Worth recording that the trap fires on brand-new
   guards written in the same commit as the file they scan, not only on pre-existing ones.
5. **`App\Support\Clinics\ClinicPickers` is a new file the task's "Files touched" list does not
   name, and D9 is why.** Test 3 asks for offer-and-accept parity as a matrix; that needs ONE
   predicate per field consumed by both the props and the FormRequest. `Rule::exists` runs on the
   raw query builder and never sees SoftDeletes' global scope, so a predicate written once as
   Eloquent and once as raw SQL is two predicates — `SignoffPickers`' whole reason for existing.
   Three predicates (`unitPredicate`, `levelPredicate`, `personPredicate`), each applied to the
   rule directly and to the offer query through `getQuery()`. The parity matrix is asserted for
   units (4 fixtures), people (3) and levels (2).
6. **`ClinicRequest` has a sibling, `ClinicAttendeesRequest`.** The mode and the rule set are ONE
   act and travel together (Task 2 amendment 6's reason: splitting them admits a moment where
   `levels` mode holds person rows), so the attendee endpoint has its own payload shape and its own
   request class rather than optional keys bolted onto the CL-01 one.
7. **Every `ClinicWriter` refusal is caught and flashed, and that is load-bearing rather than
   polite.** The writer throws `InvalidArgumentException` for every rule the database cannot hold;
   uncaught, an administrator ticking "levels" with nothing selected gets a 500.
   `test_a_writer_refusal_reaches_the_screen_as_an_error_not_a_500` pins it, and also asserts the
   clinic is unchanged afterwards — the writer throws before its transaction opens, so a refusal
   must leave the row exactly as it was.
8. **An attached level or person the pickers no longer offer is NAMED, not silently dropped.**
   `SignoffPickers`' `$keep` problem in a different shape: the attendee editor replaces the set
   whole, so a checkbox that disappears because its subject was deactivated takes the rule with it
   on the next save. The controller resolves those subjects (`withTrashed()` on the person side —
   a plain lookup drops precisely the row that most needs naming) into an `unlisted` list per
   clinic, and the screen says "no longer offered, and saving will drop them". They are NOT made
   acceptable to the rule again: parity is per offered-and-selectable option.
9. **Found by the query-cost test, not by inspection: the two picker offers were being built
   TWICE per page load** — once for the props and once inside the listing's unlisted-attendee
   lookup, which re-ran the whole roster query. Fixed by building them once in `index()` and
   passing them down. `test_the_listing_cost_does_not_grow_with_the_roster` pins the result on a
   populated department (30 people on the rota, 3 clinics): a bound measured on an empty one only
   ever proves the empty case. **And the bound itself had to be tightened before it meant
   anything** — measured **18**; re-planting the per-clinic offer rebuild took it to **23**, which
   the first bound written (25) passed. A bound with more headroom than the regression it exists to
   catch is decoration. Twenty, watched failing against the plant, then reverted and green.
10. **`AuditLog`'s column is `detail`, singular.** A first draft of the audit assertions read
    `$row->details`, which is not an attribute — Eloquent returns `null` for it silently, so the
    assertion compared against `''`. It failed here rather than passing vacuously only because the
    test asserts `assertStringContainsString` (a positive claim) alongside the negative ones. A
    file that had asserted ONLY `assertStringNotContainsString('Renal Clinic', $row->details)`
    would have been green forever against a column that does not exist.
11. **`test_there_is_no_destroy_route_for_a_clinic` passed on the FIRST red run**, which is exactly
    what its vacuity twin is for: with no clinic routes registered at all, a sweep for DELETE verbs
    over routes whose URI contains "clinic" iterates an empty set.
    `test_the_clinic_routes_are_actually_registered` was red at that moment and is what makes the
    sweep mean anything. A third case, `test_deleting_a_clinic_is_a_plain_method_not_allowed`,
    asserts the 405 at runtime and that the row survives.
12. **`ReferenceSeeder`'s WARD is an active clinic owner**, so any assertion of the form
    `->where('units', [])` is asserting the seeder rather than the rule. The retired-unit case
    asserts the specific code's ABSENCE from the offered list instead.
13. **The Vitest `useForm` mock returns `reactive()`, unlike the two existing ones.** A plain
    object mutates without notifying Vue, so `setValue` on the mode `<select>` changed the form and
    re-rendered nothing — the two picker-visibility assertions would have been checking the initial
    render twice and passing for the wrong reason. Found by the tests going red, not by review.
14. **Nothing else in the task text was wrong against the tree**, and the prescribed props, routes,
    audit actions and no-destroy rule are all implemented as written. The `clinics` prop is the
    GROUPED structure the task asks for (`[{unit, clinics[]}]`), which is worth stating because the
    name reads like a flat list.

### 2026-08-11 — Task 5

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1508 passed, 0
   failed**, matching Task 4's recorded number exactly. `npm test` → 202, `npm run build` → green.
   Task 5 took PHPUnit to **1524** (16 `ClinicMapTest`) and Vitest to **212** (9
   `ClinicMap.test.js` + 1 new `AppLayout.test.js` case). No migration was added, but the e2e world
   was rebuilt and re-run anyway (`rm database/e2e.sqlite && npm run test:e2e` → **22 passed**): the
   capability catalog is seeded data in that file, so a stale world would have run against a
   department that had never heard of `clinics.view`.
2. **A GUARD IN A FILE THE TASK TEXT NEVER NAMES WENT RED, AND ONLY THE FULL RUN SAW IT.**
   `AccessControlParityTest` pins each position's EFFECTIVE capability set by hand
   (`expectedByPosition()`), so a new default-to-everybody key fails two of its cases —
   `test_each_role_effective_set_matches_the_documented_server_gates` and
   `test_seeder_is_idempotent`. The task's own `--filter "ClinicMapTest|RotaAccessTest|
   AccessControl"` would in fact have caught this one by luck of the word "AccessControl", but the
   run that actually found it was the unfiltered one, and the general lesson stands from Task 2
   amendment 11: **a capability is added in four places, not three** — `CATALOG`, `DESCRIPTIONS`,
   `ROLE_DEFAULTS`, and the parity test's `$anyAuth`. Fixed by adding `clinics.view` to `$anyAuth`
   beside `rota.view`, with the reason stated at the site.
3. **THE MAP SHIPS NO PERSON-SHAPED VALUE AT ALL — a deliberate deviation from the task's *"if a
   count or a name list is shown at all it comes from `ClinicRoster` through `contactFree()`"*.**
   The task offers that as a conditional and the condition is not met, for a reason worth recording
   because it is not obvious: **`ClinicRoster::forDate()` answers for a DAY, and the map has no
   day.** A clinic is a weekly recurrence with no date of its own, so resolving a Tuesday cell as of
   today (a Thursday) reports Thursday's rota with complete confidence and no error anywhere — the
   answer would be wrong for six of the seven columns. Only `named` mode is date-independent, and
   using the resolver for one mode out of three is a third definition of "who attends". So the map
   shows the RULE: the mode label, plus the training-level CODES for a `levels` refinement, which
   are department structure and not people. That is the strongest available form of contact-free —
   there is nothing on this surface to gate, no viewer passed anywhere, and no second projection.
   Owner binding *"the map shows clinics and sessions"* is satisfied literally.
4. **Consequently there is NO `today` prop either**, and that is the same decision rather than an
   omission. A first draft sent `Calendar::label(Calendar::todayYmd())` for context and it was
   removed: the moment a date appears on this surface somebody resolves a cell as of it. Stated in
   the controller at the site where the prop would go, so the next reader adds it deliberately or
   not at all.
5. **TRAP 2 CONFIRMED AGAIN, AND THE PLAN IS STILL WRONG.** Task 5's Files list says
   `resources/js/__tests__/ClinicMap.spec.js`; `vitest.config.js` includes exactly
   `tests/js/**/*.test.js`, so a spec at that path is collected by nothing and `npm test` reports
   the same 202 it always did. The file is `tests/js/ClinicMap.test.js`, as Task 4 amendment 2
   already recorded for its own.
6. **TRAP 1 DID NOT FIRE, and that was construction rather than luck.** Task 2 amendment 11's and
   Task 4 amendment 4's lesson was applied before a line was written: `Map.vue`'s docblock explains
   that the file names no day of the week, uses the raw-markup directive nowhere and carries no
   dark-mode utility — **without spelling any of the three tokens**, because
   `CalendarIsTheOnlyConverterTest`'s weekday needle and `ClinicMap.test.js`'s own source
   assertions all scan prose. Likewise `ClinicMapController`'s docblock names neither the
   institution-row accessor (a `CalendarWritersFlushTest` WRITE_NEEDLE) nor any of the four contact
   field names in quotes or arrow form (`ContactFieldsAreProjectedOnceTest`), and no
   property-assignment shape `ClinicWritersAreSingularTest` looks for. All four guards green with
   no allow-list entry added anywhere.
7. **The weekday guard was watched failing on THIS file.** A literal seven-name array was planted
   in `Map.vue` and used for the column header; the repo-wide scan named the file and all seven
   strings, and `ClinicMap.test.js`'s behavioural half named the wrong labels in the same run — the
   two fail for different reasons, which is why both ship. Reverted, re-run, green.
8. **Query cost measured on a POPULATED department, then checked against a plant.** Three
   clinic-owning units, twenty-four clinics (six level-refined) and thirty people on the ward's
   rota: **10 queries**. Replacing the one page-wide level lookup with a per-clinic one took it to
   **16**. The bound written is **12** — two queries of slack against a regression worth six — and
   it was watched failing at 16 before being trusted. The cost is flat in both the roster and the
   clinic count by construction: the units are one query, their clinics one, the refinement rules
   one eager load, and the level vocabulary one for the whole page.
9. **Contact-freedom proved TWICE, and each half was watched failing.** At source level: `->email`
   planted inside the projection map, and `ContactFieldsAreProjectedOnceTest` named
   `app/Http/Controllers/ClinicMapController.php` by path — without that half, "green" and "the
   guard cannot see this file" are indistinguishable. Behaviourally: the obvious implementation was
   planted whole (`PersonPresenter::many($people, $request->user())` hung off each clinic, which is
   exactly what `RotaGrid` once did), and
   `test_the_map_carries_no_contact_field_for_any_viewer` named the precise prop paths —
   `resolved.1.0.phone`, `resolved.1.0.email` — **at position 4**, a resident, on a department set
   to `contact_visibility = members`. Both plants reverted; `git status` back to exactly the
   untracked set this task created.
10. **TWO OF THE SIXTEEN PASSED ON THE FIRST RED RUN, VACUOUSLY, AND THEIR TWINS WERE RED AT THAT
    MOMENT** — which is the whole point of shipping them in pairs, and exactly what Task 4
    amendment 11 warned about. `test_every_route_behind_cap_clinics_view_is_a_get` iterated an
    empty router set, and `test_the_retired_nurse_position_gains_no_default` asserted the absence
    of a capability that did not exist. `test_the_map_route_is_actually_registered_behind_cap_
    clinics_view` and `test_clinics_view_is_in_the_catalog` were red beside them and are what make
    the other two mean anything. A third, `test_posting_to_the_map_is_a_plain_method_not_allowed`,
    asserts the read-only property at RUNTIME as well as over the router.
11. **Six cases beyond the plan's ten**, each closing something the ten leave open: the vacuity
    twin above; the runtime 405; `test_managing_clinics_still_needs_structure_manage` (Decision C's
    "one new key, not two" — a resident reaches the map and is refused the structure screen, in one
    test); `test_a_reader_sees_the_map_with_its_clinics` (the whole cell shape asserted, so a
    future key cannot be added to the payload unnoticed); `test_a_refined_clinic_shows_its_rule_
    and_not_its_roster` (item 3's decision as behaviour, both branches); and the `auth`-over-the-
    router half of the D7 case, so the guest redirect cannot be some other middleware's doing.
12. **`docs/spec/08-foundation.md` needed TWO edits, not one.** Finding 11 names the "Capability
    catalog (complete)" list; the **Role defaults** paragraph immediately below it is the one that
    actually records what a key defaults TO, and a key added only to the first would have left the
    document self-contradictory while
    `ClinicMapTest::test_the_catalog_document_lists_the_key` stayed green. Both are updated, and
    the second also records the D7 override of Munawib §5's link-public footnote and the
    `applied_role_defaults` once-only behaviour the task text calls out.
13. **Nothing else in the task text was wrong against the tree.** The route, the capability key,
    the seeded positions, `Calendar::weekdayColumns()`, the `Unit::BAR_CLASSES` colouring, the nav
    placement and finding 11 all check out as described. Item 3 is a deviation taken deliberately,
    not a correction; item 2 is an interaction the task text could not reasonably have foreseen.

### 2026-08-11 — Task 6

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1524 passed, 0
   failed**, matching Task 5's recorded number exactly. `npm test` → 212, `npm run build` → green.
   Task 6 took PHPUnit to **1527** (3 `ClinicHooksTest`). Vitest unchanged. No migration and no e2e
   file was touched, so the e2e world was not rebuilt for this task.
2. **The stripper was EXTRACTED, as the task text permits, to `tests/Support/SourceScanner.php`
   (`Tests\Support\SourceScanner::withoutComments()`).** `RotaAccessTest`'s private
   `sourceWithoutComments()` now delegates to it in one line and keeps its own name, so nothing that
   called it had to change. `tests/Support/` is collected by no test suite (`phpunit.xml` names only
   `tests/Unit` and `tests/Feature`), and `Tests\` already maps to `tests/` in `autoload-dev`, so
   this cost no configuration. Both callers keep their OWN two-way calibration against their OWN
   files — a proof that the stripper handles `AvailabilitySummary`'s docblock is not a proof that it
   handles `Map.vue`'s.
3. **THE CL-04 SCAN WENT RED ON A REAL OFFENDER ON ITS FIRST RUN, IN CODE RATHER THAN IN PROSE, AND
   THE STRIPPER COULD NOT HELP.** `ClinicWriter::assertOwns()` refuses a clinic on a retired unit
   with *"…appears on no map and is coverage nobody can see."* — an exception MESSAGE, which is a
   string literal, and the stripper removes comments only. The message now reads *"is cover nobody
   can see"*: identical meaning, one needle preserved. The alternative was to drop `coverage` from
   the needle set (it is the word design §6.3's unbuilt `coverage_templates` is named for, and CL-04's
   own *"morning cover"* requirement) or to allow-list the module's own writer, which is precisely
   the file where a second implementation would appear. The reason is stated at the site so nobody
   "improves" the wording back. **Deliberate rule, worth generalising:** strings are NOT stripped,
   by either path — an exception message or a rendered label carrying a forbidden word is code a
   user can see, not documentation about code, and a scan that ignored strings would miss a real
   surface. `SourceScanner`'s docblock says so.
4. **`Clinics/Map.vue` gained a paragraph naming CL-04's vocabulary out loud, and that is a
   deliberate addition beyond the task's "`ClinicRoster.php` (modify — docblock only, if needed)".**
   Two reasons. First, a wall chart of the department's week is the single most tempting place in
   the product to add a "who is free" column, and the person tempted is reading that file, not the
   plan. Second, and the reason it is load-bearing rather than decorative: without it **no `.vue`
   comment in the clinic module contained any needle at all**, so the `.vue` half of the stripper
   calibration could only be pinned on an arbitrary phrase (which is all `RotaAccessTest` can do —
   it calibrates on `MR-06`, not on a needle). It is now calibrated on the real thing: `availab` and
   `coverage` are asserted present in the raw file and absent from the stripped one, so a `.vue`
   stripper that stopped working fails the build on that sentence rather than silently disabling the
   scan.
5. **Both scans cover the two MODELS as well**, which the task text's *"`app/Support/Clinics/` in
   full, plus the clinic controllers, form requests and Vue screens"* does not name: `Clinic` is
   where a `conditions()` relation or a `severity` cast would land, and a guard blind to the model
   is a guard around three quarters of a module. Eleven files, the support half a glob and the other
   eight each `assertFileExists`'d, with a count floor on both halves.
6. **`test_nothing_in_the_clinic_module_evaluates_a_condition` PASSED ON THE FIRST RED RUN** — trap
   4 exactly, for the third time in this slice. What makes it non-vacuous is not another assertion
   in the same test but the two structural twins inside `clinicSurfaceFiles()` (a floor on the glob,
   a floor on the total, and `assertFileExists` per named path) plus the plant below; a scan that
   iterated an empty set would fail on the floors before it could be green for the wrong reason.
7. **Both scans were watched failing against planted offenders in BOTH languages, and the stripper
   was then watched staying GREEN on the same words in comments.** The positive half:
   `app/Support/Clinics/PlantedHookOffender.php` (a condition evaluator carrying every shape at
   once) plus one planted line in `Map.vue`. It named `PlantedHookOffender.php` on six CL-03 needles
   and five CL-04 needles, and `Map.vue` on `severity`, `availab`, `coverage` and `unavailable`. The
   negative half — the one that makes the positive half mean anything — was
   `app/Support/Clinics/PlantedCommentOnly.php` carrying **all sixteen needles in prose** across a
   docblock, a `//` comment, a `#` comment and a trailing `/* */`, plus a `//` line and an HTML
   comment in `Map.vue` carrying the same words: **all three tests stayed green**. Both plants
   reverted, re-run green, `git status` back to exactly the untracked set this task created. Note
   for the next planter: `Map.vue` carried this task's own uncommitted work, so it was copied aside
   and restored from the copy — `git checkout` would have reverted the task with the plant (Task 1
   amendment 6 recorded the same hazard for the fixture).
8. **Design §14 gains item 22**, in item 18's shape, stating what each hook IS (CL-03 reads
   `clinics.weekday` + `clinics.unit_id` against a date and a person's current unit; CL-04 reads
   `ClinicRoster::forDate()`), that neither needs a schema change when it arrives, and that the
   absence is real rather than allow-listed — Task 3 built `ClinicRoster` so it never reads the
   leave tables, and neither scan has an allow-list at all.
9. **Nothing in the task text was wrong against the tree.** The needle sets, the comment-stripping
   requirement, the `RotaAccessTest` precedent, the both-directions calibration and the
   no-allow-list instruction all check out as described. Item 3 is an offender the task text could
   not have foreseen (it is in a string, not a comment); items 4 and 5 are deliberate widenings.

### 2026-08-11 — Task 7

1. **Baseline: `php artisan test` → 1527, `npm test` → 212, `npm run build` green**, matching Task
   6's recorded numbers exactly. The e2e suite was then re-run from a genuinely CLEAN world
   (`rm database/e2e.sqlite && npm run test:e2e`) → **24 passed across 8 spec files** (22 + 2),
   counted rather than assumed, exactly the figure the task text predicts. Task 7 adds no PHPUnit
   and no Vitest case, so both of those counts are unchanged at 1527 and 212.
2. **`ClinicWritersAreSingularTest` needed NO allow-list entry, and that was checked rather than
   assumed.** `E2eSeeder` writes its clinic through `ClinicWriter::create()`, and none of the
   guard's forty needles matches the seeder's new code (`Clinic::query()->where(...)->exists()` is a
   read; `ClinicWriter::create(` is the sanctioned writer). The task text's "only if needed" was
   correctly hedged, and the answer is: not needed.
3. **WARD's `clinic_owner` was verified rather than assumed, as the task text insists — and the
   verification now lives IN the seeder rather than in a run somebody did once.** `ReferenceSeeder`
   ships WARD as an active clinic owner and `prepare-world.js` runs `migrate:fresh --force --seed`,
   so the e2e world takes the cold-start path. `seedReadableClinic()` nevertheless throws if WARD is
   missing, inactive or not a clinic owner: `ClinicWriter::create()` would refuse in that case, and
   a bare refusal surfaces as an empty map that reads like a screen defect rather than a seeding
   one.
4. **THE BROWSER FOUND SOMETHING NO OTHER LAYER COULD, AND IT IS ABOUT THE ASSERTION RATHER THAN
   THE PRODUCT: THERE IS NO `data-page` ATTRIBUTE TO READ.** The task's "assert no `@` appears in
   the page's Inertia props" has an obvious implementation — read `#app`'s `data-page` — and it
   returns **null**, because Inertia's Vue 3 adapter removes that attribute as soon as it has parsed
   it. The second-obvious implementation, grepping the served HTML for `id="app" data-page="…"`,
   finds nothing either: this version of `inertia-laravel` emits
   `<script data-page="app" type="application/json">` with the JSON as the script's BODY, and
   `data-page` there holds the element id. Neither mistake could pass silently — both throw on a
   null match, which is why the helper is allowed to be this specific about the markup — but the
   repair is also the better assertion: the payload is now taken from the **response body** of the
   navigation, which is literally what the server sent before any client code ran. That is the
   standard the rest of this suite is held to, and it is what the task actually asked for.
5. **NO PRODUCT GAP WAS FOUND, and that is a measured result rather than an absence of effort.**
   P1d-1's e2e found there was no way to clear a rota cell at all; P1d-2a's found six screens
   dropping `aria-current`. Both classes were specifically looked for here. Both screens are
   reachable from the nav for the actor holding the capability and absent for the one who does not;
   every control the administrator needs exists and is labelled; the created clinic survives a
   reload with its day, session, location and default mode intact; and the map renders the seeded
   clinic in exactly one cell, on the right unit row and the right weekday column.
6. **Each of the three load-bearing claims was watched failing against a planted defect.**
   - *The reader assertion is not vacuous.* `seedReadableClinic()` removed from `run()`, world
     rebuilt: the map renders its empty state, `map-table` does not exist, and the test fails on the
     first assertion about the table. A journey asserting a clinic that was never seeded would
     otherwise look identical to one asserting a clinic that is.
   - *The contact check really catches a contact leak.*
     `'lead_contact' => Person::query()->value('email')` planted in
     `ClinicMapController::present()` — a REAL address out of the database, which is exactly P1d-2
     Decision C's shape. The test named it, and the failure output doubles as proof that the payload
     is otherwise `@`-free on a clean tree: the whole dumped page object contains no address
     anywhere, shared props included.
   - *The read-only claim is about the rendered page.* A `<button>Book me in</button>` planted in
     `Map.vue` and rebuilt: `main.locator('button')` went 0 → 1 and named it.
   All three reverted, rebuilt, re-run green; `git status` back to exactly this task's own working
   set. The edited files were copied aside and restored from the copies rather than
   `git checkout`-ed — one carried this task's uncommitted work and one carried Task 6's, and
   `git checkout` would have reverted the task along with the plant (Task 1 amendment 6 recorded the
   same hazard).
7. **`watchForWrites()` and `signInAndWatch()` moved from `rota-read.spec.js` into `fixtures.js`.**
   The clinic map needs the identical "did anything non-GET leave this page" property, and two
   copies of that recorder would be two definitions of one fact — the same call Task 6 made about
   the comment stripper. `signInAndWatch(page, who)` now takes the actor and defaults to `ADMIN`;
   `rota-read.spec.js` keeps a one-line `signInAsResident` alias so its three call sites read
   unchanged. Both specs re-run green in the clean whole-suite run.
8. **The reader asserts the SEEDED clinic and the administrator creates a DIFFERENT one** — a
   different day (Wednesday vs Tuesday) and a different session (Afternoon vs Morning). The task
   text's "assert the clinic appears" does not say which, and asserting the one the first test
   created would make the second test pass only when the first had run — `npx playwright test
   --grep` runs one. Both were in fact exercised singly during the plants above, which is how the
   property was confirmed rather than argued.
9. **The cell assertion sweeps all seven columns rather than checking one.** A positive check on the
   Tuesday cell alone passes for a clinic rendered into every cell, and for one rendered a column
   out. The spec collects the `data-cell-key` of every cell in the WARD row whose text contains the
   clinic and asserts the whole list equals exactly `[unitId-2]` — the same assert-over-the-whole-set
   discipline the PHP guards keep, in a browser.
10. **Both screens ship two trees and both were scoped, as the task text warns.** The listing on
    `Admin/Clinics.vue` is mobile `<article>` cards plus a desktop `<table>`; `Clinics/Map.vue` is
    `map-cards` plus `map-table`. Every lookup here is scoped to the desktop tree. Worth recording
    that the CREATE form is NOT duplicated — it sits outside the per-unit groups — so `#new-name`
    and its siblings are addressed by id with no scoping needed, which is why this one spec carries
    both idioms.
11. **The fixture is disjoint from the two rota specs' by construction, not by luck.** The clinic is
    `rotators` mode, so it needs no attendee rows and names no person at all; it adds nothing to
    `master_rota_assignments` or `vacations`, so `rota-import.spec.js`'s and `rota-read.spec.js`'s
    coverage arithmetic (21 assigned, 35 not assigned, one gap, two unassigned) is untouched. The
    whole-suite run confirms it: those three specs are green in the same clean run as this one.
12. **Nothing in the task text was wrong against the tree**, and its predicted count (24) was exact.
    Item 4 is an interaction with the installed Inertia version that the task text could not have
    foreseen; item 8 is a disambiguation, not a correction.

### 2026-08-11 — P1e-1 adversarial review, findings 1–4

Baseline re-measured clean before touching anything: `php artisan test` → **1527 passed, 0 failed**,
matching the review's stated baseline exactly; `npm test` → **212**, `npm run test:e2e` → **24**,
`npm run build` → green. Three findings fixed, one recorded. Rulings 48, 49 and 50 added; design §14
gains item 23.

1. **Finding 1 was reproduced exactly as reported, and the RED run named the two keys the report
   predicted** — `The selected person_ids.0 is invalid.` on the switch-to-`rotators` escape, and
   `The selected level_ids.0 is invalid.` on the named-mode save. Both fixed halves are needed and
   neither is sufficient: the server narrowing stops a stale or hand-edited payload, the client
   seeding stops the screen generating one. Recorded as ruling 48.
2. **The client half is asserted on the SUBMITTED PAYLOAD, not the DOM, and that forced a change to
   the Vitest mock.** Inertia's `useForm` sends `form.data()`, which never appears in the
   `put(url, options)` arguments — so the existing spy could prove which endpoint a control reached
   and nothing about what it sent, which is where this entire defect lived. The mock's `put` is now
   a `function` (not an arrow) that records `this.mode`/`level_ids`/`person_ids`. Stated because it
   generalises: **a form holding `[5, 99]` and one holding `[5]` render byte-identical markup when
   99 has no checkbox**, so no DOM assertion of any kind could have caught this.
3. **`patch` gained a refusal mode for finding 3** (`store.refuseWith` → populates `form.errors` and
   fires `onError`). Without it there is no way to exercise a refusal path from the client side, and
   "the control calls the endpoint" — which is what the existing test asserted — cannot distinguish
   a rendered refusal from a silent one. The finding-3 test mounts **two** clinics deliberately:
   `activeForm` is one `useForm` shared by every row's button, so the naive `v-if` renders the same
   refusal under clinics nobody touched. That is a second, quieter defect the obvious fix would have
   shipped, and the second assertion is what refuses it.
4. **The flash-key source guard was designed, measured and REJECTED** — the full reasoning is
   ruling 49, and the decisive number is that `errors.person_ids` is rendered by Admin → People's
   bulk-resend panel, so "every flashed key is rendered by SOME screen" is **green on finding 1**.
   Measured, not argued: 4 `withErrors` keys + 10 `withMessages` keys + ~70 FormRequest rule keys
   against ~55 rendered keys. Recorded as a measurement so the fourth instance of this shape does
   not re-derive it from scratch.
5. **Finding 2's hole was measured before it was closed, and the guard was green against a plant
   rewriting six columns on both tables.** `app/Support/Clinics/PlantedUpdateWriter.php`, deleted
   immediately after; the plant carried both a `$clinic->update([...])` and a `$c->update([...])`
   so the two needle families could be told apart. After the fix it named the file on four needles.
   **Observed on the plant rather than reasoned about:** the column-qualified needle matches only
   the array's FIRST key, so `update(['weekday' => 3, 'session' => 'PM'])` fires
   `->update(['weekday'` and not `->update(['session'` — which is exactly why the variable-qualified
   half is not redundant, and it is stated in the guard's own docblock.
6. **`->update(['active'` was written, measured and withdrawn** (ruling 42's discipline). Six files,
   five tables, and the two most dangerous allow-list entries it would buy are `UnitController` and
   `UnitMerge` — `UnitMerge` being the file finding 4 shows *should* be writing `clinics.unit_id` and
   is not. An allow-list entry there would blind the guard at the exact point the next real offender
   arrives.
7. **The sibling guards were PROBED, not read.** One `$model->update([...])` per guarded table in a
   single throwaway file, then five filtered runs: `RotaWritersAreSingularTest` **green** (shares the
   hole — `master_rota_assignments`, `vacations`), `PersonLevelsHaveOneWriterTest` **green** (shares
   it — and `LevelAssignment` writes `effective_to` that way twice, so it is the live idiom for that
   table), `InvitationWritersAreSingularTest` / `AccountLinkHasOneWriterTest` /
   `CapabilityWritersAreSingularTest` **red** (do not share it). Reading the needle lists would have
   got the last three right and is not the same as knowing. Left open deliberately: they guard P1c's
   and P1d's tables, the same scope line design §14 item 23 draws for `UnitMerge`.
8. **Finding 4 verified from the SCHEMA rather than from the report.** Every migration declaring a
   `unit_id`/`preferred_unit_id` column was enumerated and compared against `UnitMerge::plan()`:
   four covered, three stranded (`reminder_preferences`, `master_rota_assignments`, `clinics`). The
   report is correct, and its refutation is correct too — `UnitMerge`'s docblock enumerates what a
   merge does and never claims exhaustiveness over `unit_id`. Recorded as design §14 item 23 with
   one addition the review did not state: **a clinic-only fix would be actively wrong**, because
   re-pointing `clinics.unit_id` while the rota rows stayed behind resolves every migrated clinic
   to nobody.
9. **What the review got wrong: nothing material.** Every claim checked out against the tree,
   including the two line references and the "both files are new on this branch" scoping. One
   refinement: finding 2's list of shapes the guard catches says "property-assign-then-`save()` and
   relation writes", which is right, but the *reason* the hole exists is recorded in this plan's own
   Task 2 amendment 2 — the array-key twins (`'weekday' =>`, …) were deliberately withdrawn to avoid
   allow-listing `ClinicController`, and those are precisely what closes this shape in the three
   sibling guards that do not share it. The `update(`-qualified form is the narrowing that recovers
   the coverage without the cost, which Task 2's measurement had no reason to anticipate.

### 2026-08-11 — Task 8

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1532 passed, 0
   failed**, matching the figure P1e-1 finished on. `npm test` → 216, `npm run build` → green.
   Task 8 took PHPUnit to **1541** (9 `DepartmentProfileTest`); Vitest is unchanged at 216 — the
   two `AppLayout.test.js` edits below extend existing cases rather than adding one. No migration
   and no e2e file was touched, so the e2e world was not rebuilt.
2. **The `CalendarWritersFlushTest` trap fired exactly as Decision G predicts, and the allow-list
   entry was WATCHED EARNING ITS PLACE rather than added on faith.** With the entry removed the
   guard named `DepartmentProfileController.php` on its own; restored, green. Worth recording
   because the entry is otherwise indistinguishable from a decorative one: `test_the_allow_list_is_
   not_stale` only checks that an entry still matches a needle, so an exemption nobody ever saw
   fire would survive forever.
3. **The audit detail is `fields=name` with NO subject id, deliberately** — the sibling screen
   (`CalendarSettingsController`, `keys=…`) sets the precedent, and D11 means there is exactly one
   institution row, so an id identifies nothing an action name does not. `test_a_submission_that_
   changes_nothing_names_no_field` pins `fields=none` by `assertSame`, which is only possible
   because the string carries nothing else.
4. **Two plants, both watched naming their own defect.** `'code' => ['sometimes', …]` added to
   `DepartmentProfileRequest::rules()` — `test_the_code_is_not_writable` went red with
   `'QCH'` → `'HIJACKED'`, which is the whole point of validating `name` alone rather than
   trusting a `disabled` attribute. And `ReferenceSeeder`'s `if (! $institution->exists)` guard
   removed — `test_a_rename_survives_db_seed_force` went red with the administrator's name
   reverted to the env default, which is the defect
   `2026_08_15_120002_correct_ward_clinic_owner` had to exist to repair for the unit profile
   columns. Both reverted, re-run green, `git status` back to exactly this task's own working set.
   The two edited files were copied aside and restored from the copies — `git checkout` on
   `CalendarWritersFlushTest.php` would have reverted this task's own allow-list entry with the
   plant (Task 1 amendment 6 recorded the same hazard).
5. **`AppLayout.test.js`'s `adminHrefs` sweep is a HAND-WRITTEN list and a new nav link is
   unswept until it is added to it.** `/admin/structure/department` is now in it, so the
   `aria-current` property adversarial-review finding 3 restored is proved for this entry too.
   The Vitest file is not in Task 8's "Files touched" list; the alternative was a nav link nothing
   asserts, which is the shape that produced twelve silent links once already.
6. **The `code` verdict, stated because the task text invites the opposite finding.** `code` stays
   env-only, and it is not a UI omission: it is `ReferenceSeeder`'s `firstOrNew` key, so re-coding
   a live institution makes the next `db:seed --force` — a mandatory step of every deploy —
   CREATE a second `institutions` row rather than update the first, at which point
   `Institution::current()` returns null (two active rows means no right answer, D11) and every
   screen reading the department's configuration goes blank. It is also provenance already
   stamped on `users`, `people`, `levels`, `periods` and `clinics` rows. That is a provisioning
   operation with a migration behind it, not a settings change.
7. **Nothing in the task text was wrong against the tree.** The route names, the `ALLOW_LIST`
   precedent it quotes verbatim (`PersonController`) turned out to be `ContactVisibility.php` in
   the current tree carrying that exact sentence — the reason is identical and the file moved
   since Decision G was written, which is a stale quotation rather than a wrong instruction.
   `ReferenceSeeder`'s create-only `name` write, `institutions.name`'s `string` (255) column and
   the `INSTANCE_SLUG`/`INSTITUTION_CODE` distinction all check out as described.

### 2026-08-11 — Task 9

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1541 passed, 0
   failed**, matching Task 8's recorded number exactly. `npm test` → 216, `npm run build` → green.
   Task 9 took PHPUnit to **1557** (16 `DepartmentSetupTest`). Vitest unchanged; no migration and
   no e2e file was touched, so the e2e world was not rebuilt.
2. **`clinics` is a REQUIRED step, not a third `OPTIONAL` kind — the plan's own table and its own
   Decision D disagree, and the prose wins** (the P1d-1 lesson: task text contradicting the
   decisions block is this plan family's single most repeated defect class). Decision D enumerates
   exactly two kinds and lists `clinics` under REQUIRED with the escape in its predicate; the
   implementation table calls the kind `OPTIONAL`. Two kinds shipped, `KIND_REQUIRED` /
   `KIND_REVIEW`, and the escape lives where Decision D puts it — in `done`.
3. **`clinics` carries NO `blocked_by`, and the table's entry for it is unreachable by
   construction.** It names "no clinic-owning unit" as the block — but that is precisely the state
   that SATISFIES the step (a department without an outpatient week is a valid department), so a
   populated `blocked_by` there would render as "done, but blocked". Stated at the site so nobody
   restores it from the table.
4. **The `periods` predicate is "a period whose `ends_on` is today or later", not "a row exists for
   the current academic year" — a deliberate deviation, and it is stronger in both directions.**
   Deriving the current year's LABEL means calling `PeriodGenerator::deriveAcademicYear($firstStart,
   $lastEnd)`, whose second argument is the last generated end date: you must regenerate the whole
   run in memory just to name the year, which is a second definition of a label the generator owns.
   The shipped predicate avoids two real lies instead. A bare `exists()` calls a department whose
   only year ended in a previous academic year "done" — the direction nobody re-checks. A
   contains-today test calls a department that generated its year in August for a September start
   "not done" — the direction that makes an administrator redo work they just did.
   `test_a_year_that_has_already_ended_does_not_satisfy_the_period_step` pins the first.
   `whereDate()`, not a string comparison: both bounds are `date`-cast and MySQL 8.4 round-trips
   such a column as `'Y-m-d 00:00:00'` — the same caveat Task 3 amendment 3 recorded.
5. **`count()`, not `exists()`, on every REQUIRED step — the same one query, and it buys the
   summary line.** The plan says `exists()`; a REQUIRED step whose summary cannot say *how many* is
   a tick with nothing behind it, and the REVIEW steps would then be the only ones an administrator
   could actually verify from the checklist. The measured cost is identical.
6. **`Institution::PERIOD_TYPES` is new, and the two files that name a period system now read it.**
   The checklist reports which system is in force and the calendar screen offers the choice; two
   hand-written label pairs are the `SignoffPickers` defect in miniature. `CalendarSettingsController`
   now renders `period_type_options` from the constant with byte-identical output.
7. **THE `CalendarWritersFlushTest` TRAP FIRED A SECOND TIME, in a file Decision G never
   anticipated.** `DepartmentSetup` reads the institution row for the profile summary and counts
   `Holiday::query()` for the holidays summary — two WRITE_NEEDLES in a class that writes nothing
   at all. Allow-listed with the reason at the site, and **watched failing without the entry**
   (it named the file), then restored. Generalisable: the guard's needles are "touches calendar
   configuration", and a READ-ONLY reporting surface trips them by construction. The wizard screen
   in Task 10 will not — it reads `DepartmentSetup`, not the row.
8. **NINE steps, TEN queries, both measured rather than argued, and the bound was watched failing
   at 11 AND at 12.** Ten is one per REQUIRED step (six), two for `clinics` (an active clinic and a
   clinic-owning unit are different questions and the summary must tell them apart), plus the three
   REVIEW reads. The bound is EXACT, not generous: the regression it exists to catch is a step
   resolving per-step what belongs in one read, and each of those costs exactly one query — a bound
   with headroom would not see it. Planted `Institution::current()` a second time → 11, named.
9. **Trap 3 caught this task: `test_no_step_names_a_slot_a_coverage_template_or_a_condition` PASSED
   ON ITS FIRST RUN.** What makes it non-vacuous is not another assertion inside it but the two
   structural twins — `test_every_step_carries_the_same_keys_and_a_registered_route` (which pins
   the key set AND resolves every `route` over the router, so a checklist pointing at a dead
   screen fails at source) and the plant. A step titled *"Coverage templates"* was added and it
   named `templates names coverage`; the already-configured test named `templates` in the same run,
   which is a second, quieter proof — an unsatisfiable step also breaks "complete with no backfill".
10. **`test_asking_writes_nothing_anywhere` was watched failing against a planted stored counter**
    (`app_settings` updateOrInsert of a `setup_step` key inside `steps()`): the diff named
    `'main.app_settings' => 0` becoming `1`. Worth recording that SQLite returns table names
    SCHEMA-QUALIFIED from `Schema::getTableListing()` (`main.audit_log`, …), which is exactly why
    the map is compared WHOLE rather than by hand-written keys — a named-key assertion written
    against `'app_settings'` would have found nothing on either side and passed forever.
11. **Two route facts the task's table does not state, both checked against the router rather than
    assumed.** There is **no GET invitations screen** — invitations are issued from Admin → People
    (`admin.people`), so that is the invitations step's route. And the `roster` step points at
    `admin.roster-import` rather than at People, because its `blocked_by` ("no active level") is
    that screen's OWN prerequisite: `RosterImportController::index()` offers
    `Level::query()->active()` for the level mapping, so an import against an empty ladder maps
    nothing. `blocked_by` reading the target's own predicate is Decision D's rule, and the route
    has to be the screen that predicate belongs to.
12. **Three files beyond the task's two-file list were touched**, all stated above: `Institution`
    (the new constant), `CalendarSettingsController` (reads it), and `CalendarWritersFlushTest`
    (item 7). Nothing else in the task text was wrong against the tree — the nine step keys, the
    REQUIRED/REVIEW split, the `later` shape, `ReferenceSeeder` seeding levels and units but not
    periods, and WARD as the sole seeded clinic owner all check out as described.

### 2026-08-11 — Task 10

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1557 passed, 0
   failed**, matching Task 9's recorded number exactly. `npm test` → 216, `npm run build` → green.
   Task 10 took PHPUnit to **1567** (10 `DepartmentSetupScreenTest`) and Vitest to **224** (8
   `DepartmentSetup.test.js`). No migration and no e2e file was touched, so the e2e world was not
   rebuilt at this point.
2. **THE VITEST PATH IN THE TASK TEXT IS WRONG TWICE OVER — trap 2, third instance in this plan
   family.** `resources/js/__tests__/DepartmentSetup.spec.js` matches neither half of
   `vitest.config.js`'s `include: ['tests/js/**/*.test.js']`: wrong directory AND wrong extension
   pattern. A file written there runs zero tests and reports success. Shipped as
   `tests/js/DepartmentSetup.test.js`, which is where every one of the other 22 Vitest files lives.
3. **The GET-only sweep cannot be scoped by capability here, and the task text's "inside the
   existing structure group or its own — either is fine as long as the group is GET-only" is only
   half true.** `cap:structure.manage` legitimately guards the write endpoints of every structure
   screen (units, levels, calendar, periods, holidays, clinics, department), so a capability-scoped
   sweep can never be GET-only whichever group the route joins. Scoped by URI PREFIX instead —
   `admin/setup` and `admin/setup/*` — over the router, so it holds for routes nobody has written
   yet. The route sits in its own group for the same reason.
4. **TRAP 3 FIRED EXACTLY AS WARNED: `test_every_route_under_admin_setup_is_a_get` PASSED ON ITS
   FIRST RED RUN.** Nine of ten tests were red and that one was green, against zero matching routes
   — the P1e-1 Task 4 defect reproduced precisely. The vacuity twin
   (`test_the_checklist_route_is_registered_behind_the_structure_capability`, which asserts exactly
   one route, its name and its two middlewares) was red, and it is the only reason the vacuous pass
   was visible at all.
5. **Both router guards were then watched failing against plants.** A `POST /admin/setup/progress`
   — the "somebody started storing wizard state" shape — made the sweep name
   `admin/setup/progress allows POST` and the twin name a count of 2. Separately, `'admin/setup'`
   added to `RequireSetup::ALLOWED` made
   `test_an_administrator_who_has_not_done_their_own_two_factor_setup_is_redirected_away` go red
   with a 200 where a redirect belonged — which is exactly the "fix" that test exists to refuse.
   Both plants reverted from copies taken first, re-run green, `git status` back to this task's own
   working set.
6. **The step's URL is resolved SERVER-SIDE, which the task text does not specify.**
   `DepartmentSetup` stores a route NAME; a screen needs a path. The controller adds
   `route($name, absolute: false)` per step, and `test_every_step_links_to_a_registered_route`
   compares every emitted `url` against what the router itself builds — so the router stays the one
   authority on where a step goes, rather than a second table of paths on the client. `later` gains
   no `url` at all, asserted alongside its absent `route` and `done`.
7. **`AppLayout.test.js`'s `adminHrefs` sweep is hand-written (Task 8 recorded it), so
   `/admin/setup` was added to it** — first, matching the nav entry's position — and the
   `aria-current` property adversarial-review finding 3 restored is now proved for this entry too.
8. **`isActive('/admin/setup')` does not collide with `/admin/settings`, checked rather than
   assumed.** `isActive` is `===` or `startsWith(href + '/')`, so neither path matches the other and
   the sweep's "exactly one current entry" assertion holds on both. Worth stating because the two
   hrefs share a seven-character prefix and a bare `startsWith` would have announced two entries at
   once — the failure mode that sweep's second assertion exists to catch.
9. **The `CalendarWritersFlushTest` trap did NOT fire, exactly as Task 9 amendment 7 predicted.**
   The controller reads the projection, never the institution row, so it matches none of that
   guard's needles and needed no allow-list entry. Recorded as a confirmed prediction rather than
   silence, because the same trap had fired twice in the two preceding tasks.
10. **Nothing else in the task text was wrong against the tree.** `RequireSetup`'s `ALLOWED` list,
    `SetupController` rendering `Setup`, the route names `setup.show`/`setup.complete`, the nine
    step keys and the two `later` entries all check out as described. `FirstLoginSetupTest` stayed
    green untouched, which is the collision check the task text asks for.

### 2026-08-11 — Task 11

1. **Baseline: `php artisan test` → 1567, matching Task 10's number.** Task 11 took PHPUnit to
   **1580** (10 `DemoLedgerTest` + 3 `DemoRowsAreLedgeredTest`). Vitest unchanged at 224 — no JS was
   touched. A migration landed, so the e2e world WAS rebuilt (`rm database/e2e.sqlite`) and
   Playwright re-run: **24 passed**.
2. **The reserved migration slot was free**, checked before writing the file as the plan instructs:
   `2026_08_16_120001_create_clinics_and_attendees_tables` was the last, so `2026_08_16_120002` is
   the next, exactly as reserved. P1d-1's renumbering did not repeat.
3. **TRAP 1 FIRED, IN THE MIGRATION'S OWN DOCBLOCK, AND WAS CAUGHT BY A RED RUN RATHER THAN BY
   INSPECTION.** The paragraph rejecting a second `institutions` row ended *"…would teach the next
   reader that `where('institution_id', …)` is acceptable here"* — and
   `InstitutionProvenanceTest::test_no_query_filters_on_institution_id` scans `database/` as source,
   prose included, with **no allow-list**. So the build failed on the migration's own illustration of
   the rule it was upholding, naming
   `database/migrations/2026_08_16_120002_create_demo_rows_table.php (query filter)`. Spelled around
   rather than deleted, following `ClinicWriter`'s precedent for the identical event against
   `CalendarWritersFlushTest`, and the reason is stated at the site so the next author does not
   restore the illustration.
4. **All five writer shapes were proved with plants, one throwaway file per shape, all deleted
   immediately after.** `DemoRow::create(` · `DB::table('demo_rows')` · `$r->batch_id = ` (which also
   fired `->table_name = ` and `->row_id = `) · `->demoRows()->create(` · and both halves of
   `$model->update([...])`.
5. **The column-qualified needle's first-key-only property was reproduced here too, on the plant
   rather than reasoned about.** `$demoRow->update(['batch_id' => $b, 'row_id' => 9])` fired
   `->update(['batch_id'` and did NOT fire `->update(['row_id'`; a second method's
   `$other->update(['table_name' => …])` fired the column needle and did NOT fire
   `$demoRow->update(`. The two halves fail for different reasons, which is what earns keeping both
   — the same measurement P1e-1's adversarial review recorded for the clinic guard.
6. **A SIXTH SHAPE WAS FOUND BY PROVING THE VACUITY TWIN, NOT BY WRITING THE NEEDLE LIST.** The
   offender sweep passes against a tree containing no ledger at all (trap 3 again), so a twin
   asserts the writer itself matches at least one needle. Mutating `DemoRow::create([` to
   `DemoRow::query()->create([` left the sweep GREEN and turned the twin RED: the writer still wrote
   the table, through a shape no needle named. `DemoRow::query()` is now needled whole — which also
   covers `::query()->…->delete()`, the shape `forgetBatch()` itself uses — measured at ZERO matches
   outside the writer, and proved on a fresh plant carrying both spellings. **The sibling guards
   (`ClinicWritersAreSingularTest`, `RotaWritersAreSingularTest`, `PersonLevelsHaveOneWriterTest`)
   share the original blind spot**; widening them is their own tables' scope, the same line design
   §14 item 23 draws for `UnitMerge`. This is the second consecutive slice in which a guard's blind
   spot was found by attacking it rather than by reading it.
7. **The plan's interface shipped exactly as written** — `record`, `batches`, `rowsFor`, `has`,
   `forgetBatch` — with no batch-minting method added. The UUID is minted by the creator (Task 12),
   the `person_levels.promotion_batch_id` precedent, and adding a sixth method here would have been
   scope the plan did not ask for.
8. **`record()`'s duplicate refusal is a courtesy; the UNIQUE index is the guarantee, and the tests
   say both.** A pre-flight `exists()` in PHP is a race and a single writer's promise, so
   `test_the_unique_pair_is_enforced_by_the_schema_and_not_only_by_the_writer` inserts a duplicate
   through the query builder and expects a `QueryException` — beside the test that pins the
   `InvalidArgumentException` the writer raises.
9. **Trap 5 did not bite, because the schema assertions avoid the call that carries it.**
   `Schema::hasTable()` / `hasColumn()` / `getColumnListing()` are not schema-qualified on SQLite;
   `getTableListing()` is, which is why Task 10's write-nothing test compares its map WHOLE rather
   than by hand-written keys.
10. **Two indexes, each justified, neither led by `institution_id`** — and the table carries no such
    column at all, asserted against the live schema by comparing the WHOLE column list rather than
    one absence. `unique(table_name, row_id)` is the identity of a ledgered row (a row cannot belong
    to two batches) and doubles as Task 13's per-foreign-key pre-flight lookup; `index(batch_id)` is
    what every read filters or groups by. A separate `table_name` index would be dead weight — the
    unique's leading column already serves a prefix lookup.
11. **Nothing else in Task 11's text was wrong against the tree.** The table shape, the absent
    foreign key on `row_id`, the three precedents the docblock is asked to name, and the
    `DemoDepartment`-is-deliberately-absent-from-the-allow-list position all check out.

### 2026-08-11 — Task 12

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1580 passed, 0
   failed**, matching Task 11's recorded number exactly; `npm run build` green. Task 12 took PHPUnit
   to **1600** (16 `DemoCreateTest` + 4 new `DemoRowsAreLedgeredTest` cases). Vitest unchanged at
   224 and no e2e file or migration was touched, so neither was re-run. One stray file was found in
   the tree first and deleted: `tests/Feature/Demo/DumpFkTest.php`, a Task 11 scratch test that
   `require`d a script from a temp directory — it would have failed the suite on any other machine.
2. **`create()` returns `{batch, rows, skipped}`, not the bare batch id the task text specifies.**
   The period step legitimately SKIPS on a department that already generated its academic year, and
   the task text itself asks for "a note in the result" — which a `string` return cannot carry. Two
   further skips exist for the same reason (an empty training ladder, and no period to place the
   rota in).
3. **THE RESULT KEY IS `skipped` AND NOT THE OBVIOUS WORD, AND THAT IS TRAP 1 IN ITS MOST MOBILE
   FORM.** The obvious name is also a `people` column, and `ContactFieldsAreProjectedOnceTest`
   needles it as a quoted array key. Unlike every previous instance of this trap, THE COLLISION
   TRAVELS: the key appears in the support class, in Task 13's console command and in Task 14's
   controller, so allow-listing would have blinded three files to a real contact-field guard for the
   sake of a name. It was in fact observed twice — once on `DemoDepartment`, then again on
   `DemoSeedCommand` a task later, which is what made the propagation visible. Renaming costs one
   word. **Generalisable: when trap 1 fires on an IDENTIFIER rather than on prose, rename it;
   spelling around only works for words that stay in one file.**
4. **`ContactFieldsAreProjectedOnceTest` and `CalendarWritersFlushTest` both fired, and both entries
   were watched earning their place.** The first on `'email'` (write-only — the address is generated
   from the demo's own short name, rendered back to nobody; the `CreateAdmin`/`InvitationIssue`
   precedent). The second because `create()` resolves the institution row for `institution_id`
   provenance when the caller supplies none — `ClinicWriter` takes the opposite branch deliberately,
   and the difference is real: that writer runs only inside a request where an actor always exists,
   while this one is also driven from the console, where the alternative to reading the row here is
   a second definition of "the current institution" in a command. Each entry was removed and the
   guard watched naming the file before it was restored.
5. **`ClinicWritersAreSingularTest` needed NO allow-list entry, contradicting the task's own "Files
   touched" list, and `RotaWritersAreSingularTest` needed none either — which is the task's own test
   that no writer was bypassed.** Creation goes through `ClinicWriter::create()`/`setAttendees()`,
   `RotaAssignment::set()`/`split()`, `VacationBooking::book()` and `LevelAssignment::assign()`, none
   of which matches a needle in either guard. Measured, not assumed: the full suite was run with the
   file present and both stayed green. (Task 13 revisits this from the DELETE side.)
6. **THE DEMO MINTS NO ACCOUNT, and that is the property that makes the owner's
   run-it-in-production ruling safe rather than merely ledgered.** A roster-only person has no
   `users` row and therefore cannot authenticate BY CONSTRUCTION (P0c) — so pressing this on a live
   instance holding children's PHI creates no working login, which is the specific harm
   `DemoSeeder`'s throw exists to prevent. `test_it_creates_no_account_and_therefore_no_way_in` pins
   it, and the class docblock states it as a design constraint so nobody "completes" the fixture with
   a demo login later.
7. **Addresses sit on `demo.invalid`, not on `DemoSeeder`'s `demo.example.org`.** `.invalid` is
   reserved by RFC 2606 and guaranteed never to resolve; `example.org` resolves to a real
   IANA-operated host. On a live instance that difference is the difference between an invitation
   that bounces and one that is delivered somewhere.
8. **The demo generates its periods under the academic year label `Demo`, and only when the
   department has none.** A derived `2026-2027`-shaped label would block the Periods screen from
   generating the department's real year (generating twice for one label is refused outright) and
   would be indistinguishable from it in the rota year picker. When periods DO exist the demo uses
   the one containing today, falling back to the next one to end — so the clinic still resolves —
   and says which branch it took.
9. **`test_every_row_it_creates_is_in_the_ledger` had to excuse `demo_rows` as well as `audit_log`,
   and the reason is worth stating because Task 13 does the opposite.** The ledger cannot ledger
   itself — a row recording the row would be an infinite regress — so its growth is bookkeeping
   about the rows rather than one of them. Its RETURN to the pre-seed count is a different question
   and Task 13's round trip does not excuse it.
10. **The new source guard went into the existing `DemoRowsAreLedgeredTest` rather than a new file,
    because Task 11 already created that file with a different subject.** Task 12's text lists it as
    "(new)". It now carries two scans: "only `DemoLedger` writes `demo_rows`" (Task 11) and "a file
    in `app/Support/Demo/` that CREATES a row also RECORDS it" (Task 12), with its own allow-list,
    staleness twin and vacuity twin.
11. **The creation scan runs over COMMENT-STRIPPED source, and the reason was found on a plant
    rather than reasoned about.** The first version scanned raw text, and a plant carrying a
    COMMENTED-OUT `DemoLedger::record(` satisfied the escape while creating rows in code — a false
    negative, which is the direction a guard must never fail in. `Tests\Support\SourceScanner` (Task
    6's extraction) fixes both directions at once: a docblock naming a creation shape no longer
    flags its own file either. Calibrated in both directions on this guard's own files.
12. **The creation scan was watched failing on three separate plants and staying green on two.**
    Red: a plain `Person::create(` (named `::create(`); a file carrying all five sanctioned-writer
    needles at once (named every one); and the comment-only escape above. Green: the same file once
    a real `DemoLedger::record(` call was present, and `DemoDepartment` itself. Every plant deleted
    immediately after, `git status` back to exactly the task's own working set.
13. **The central claim was watched failing against a MUTATION of the writer, not only against a
    plant beside it.** `DemoLedger::record('vacations', …)` was deleted from `leave()` and
    `test_every_row_it_creates_is_in_the_ledger` went red naming `'vacations' => 1` present on one
    side and absent on the other. Restored from a copy taken first — the file is untracked, so
    `git checkout` is not available as a revert here at all.
14. **Nothing else in the task text was wrong against the tree.** `Unit::RESERVED_CODES`,
    `Unit::BAR_CLASSES`, the four writers' signatures, `Clinic::SESSIONS`/`ATTENDEE_MODES`,
    `Calendar::weekdayColumns()` and the no-production-throw instruction all check out as described.
    Items 2, 5 and 10 are the three corrections.

### 2026-08-11 — Task 13

1. **Baseline: `php artisan test` → 1600**, matching Task 12's number. Task 13 took PHPUnit to
   **1627** (12 `DemoRemoveTest` + 9 `DemoRoundTripTest` + 6 `DemoCommandsTest`). `npm test` → 224
   and `npm run build` green; no migration and no JS was touched.
2. **`DemoCommandsTest` is a sixth file the task's list does not name.** The task ships two console
   commands and tests neither; its verification block runs them by hand instead. A command nothing
   asserts is the shape that produced twelve silent nav links once already, and the by-hand run
   proves one machine's `.env`, not the behaviour.
3. **The refusal is its own exception type, `DemoRemovalBlockedException`, carrying the `(table,
   count)` list as DATA.** `StaleRotaStateException`'s precedent and its reason: a caller has to tell
   "blocked by real rows" from "no such batch" by TYPE, not by matching message text, because the two
   remedies differ. The screen renders the list, the audit row is built from the same list, and the
   message is built from it too — one definition, three consumers.
4. **The refusal audit detail is `blocked=a:1,b:2`, comma-separated inside ONE value, not the task's
   `blocked=<table>:<count>;…`.** The house convention is semicolon-delimited `key=value`; the task's
   shape makes every pair after the first read as a bare key. Pinned by `assertSame` on a two-table
   refusal, which is the only case where the two spellings differ.
5. **THE PRE-FLIGHT'S LOAD-BEARING CLAUSE IS "AND NOT ITSELF LEDGERED", AND WITHOUT IT EVERY REFUSAL
   TEST STILL PASSES.** The demo's own clinic sits on the demo's own unit; its own rota spans name
   its own people and its own periods. A pre-flight that merely counted inbound references would
   refuse every removal forever — a department nobody could ever delete — with all six refusal cases
   green. `test_the_demos_own_rows_do_not_block_its_own_removal` is the twin that pins it, and it is
   the single most important assertion in the file.
6. **Counting is per referencing ROW, not per reference.** One `handover_signoffs` row can name demo
   people in as many as four of its columns; summing per column would report four blockers where an
   operator has one row to deal with. The conditions for a table are OR-ed into one query.
7. **Soft deletes are deliberately not filtered out of the pre-flight, and `TableCounts` counts
   through the query builder for the same reason.** A tombstoned handover still holds its foreign key
   and still makes the demo unit undeletable at the database level; a count that skipped it would
   call a removal complete while the row was still there.
8. **Deletion is generic — `DB::table($table)` with the name taken from the ledger row — and that
   makes it INVISIBLE to `ClinicWritersAreSingularTest`, `RotaWritersAreSingularTest` and
   `PersonLevelsHaveOneWriterTest`.** Two things follow, and the second is a deviation from the task
   text. It has to be generic: a hard delete is required (`people` soft-deletes, and a tombstoned
   demo person would hold its unique email and short name forever), and a switch of eight branches
   would go stale the moment a ninth table joined. And **`DemoDepartment` was NOT added to any of the
   three allow-lists**, contradicting the task's "(modify — allow-list `DemoDepartment`)": an entry
   exempts the file from every needle while buying no green — it matches none of them either way —
   and it would blind those guards at the one file that legitimately reaches all eight demo tables.
   Each guard's DOCBLOCK now records the path instead, which costs no exemption, and
   `DemoRoundTripTest` is what actually holds the line.
9. **`demo_rows` is NOT on the round trip's exclusion list, correcting the task text, which lists
   it.** Excusing the ledger would excuse the one failure mode nothing else catches — rows deleted
   while their entries survive, or the reverse. It returns to its pre-seed count like every other
   table. (Task 12's ledger-completeness test excuses it for the opposite and compatible reason: a
   ledger cannot ledger itself.)
10. **The exclusion list is a `table => reason` map and is asserted three ways**: every entry has a
    non-empty reason; every entry still exists in the live schema; and no entry is a table
    `DemoReferences::MAP` names, so a table the demo writes can never be excused. A fourth test asks
    the question from the other side — create, and assert the only excluded table that MOVED is
    `audit_log`.
11. **THE NEGATIVE CONTROL WAS RUN TWICE, WITH TWO DIFFERENT MUTATIONS, AND THEY WERE CAUGHT BY TWO
    DIFFERENT MECHANISMS — which the plan does not anticipate and which is the most useful thing this
    task found.**
    - Dropping `DemoLedger::record('clinic_attendees', …)` from `create()` made removal **refuse**:
      the two unledgered attendee rows reference the demo clinic, so the PRE-FLIGHT saw them. Red,
      naming `clinic_attendees` — but by the reference check, not by the count comparison.
    - Dropping `DemoLedger::record('people', …)` made removal **succeed and leave five rows behind**,
      because an unledgered row in a table that is only ever REFERENCED has nothing pointing at it
      for the pre-flight to see. `test_removal_returns_every_table_to_its_pre_seed_row_count` went
      red naming `'main.people' => 0` against `5`.
    **The lesson: the pre-flight catches an unledgered CHILD and only the round trip catches an
    unledgered PARENT.** A negative control planted in a child table would have "proved" the round
    trip while never exercising it. The shipped control (`test_a_row_created_outside_the_ledger_
    makes_the_round_trip_fail`) therefore plants an extra unledgered PERSON, and it was itself
    watched failing with the plant removed — without that step, a control that asserted the count
    comparison notices nothing would look identical to one that does.
    A consequence worth recording: a `create()` that forgot to ledger a child row would make the
    demo **permanently unremovable through the product**, since the unledgered child blocks the
    pre-flight and no screen can delete it. Conservative, correct, and a reason the Task 12 guard
    exists as an early warning.
12. **The plan's "throwaway subclass" is not available: `DemoDepartment` is `final`**, in this
    codebase's house style for support classes, and un-finalising a production class so a test can
    subclass it weakens the class to suit the test. Planting the row beside the creator reproduces
    the same state; the mutations in item 11 cover the inside-`create()` half.
13. **`Tests\Support\TableCounts` was extracted, the `SourceScanner` precedent.** Three test classes
    needed the same whole-schema snapshot, and three copies would have been three chances to forget
    that SQLite qualifies its table names. It also owns `qualify()`, for the two places a test
    legitimately names one table, and `delta()`.
14. **Every refusal test compares the snapshot MINUS `audit_log`.** "Nothing was deleted" is the
    claim; "nothing was written" is not — a refused removal is an operator action the hash-chained
    trail records, and asserting the full snapshot made all five refusal cases fail on the trail
    working correctly. `preflight()` on its own is held to the FULL snapshot, because asking is a
    query and audits nothing.
15. **Both commands were run for real against the local sqlite database**, as the task's
    verification block asks: `demo:seed --force` created 15 rows (that database has no seeded level
    ladder, so the empty-ladder branch was exercised in production conditions rather than only in a
    fixture), `demo:remove --force` removed all 15, and every table the demo writes came back to
    zero with the two audit rows retained.
16. **Nothing else in the task text was wrong against the tree.** The `PeriodController::destroy()`
    shape, the five refusal cases, the introspection precedent, the reverse-ledger delete order and
    the no-env-guard instruction all check out. Items 4, 8, 9 and 12 are the corrections; items 2
    and 13 are additions.

### 2026-08-11 — Task 14

1. **Baseline re-measured clean before touching anything: `php artisan test` → 1627 passed, 0
   failed**, matching Task 13's recorded number exactly. `npm test` → 224, `npm run test:e2e` → 24,
   `npm run build` → green. Task 14 took PHPUnit to **1643** (16 `DemoScreenTest`) and Vitest to
   **232** (8 `DemoDepartment.test.js`). No migration was added and the capability catalog is
   unchanged, so the e2e world was not rebuilt; the suite was re-run anyway and is **24**.
2. **THERE IS NO `POST /admin/structure/demo/preview`, correcting the task text's three-route list.
   The GET *is* the preview, and the reason generalises.** Every other preview-then-confirm surface
   in this codebase previews with a POST because its preview takes operator INPUT — a file to parse
   (`RotaImport`), a cohort to select (`BulkResend`), a source cell to fill from (`RotaFill`). This
   one takes none: neither action has a single field beyond the confirmation itself. A `POST
   /preview` would therefore be a button whose only job is to show what the page already shows, and
   it would sit one boolean away from the destructive path — the exact hazard `routes/web.php`'s own
   comment gives for splitting the fill into two routes. Both pins travel as ordinary props on the
   GET instead, which makes "the operator saw it" structural: neither confirm control can be reached
   without the request that computed one. Four routes became three (GET, POST, DELETE).
3. **`StatePin` WAS REUSED FOR BOTH, and each reuse was proved by mutation rather than argued.**
   Removing the two `assertPinned()` calls made exactly three cases go red, and *how* they failed is
   the evidence: `test_creating_is_pinned_to_what_the_operator_saw` and
   `test_removal_is_pinned_to_what_the_operator_saw` both failed with **"Session is missing expected
   key [errors]"** — the operations SUCCEEDED, silently, which is precisely the failure a pin exists
   for and which no other assertion in the tree could see. The third failed differently
   (`remove_pin` absent while `confirm_demo` was present), showing the blocked-removal path
   correctly falling through to the writer's own refusal. Restored from a copy taken first;
   `git checkout` was not available, the file being untracked (Task 12 amendment 13's hazard).
4. **What each pin catches that its operation's own re-derivation cannot — measured, not assumed.**
   Both `create()` and `remove()` re-derive everything inside their own transaction and are right
   to; `StatePin`'s docblock says that is necessary and NOT sufficient, and here is what it misses.
   *Creation:* the preview says "this department has no periods, so the demo will generate its own
   academic year"; somebody generates the department's real year in another tab; `create()` computes
   a fresh answer and quietly places the demo rota in the REAL year. No error, no refusal, and a
   department different from the one approved. *Removal:* two administrators — the first opens the
   screen, the second removes the demo and creates a fresh one, the first presses Remove. Without
   the batch in `identity` that DELETE removes a department this operator never previewed, and every
   table still returns to its pre-seed count, so neither `DemoRoundTripTest`, nor the pre-flight, nor
   the audit trail shows anything amiss.
5. **The removal pin uses BOTH of `StatePin`'s cell identity slots as `null`, which is the strained
   fit and is stated at the site rather than papered over.** A ledger row's identity is
   `(table, id)`; neither a person nor a period applies to it. `null` is exactly what that parameter
   documents for a concept that does not apply (`BulkResend` already uses one of the two that way),
   and the identity lives in the `current` map beside what the row is. `proposed` is `[]` because
   the operation writes nothing anywhere — after it, the row is not there. **The creation pin's
   `cells` is `[]` outright**, for the honest reason that a creation touches no existing row: there
   is no current-versus-proposed pair to project, so the whole projection is `identity` (the world
   facts that decide what gets built) plus `errors` (the refusals). Writing a second digest class
   for either would have been the near-copy that drifts; the hash rule is not re-typed anywhere.
6. **`refusals()` was EXTRACTED so the screen and `create()` cannot disagree.** The two refusal
   messages were inline `throw`s; the screen has to render them BEFORE offering the button, and a
   screen deciding for itself when to grey a control out is a second copy of that rule. One
   definition, two consumers — `PeriodGenerator::assertMonthAligned()`'s shape. Same call for the
   two `skipped` sentences, which are now constants read by `plan()` (predicting) and `create()`
   (reporting): one string, present tense in both directions, because a preview and a receipt of
   the same fact drifting apart is how an operator concludes the button is broken. One word changed
   — *"the demo used the existing academic year"* → *"uses"*.
7. **TRAP 1 WAS AVOIDED BY CONSTRUCTION, AND THE NEAR-MISS IS WORTH RECORDING: the obvious name for
   `plan()`'s second key is `notes`, which is a `people` column and a
   `ContactFieldsAreProjectedOnceTest` needle in quoted form.** Task 12 amendment 3 hit this exact
   shape on `skipped` and its lesson — *"when trap 1 fires on an IDENTIFIER rather than on prose,
   rename it; spelling around only works for words that stay in one file"* — was applied before a
   line was written. The key is `skipped`, the same word `create()` already returns, so preview and
   result read identically and nothing new needed allow-listing. `address_domain` rather than
   `email_domain` for the same reason, one needle away.
8. **`demo_result` was added to `HandleInertiaRequests::share()` IN THIS TASK, not in an amendment
   after it.** A session key no `share()` names is invisible to every page in the app — the trap
   that has now cost this plan family three features whose tests were green and whose screens showed
   nothing (P1c-1 Tasks 7/9/10, P1d-2 Task 8). `test_creating_reports_what_it_skipped_rather_than_
   skipping_it_silently` reads the flash back through a real second request rather than trusting the
   redirect.
9. **NO NAV ENTRY WAS ADDED, deliberately, so `AppLayout.test.js`'s hand-written `adminHrefs` sweep
   needed no change — stated rather than left silent, because "the sweep is unchanged" and "the
   sweep was forgotten" look identical in a diff.** A demo department is not a routine configuration
   surface, and a permanent entry beside Units and Levels on a live clinical instance would read as
   part of the department. It is linked from `/admin/setup`, which IS in the nav and is where
   somebody setting a department up looks for somewhere to practise. The link is a section rather
   than a checklist step, for a reason stated in the markup: a department is fully configured
   without a demo, and a demo that could be ticked off a checklist invites leaving it in place.
10. **The refusal reaches the screen twice, and the second is the one that makes it actionable.**
    `DemoRemovalBlockedException`'s message goes under `confirm_demo` (a rendered key — ruling 49's
    subject), and the pre-flight's `(table, count)` list is rendered from a FRESH read on every page
    load rather than only after a failed attempt. So an operator who has never pressed the button
    still sees what is holding the removal, and while anything holds it the confirmation box and the
    button are not rendered at all. `test_a_blocked_removal_is_refused_whole_and_the_screen_says_
    what_holds_it` asserts the blocked list on the page the operator lands back on, not merely the
    error string.
11. **Two cases beyond the plan's seven, plus a vacuity twin.** The twin
    (`test_the_three_routes_are_registered_behind_the_structure_capability`) is not optional: the
    verb sweep beside it iterates an empty set and passes, which is the same defect P1e-1 Task 4 and
    Task 10 each shipped. The verb sweep also asserts the write routes are inside the `web` group,
    because CSRF is what that group carries and `ValidateCsrfToken` is skipped under test, so "POST +
    CSRF" is otherwise unassertable. The two extra cases are the audited refusal being its own
    action, and the pre-flight payload naming no person, no address and no patient anything.
12. **Nothing in the task text was wrong against the tree** beyond item 2's route list and the
    absence of a GET route from its own "Files touched" implication. `StatePin`'s signature, the
    `PeriodController::destroy()` typed-word idiom, `create()`'s `{batch, rows, skipped}` return and
    the `cap:structure.manage` group's shape all check out as described.

### 2026-08-11 — Task 15

1. **Baseline: `php artisan test` → 1643, `npm test` → 232, `npm run test:e2e` → 24, `npm run build`
   green**, matching Task 14's recorded numbers exactly. Task 15 touches no code and adds no test,
   so all four are unchanged at the end of it; the suites were re-run anyway, because a
   documentation commit is exactly where a tree is assumed rather than checked.
2. **TWO DOCUMENTS THIS TASK WAS TOLD TO FIX WERE ALREADY CORRECT ON DISK — the fourth instance of
   this shape in four slices** (P1c-2 Task 7, P1d-1 Task 12, P1d-2 Task 13, and now this one), and
   the pattern is worth stating as a rule: *check whether the document is already right before
   editing it, because the stale version usually exists only in cached context.*
   - **`docs/spec/08-foundation.md` needed nothing.** Task 5 amendment 12 already added
     `clinics.view` to BOTH the capability catalog list and the role-defaults paragraph below it,
     including the D7 override of Munawib §5's link-public footnote and the `applied_role_defaults`
     once-only behaviour. The Task 15 text hedges this correctly (*"if Task 5 did not already
     complete it"*); it did. The only change here was ticking P1e-1's own definition-of-done box,
     which was still unticked against work that had shipped.
   - **Design §14 item 22 needed nothing.** Task 6 wrote it in full — both hooks, what each will
     read when it arrives, the sixteen needles, the comment-stripping requirement, the
     `SourceScanner` extraction and the fact that the absence is real rather than allow-listed. The
     Task 15 text asks for *"the CL-03/CL-04 hook item (Task 6)"* as if it were outstanding.
3. **Four MORE P1e-1 definition-of-done boxes were unticked against shipped, verified work**
   (`weekdayColumns()` + the fixture block, `ClinicRoster`'s bounded contact-free resolution,
   `/clinics`'s GET-only gate, and the catalog entry). Each was checked against the tree before
   ticking rather than ticked because the task claimed it: the fixture block exists, the method
   exists, `ClinicMapTest` carries both named cases. A definition of done that lags the work is how
   a later slice concludes something was skipped.
4. **The caller's framing "all of P1a–P1e merged" is not quite true and the documents do not say
   it.** `main` carries P1a–P1d and **P1e-1**; P1e-2 is this branch and is unmerged, per the
   instruction not to merge. The documents are written in the house convention — describing the
   state as shipped, which is what every prior slice's Task N did before its own merge — and the
   one place the distinction matters (the P1 plan's Stage 1 note, design §14 item 27) says what is
   met is the CAPABILITY, not the event: no real QCH rota, clinic or roster row exists anywhere yet,
   and accepting §35 is the owner's call after that data lands, not a developer's after a merge.
5. **Design §14 item 23 moved from "recorded, deliberately not fixed" to "ACCEPTED AND SCHEDULED"**
   on the owner's decision, with one addition the instruction did not state: it is scheduled for the
   NEXT slice rather than inside P1, because P1e was the last one and this is not a clinics defect.
   The analysis below it is left byte-for-byte as found — including the paragraph explaining that a
   clinic-only fix would be actively wrong (re-pointing `clinics.unit_id` while the rota rows stayed
   behind resolves every migrated clinic to nobody), which is what makes "all three, whole" the
   scheduled unit of work rather than three independent tickets.
6. **Item 12 was NARROWED, not closed, and `code` was split out into its own item 25.** Task 8
   closed the `name` half; the `code` half is not an unbuilt feature but a thing that **must not be
   built**, and leaving both inside one open item reads as the first. "Not built yet" and "must not
   be built" are different states — the same distinction item 18 and item 22 draw for MR-04 and
   CL-03/CL-04 — so they are now two items.
7. **Three new design §14 items beyond the five the task text lists**, each recording something P1e
   found rather than something it decided: item 24 (`DemoSeeder`/`E2eSeeder` stay unledgered and
   unremovable, and consolidating them is a follow-on rather than something this slice did quietly),
   item 26 (`Model::query()->create(` as a sixth writer shape, with the three sibling guards
   verified at **zero** `::query()` needles apiece and a sweep queued), and item 27 (what Stage 1
   acceptance does and does not now mean).
8. **`docs/OWNER-CHECKLIST.md` was not in Task 15's file list and needed two edits.** Its CI item
   said *"P0a through P1d-2 have had zero CI coverage"* — true and now understated, since P1e has
   none either. And the demo department is the first feature in this codebase an owner can press on
   the LIVE instance that creates records, so it gets a section of its own written for somebody who
   will be asked "may I?" rather than for a developer: what it creates, why this one is safe where
   `DemoSeeder` is not, what typing `DEMO` does, and that a refusal naming tables and counts is the
   feature working rather than a fault.
9. **`docs/OPEN-DECISIONS.md` item D was moved rather than edited in place**, following that file's
   own stated convention (*"recorded here as decisions, not deleted, because 'we considered it and
   chose this' is the answer an auditor wants"*). The new decided block also records the two P1e
   owner answers that were live questions in this plan — the demo department may be created in
   production, and `name` is editable while `code` is not — because an owner decision that lives
   only in a plan's "Owner decisions needed" section is one nobody finds later.
10. **Every claim written here was checked against the tree first, and the checking caught three
    things.** (a) `DemoRowsAreLedgeredTest` really does needle `DemoRow::query()` (line 106), and the
    three sibling guards really do carry **zero** `::query()` needles apiece — both stated in
    CLAUDE.md and design §14 item 26, both measured rather than inferred. (b) **This plan's own
    finding 3 cites the WARD clinic-owner guard as `P1bStructureTest::test_ward_alone_is_seeded_as_
    a_clinic_owner`, and no such class exists** — the test is real and green, but it lives in
    `tests/Feature/Units/UnitCapabilityFlagsTest.php`. Corrected where it was being quoted into
    `docs/OPEN-DECISIONS.md`, which is a permanent document; the plan's finding is left as written,
    since the amendments block is where its errors are recorded. **Never cite a guard by a class name
    you have not grepped for** — a wrong citation reads exactly like a real one and survives review.
    (c) `SPC-RPT-059` does not say what this plan's finding 7 implies. Its subject is that the two
    seeders' guards are **`APP_ENV`-only**, so a staging or DR-rehearsal instance restored from
    production data would accept `db:seed --class=DemoSeeder` and mint a position-0 administrator
    whose password is in this repository — a sharper and more specific risk than "their rows are
    identifiable only by documented addresses". Design §14 item 24 quotes it accurately.
    The design doc has been factually wrong eight times; the cost of checking is one `grep` per
    sentence, and it was wrong twice more in the space of this task.

---

## Standing rules for every task

Verified against the tree; these are not preferences.

- **TDD, strictly.** Write the test, run it, **watch it fail for the reason you expect** (not a
  typo, not a missing class), then implement. A test that passes on first run has proved nothing.
  P1d-1 recorded three legitimate zero-red tasks where an earlier task's scope had already covered
  a later one's behaviour — when that happens, say so in the amendments and check each case would
  have failed before the earlier task landed.
- **Build before test, every time.** `npm run build && php artisan test`.
- **Verify with Bash, not PowerShell.** PowerShell's PATH on this machine lacks `openssl` and the
  backup tests silently self-skip there — a false green indistinguishable from a real one. If PHP
  is not on PATH in a fresh shell:
  `export PATH="$LOCALAPPDATA/php84:$LOCALAPPDATA/composer-bin:$PATH"`.
- **Filter output.** `| tail -5` for a full run; `php artisan test --filter <TestName> | head -30`
  on a failure. Never dump a failing suite into context.
- **Assert over the whole set, never inside a `foreach`.** Every source-scanning guard collects
  `$offenders[]` and ends with `assertSame([], $offenders, ...)`, and carries a staleness twin.
- **Every source-level guard is watched failing against a planted violation before it is trusted.**
  Plant, run, see it named, revert, run, see it green.
- **Every route behind `auth` + a `cap:`.** Writes are POST/PATCH/DELETE + CSRF.
- **Eloquent/bindings only.** Never concatenate SQL.
- **Light theme only, semantic classes only.** No `dark:` utility, no raw Tailwind palette class,
  no hex in markup. There is no `bg-panel-soft` token.
- **New screens follow `Units.vue` / `Levels.vue` / `People.vue` / `MasterRota.vue`**: mobile cards
  plus desktop table, `useForm`, `preserveScroll`, live regions, a computed column count.
- **The client performs no date arithmetic** — ten needles, no allow-list, matching docblock prose
  too. Every weekday label, date and range arrives pre-formatted from `Calendar`.
- **Free text is escaped on render.** `{{ }}` or `:value`, never `v-html`, for every clinic name,
  location and note.
- **`institution_id` is provenance.** Never a `where`, never inside an `index([...])` /
  `unique([...])` array.
- **Audit by ids, field names and counts only.** Never a person's name, a clinic's name, a unit's
  name or a filename. `AuditLog::record()` takes a plain semicolon-delimited string (finding 10).
- **`ClinicWriter` is the only writer of `clinics` and `clinic_attendees`; `DemoDepartment` is the
  only writer of `demo_rows`.** Every seeder, factory and console command goes through them or sits
  on an allow-list with a stated reason.
- Commit at the end of each task with the message given, only after `npm run build` and
  `php artisan test` are both green.

---

# P1e-1 — tasks

### Task 1: the department's own week, in `Calendar` and nowhere else

Decision A. The weekday integer is not a date and needs no conversion; **ordering and labelling the
department's week are `Calendar`'s and go nowhere else**, so the clinic form and the map both
consume one server-built array and `resources/js` computes nothing. This is first because Tasks 2,
4 and 5 all consume it, and a screen that builds its own day list is the converter this codebase
fails the build over.

**Files touched**

- `app/Support/Calendar.php` (modify)
- `tests/fixtures/calendar/golden.json` (modify)
- `tests/Feature/Calendar/WeekdayVocabularyTest.php` (new)
- `tests/Feature/Calendar/GoldenFixtureTest.php` (modify)

**The failing test to write first**

`WeekdayVocabularyTest`:

1. `test_monday_is_one_and_sunday_is_seven` — pins **ISO-8601** explicitly, and asserts it is *not*
   Carbon's `dayOfWeek` (where Sunday is 0). The one test that stops a third numbering scheme.
2. `test_the_columns_start_at_the_departments_own_week_start` — weekend `[5, 6]` gives
   `[7,1,2,3,4,5,6]`; weekend `[6, 7]` gives `[1,2,3,4,5,6,7]`. Set the institution's
   `weekend_days` and `Calendar::flush()` between cases.
3. `test_every_column_says_whether_it_is_a_weekend_day` — read from `weekendDays()`, one definition,
   never recomputed.
4. `test_there_are_always_seven_columns_and_no_iso_day_repeats`.
5. `test_changing_the_weekend_reorders_the_columns_with_no_stored_value_changing` — the survival
   property Decision A rests on. Assert the *order* moved and that nothing else did.
6. `test_an_empty_weekend_list_still_produces_seven_ordered_columns` — `weekStartIsoDay()`'s Monday
   fallback, which `weekendDays()` can genuinely return `[]` for (see its own comment at
   `Calendar.php:474-481`).
7. `test_an_out_of_range_iso_day_is_refused` — `weekdayLabel(0)` and `weekdayLabel(8)` throw.

`GoldenFixtureTest` gains a case asserting the new `weekday_columns` block.

Run them. All must be red before the methods exist.

**The implementation**

Two public statics on `App\Support\Calendar`:

- `weekdayLabel(int $iso): string` — `'Monday' … 'Sunday'`, throwing on anything outside 1–7.
- `weekdayColumns(): list<array{iso:int, label:string, short:string, weekend:bool}>` — seven
  entries, rotated to begin at `weekStartIsoDay()`, `weekend` read from `weekendDays()`.

Labels are English-only at launch (Munawib §4, AR-07 translation-ready architecture) and are
plain constants — **no `IntlDateFormatter`, no `IntlCalendar`, no date construction**, because a
weekday name is not a date conversion and building one would put an unnecessary converter inside
the module whose whole job is to be the only one.

Add a `weekday_columns` block to `tests/fixtures/calendar/golden.json`, keyed by weekend
configuration (at minimum `[5,6]` and `[6,7]`). Its docblock must state that P2's `packages/engine`
mirror asserts the same file, that CL-03 is why the mirror needs weekdays at all, and that changing
one side without the other is the drift the fixture exists to catch.

**How to verify**

```bash
npm run build && php artisan test --filter "WeekdayVocabularyTest|GoldenFixtureTest|CalendarIsTheOnlyConverter" | tail -5
php artisan test | tail -5
```

Expected: seven new tests plus one fixture case. `CalendarIsTheOnlyConverterTest` must stay green
with **no allow-list change** — if it names `Calendar.php` itself, the implementation reached for a
converter it did not need.

```bash
git commit -am "feat: the department's week has an order, and one place that knows it"
```

---

### Task 2: `clinics`, `clinic_attendees`, and the one writer

Decision B's shape. CL-01's full field set, CL-02's three modes, and the writer that is the only
thing standing between `clinic_attendees` and a duplicate row — because finding 12 means the
database will not do it.

**Files touched**

- `database/migrations/2026_08_16_120001_create_clinics_and_attendees_tables.php` (new)
- `app/Models/Clinic.php` (new)
- `app/Models/ClinicAttendee.php` (new)
- `app/Support/Clinics/ClinicWriter.php` (new)
- `database/factories/ClinicFactory.php` (new)
- `tests/Feature/Clinics/ClinicWriterTest.php` (new)
- `tests/Feature/Build/ClinicWritersAreSingularTest.php` (new)

**Check the migration slot is free first** (`ls database/migrations/ | tail -5`).

**The failing test to write first**

`ClinicWriterTest`:

1. `test_a_clinic_must_belong_to_a_clinic_owning_unit` — a unit with `clinic_owner = false` is
   refused. Units are configuration: this reads the column, never a code list.
2. `test_a_clinic_on_a_retired_unit_is_refused` — `active = false` owner.
3. `test_the_weekday_is_iso_and_zero_and_eight_are_refused`.
4. `test_the_session_must_be_one_of_the_offered_list` — and the offer and the validation both come
   from `Clinic::SESSIONS`, asserted by reading the constant in the test rather than repeating
   `['AM','PM']` (the D9 shape: a predicate written twice is two predicates).
5. `test_levels_mode_refuses_a_named_person_row_and_named_mode_refuses_a_level_row`.
6. `test_rotators_mode_holds_no_attendee_rows_and_switching_to_it_clears_them`.
7. `test_the_same_level_cannot_be_attached_twice_and_nor_can_the_same_person`.
8. `test_setting_attendees_replaces_the_set_rather_than_appending` — `RotaAssignment::set()`'s
   replace semantics, which is what makes a re-run of a seeder converge instead of duplicating.
9. `test_two_clinics_may_share_a_unit_weekday_and_session` — deliberate: two rooms, one session.
   Pins the *absence* of a unique index so nobody adds one later.
10. `test_the_name_location_and_note_are_stored_verbatim_and_never_purified` — invariant 11. Store
    a string containing `<b>` and assert it comes back byte-identical. Escaping is the renderer's
    job.

`ClinicWritersAreSingularTest` — copy `RotaWritersAreSingularTest` structurally:

- `ALLOW_LIST` = `app/Support/Clinics/ClinicWriter.php`, `database/factories/ClinicFactory.php`, and
  (from Task 12) `app/Support/Demo/DemoDepartment.php` — **add that entry when Task 12 lands, not
  now**, and note in this test's docblock that `DemoDepartment` will join it and why (it is a
  ledgered creator, not a second definition of the clinic rules; it calls `ClinicWriter`).
- `NEEDLES` = `Clinic::create(`, `Clinic::insert(`, `Clinic::updateOrCreate(`,
  `ClinicAttendee::create(`, `ClinicAttendee::insert(`, `ClinicAttendee::updateOrCreate(`,
  `DB::table('clinics')`, `DB::table("clinics")`, `DB::table('clinic_attendees')`,
  `DB::table("clinic_attendees")`, plus ruling 42's three extra shapes a `::create(` scan misses:
  property-assignment-then-`->save()`, the four relation-write spellings beside `create`, and
  `find()`-then-`delete()`.
- Scans `app/` + `database/` + `routes/`. `tests/` is out.
- Ends `assertSame([], $offenders, ...)`, plus `test_every_allow_listed_file_still_exists`.

**Plant a violation and watch the guard name the file before trusting it.**

**The implementation**

Migration:

```
clinics
  id
  institution_id   nullable FK -> institutions, nullOnDelete     (provenance only — D11)
  unit_id          NOT NULL FK -> units, restrictOnDelete
  name             string, NOT NULL
  weekday          unsignedTinyInteger, NOT NULL                 (ISO-8601: Mon=1 … Sun=7)
  session          string(2), NOT NULL                           ('AM' | 'PM')
  location         string, nullable
  note             text, nullable
  attendee_mode    string(20), NOT NULL, default 'rotators'
  active           boolean, NOT NULL, default true
  timestamps
  index(unit_id, weekday, session)      -- the columns the map filters and orders on
  index(active)

clinic_attendees
  id
  clinic_id        NOT NULL FK -> clinics, cascadeOnDelete
  level_id         nullable FK -> levels, restrictOnDelete
  person_id        nullable FK -> people, cascadeOnDelete
  timestamps
  index(clinic_id)
```

**No soft deletes on either** (invariant 7). **No `institution_id` on `clinic_attendees`** — a pure
child table does not repeat its parent's provenance (`person_levels`, `unit_field_definitions`).
**No index led by `institution_id`** anywhere (D11 — this exact mistake has been made twice by plan
text and caught twice by `InstitutionProvenanceTest`).

The migration docblock must state, in this order: that `weekday` is ISO-8601 and why (Decision A);
that `clinic_attendees` holds a *rule*, never a resolved roster (Decision B), so it never needs
maintaining when the rota moves; and that the exactly-one-of and no-duplicate constraints live in
`ClinicWriter` because **neither SQLite nor MySQL 8.4 can express a unique index over nullable
columns that means what we need** — quoting the `person_levels` precedent by name.

`Clinic` model: `SESSIONS` and `ATTENDEE_MODES` constants (label maps, offered *and* validated from
one place); `unit()`, `attendees()` relations; `scopeActive()`; `scopeOrdered()` by
`(weekday, session, name)`.

`ClinicWriter`: `create()`, `update()`, `setActive()`, `setAttendees()`. Every refusal throws — a
refusal written inside a caller's transaction must roll back with it, which is P1c-1 finding 12 and
ruling 35's shape. Its docblock states it is the only writer, names the guard test, and states that
it never resolves people (that is `ClinicRoster`, Task 3).

**How to verify**

```bash
npm run build && php artisan test --filter "ClinicWriterTest|ClinicWritersAreSingular|InstitutionProvenance" | tail -5
php artisan test | tail -5
```

`InstitutionProvenanceTest` must stay green untouched — if it goes red, an index led with
`institution_id` or a `where('institution_id', ...)` crept in.

```bash
git commit -am "feat: a clinic is a unit, a weekday and a session"
```

---

### Task 3: `ClinicRoster` — who this clinic resolves to on a date, computed and never stored

Decision B's read-time half, and the thing that makes `clinic_attendees` mean something. **It
subtracts no leave and computes no availability** — CL-04 is P3, and this class is exactly what a
future reader will reach into to answer "who can cover Tuesday clinic". Its docblock says so and
Task 6 guards it.

**Files touched**

- `app/Support/Clinics/ClinicRoster.php` (new)
- `tests/Feature/Clinics/ClinicRosterTest.php` (new)

**The failing test to write first**

1. `test_rotators_mode_returns_everyone_the_rota_has_on_the_unit_that_day`.
2. `test_a_person_whose_span_ends_the_day_before_is_not_on_it` — **both bounds inclusive**, the
   idiom `Period::contains()` and `Person::levelAt()` already share.
3. `test_a_split_period_puts_the_person_on_the_clinic_only_for_the_unit_half` — the P1d span shape.
4. `test_levels_mode_uses_the_level_held_on_that_date_not_the_current_one` — mid-year promotion.
   This is the case a naive implementation gets wrong, and it is the same one
   `AvailabilitySummaryTest` case 3 exists for.
5. `test_named_mode_ignores_the_rota_entirely` — a person with no span at all is returned.
6. `test_a_person_on_leave_is_still_returned_and_carries_no_availability_field` — pins the CL-04
   boundary as **behaviour**, not just prose. Assert the key is absent.
7. `test_no_contact_field_reaches_the_projection` — `email` and `phone` **ABSENT**, not null, on a
   department set to `contact_visibility = members` **and** for a `people.manage` holder. Both
   cases, because the first is the department toggle and the second is the capability, and P1d-2
   found the live disclosure on exactly the second.
8. `test_a_retired_person_still_holding_a_span_is_reported_stale_rather_than_attending` — P1d-2
   Decision D's shape: a departed colleague on a clinic list reads as coverage that is not there.
9. `test_the_query_count_is_bounded` — `DB::enableQueryLog()`, assert an exact small number. Stops
   the next person "just fetching the unit name" and turning a map cell into an N+1 across seven
   columns.
10. `test_an_inactive_clinic_still_resolves` — resolution is not authorization; the map filters,
    the resolver answers.

**The implementation**

`ClinicRoster::forDate(Clinic $clinic, string $date): array`, returning a list of
`PersonPresenter::contactFree($person, ['via' => 'rotation'|'named', 'stale' => bool])`.

- Spans: `master_rota_assignments` where `unit_id = $clinic->unit_id` and
  `starts_on <= $date <= $ends_on`, compared as **`Y-m-d` strings** — the four-way string
  comparison P1d-2 Decision B established, no `DateTime`, no `Calendar` call, because
  lexicographic order on `Y-m-d` is chronological order.
- `levels` mode filters on `Person::levelAt($date)`, never the current level.
- `named` mode skips the span query entirely.
- **`contactFree()`, not `one($person, $viewer)`.** The clinic screen and the map both pick and show
  *names*; a surface that leaks an email as a side effect of naming somebody is precisely the
  payload disclosure P1d-2 Decision C closed. Passing `null` as the viewer would work and would lie
  — `contactFree()` is the named intent, for the reason its own docblock gives.

Class docblock, in this order: it is the one resolver; attendance is **derived, never stored**, and
why (Decision B); the level is the one held on that date; it subtracts no leave and **must never
become an availability or eligibility computation** (CL-04 is P3, MR-04 is Stage 2, Task 6 asserts
both).

**How to verify**

```bash
npm run build && php artisan test --filter "ClinicRosterTest|ContactFieldsAreProjectedOnce" | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: a clinic's people are the rota's people, asked on the day"
```

---

### Task 4: the clinics screen — `/admin/structure/clinics`, `cap:structure.manage`, no destroy

CL-01 and CL-02's administrative surface, beside Units and Levels because a clinic is department
structure (Decision C).

**Files touched**

- `app/Http/Controllers/Admin/ClinicController.php` (new)
- `app/Http/Requests/Admin/ClinicRequest.php` (new)
- `resources/js/Pages/Admin/Clinics.vue` (new)
- `resources/js/Layouts/AppLayout.vue` (modify — one nav entry)
- `routes/web.php` (modify)
- `tests/Feature/Clinics/ClinicScreenTest.php` (new)
- `resources/js/__tests__/Clinics.spec.js` (new, Vitest)

**The failing test to write first**

`ClinicScreenTest`:

1. `test_a_structure_manager_reaches_the_screen_and_a_resident_does_not`.
2. `test_a_guest_is_redirected_to_login`.
3. `test_the_unit_picker_offers_only_active_clinic_owning_units` — and asserts the offer matches
   what the write side accepts, per fixture, in one test (the `PickerParityTest` matrix shape). A
   picker offering what its validation refuses, or vice versa, is D9's whole subject.
4. `test_creating_a_clinic_audits_by_id_and_never_by_name` — assert `audit_log.detail` contains the
   ids and does **not** contain the clinic's name or the unit's name.
5. `test_there_is_no_destroy_route_for_a_clinic` — asserted over the **router**, not by absence of a
   method. Deactivation hides forward (UN-04); a clinic referenced by a future P2 condition must not
   vanish.
6. `test_the_attendee_picker_is_contact_free` — the props array carries no `email` and no `phone`
   key **even for an administrator who also holds `people.manage`**. This is the one that fails on
   the obvious implementation.
7. `test_switching_to_rotators_mode_clears_the_attendee_rows_through_the_writer`.
8. `test_the_weekday_options_come_from_the_calendar_and_are_in_the_departments_order`.
9. `test_a_clinic_shows_who_it_resolves_to_today` — the detail panel calls `ClinicRoster::forDate(…,
   Calendar::todayYmd())`, which is what makes a refinement rule usable rather than abstract.

`Clinics.spec.js` (Vitest): renders the table; asserts no `dark:` class, no `v-html`, and that the
weekday header comes from the prop rather than a literal array in the component.

**The implementation**

Routes, inside the existing `['auth','throttle:clinical','cap:structure.manage']` group with prefix
`admin/structure` and name `admin.structure.`:

```
GET    /clinics                     admin.structure.clinics
POST   /clinics                     admin.structure.clinics.store
PATCH  /clinics/{clinic}            admin.structure.clinics.update
PATCH  /clinics/{clinic}/active     admin.structure.clinics.active
PUT    /clinics/{clinic}/attendees  admin.structure.clinics.attendees
```

**No `destroy`** — the `UnitController` / `LevelController` / `HolidayController` precedent, stated
in the controller docblock with its reason.

Controller delegates every write to `ClinicWriter` and audits `clinic_create`, `clinic_update`,
`clinic_activate` / `clinic_deactivate`, `clinic_attendees_set` — **ids, field names and counts
only**.

Props: `clinics` grouped by unit; `units` (active clinic owners); `weekdays` from
`Calendar::weekdayColumns()`; `sessions` from `Clinic::SESSIONS`; `modes` from
`Clinic::ATTENDEE_MODES`; `levels` (active); `people` through `PersonPresenter::contactFree()`;
`resolved_today` per clinic from `ClinicRoster`.

`ClinicRequest` validates from the same constants the props are built from — one definition, two
consumers, never re-typed.

Screen: mobile cards plus desktop table, `useForm`, `preserveScroll`, live regions, a computed
column count. Unit colour from `Unit::BAR_CLASSES` / `DEFAULT_BAR_CLASS`, no new palette. Free text
interpolated, never `v-html`.

Nav: one entry under Administration, `can('structure.manage')`, beside Units and Levels.

**How to verify**

```bash
npm run build && php artisan test --filter "ClinicScreenTest|PickerParity" | tail -5
npm test | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: somewhere to say when the clinic runs"
```

---

### Task 5: CL-05's weekly clinic map — a new `clinics.view`, GET-only, contact-free

The department-wide read surface. Decision C: `auth` + a new capability seeded to every position,
never link-public, and asserted GET-only over the router so a write endpoint cannot arrive on a
surface the whole department can read.

**Files touched**

- `app/Http/Controllers/ClinicMapController.php` (new)
- `resources/js/Pages/Clinics/Map.vue` (new)
- `database/seeders/AccessControlSeeder.php` (modify — `CATALOG`, `DESCRIPTIONS`, `ROLE_DEFAULTS`)
- `docs/spec/08-foundation.md` (modify — the capability catalog document, finding 11)
- `resources/js/Layouts/AppLayout.vue` (modify)
- `routes/web.php` (modify)
- `tests/Feature/Clinics/ClinicMapTest.php` (new)
- `resources/js/__tests__/ClinicMap.spec.js` (new, Vitest)

**The failing test to write first**

1. `test_clinics_view_is_in_the_catalog` and `test_the_catalog_document_lists_the_key` — finding 11.
   Without the second, `docs/spec/08-foundation.md` goes stale and a *rota* test is what fails.
2. `test_every_seeded_position_holds_clinics_view_by_default` — 0, 2, 3, 4, 5.
3. `test_the_retired_nurse_position_gains_no_default` — position 1 is retired and must stay so.
4. `test_every_route_behind_cap_clinics_view_is_a_get` — enumerated over the **ROUTER**, the
   `RotaReadViewTest` idiom.
5. `test_a_guest_is_redirected_to_login_and_the_map_is_not_anonymous` — D7.
6. `test_the_map_carries_no_contact_field_for_any_viewer` — absent, not null, on both
   `contact_visibility` settings and for a `people.manage` holder.
7. `test_the_columns_are_the_departments_own_week_in_its_own_order`.
8. `test_an_inactive_clinic_and_a_retired_unit_are_absent_from_the_map`.
9. `test_two_clinics_in_one_cell_both_appear` — Task 2 case 9's consequence.
10. `test_the_map_issues_a_bounded_number_of_queries`.

`ClinicMap.spec.js`: renders seven columns from the prop; no `dark:`; no `v-html`.

**The implementation**

Route `GET /clinics` → `clinics`, middleware `['auth','throttle:clinical','cap:clinics.view']`.
**Its own controller and its own route group** — the P1d-2 Decision A shape: a read surface whose
group contains no write route cannot grow one by accident.

`AccessControlSeeder`: `clinics.view` added to `CATALOG`, given a `DESCRIPTIONS` entry — **that
string is what an administrator reads on the Access Control screen**, so it must state the default
plainly ("Default: every role — a resident needs to know when their unit's clinic runs") — and added
to `ROLE_DEFAULTS` for 0, 2, 3, 4, 5. Note in the task, because P1d-2's Task 1 amendment found it
the hard way: `applied_role_defaults` means **an already-seeded instance receives the new default on
its next `db:seed --force`, once**, and a later administrator revocation is never re-asserted. Task
15 puts that in `docs/RUNBOOK-DEPLOY.md`.

The map: rows = active clinic-owning units in `display_order`; columns =
`Calendar::weekdayColumns()`; each cell split AM/PM; each holding that unit's active clinics.
Clinic names, locations and notes are interpolated. **The map shows clinics, not people** — if a
count or a name list is shown at all it comes from `ClinicRoster` through `contactFree()`.

Nav entry gated `can('clinics.view')`, above Administration beside the rota read view.

**How to verify**

```bash
npm run build && php artisan test --filter "ClinicMapTest|RotaAccessTest|AccessControl" | tail -5
npm test | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: the department's week of clinics, on one page"
```

---

### Task 6: CL-03 and CL-04 are hooks, and their absence is guarded — comments stripped

The MR-04 treatment, applied to clinics. Design §14 item 18 records that `RotaAccessTest` scans for
the eligibility shape **twice**, and that the second scan **strips comments before matching**,
because the files' own docblocks state the rule the scan is looking for — a literal needle scan
would fail the build on its own documentation and train people to delete it. Clinics have exactly
that problem: `ClinicRoster`'s docblock (Task 3) says it must never become an availability
computation, in those words.

**Files touched**

- `tests/Feature/Clinics/ClinicHooksTest.php` (new)
- `app/Support/Clinics/ClinicRoster.php` (modify — docblock only, if needed)
- `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md` (modify — §14, a new
  open item recording the hook)

**The failing test to write first**

1. `test_nothing_in_the_clinic_module_evaluates_a_condition` — CL-03's shape. Needles, lower-cased
   and matched case-insensitively over **comment-stripped** source: `post_call`, `postcall`,
   `condition`, `severity`, `violation`, `hard_block`, `soft_block`, `rank_order`. Scanned over
   `app/Support/Clinics/` in full, plus the clinic controllers, form requests and Vue screens.
2. `test_nothing_in_the_clinic_module_computes_availability` — CL-04's shape: `availab`,
   `coverage`, `on_now`, `onnow`, `subtract`, `personal_schedule`, `unavailable`.
3. `test_the_scan_strips_comments_and_still_sees_the_code` — **the stripper pinned in BOTH
   directions**, copied from `RotaAccessTest`: a stripper that over-reaches returns comment-free
   *and code-free* source, every needle misses, and the guard is silently vacuous while looking
   identical to a clean tree. Assert the stripped source no longer contains a known docblock phrase
   **and** still contains a known code token, for `.php` and `.vue` separately.

**Plant a violation in each scan's directory and watch it named before trusting either.**

**The implementation**

Reuse `RotaAccessTest`'s stripper rather than re-typing it — if that means extracting it to a shared
test helper, do that, and say so in the amendments; two comment strippers is two definitions of one
fact, which is the failure this whole codebase is organised against.

Design §14 gains an item, in the shape of item 18: **CL-03 and CL-04 are unbuilt, their hooks are
recorded rather than built, and the absence is asserted rather than merely unimplemented.** State
what the hook *is*: CL-03 reads `clinics.weekday` + `clinics.unit_id` against a date and a person's
current unit; CL-04 reads `ClinicRoster::forDate()`. Neither needs a schema change when it arrives.

**How to verify**

```bash
npm run build && php artisan test --filter "ClinicHooksTest|RotaAccessTest" | tail -5
php artisan test | tail -5
```

```bash
git commit -am "test: the clinic module does not decide who is available"
```

---

### Task 7: the e2e journey — an administrator defines a clinic, a resident finds it, and it is still there after a reload

**Files touched**

- `tests/e2e/clinics.spec.js` (new)
- `database/seeders/E2eSeeder.php` (modify)
- `tests/Feature/Build/ClinicWritersAreSingularTest.php` (modify — allow-list, only if needed)

**The failing test to write first**

Two `test()` blocks:

1. **Administrator creates a clinic.** Sign in as the e2e admin, go to Admin → Structure → Clinics,
   create one on the clinic-owning unit, **reload the page**, and assert it is still there with its
   weekday and session. *Persistence after reload, never the indicator alone* — CLAUDE.md's autosave
   rule generalised, and the reason `rota-read.spec.js` reloads.
2. **A `clinics.view` resident reads the map.** Sign in as the e2e rota reader, open `/clinics`,
   assert the clinic appears in the right unit row and weekday column, and assert **no `@` appears
   in the page's Inertia props** — the cheap, blunt check that catches a contact leak the way
   reading a rendered page never will.

**The implementation**

`E2eSeeder` gains a clinic. **It must go through `ClinicWriter`** — `database/` is scanned by the
guard (ruling 42: a seeder writing the column directly *is* a second writer, merely one whose blast
radius stops at the suite), so a direct `Clinic::create()` there fails the build. If the seeder is
allow-listed instead, that is the wrong call and the amendment must say why it was taken.

WARD is already a clinic owner on a cold start (finding 3), and `migrate:fresh --force --seed` runs
`ReferenceSeeder`, so no unit reconfiguration is needed — **verify that rather than assuming it**;
if the e2e sqlite database takes the upgrade path rather than the cold-start path, WARD's
`clinic_owner` may be `false` and the seeder must tick it explicitly.

**How to verify**

```bash
npm run build && npm run test:e2e | tail -20
php artisan test | tail -5
```

Expected: 24 e2e tests across 8 spec files (22 + 2). **Count it, do not assume it.**

```bash
git commit -am "test: a clinic somebody made is a clinic somebody sees"
```

---

## Definition of done — P1e-1

- [x] `clinics` and `clinic_attendees` exist; neither soft-deletes; no index is led by
      `institution_id`; `InstitutionProvenanceTest` is green untouched.
- [x] `ClinicWriter` is the only writer of both, proved by a guard that was watched failing on a
      planted violation and carries a staleness twin.
- [x] `clinics.weekday` is ISO-8601, documented as such in three places, and no Carbon `dayOfWeek`
      appears anywhere near it.
- [x] `Calendar::weekdayColumns()` is the only source of the department's week order;
      `CalendarIsTheOnlyConverterTest` is green with **no allow-list change**;
      `tests/fixtures/calendar/golden.json` carries the new block.
- [x] `ClinicRoster` resolves at read time, issues a bounded and measured number of queries, and
      returns `contactFree()` projections in which `email` and `phone` are **absent**.
- [x] `/admin/structure/clinics` is `cap:structure.manage`, has **no destroy route** (asserted over
      the router), and audits by id.
- [x] `/clinics` is `cap:clinics.view`, seeded to every position, asserted **GET-only over the
      router**, and carries no contact field for any viewer.
- [x] `clinics.view` appears in `docs/spec/08-foundation.md` — in BOTH the catalog list and the
      role-defaults paragraph below it (Task 5 amendment 12: a key added only to the first leaves
      that document self-contradictory while the catalog test stays green).
- [x] CL-03's and CL-04's absence is guarded by two comment-stripped scans, each watched failing,
      with the stripper pinned in both directions.
- [x] `npm run build`, `php artisan test` (**1527**), `npm test` (**212**) and `npm run test:e2e`
      (**24 across 8 spec files**, from a world rebuilt with `rm database/e2e.sqlite`) all green, on a
      **clean tree** — measured, not arithmetic.

---

# P1e-2 — tasks

### Task 8: the department gets a name it can change, and a code it cannot

Decision G. ST-01's first step, which has no screen today (finding 5).

**Files touched**

- `app/Http/Controllers/Admin/DepartmentProfileController.php` (new)
- `app/Http/Requests/Admin/DepartmentProfileRequest.php` (new)
- `resources/js/Pages/Admin/DepartmentProfile.vue` (new)
- `resources/js/Layouts/AppLayout.vue` (modify)
- `routes/web.php` (modify)
- `tests/Feature/Build/CalendarWritersFlushTest.php` (modify — `ALLOW_LIST`)
- `tests/Feature/Admin/DepartmentProfileTest.php` (new)

**The failing test to write first**

1. `test_a_structure_manager_can_rename_the_department_and_a_resident_cannot`.
2. `test_the_code_is_not_writable` — POST a `code` and assert the stored value is unchanged. Not
   "the field is disabled in the template": mass assignment is a server-side question.
3. `test_a_rename_survives_db_seed_force` — run the rename, then `ReferenceSeeder`, then assert the
   name held. `ReferenceSeeder` writes `name` on **create only**; this test is what proves it,
   because the analogous property for unit profile columns is the entire reason
   `2026_08_15_120002_correct_ward_clinic_owner` had to exist.
4. `test_the_rename_is_audited_by_field_name_and_never_by_value` — `institution_profile_update`,
   detail `fields=name`, and **not** the old or new name (invariant 9: staff- and
   department-identifying strings are covered by the same rule as PHI).
5. `test_the_screen_states_that_the_code_is_set_at_provisioning`.

**The implementation**

Route `GET /admin/structure/department` → `admin.structure.department`, `PATCH` →
`admin.structure.department.update`, in the existing `cap:structure.manage` group.

`institutions.name` only. `code` is rendered read-only with the reason, and the screen must not
imply it is `App\Support\Instance::slug()` — different value, different variable
(`INSTANCE_SLUG`), different job (the backup archive name and the host scripts' files).

**The `CalendarWritersFlushTest` trap, restated because the build will fail and the fix is not
obvious.** That guard's `WRITE_NEEDLES` include the bare `Institution::current()`, so the new
controller is matched the moment it reads the row. **Add it to `ALLOW_LIST` with the reason stated
at the site**, following the precedent already in that file for `PersonController`: *"reads and
writes `institutions.name` — a display column, not a calendar one."* Do **not** call
`Calendar::flush()` here: flushing the calendar memo on a rename implies a relationship that does
not exist, and the allow-list is where a stated exception belongs.

**How to verify**

```bash
npm run build && php artisan test --filter "DepartmentProfileTest|CalendarWritersFlush|ReferenceSeeder" | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: the department can say what it is called"
```

---

### Task 9: `DepartmentSetup` — how far this department is from configured, derived and stored nowhere

Decision D. A pure projection over `exists()` queries. **No table, no column, no `app_settings`
key.** This task is the whole decision, and it is testable without a single screen.

**Files touched**

- `app/Support/Setup/DepartmentSetup.php` (new)
- `tests/Feature/Setup/DepartmentSetupTest.php` (new)

**The failing test to write first**

1. `test_a_fresh_seeded_instance_reports_levels_and_units_done_and_periods_not` — `ReferenceSeeder`
   seeds levels and units, so those are satisfied out of the box; `periods` is not.
2. `test_generating_periods_moves_that_step_to_done_with_no_write_anywhere_else` — snapshot
   `app_settings` and every row count before and after calling `steps()`, and assert nothing moved.
   **This is the test that pins "holds no state".**
3. `test_a_review_step_is_never_reported_done` — `profile`, `calendar`, `holidays`. A calendar left
   entirely on defaults is *configured* and possibly *wrong*; no query distinguishes a reviewed zero
   Hijri offset from an unreviewed one, and ST-01's own example (`hijriOffsetDays`, where the QCH
   prototype needed `−1`) is exactly that case.
4. `test_a_review_step_carries_its_current_values_in_its_summary` — otherwise "review" is an
   instruction with nothing to review.
5. `test_the_period_step_names_the_academic_year_start_as_what_blocks_it`.
6. `test_the_clinic_step_is_satisfied_when_no_unit_owns_clinics` — a department with no clinics is a
   valid department, not an unfinished one.
7. `test_an_already_configured_department_shows_complete_with_no_backfill` — the QCH case, and
   Decision D's single clearest argument: a stored counter would show a live, working department at
   step 0.
8. `test_no_step_names_a_slot_a_coverage_template_or_a_condition` — the P2/P3 items live in a
   separate `later` array with **no route and no `done` key**, so they are *stated* rather than
   rendered as two permanently unticked boxes. Finding 2 is why this assertion exists.
9. `test_the_checklist_never_becomes_the_gate` — request a `blocked_by` step's route directly and
   assert it returns **its own** refusal (or renders, if it has none), never the checklist's. A
   checklist that authorizes is a second authorization boundary.
10. `test_it_issues_a_bounded_number_of_queries` — one `exists()` per derived step, measured.

**The implementation**

`DepartmentSetup::steps(): array` returning `['steps' => [...], 'later' => [...]]`.

| key | kind | `done` derived from | `blocked_by` |
|---|---|---|---|
| `profile` | REVIEW | — | — |
| `calendar` | REVIEW | — | — |
| `levels` | REQUIRED | an active `levels` row exists | — |
| `units` | REQUIRED | an active `units` row exists | — |
| `holidays` | REVIEW | — | — |
| `periods` | REQUIRED | a `periods` row exists for the current academic year | `academic_year_start` is null |
| `roster` | REQUIRED | an active `people` row at a position other than 0 exists | no active level |
| `clinics` | OPTIONAL | an active `clinics` row exists **or** no unit has `clinic_owner` | no clinic-owning unit |
| `invitations` | REQUIRED | an `invitations` row has ever been issued | no roster person |

`later` carries slots + coverage templates and conditions, as prose, with a stage label and no
route.

Each step's `route` is the **existing** screen's route name. This class creates no screen and
duplicates no validation; `blocked_by` reads the same predicate the target's own server-side rule
already enforces, and is advisory.

Class docblock: it is derived and stores nothing; why a stored step counter was rejected (three
prior precedents by name — `Person::hasAccount()`, `InvitationStatus`, the rota's absent `status`);
what REVIEW means and why those three steps can never be honestly ticked; and that `blocked_by` is
advisory, never a gate.

**How to verify**

```bash
npm run build && php artisan test --filter DepartmentSetupTest | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: how far this department is from finished, asked rather than remembered"
```

---

### Task 10: the checklist screen at `/admin/setup` — and `Setup` is already taken

Decision E. Read finding 4 before creating a single file.

**Files touched**

- `app/Http/Controllers/Admin/DepartmentSetupController.php` (new)
- `resources/js/Pages/Admin/DepartmentSetup.vue` (new)
- `resources/js/Layouts/AppLayout.vue` (modify)
- `routes/web.php` (modify)
- `tests/Feature/Setup/DepartmentSetupScreenTest.php` (new)
- `resources/js/__tests__/DepartmentSetup.spec.js` (new, Vitest)

**The failing test to write first**

1. `test_a_structure_manager_reaches_the_wizard_and_a_resident_does_not`.
2. `test_the_route_group_is_get_only` — over the **router**. The checklist writes nothing; a POST
   appearing here would mean somebody started storing wizard state.
3. `test_the_department_wizard_does_not_collide_with_the_per_user_setup_flow` — assert `/setup` and
   `/admin/setup` resolve to **different controllers**, and that `setup.show` still renders
   `Setup.vue`. Cheap, and it fails loudly if a later refactor merges them.
4. `test_an_administrator_who_has_not_done_their_own_2fa_is_redirected_away_from_the_department_
   wizard` — `RequireSetup` is **not** modified and `/admin/setup` is **not** in its `ALLOWED`
   list. The consequence is intended: configure a PHI system only after your own 2FA is on. This
   test is what stops a future reader "fixing" it.
5. `test_every_step_links_to_a_registered_route` — resolve each `route` name over the router. A
   checklist pointing at a route that no longer exists is worse than no checklist.
6. `test_the_later_items_render_with_no_link_and_no_checkbox` (Vitest) — finding 2's requirement,
   asserted where it can actually be seen.

**The implementation**

`GET /admin/setup` → `admin.setup`, `cap:structure.manage`, inside the existing structure group or
its own — either is fine as long as the group is GET-only.

The screen renders REQUIRED steps as a checklist with a state and a link; REVIEW steps as cards
showing their current values with a "Review" link; `later` items as a plain, unlinked "arriving in
Stage 2/3" list. Mobile cards plus desktop layout, live region on the completion count, semantic
classes, no `dark:`, free text interpolated.

Nav: one entry, **first** in Administration, `can('structure.manage')`.

**How to verify**

```bash
npm run build && php artisan test --filter "DepartmentSetupScreenTest|FirstLoginSetupTest" | tail -5
npm test | tail -5
php artisan test | tail -5
```

`FirstLoginSetupTest` must stay green untouched — if it moves, the collision was not avoided.

```bash
git commit -am "feat: a path through the screens, not a second set of them"
```

---

### Task 11: `demo_rows` and `DemoLedger` — provenance as a join

Decision F's first third. **No demo row is created in this task** — the ledger lands and is proved
first, because a creator that ships before its ledger is a creator whose first batch is
unremovable.

**Files touched**

- `database/migrations/2026_08_16_120002_create_demo_rows_table.php` (new)
- `app/Models/DemoRow.php` (new)
- `app/Support/Demo/DemoLedger.php` (new)
- `tests/Feature/Demo/DemoLedgerTest.php` (new)

**The failing test to write first**

1. `test_a_row_is_recorded_once_per_table_and_id`.
2. `test_recording_the_same_row_twice_is_refused` — the UNIQUE pair, surfaced as a refusal rather
   than a database exception.
3. `test_rows_come_back_grouped_by_batch_and_in_reverse_creation_order` — removal order matters and
   this is where it is defined.
4. `test_has_reports_whether_any_demo_department_exists`.
5. `test_the_ledger_carries_no_institution_id` — a schema assertion, because D11 and because
   `units` has no such column to group by anyway (finding 16).

**The implementation**

```
demo_rows
  id
  batch_id     uuid, NOT NULL, indexed
  table_name   string(64), NOT NULL
  row_id       unsignedBigInteger, NOT NULL
  created_at   timestamp
  unique(table_name, row_id)
```

No `institution_id`. No FK on `row_id` — it points at nine different tables, exactly as
`person_levels.promotion_batch_id` carries no FK because a batch is not a row anywhere.

`DemoLedger`: `record(string $table, int $id, string $batch): void`, `batches(): list<string>`,
`rowsFor(string $batch): list<array{table:string, id:int}>` (reverse creation order), `has(): bool`,
`forgetBatch(string $batch): void`.

Docblock: the three precedents this generalises (`applied_role_defaults` as a ledger,
`handovers.legacy_source_table`+`legacy_id` as provenance-as-a-key,
`person_levels.promotion_batch_id` as a batch UUID with no FK); why a `demo` boolean column on nine
tables was rejected; why a second `institutions` row is forbidden by D11 and would collide on
`units.code` / `people.email` / `users.member_name`.

**How to verify**

```bash
npm run build && php artisan test --filter "DemoLedgerTest|InstitutionProvenance" | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: a record of which rows were never real"
```

---

### Task 12: `DemoDepartment::create()` — one transaction, one batch, every row ledgered

Decision F's second third. Creation only; removal is Task 13, and **the route comes after both**.

**Files touched**

- `app/Support/Demo/DemoDepartment.php` (new)
- `tests/Feature/Demo/DemoCreateTest.php` (new)
- `tests/Feature/Build/DemoRowsAreLedgeredTest.php` (new)
- `tests/Feature/Build/ClinicWritersAreSingularTest.php` (modify — allow-list `DemoDepartment`)
- `tests/Feature/Build/RotaWritersAreSingularTest.php` (modify — only if needed; it should NOT be)

**The failing test to write first**

`DemoCreateTest`:

1. `test_every_row_it_creates_is_in_the_ledger` — compare the ledger's count per table against the
   real row delta per table.
2. `test_it_refuses_to_run_twice_and_names_the_remedy`.
3. `test_it_runs_in_one_transaction_and_a_failure_leaves_nothing_behind` — force a failure part-way
   and assert **zero** demo rows and **zero** ledger rows.
4. `test_every_created_row_is_visibly_labelled_as_demo` — unit names, person names. ST-05's
   "clearly-labeled", and the half a ledger cannot do (a CSV export and a printed sheet do no
   ledger lookup).
5. `test_the_demo_people_are_obviously_fictional_and_use_a_reserved_domain` — CLAUDE.md's
   synthetic-fixtures rule, asserted rather than trusted.
6. `test_it_writes_one_audit_row_naming_the_batch_and_the_count_and_no_name`.
7. `test_it_uses_a_unit_code_that_is_not_reserved` — `Unit::RESERVED_CODES` is `TODAY`,
   `COMPLIANCE`, `ROWS`, enforced by a `saving` guard, and derived from the registered routes by
   `ReservedUnitCodesTest`. A demo unit that picks one throws at insert.
8. `test_it_creates_a_working_clinic_that_resolves_to_the_demo_residents` — the demo must
   demonstrate the thing P1e-1 built, not just exist.

`DemoRowsAreLedgeredTest` — the source guard, honest about being coarse: every file under
`app/Support/Demo/` that contains a creation needle must also contain `DemoLedger::record(`.
Allow-list + staleness twin + `assertSame([], $offenders, ...)`. **Plant a violation and watch it
fire.** Its docblock states plainly that it is an early warning and that
`DemoRoundTripTest` (Task 13) is the actual proof.

**The implementation**

`DemoDepartment::create(): string` — returns the batch UUID. One `DB::transaction()`.

Creates, in dependency order, ledgering each id as it goes: one demo unit (clinic-owning,
training-rotation, a `Unit::BAR_CLASSES` colour); a handful of demo people at positions 3/4/5 with
level spans; one academic year of periods **only if none exists** (never a second year alongside a
real one — check, and skip with a note in the result if the department already has periods); master
rota spans; one vacation; one clinic with attendees.

**Every write goes through the writer that already owns that table** — `ClinicWriter`,
`RotaAssignment`, `VacationBooking`, `LevelAssignment`. `DemoDepartment` joins each writer's
allow-list only where it must create a row no writer covers, and each such entry states why.
`RotaWritersAreSingularTest` should need **no** change; if it does, the implementation bypassed a
writer and that is the bug.

**No `app()->environment('production')` throw** — Decision F. `DemoSeeder`'s throw is right for
`DemoSeeder` (unmarked, unremovable) and wrong here (ledgered, removable, and ST-05's "training"
means the live instance). `DemoSeeder` is **not** modified and keeps its throw.

Audit `demo_department_create`, detail `batch=<uuid>;rows=<n>`. No names.

**How to verify**

```bash
npm run build && php artisan test --filter "DemoCreateTest|DemoRowsAreLedgered|ClinicWritersAreSingular|RotaWritersAreSingular" | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: a department to practise on"
```

---

### Task 13: `DemoDepartment::remove()` — refused whole when a real row leans on it, and proved complete

Decision F's third and hardest third. **This task ships before the creation route is exposed.**

**Files touched**

- `app/Support/Demo/DemoDepartment.php` (modify)
- `app/Support/Demo/DemoReferences.php` (new)
- `app/Console/Commands/DemoSeedCommand.php` (new)
- `app/Console/Commands/DemoRemoveCommand.php` (new)
- `tests/Feature/Demo/DemoRemoveTest.php` (new)
- `tests/Feature/Demo/DemoRoundTripTest.php` (new)

**The failing test to write first**

`DemoRemoveTest`:

1. `test_removal_deletes_every_ledgered_row_and_the_ledger_entries_with_them`.
2. `test_removal_is_refused_whole_when_a_real_handover_sits_on_a_demo_unit` — and assert **nothing
   was deleted**, not merely that it refused. A refusal that half-applied is worse than no refusal.
3. `test_removal_is_refused_when_a_real_account_is_bound_to_a_demo_person`.
4. `test_removal_is_refused_when_a_real_rota_span_or_vacation_touches_a_demo_row`.
5. `test_removal_is_refused_when_a_real_clinic_names_a_demo_person_as_an_attendee`.
6. `test_removal_is_refused_when_a_signoff_names_a_demo_person` — medico-legal evidence must not be
   reachable from a cleanup button.
7. `test_the_refusal_names_tables_and_counts_and_never_a_name` — invariant 9.
8. `test_the_refusal_is_audited_and_the_removal_is_audited_and_they_are_different_actions`.
9. `test_a_second_demo_department_may_be_created_after_a_removal` — the lifecycle closes.
10. `test_every_foreign_key_pointing_at_a_demo_table_is_in_the_reference_map` — **derived from the
    live schema by introspection, not from the constant it guards.** The precedent is
    `ReservedUnitCodesTest::test_the_reserved_list_covers_every_literal_route_segment`. This is what
    makes the map survive a migration written six months from now.

`DemoRoundTripTest` — the proof:

11. `test_removal_returns_every_table_to_its_pre_seed_row_count` — snapshot `count(*)` for every
    table from `Schema::getTableListing()` minus `EXCLUSIONS`; create; remove; `assertSame($before,
    $after)`. Deriving the table list is the point: a hand-written one goes stale on the next
    migration and the test keeps passing.
12. `test_the_exclusion_list_holds_only_append_only_or_framework_tables` — a stated reason per
    entry, and the test fails if any excluded table is one `DemoDepartment` writes. `audit_log` is
    necessarily excluded (append-only, hash-chained, and both create and remove append to it —
    correct behaviour, not leakage), alongside `migrations`, `applied_role_defaults`, `cache`,
    `cache_locks`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens` and
    `demo_rows` itself.
13. `test_a_row_created_outside_the_ledger_makes_the_round_trip_fail` — **the negative control, and
    the test that makes every other assertion here mean something.** Plant an unledgered row via a
    throwaway subclass and watch the count check name it. **Watch it fail. A round-trip test that
    has never gone red compares nothing.**

**The implementation**

`DemoDepartment::preflight(string $batch): array` — walks the ledger and counts **non-ledgered**
inbound references per `DemoReferences::MAP`, returning `(table, count)` pairs.

`DemoDepartment::remove(string $batch): array` — preflight; **refuse whole** on any reference,
throwing with the table/count list and the remedy; otherwise delete in reverse ledger order inside
one `DB::transaction()`, deleting the batch's ledger rows last.

`DemoReferences::MAP` — hand-written `referenced_table => list<[referencing_table, column]>`,
asserted against the schema by test 10.

Two thin console commands over the same methods — ST-05's *"and for development fixtures"* half.
Both confirm interactively unless `--force`. Neither carries an env guard, for Decision F's reason.

Audit `demo_department_remove` (`batch=;rows=`) and `demo_department_remove_refused`
(`batch=;blocked=<table>:<count>;…`). No names.

**How to verify**

```bash
npm run build && php artisan test --filter "DemoRemoveTest|DemoRoundTripTest" | tail -5
php artisan test | tail -5
php artisan demo:seed --force && php artisan demo:remove --force
```

```bash
git commit -am "feat: and a way to make the practice department never have happened"
```

---

### Task 14: the one click, and the confirmation before the other one

ST-05's "one-click". Preview-then-confirm, pinned to what the operator saw.

**Files touched**

- `app/Http/Controllers/Admin/DemoDepartmentController.php` (new)
- `resources/js/Pages/Admin/DemoDepartment.vue` (new)
- `resources/js/Pages/Admin/DepartmentSetup.vue` (modify — one link)
- `routes/web.php` (modify)
- `tests/Feature/Demo/DemoScreenTest.php` (new)

**The failing test to write first**

1. `test_only_a_structure_manager_reaches_it` — and a resident gets a 403, not a redirect to a
   half-rendered page.
2. `test_creating_requires_a_post_with_csrf`.
3. `test_the_create_control_is_refused_when_a_demo_already_exists`.
4. `test_removal_shows_the_preflight_before_it_asks_for_confirmation`.
5. `test_removal_is_pinned_to_what_the_operator_saw` — `App\Support\Rota\StatePin` is the **one
   definition** of such a pin (invariant 12). Change the world between preview and confirm and
   assert the confirm is refused, naming the change. Removing a department someone else has since
   written a real handover into, on a stale preview, is exactly the failure a pin exists for.
6. `test_removal_requires_the_word_typed` — the `PeriodController::destroy()` idiom
   (`confirm_academic_year`); this is a hard delete with no undo in the UI.
7. `test_both_actions_write_exactly_one_audit_row`.

**The implementation**

`POST /admin/structure/demo` → create; `POST /admin/structure/demo/preview` → preflight;
`DELETE /admin/structure/demo` → remove. All `cap:structure.manage`, all CSRF.

The screen states plainly what a demo department is, that it is clearly labelled, that removal is
refused if real work has attached to it, and what the remedy is. One link from
`DepartmentSetup.vue`.

**How to verify**

```bash
npm run build && php artisan test --filter "DemoScreenTest|StatePin" | tail -5
npm test | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: one button to practise on, one to clean up after"
```

---

### Task 15: correct the documents this invalidates

Every previous sub-plan ends here, and every one of them found the documents saying something that
was no longer true. Findings 1–5 are all document defects.

**Files touched**

- `docs/OPEN-DECISIONS.md`
- `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md` (§1.2, §6.3, §13,
  §14)
- `docs/spec/15-rulings.md`
- `docs/spec/08-foundation.md` (if Task 5 did not already complete it)
- `docs/RUNBOOK-DEPLOY.md`
- `CLAUDE.md`
- `docs/superpowers/plans/2026-08-08-p1-master-rota.md` (a dated correction note, the P1d-1
  precedent)

**What each needs**

- **`docs/OPEN-DECISIONS.md` item D is stale and says the opposite of what shipped** (finding 3).
  Move it to the decided section, dated 2026-08-09, recording Owner Decision B (WARD is the sole
  clinic owner), that `ReferenceSeeder` writes it on cold start, and that
  `2026_08_15_120002_correct_ward_clinic_owner` exists because the upgrade path did not and
  `db:seed --force` could not fix it.
- **Design §1.2** gains a row: **the clinic map is not link-public.** Munawib §5's footnote names it
  among three link-public surfaces; D7 overrides it; the map is `auth` + `cap:clinics.view`. A
  deviation recorded only in a plan is one nobody finds — that sentence is already in §1.2 about
  AC-02.
- **Design §6.3**'s `clinics`, `clinic_attendees` row gets the treatment
  `master_rota_assignments` and `vacations` now carry: shipped date; the ISO-8601 weekday; that
  `clinic_attendees` holds a **rule** and attendance is resolved at read time (Decision B), so the
  rota moving moves the clinic with no write; that `ClinicWriter` is the only writer; that neither
  table soft-deletes and there is no destroy route.
- **Design §13**'s P1 row gains P1e, in the register the P1b/P1c/P1d entries use.
- **Design §14** gains: the CL-03/CL-04 hook item (Task 6); **ST-03 is not shippable in P1 and why**
  (finding 2); `institutions.code` is still env-only and item 12 is narrowed again but not closed
  (Task 8); and `DemoSeeder`/`E2eSeeder` remain unledgered and unremovable, with consolidating them
  onto `DemoDepartment` recorded as a follow-on rather than done quietly.
- **`docs/spec/15-rulings.md`** gains a P1e block: the weekday numbering; read-time attendance; the
  clinic map's capability and its non-link-public status; the wizard holding no state; `Setup`'s
  naming collision; the demo ledger and refuse-whole removal.
- **`docs/RUNBOOK-DEPLOY.md`**: the two migrations and their verification queries; that
  `clinics.view` lands on **every seeded role** on the next `db:seed --force`, **once**, and that an
  administrator revocation is never re-asserted (`applied_role_defaults`) — the correction P1d-2's
  Task 1 amendment had to make to this same file; and the demo department's create/remove operator
  path.
- **`CLAUDE.md`**: clinics as configuration and `ClinicWriter` as the one writer; `clinics.weekday`
  is ISO-8601 and Carbon's `dayOfWeek` is not; attendance is resolved, never stored, and why a
  snapshot would need three more writers; the demo ledger and that removal is refused whole; and
  **that `Setup*` names belong to the per-user 2FA flow** — the single most likely thing for a
  future worker to get wrong.
- **The P1 plan** gets a dated correction note recording findings 1–5 as facts rather than as
  criticism, the way P1d-1's migration-ordering correction is written.

**How to verify**

```bash
npm run build && php artisan test | tail -5
grep -rn "clinics.view" docs/spec/08-foundation.md
```

Read each edited section back in full. A document correction that introduces a new wrong claim is
the failure mode here, and it has happened before.

```bash
git commit -am "docs: what P1e made true, and what it made false"
```

---

## Definition of done — P1e-2

- [x] `institutions.name` is editable and audited; `code` is not writable, asserted server-side (a
      `code` rule was PLANTED and watched turning `QCH` into `HIJACKED`, because a `disabled`
      attribute is not a validation rule); a rename survives `db:seed --force` (watched failing with
      `ReferenceSeeder`'s create-only guard removed); `CalendarWritersFlushTest`'s allow-list carries
      the new controller with its reason at the site, and that entry was watched EARNING its place.
- [x] `DepartmentSetup::steps()` is derived, stores nothing (`test_asking_writes_nothing_anywhere`,
      watched failing against a planted `app_settings` counter), costs an EXACT measured ten
      queries, reports an already-configured department as complete with no backfill, and names no
      slot, coverage template or condition among its steps.
- [x] `/admin/setup` is `cap:structure.manage`, GET-only over the ROUTER by URI prefix (not by
      capability — `structure.manage` legitimately guards every structure screen's writes), and does
      **not** collide with `/setup`; `FirstLoginSetupTest` is green untouched; `RequireSetup` is
      unmodified, and the plant that adds `admin/setup` to its `ALLOWED` list was watched going red.
- [x] `demo_rows` exists with no `institution_id` (asserted by comparing the WHOLE column list, not
      one absence); `DemoDepartment` is its only writer, and a SIXTH writer shape
      (`Model::query()->create(`) was found by mutating the writer rather than by reading the needles.
- [x] `DemoDepartment::create()` ledgers every row it creates (watched failing against a mutation
      that dropped one `record()` call), runs in one transaction, refuses a second run, labels every
      row visibly, mints **no account at all**, and writes one audit row with no names.
- [x] `DemoDepartment::remove()` refuses **whole** when any real row references a demo row, naming
      tables and counts only — and its pre-flight's *"and not itself ledgered"* clause is pinned by
      its own twin, without which every refusal test still passes and no demo is ever removable.
- [x] `DemoRoundTripTest` derives its table list from the schema, justifies every exclusion three
      ways, and its negative control **was watched failing** — twice, with two different mutations,
      which is what surfaced the child/parent asymmetry the plan did not anticipate.
- [x] The demo screen is preview-then-confirm, pinned with `StatePin` on **both** actions, and
      requires the word typed. Both pins were watched failing by mutation: with `assertPinned()`
      removed, the two pin tests failed with *"Session is missing expected key [errors]"* — the
      operations SUCCEEDED silently, which is the failure they exist for.
- [x] Every document in Task 15 is corrected and re-read. Two were found **already correct** and
      left alone: `docs/spec/08-foundation.md` (Task 5 completed both halves) and design §14 item 22.
- [x] `npm run build`, `php artisan test` (**1643**), `npm test` (**232**) and `npm run test:e2e`
      (**24**) all green on a **clean tree**, measured rather than arithmetic.

---

## Owner decisions needed

None blocks Task 1. Each blocks a specific later task and each has a stated default.

1. **Does the one-click demo department exist in PRODUCTION, or in development only?**
   *Blocks:* Task 14. *Recommended default:* **production, behind `cap:structure.manage`, with the
   removal path shipped first.** ST-05 says *"for training"*, and training happens on the live
   instance; a demo that only exists in development is ST-05 half-built. This is a deliberate
   departure from `DemoSeeder`/`E2eSeeder`, which throw in production and should — they are
   unmarked and unremovable, which is precisely what `DemoDepartment` is not. *If answered "dev
   only":* Task 14 becomes an artisan-only task and the screen is dropped; nothing else changes.

2. **Which units own clinics?** *Recommended default:* **already answered — no new decision needed,
   only a confirmation.** Owner Decision B (P1b, 2026-08-09) ruled WARD the sole clinic owner;
   `ReferenceSeeder` and `2026_08_15_120002_correct_ward_clinic_owner` both implement it.
   `docs/OPEN-DECISIONS.md` item D still says the opposite and Task 15 corrects it. *Confirm only
   if the department has since decided otherwise* — a second clinic-owning unit is a checkbox on
   Admin → Structure → Units and needs no code.

3. **May `institutions.name` be edited from a screen, and may `code`?** *Blocks:* Task 8.
   *Recommended default:* **`name` yes, `code` no.** `name` is a display string nothing keys on;
   `code` is `ReferenceSeeder`'s `firstOrNew` key, so re-coding a live institution makes the next
   `db:seed --force` create a *second* institution row. The screen states that plainly. *If
   answered "neither":* Task 8 shrinks to a REVIEW card on the checklist stating that the name is
   env-managed, and design §14 item 12 stays exactly as open as it is today.

4. **Does ST-01's "branding" include a logo upload?** *Blocks:* Task 8's scope. *Recommended
   default:* **no — display name only.** A file upload into a system holding children's PHI is its
   own security decision (storage location, content-type validation, path traversal, and the size
   it adds to every backup archive) and must not arrive as a side effect of a wizard step.
   Additive later.

5. **Is the clinic map link-public for viewers?** *Blocks:* Task 5. *Recommended default:*
   **no — D7 holds, unchanged.** Munawib §5's footnote explicitly contemplates it, which is why
   this is asked rather than assumed. *Cost of no:* a consultant who wants to check clinic times
   signs in. *Cost of yes:* the first anonymous route in this codebase, on a system holding
   children's records, reversing a decision taken in P0 and reasserted in P1d.

6. **What capability gates the clinic surfaces?** *Blocks:* Tasks 4 and 5. *Recommended default:*
   **one new key, `clinics.view`, seeded to every position for the map; management on the existing
   `structure.manage`.** A clinic's whole payload is a unit, a weekday and a session — department
   structure, and the screen sits beside Units and Levels. *Alternative considered and rejected as
   premature:* a `clinics.manage` mirroring `rota.manage`, which buys letting a Scheduler edit
   clinics without structure rights. Purely additive the day somebody asks.

7. **Does per-clinic refinement include an EXCLUDE rule?** *Blocks:* Task 2. *Recommended default:*
   **no.** CL-02 names two refinement axes and both are include-shaped. An exclude rule introduces
   a precedence question nobody has answered — "named person X" plus "exclude person X" is a legal
   state with two defensible readings — and every wrong answer fails silently. Additive if asked
   for.

8. **May the demo department create an academic year of periods when the department already has
   one?** *Blocks:* Task 12. *Recommended default:* **no — skip periods and say so in the result.**
   A second academic year sitting beside the real one is confusing on the rota's year picker, and
   `periods_year_position_unique` makes a collision an insert failure rather than a graceful skip.
   The demo rota then uses the department's real periods, which is more realistic training anyway.

---

## Stage 1 acceptance (§35), after P1e

> *Accepted:* the pilot's real master rota and clinics live; residents claimed accounts;
> availability summaries match reality.

**P1e completes the second clause and is the last plan in Stage 1.** The other three were met by
P1d-1/P1d-2 (master rota, availability summaries) and P1c-1/P1c-2 (claimed accounts). What P1e adds
beyond CL-01/02/05 is the two things that make the criterion *reachable by a department rather than
by a developer*: a path through eleven configuration screens that does not require knowing which to
open first, and somewhere to be trained that can afterwards be proved to have left nothing behind.

**What P1e does NOT make true, stated so the gate is not read as wider than it is:** CL-03's clinic
conditions, CL-04's personal schedules and coverage board, MR-04's on-call eligibility, and ST-03's
launch presets are all unbuilt, and three of the four now have a guard asserting they are unbuilt
rather than merely absent — ST-03 being the exception, because it has no module to guard.

**2026-08-11, on completion — what "met" means, honestly.** All fifteen tasks shipped and P1 is
complete: P1a's calendar, P1b's structure, P1c's people and accounts, P1d's master rota, P1e's
clinics, wizard and demo department. **What §35's four clauses now have is the CAPABILITY, not the
event.** *"The pilot's **real** master rota and clinics live"* is a statement about QCH's data, and
the department has entered none of it yet — what changed is that the system can hold it, on screens
an administrator reaches without knowing which of eleven pages to open first, with somewhere to be
trained that can afterwards be proved to have left nothing behind. **Declaring the criterion
accepted is the owner's call once that data exists, and it is not a developer's to make after a
merge.** Recorded here in the plan that completes the stage, because "P1 is complete" and "Stage 1
is accepted" are different claims and only the first is ours.

One honest scoping note against this document's own claim that P1e *"completes the second clause"*:
it completes the **clinics** half of that clause in the same sense the rota half was completed by
P1d — the tables, the writers, the screens and the read surfaces exist and are proved. Neither
slice put a single real QCH row anywhere.

---

## Next plan

**P2 — the conditions engine and `packages/engine`.** Three P1 decisions come due at once there:
P1a's Decision A deferred the TypeScript calendar mirror to P2, and
`tests/fixtures/calendar/golden.json` — which P1e Task 1 extends with `weekday_columns` — is the
contract it must satisfy; CL-03 is the first condition type that reads a P1e table, and it needs
`clinics.weekday` client-side, which is why the weekday vocabulary went into the fixture rather than
staying server-only; and design §4.1's *"one definition, three consumers"* is the property that must
hold from the first condition, not be restored later.

Two P1e outputs P2 must respect: **`ClinicRoster` resolves and never stores**, so a condition asking
"who is at this clinic on this date" asks it rather than reading a cached list; and **the clinic
module is guarded against becoming a conditions engine** (Task 6), so P2's first condition lives in
the conditions module and reads clinics, never the other way round.
