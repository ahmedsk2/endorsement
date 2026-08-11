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

- [ ] `clinics` and `clinic_attendees` exist; neither soft-deletes; no index is led by
      `institution_id`; `InstitutionProvenanceTest` is green untouched.
- [ ] `ClinicWriter` is the only writer of both, proved by a guard that was watched failing on a
      planted violation and carries a staleness twin.
- [ ] `clinics.weekday` is ISO-8601, documented as such in three places, and no Carbon `dayOfWeek`
      appears anywhere near it.
- [ ] `Calendar::weekdayColumns()` is the only source of the department's week order;
      `CalendarIsTheOnlyConverterTest` is green with **no allow-list change**;
      `tests/fixtures/calendar/golden.json` carries the new block.
- [ ] `ClinicRoster` resolves at read time, issues a bounded and measured number of queries, and
      returns `contactFree()` projections in which `email` and `phone` are **absent**.
- [ ] `/admin/structure/clinics` is `cap:structure.manage`, has **no destroy route** (asserted over
      the router), and audits by id.
- [ ] `/clinics` is `cap:clinics.view`, seeded to every position, asserted **GET-only over the
      router**, and carries no contact field for any viewer.
- [ ] `clinics.view` appears in `docs/spec/08-foundation.md`.
- [ ] CL-03's and CL-04's absence is guarded by two comment-stripped scans, each watched failing,
      with the stripper pinned in both directions.
- [ ] `npm run build`, `php artisan test`, `npm test` and `npm run test:e2e` all green, on a **clean
      tree**, with the counts recorded here as measured numbers.

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

- [ ] `institutions.name` is editable and audited; `code` is not writable, asserted server-side;
      a rename survives `db:seed --force`; `CalendarWritersFlushTest`'s allow-list carries the new
      controller with its reason stated at the site.
- [ ] `DepartmentSetup::steps()` is derived, stores nothing, is query-bounded, reports an
      already-configured department as complete with no backfill, and names no slot, coverage
      template or condition among its steps.
- [ ] `/admin/setup` is `cap:structure.manage`, GET-only, and does **not** collide with `/setup`;
      `FirstLoginSetupTest` is green untouched; `RequireSetup` is unmodified.
- [ ] `demo_rows` exists with no `institution_id`; `DemoDepartment` is its only writer.
- [ ] `DemoDepartment::create()` ledgers every row it creates, runs in one transaction, refuses a
      second run, labels every row visibly, and writes one audit row with no names.
- [ ] `DemoDepartment::remove()` refuses **whole** when any real row references a demo row, naming
      tables and counts only.
- [ ] `DemoRoundTripTest` derives its table list from the schema, justifies every exclusion, and its
      negative control **was watched failing**.
- [ ] The demo screen is preview-then-confirm, pinned with `StatePin`, and requires the word typed.
- [ ] Every document in Task 15 is corrected and re-read.
- [ ] `npm run build`, `php artisan test`, `npm test` and `npm run test:e2e` all green on a **clean
      tree**, with measured counts recorded.

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
rather than merely absent.

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
