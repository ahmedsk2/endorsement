> ## OWNER DECISIONS, 2026-08-09 — binding, and one of them changes sequencing
>
> **1. `rota.manage` defaults to Administrator AND Chief Resident.** Chief Resident is
> Munawib's Scheduler persona and owns the master rota. Seed the default for **both** positions,
> and update `AccessControlParityTest`'s expected sets accordingly — that test hardcodes each
> role's effective capabilities and will go red, which is the correct kind of red.
>
> **2. `rota.view` is seeded for every authenticated member**, not Administrator-only. MR-05's
> point is that a resident can see which unit they rotate through next.
>
> **THIS MAKES TASK 7 A HARD PREREQUISITE OF TASK 8, NOT A TIDY-UP.** `PersonPresenter::one()`
> currently emits `email` unconditionally; its own docblock names this grid as the case where
> that stops being a no-op. With `rota.view` held by every member, shipping the grid before the
> projection is fixed hands **every resident every colleague's email address**. Task 7 lands
> first, and no task may render a person on a rota surface until it has. If you find yourself
> building the grid and Task 7 is not done, stop.
>
> **3. A `week`-granularity vacation whose dates are not week-aligned SNAPS** to the full
> department week containing them — exactly as the on-screen week picker does — and the import
> preview **reports the adjustment**. One rule for typing and importing, never silent. (P1d-2
> scope; recorded here so it is not re-litigated there.)

> ## OWNER DECISIONS, 2026-08-09 — READER'S INDEX ONLY
>
> **Every decision below is already folded into the task text it governs.** This block is an index,
> not a patch applied on top of tasks that contradict it. **Three times** in this programme a plan
> carried decisions in a prepended block and left the task text below unchanged, and three times an
> implementer was instructed by task text to build the thing the decision had forbidden (P1b Task 1's
> `clinic_owner` seed; P1b Tasks 6/7/8's `terminal` column; P1c Task 12's `clean.csv` "8 creates").
> All three were caught only because the implementer read the block first. **If any task text below
> appears to disagree with this index, the task text is the bug — but it should not, because it was
> written after these decisions, not before.**
>
> **1. MR-04 IS NOT IN P1d.** *"The master rota drives on-call eligibility automatically"* is Stage 2
> (§35), and it has nothing to drive: slots, call rosters, `off_roster` unit flags and per-person
> include/exclude overrides do not exist and are P3 (design §13). P1d ships the rota's data and its
> screens and **records the hook**. Nothing in this plan adds an eligibility column, an `off_roster`
> flag, an "included in call" toggle, or a derivation of any kind. → **every task; asserted by
> Task 12.**
>
> **2. A master-rota assignment is ALWAYS a date-bounded span.** One table, `starts_on`/`ends_on`
> **NOT NULL** on every row, both bounds inclusive. A whole-period assignment is the degenerate
> split: one row whose bounds equal its period's. There is no nullable date range meaning "the whole
> period", and no parent/child span table. → **Tasks 4, 5, 9. Reasoning in Decision B.**
>
> **3. Overlaps are REFUSED; gaps are ALLOWED and made visible.** Two spans covering one day for one
> person is never a real state — it is one person on two units, which the grid cannot render and
> MR-04's future call roster cannot resolve, the same reasoning `Period::booted()` already refuses
> overlapping periods with. A gap **is** a real state (a mid-block joiner, a partly-planned year), so
> it is allowed, rendered as unassigned days in the cell, and counted by MR-07's summary — never
> silently invisible. → **Tasks 4, 5, 9.**
>
> **4. Vacations are a SEPARATE table with NO `period_id`.** A vacation crosses period boundaries, is
> not a rotation, and must survive a department regenerating or switching its period system. It is
> keyed on `person_id` plus a date range; the period(s) it touches are derived at read time. →
> **Task 6. Reasoning in Decision C.**
>
> **5. MR-05's publish view is a logged-in, `cap:rota.view`-gated screen. It is NOT a token link and
> NOT a state machine.** D7 and design §9.1 permit no unauthenticated route anywhere in this platform,
> and tokenized share links are **P3**, not Stage 1. Separately: Munawib AR-05 gives
> `masterRota/{periodId}` **no status field at all** (unlike `schedules/{periodId}`, which has one), so
> "publishable" means "residents can read it", not "there is a draft the chief must promote". §18's
> publish/version/archive machinery is Stage 2 and lands once, for the rota and the call schedule
> together. → **Tasks 1 and 12; the read screen itself is P1d-2. Reasoning in Decision D.**
>
> **6. Two new capabilities: `rota.view` (all seeded roles) and `rota.manage` (Administrator-only).**
> Munawib §5's matrix gives "Manage master rota & clinics" to Scheduler **and** Admin; this codebase
> has no Scheduler role, and the closest fit (Chief Resident, position 5) today holds exactly one
> scoped admin power. `rota.manage` therefore defaults **Administrator-only**, grantable per role from
> the Access Control screen with no code change — the same shape `structure.manage` and
> `people.manage` shipped in. Listed as an open owner decision with that stated default. →
> **Task 1.**
>
> **7. `PersonPresenter::one()` stops emitting `email` unconditionally, in P1d.** Its own docblock
> names this plan as the case where the current behaviour stops being a no-op: today every caller sits
> behind `cap:people.manage`, which is also what grants `viewContact`; a rota grid behind
> `cap:rota.view` is the first caller with a narrower capability, and shipping it unchanged hands
> every resident every colleague's email address. → **Task 7, before any grid props exist.**
>
> **8. MR-06's import is CSV only** (standing decision, P1c Decision E — no spreadsheet package), its
> export goes through `App\Support\Csv` (`CsvIsTheOnlyReaderWriterTest`), and its fixtures are
> **synthetic, always**. The export carries **no contact field** — a person is identified by
> `short_name`, the app-wide handle, never by email. → **P1d-2.**
>
> **9. P1d splits into P1d-1 (this document, executable) and P1d-2 (scoped).** Reasoning in
> [The split](#the-split-p1d-1-this-document-and-p1d-2).

# P1d-1 — The Master Rota Grid

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Munawib Stage 1's master rota — the grid a department actually plans its year on. P1a built
the periods that are its columns; P1b built the units that fill its cells and the screens that
generate those columns; P1c built the people that are its rows and the levels they group by.
**Nothing has ever joined them.** `master_rota_assignments` and `vacations` are named in design §6.3
and exist nowhere.

**Binding requirements:** MR-01 (as consumed — the columns come from `periods`, both systems, and no
code path assumes a calendar month), MR-02 (the grid and its date-bounded split sub-assignments),
MR-03 (vacations at week **or** exact-date granularity) — plus ST-06 held (every day-boundary
computation still goes through `App\Support\Calendar`), D7 held (no unauthenticated route), D9 held
(`SignoffPickers`' per-field parity survives everything done here), and D11 held (`institution_id` is
provenance, never a filter, never part of a key).

**MR-05, MR-06 and MR-07 are P1d-2** and are scoped at the end of this document.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 3 + Vue 3, PHPUnit 12 (SQLite in-memory,
`APP_TIMEZONE=Asia/Riyadh`), Vitest, Playwright, Tailwind 4 via `@theme`, MySQL 8.4 in production.

**Baseline this plan was written against:** branch `feat/p1d-master-rota` at `6b9b9be` (the
ops-rehearsal-defects merge). Measured, not assumed, by running the commands below via **Bash**:

```bash
npm run build     # ✓ built in 1.52s
php artisan test  # {"tool":"phpunit","result":"passed","tests":1063,"passed":1063,"assertions":4652,"duration_ms":139019}
npm test          # Test Files 11 passed (11) / Tests 113 passed (113)
```

**`php artisan test` takes ~2 minutes 20 seconds.** Twelve tasks each ending in a full run is roughly
half an hour of pure test time; budget for it and do not skip the run to save it. Filter verbose
output (`| tail -5`), and on a failure re-run only the failing filter
(`php artisan test --filter <TestName> | head -30`).

**Scope of P1d-1 specifically:** two new capabilities, two additive migrations (two new tables), two
new models with `saving` guards, two new one-writer support classes and the source-level guard that
keeps them the only ones, one new `App\Support\Calendar` capability (the department's week), one new
set-wise level resolver, one security fix to `PersonPresenter`, one hardening of a delete path this
plan makes dangerous, one new admin screen, and one e2e journey.

**What P1d-1 is NOT.** It builds no on-call eligibility of any kind (owner decision 1). It touches no
`handovers`, `handover_revisions` or `handover_signoffs` row — design §9.2's rule that Rota models and
queries never reference a PHI model holds, and Task 12 asserts it. It adds no unauthenticated route
(D7). It does not build the resident-facing publish view, the availability summaries, fill-down/across,
copy-period, or CSV import/export — those are P1d-2. It adds no `terminal` level, no
`Level::nextAfter()`, and no inference of "one step up" anywhere (P1b Owner Decision A, pinned by
`LevelLadderTest::test_there_is_no_terminal_column_and_no_next_after_inference`).

---

## Findings

Read these before any task. Each was verified against the tree at `6b9b9be` by running or grepping,
not inferred from a document.

1. **`PersonPresenter::one()` emits `email` to every viewer, and its own docblock names this plan as
   the moment that becomes a leak.** Verbatim, `app/Support/PersonPresenter.php:43-53`: *"It is safe
   TODAY only because every current caller … sits behind `cap:people.manage` … It stops being a no-op
   the day a consumer with a NARROWER capability calls this — **P1d's rota grid is the named future
   case** — at which point `email` needs `$viewer->can('viewContact', $person)` too."* A grid behind
   `cap:rota.view`, held by every resident, is exactly that consumer. **This is a security finding,
   not a style point**, and Task 7 fixes it before Task 8 builds any props. Both existing callers keep
   seeing `email`: `PersonPolicy::viewContact()` (`app/Policies/PersonPolicy.php:30-33`) returns true
   for any holder of `people.manage`, which is precisely what gates `PersonController` and
   `RosterImportController`.

2. **`PeriodController::destroy()` hard-deletes an academic year's periods, and its own docblock says
   P1d is what makes that unsafe.** Verbatim, `app/Http/Controllers/Admin/PeriodController.php:151-156`:
   *"P1d: refuse when `master_rota_assignments` references any of these periods. No such table exists
   yet — this is the hook a later plan fills in … (`PeriodGenerationScreenTest::test_delete_succeeds_
   today_with_no_assignment_table_to_check` pins that it succeeds NOW and will need a new red test the
   day that table lands)."* That test exists at `tests/Feature/Admin/PeriodGenerationScreenTest.php:313`.
   **Task 4 therefore creates the table and hardens the delete in ONE task** — the moment the table
   exists and the delete is unhardened, one typed academic year silently destroys a department's whole
   planned rota, and there is no soft delete to recover it from.

3. **P1b Decision D's unlock instruction becomes a lie the same moment.** `CalendarSettingsRequest`
   (`app/Http/Requests/Admin/CalendarSettingsRequest.php:96-110`) hard-locks `period_type` and
   `academic_year_start` once any `periods` row exists and tells the administrator *"Delete this
   academic year's periods first (Structure → Periods), then change this."* Once assignments reference
   those periods that instruction routes the administrator straight at finding 2's data-loss path. The
   message changes in the same task.

4. **The migration slots the P1 plan allocated to P1d are already consumed.** The P1 plan
   (`docs/superpowers/plans/2026-08-08-p1-master-rota.md`, "Migration ordering") assigns P1d
   `2026_08_15_*`. Both `2026_08_15_120001_widen_rich_text_handover_columns.php` and
   `2026_08_15_120002_correct_ward_clinic_owner.php` landed from the unrelated MySQL-defects branch.
   P1d-1 therefore uses `2026_08_15_120003` and `2026_08_15_120004`, which still sorts strictly after
   P1c's `2026_08_14_*` and leaves P1e's `2026_08_16_*` untouched.

5. **`Person::levelAt()` resolves ONE date, and `Person::levelsAt()` resolves one date for a set.
   Neither answers the grid's question.** A rota grid needs the level held by each person **in each of
   thirteen periods** — thirteen dates. Thirteen `levelsAt()` calls is thirteen queries, and calling
   `levelAt()` per cell is 780. The predicate itself is fine and must not be re-written:
   `Person::inForceOn()` (`app/Models/Person.php:131-137`) is `private static`, table-qualified, both
   bounds inclusive, and is deliberately the single definition shared by `levelAt()` and `levelsAt()`
   (its own docblock: *"a predicate written twice is two predicates that drift"*). Task 3 adds a
   **range** fetch that reuses it, plus an in-memory resolver, plus a parity test that proves the
   in-memory resolver and `levelAt()` agree — because an in-memory resolver is by construction a second
   expression of the rule in a different language, and the only honest mitigation is a matrix test, not
   a comment.

6. **"Week" is undefined in this system, and MR-03 and MR-07 both require it.** MR-03 wants vacations
   at *week* granularity; MR-07 wants *"who is on vacation each week"*. The department's weekend is
   configuration (`institutions.weekend_days`, ISO weekday integers, default `[5,6]` = Friday and
   Saturday), so the day a week starts on is derived, not constant. Nothing in
   `App\Support\Calendar` answers it today: `grep -n "public static function" app/Support/Calendar.php`
   returns `weekendDays()` and `isWeekend()` and nothing week-bounded. Task 2 adds it **inside
   `Calendar`** — `CalendarIsTheOnlyConverterTest` makes any other home a build failure.

7. **The client-side date-math guard is ONE test with TEN needles and NO allow-list.**
   `tests/Feature/Build/CalendarIsTheOnlyConverterTest::test_no_client_side_date_construction_appears_
   under_resources_js` scans **every file** under `resources/js` (no extension filter) for
   `new Date(`, `toISOString(`, `toLocaleString(`, `toLocaleDateString(`, `toLocaleTimeString(`,
   `Date.now(`, `Date.parse(`, `Date.UTC(`, `Intl.DateTimeFormat`, `getTimezoneOffset(`. There is no
   allow-list constant and no skip branch — adding one means editing the guard. A rota grid is a screen
   full of dates: **every date it renders arrives as a server-formatted string**, and every date it
   *sends* is a string the server produced (a period's own bounds, or a `<input type="date">` value,
   which is already `Y-m-d` text and requires no client arithmetic to produce).

8. **`ContactFieldsAreProjectedOnceTest` trips on the bare single-quoted strings `'phone'`, `'notes'`
   and `'constraints'` anywhere under `app/`, `database/` (except `database/factories/`) or `routes/`.**
   Its needle list is `['->phone', '->notes', "'phone'", "'notes'", '->constraints', "'constraints'"]`
   — a plain substring scan that cannot tell a column declaration from a read (P1c Task 2's amendment
   recorded this class of false positive, and P1c Task 9's recorded it recurring). **Neither new table
   in this plan declares a notes column**, deliberately: Munawib's MR-* requirements ask for none, and
   inventing one would buy a guard exception for a column no requirement wants.

9. **`InstitutionProvenanceTest`'s key pattern has no allow-list at all.**
   `tests/Feature/Identity/InstitutionProvenanceTest.php:172` runs
   `/(index|unique)\(\s*\[[^\]]*['"]institution_id/` over `app/`, `database/` and `routes/` with **no**
   exception mechanism (unlike its `where*()` pattern, which allow-lists exactly one backfill
   migration). Both new migrations carry `institution_id` for provenance exactly as `periods` does —
   `$table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete()`, which the regex
   does not match — and **neither may name it inside an `index([...])` or `unique([...])` array.**

10. **`Period::booted()` refuses overlapping periods within an academic year, which makes one of this
    plan's invariants free.** `app/Models/Period.php:73-90`. Because no two periods overlap, and
    because Task 4's guard forces every assignment inside its own period, **one person's spans can
    never overlap across two different periods** — the per-period overlap check is sufficient, and a
    global per-person check would be a second, weaker definition of the same fact. Note also its
    comment, which applies verbatim to both new tables: the `date` cast round-trips from MySQL as
    `'Y-m-d 00:00:00'`, so a comparison must be `whereDate(...)`, never equality against a plain
    `Y-m-d` string.

11. **`AccessControlParityTest::expectedByPosition()` carries a hardcoded Administrator-default
    capability list, and it is not mentioned in any task's Files list until it goes red.** P1b Task 2's
    amendment records this exactly: adding a key to `AccessControlSeeder::ROLE_DEFAULTS[0]` fails
    `test_each_role_effective_set_matches_the_documented_server_gates` and `test_seeder_is_idempotent`
    until `$adminOnly` is extended. Task 1 adds **two** keys, and one of them (`rota.view`) goes to
    **every** seeded position, not just position 0 — so more than the `$adminOnly` array moves. Read
    the whole file before running.

12. **`docs/spec/08-foundation.md`'s capability catalog was found stale twice** (P1b Task 2, P1c
    Task 1). It currently reads *"Capability catalog (complete): … `structure.manage`, `people.manage`."*
    Task 1 extends both the catalog sentence **and** the role-defaults sentence in the same commit as
    the seeder change. Not "in P1d somewhere" — in that commit.

13. **The `SaveStatus` machine and its `preserveState: true` are the difference between a working
    per-cell indicator and a silently broken one.** `resources/js/Pages/Endorsement/Sheet.vue:91-127`
    carries the mechanism and, at `:120-122`, the comment recording that `preserveState` was once
    `false` and Inertia's remount wiped the indicator before it could be seen. Task 8 lifts it
    verbatim, keyed `personId:periodId`.

14. **`Sheet.vue:82-84` computes `desktopColumnCount` rather than hardcoding a colspan**, because
    `Users.vue:364` once had `colspan="7"` on an eight-column table (P1 finding 13). A rota grid's
    column count is `1 + periods.length` and varies by academic year and period system; it is computed,
    never written down.

15. **Route-model binding excludes soft-deleted rows unless a route opts in.** P1c Task 7's follow-up
    audited every soft-deletable binding in the app and decided each one explicitly. `Person` uses
    `SoftDeletes`; **neither** new model in this plan does (Decision E), so no `{assignment}` or
    `{vacation}` binding needs `->withTrashed()`. The grid's own person list uses
    `Person::query()->active()` and therefore never shows a retired person a cell to fill — stated so
    a reader does not go looking for the opt-in.

16. **`npm run build` must precede `php artisan test`, or two build guards silently skip rather than
    pass.** `CompiledCssIsLightOnlyTest` skips both its dark-scheme grep and its print-CSS check when
    `public/build/assets/*.css` is empty. Every verification step below builds first.

---

## Where the design doc, the P1 plan and the Munawib spec are wrong about this codebase

| Claim | Reality |
|---|---|
| A widely-circulated copy of CLAUDE.md's "Domain vocabulary" section reads *"Two known exceptions, pending: `resources/js/Layouts/AppLayout.vue` (sidebar nav) and `resources/css/app.css` (hue classes) still hardcode the four units."* | **That text is NOT in the repository and has not been since P1b Task 3.** `grep -n "AppLayout.vue" CLAUDE.md` returns exactly one hit, line 210, inside the sentence recording that both exceptions *"were closed by P1b Task 3"*. `AppLayout.vue:65` reads `page.props.nav?.units` from `Unit::navList()` (`app/Models/Unit.php:214-224`) and `app.css:121-132` declares eight hues plus `channel-bar-slate` as `Unit::DEFAULT_BAR_CLASS`. **No edit is needed and Task 12 must not make one** — this row exists because the stale wording survives in cached/embedded copies of CLAUDE.md that agents are routinely handed, and an implementer who "fixes" the on-disk file against that memory would be reverting P1b's own correction. **Verify against the file, not against a recollection of it.** |
| Munawib AR-05: `masterRota/{periodId} { assignments: { [personId]: {unitId, spans?:[…]} } }` | A Firestore document shape whose whole-document write is the last-write-wins that Munawib's **own** SC-03 forbids. The relational equivalent (design §6.3, and the P1 plan states it outright) is one row per span. P1d-1 goes one step further than the P1 plan's wording — see **Decision B**: there is no "one row per person per period **with** date-bounded split rows", because that is two row shapes; there is one row shape, always bounded. |
| Munawib AR-05: `vacations/{vacId} { personId, from, to, granularity:'week'|'date', source }` | Carried over faithfully **except** that the spec never says what a "week" is, while §8 ST-01 makes weekend days department configuration. Two departments with different weekends would snap the same vacation to different dates. P1d-1 resolves it (**Task 2**) by deriving the week start from `weekend_days` and records the resolution in the design doc's override table (Task 12). |
| Munawib MR-05: the rota is *"publishable to residents"*; Munawib §5 permits *"link-public"* viewer access | D7 and design §9.1: no unauthenticated route exists anywhere in this platform, and tokenized share links are **P3**. MR-05 ships as a `cap:rota.view` screen. See **Decision D** for the second half of this — that AR-05 gives `masterRota` no status field, so "publishable" is not a draft/publish state machine either. |
| Munawib §5's capability matrix: *"Manage master rota & clinics — Scheduler ✓, Admin ✓"* | This codebase has no Scheduler role. Positions are 0 Admin, 2 Charge Nurse, 3 Consultant, 4 Resident, 5 Chief Resident. `rota.manage` defaults Administrator-only and is grantable to position 5 from the Access Control screen with no code change. Listed under [Owner decisions needed](#owner-decisions-needed) with that default. |
| Design §6.3 lists `vacations` in the "Rota tables" grid with no note on its keying | It is the **only** rota table in that list that is deliberately period-independent, and the reason matters enough to write down (**Decision C**). Task 12 adds the note. |
| The P1 plan's "Migration ordering": *"P1d `2026_08_15_*`"* | Finding 4 — both `2026_08_15_1200{01,02}` slots are taken by an unrelated hotfix branch. P1d-1 uses `120003`/`120004`. |
| Munawib §35 Stage 1 acceptance: *"the pilot's real master rota and clinics live"* | Nothing in this repository ever contains the pilot's real rota. Fixtures are **synthetic, always** (P1c owner decision 3, restated for P1d-2's importer). "Live" means the owner loads it into a running instance, and the acceptance is observed there, not asserted by a test. |

---

## Decision A: one new surface, two new capabilities, and where the rota lives in the URL space

`/admin/rota` is the **editor** (`cap:rota.manage`). `/rota` is P1d-2's **read view**
(`cap:rota.view`). The split follows the division this codebase already draws: `/endorsement/*` is
clinical work every account does, `/admin/*` is management. A resident reading the rota is not doing
management, and a screen under `/admin` reads to a resident as a screen they should not be on.

Neither path sits under `/endorsement/`, so `Unit::RESERVED_CODES` is untouched.
`ReservedUnitCodesTest::test_the_reserved_list_covers_every_literal_route_segment` derives its expected
set from routes whose URI starts with `endorsement/` and asserts it `assertEqualsCanonicalizing` against
the constant — **bidirectionally**, so adding a stale entry fails too. Do not add `ROTA` to it.

`rota.view` defaults to **every seeded position** (0, 2, 3, 4, 5). Position 1 (Nurse) is RETIRED and
gets no defaults, ever. `rota.manage` defaults to **position 0 alone**.

## Decision B: the assignment shape — one table, every row bounded

MR-02: *"one unit per person per period; split periods supported via date-bounded sub-assignments."*

Three shapes were on the table.

- **A nullable date range on every row** (`NULL` = the whole period, non-null = a split). Rejected: it
  gives "this person is on PICU for Block 11" two valid representations — one row with nulls, or one
  row with the period's exact dates — and every reader must handle both forever. This codebase already
  has a docblock about why that class of ambiguity is a bug: `PersonPresenter`'s *"A null phone and a
  withheld phone are different facts, and a consumer given the same shape for both will eventually
  render one as the other."*
- **A parent `master_rota_assignments` row plus a child `master_rota_spans` table.** Rejected: the
  parent would have to carry a `unit_id`, and for a split period there is no single correct value for
  it. A `UNIQUE(person_id, period_id)` on the parent would enforce MR-02's "one unit per person per
  period" beautifully and forbid MR-02's splits in the same stroke.
- **One table, `starts_on`/`ends_on` NOT NULL on every row.** Chosen. A whole-period assignment is the
  degenerate split: exactly one row whose bounds equal its period's. One representation, one reader,
  one query: "which unit is this person on, on this date" is a single `whereDate` pair with no branch,
  and it reuses the both-bounds-inclusive idiom `Person::levelAt()` and `Period::contains()` already
  share.

MR-02's "one unit per person per period" survives as an **invariant on the set**, not a unique index:
*the rows for one (person, period) do not overlap, and each lies wholly within its period.* Task 4
enforces both in `MasterRotaAssignment::booted()`; Task 5's writer is the only thing that inserts.

**Tiling is not required.** MR-02 does not ask for it, and refusing an untiled period would make the
grid unfillable for a person who joins mid-block or a year that is only half planned. A gap is a real
state and is rendered as one — the cell shows its covered ranges plus an explicit unassigned-days
count, and MR-07's summary (P1d-2) counts unassigned days so a hole is visible rather than absent. An
**overlap** is never a real state and is refused outright.

## Decision C: vacations live in their own table, keyed on a person and a date range, with no period

MR-03 says vacations *"live on the master rota"*. That is a statement about where they are **entered
and seen**, not about what they are keyed on. Three properties settle it:

1. **A vacation is an overlay, not a rotation.** MR-07 asks for *"per-period availability summary per
   level and unit, **including** who is on vacation each week"* — a person on leave still belongs to a
   unit that period; the leave subtracts from availability rather than replacing the assignment.
   Storing it as a unit value would destroy the assignment it is supposed to modify.
2. **It crosses period boundaries.** A fortnight's leave straddling Block 11 and Block 12 is one fact.
   Keyed on a period it becomes two rows that must be edited, cancelled and reported on together
   forever.
3. **Periods are regenerable and hard-deletable structure.** `PeriodController::destroy()` exists and,
   after Task 4, refuses only while *assignments* reference a year. A vacation must survive a
   department switching from months to week-blocks — the same hazard `periods.kind` is stored per row
   to defend against (its migration docblock: *"a department that switches systems mid-year does not
   silently relabel periods it already scheduled against"*).

So: `vacations(person_id, starts_on, ends_on, granularity, source)` and no `period_id`. Which periods
a vacation touches is a range intersection computed at read time.

`granularity` is `'week'` or `'date'` per AR-05, and it records **how the leave was entered and how it
is edited** — the stored dates are always real dates, one canonical representation. A `'week'` booking
is snapped by the writer to the department's own week boundaries (Task 2); a `'date'` booking is stored
verbatim. `source` ships `'manual'` and `'import'`. AR-05's third source — an approved RQ-01 leave
request becoming a vacation — is **P3**, and P1d-1 records the hook rather than building a request
system to feed it.

## Decision D: "publishable" is a screen, not a state machine — and it is `cap:`-gated, not tokenized

Two separate questions hide inside MR-05.

**Which route?** `cap:rota.view`, behind `auth`, like every other route in this platform. Not a
tokenized share link, for three reasons: (a) D7 and design §9.1 say token links are **P3**, and the
`feeds` table, its minting UI and its revocation UI do not exist — shipping a bearer token with no
revocation screen is worse than no token; (b) Stage 1's own acceptance criterion is *"residents claimed
accounts"* — by the end of Stage 1 every person who needs the rota has a login, so a share link solves
a problem Stage 1 has already solved differently; (c) design §9.1 reserves share links for data
leaving the login wall (wall displays, WhatsApp), which is a Stage-2-onward use case with its own
expiry and audit requirements.

**Is there a draft/published state?** No, and the evidence is in Munawib's own data model.
`schedules/{periodId}` carries `status:'draft'|'published'|'archived'` and a `version`;
`masterRota/{periodId}` carries **neither** — it is `{ assignments: {…} }` and nothing else. §18's
publish/archive/version machinery (PU-01…03) is Stage 2 and is written entirely about the call
schedule. Inventing a `master_rota_publications` table now would ship structure AR-05 does not have,
which Stage 2 would then have to reconcile against the real one.

MR-05 is therefore satisfied by *a read-only screen residents can reach*, and P1d-2 builds it. This is
the one decision in this plan where a reasonable owner might want something else — an explicit "not
visible until I say so" gate is a real product requirement, not a misreading — so it is listed under
[Owner decisions needed](#owner-decisions-needed) with "no gate" as the stated default and the note
that adding one later is an additive nullable column plus a controller branch, not a rework.

## Decision E: neither new table soft-deletes, and that is a choice

CLAUDE.md's rule is *"clinical rows never hard-deleted; accounts deactivated, never deleted."* A rota
assignment is neither: it is schedule structure, like `periods` (which `PeriodController::destroy()`
already hard-deletes) and unlike `handovers`.

Two reasons to make it explicit rather than default into it. First, the grid's primary interaction is
**changing the same cell repeatedly** while a year is planned; soft-deleting every superseded span
would accumulate thousands of tombstones for a 780-cell grid and put a `whereNull('deleted_at')` on
every read path, including P1d-2's summaries. Second, the history already exists somewhere better: the
hash-chained `audit_log` records every set, split and clear with actor, server time and ids, and it
cannot be edited. **Delete is a real delete; the audit chain is the history.**

Consequence, stated so it is not discovered later: a mistaken clear is not undoable from the UI in
P1d-1. Undo/redo is UX-03 and lands with the Stage-2 workbench.

## Decision F: one writer per table, and one guard test covering both

`App\Support\LevelAssignment`, `App\Support\PersonStatus`, `App\Support\PositionChange` and
`App\Support\Csv` are each the single writer of their concern, each with a source-level guard
(`PersonLevelsHaveOneWriterTest`, `PersonActiveHasOneWriterTest`, `PositionChangeTest`,
`CsvIsTheOnlyReaderWriterTest`). P1d follows the pattern exactly: `App\Support\Rota\RotaAssignment` and
`App\Support\Rota\VacationBooking`, with **one** guard file covering both needle families
(`tests/Feature/Build/RotaWritersAreSingularTest.php`) — two files asserting the same shape over the
same directories would be the duplication the pattern exists to prevent.

Every allow-list in this codebase has a staleness twin (`test_every_allow_listed_file_still_exists`)
and every entry carries a prose justification at its site. The new guard has both.

## Decision G: the grid is seven queries, and a test says so

Sixty people × thirteen periods is 780 cells, each needing a person, the level that person held at that
period, a unit, any splits, and any vacation overlay. Rendered naively that is well over a thousand
queries. The budget, per grid render, is **constant in both people and periods**:

| # | Query | Why it cannot become N+1 |
|---|---|---|
| 1 | `Period::forYear($year)->ordered()->get()` | the columns; one academic year at a time, never "all years" |
| 2 | `Person::query()->active()->orderBy('people.full_name')->get()` | whole models, never `select()`/`pluck()` — a narrowed query omitting `person_id` makes `full_name` and `position` resolve to **null with no error** (CLAUDE.md; the defect that broke four live sites) |
| 3 | `Person::levelSpansBetween($people, $yearStart, $yearEnd)` | **Task 3.** One query for every span intersecting the year; the per-period level is then resolved in memory |
| 4 | `MasterRotaAssignment::whereIn('period_id', $periodIds)->get()` | one query for the whole grid, grouped in PHP into `[person_id][period_id]` |
| 5 | `Vacation::whereDate('starts_on','<=',$yearEnd)->whereDate('ends_on','>=',$yearStart)->get()` | one query, grouped in PHP by person |
| 6 | `Unit::query()->active()->ordered()->get()` | the picker **and** the per-cell code/hue map, keyed by id — a cell **never** touches `$assignment->unit` |
| 7 | `Level::query()->active()->ordered()->get()` | the row-group headers |

Named N+1 traps, each of which a reviewer should look for by name:

- `Person::levelAt()` called per cell → 780 queries. Forbidden; finding 5 and Task 3 are the answer.
- `$assignment->unit->code` per cell → up to 780 queries. The presenter takes the units map from query
  6 and resolves by id.
- `PersonPresenter::one()` calling `$person->hasAccount()` per row when the caller forgot
  `withExists(['user as has_account'])` → 60 EXISTS queries. Its own docblock names this
  (`PersonPresenter.php:40-41`); the grid carries the `withExists`.
- `Calendar::label()` per **day**. Not a query N+1 — `Calendar::holidaysOn()` memoises per process —
  but formatting thousands of dual Gregorian–Hijri dates through ICU per request is real CPU for no
  gain. **The grid labels boundaries, not days**: two labels per period (26), plus two per split span
  (only where a split exists), plus two per vacation. It never enumerates a period's days.

**Row grouping.** Rows group by the level held **on the academic year's start date** — one stable
grouping for the whole grid, stated in the screen's own header. A person cannot be in thirteen row
groups at once, so the alternative (grouping per column) is not available. A mid-year promotion is not
hidden by this: each cell independently carries the level held at **its own** period start, and renders
a marker when that differs from the row group's level. Honest, and free — query 3 already fetched every
span.

## Decision H: audit rows are per action, ids only, and no new anomaly watch

Every write in this plan calls `AuditLog::record($action, $detail, $userId, $ip)` with `$detail`
carrying **ids, field names and counts only** — never a person's name, a unit's name or a period's
label. Actions: `rota_assign`, `rota_split`, `rota_clear`, `vacation_book`, `vacation_cancel`. Detail
shape: `person=<id>;period=<id>;unit=<id>` and `person=<id>;period=<id>;spans=<n>`.

P1 finding 11 warns that a fresh action name goes unmonitored unless deliberately added to
`AuditAnomalies`' watch list, and that reusing a watched name fires `OpsAlert::critical` once per row.
**P1d-1 deliberately adds none of these five to the watch list.** A per-cell assignment is ordinary
editing, and the volume-sensitive path — P1d-2's fill-down/across and copy-period, which write hundreds
of cells behind one confirmation — is where a watched summary action (`rota_fill`) belongs, and P1d-2
adds it there. Recorded here so the omission reads as a decision.

---

## The split: P1d-1 (this document) and P1d-2

P1b was thirteen tasks; P1c-1 was thirteen and split P1c-2 off before it started. P1d as scoped by the
P1 plan is two migrations, two models, two writers, a calendar capability, a level resolver, a security
fix, a delete-path hardening, an editor grid with splits and vacations, a resident-facing read view
with search and filters, per-period availability summaries, fill-down, fill-across, copy-period, a CSV
export and a CSV import with dry-run preview and digest pinning. **That is two plans.**

| | Scope | Requirements |
|---|---|---|
| **P1d-1** (this document, executable) | Both capabilities and the nav; the department's week in `Calendar`; the set-wise level-range resolver; `master_rota_assignments` and its model guard; the `PeriodController::destroy()` hardening the table makes necessary; `App\Support\Rota\RotaAssignment`; `vacations` and `App\Support\Rota\VacationBooking`; the `PersonPresenter` email fix; the editor grid with per-cell save, splits and vacations; the e2e persistence journey. | MR-01 (consumed), MR-02, MR-03 |
| **P1d-2** (scoped below, its own plan when this merges) | MR-05's read view (search, level filter, per-person period strip); MR-07's per-period availability summaries by level and unit including who is on vacation each week — **the Stage 1 acceptance criterion**; MR-06's fill-down, fill-across, copy-period, CSV export and CSV import with dry-run preview. | MR-05, MR-06, MR-07 |

The boundary is clean and both sides leave the tree deployable: P1d-1 defines and writes the data;
P1d-2 reads it, summarises it, and bulk-writes it. Nothing in P1d-2 changes a table P1d-1 creates.

**MR-04 is in neither** (owner decision 1). It is Stage 2, and P1d records the hook.

---

## Migration ordering

Finding 4: `2026_08_15_120001` and `120002` are already taken. P1d-1 continues the sequence:

```
2026_08_15_120003_create_master_rota_assignments_table   (Task 4 — new table)
2026_08_15_120004_create_vacations_table                 (Task 6 — new table)
```

Both are additive: two new tables, nothing retyped, nothing dropped, no clinical or identity table
touched. The owner runs production migrations (CLAUDE.md); Task 12 supplies the verification queries
for `docs/RUNBOOK-DEPLOY.md`. P1e's `2026_08_16_*` allocation is untouched.

---

## Amendments made during execution

*(Empty at plan time. Follow the P0c/P0d/P1a/P1b/P1c convention: when a task turns up something this
plan's enumeration missed — a site not listed, a test that goes red for a reason the plan did not
predict, a behaviour that differs between SQLite and MySQL or between UTC and Asia/Riyadh — record it
here, dated, with what was found and how it was resolved. Findings caught empirically rather than by
inspection are the ones worth writing down.*

*The base rate is not low: P1a recorded nine amendments across nine tasks, P1b eight across thirteen
including two real plan errors, and P1c thirteen across twelve including three cases of the plan
contradicting its own tests and four cases of the plan's own expected-test-count arithmetic being
stale before the task began. **Assume this plan is wrong somewhere too**, and in particular: run
`php artisan test` before touching any file at the start of each task and trust the measured baseline
over this document's arithmetic.)*

**2026-08-09, Task 1 — the plan's own Task 1 text contradicts the plan's own top-of-document OWNER
DECISIONS block.** The binding block (very top of this document, "binding, and one of them changes
sequencing") states plainly: *"`rota.manage` defaults to Administrator AND Chief Resident."* Task 1's
step-by-step prose (Step 2's `ROLE_DEFAULTS` instruction, Step 4's spec-doc wording, and — most
concretely — the supplied `RotaAccessTest` snippet's `test_only_an_administrator_holds_rota_manage_by_
default`, which asserts position 5 does NOT hold it) all still say Administrator-only, matching the
stale "READER'S INDEX ONLY" block's decision 6, which was never updated to match the binding block
above it. This is exactly the failure mode the reader's-index block itself warns about ("three times
... an implementer was instructed by task text to build the thing the decision had forbidden"), just
not caught by the block's own author this time. Resolved per the binding decision, not the task text:
`rota.manage` seeded to positions 0 AND 5 in `AccessControlSeeder::ROLE_DEFAULTS`; `RotaAccessTest`'s
test renamed to `test_only_an_administrator_and_chief_resident_hold_rota_manage_by_default` and its
assertion for position 5 flipped to `assertTrue`; `AccessControlParityTest::expectedByPosition()`
updated to add `rota.manage` to position 5's expected set as well as the `$adminOnly` array (position
0 already picks it up from `$adminOnly`). `docs/spec/08-foundation.md`'s role-defaults sentence
rewritten to say "Administrator and Chief Resident" rather than "Administrator-only". `rota.view` was
already correctly specified as every-position in both the binding block and the task text — no
conflict there.

**2026-08-09, Task 2 — `Calendar::settings()`'s existing `weekend_days` fallback used `?:` (a falsy
check), which silently rewrites an explicit empty `weekend_days` array to the `[5,6]` default before
`weekStartIsoDay()` ever sees it.** The plan's own test (`test_the_week_starts_the_day_after_the_last_
configured_weekend_day`'s fourth case, "no weekend configured falls back to Monday") sets
`weekend_days => []` and expects `weekStartIsoDay()` to return `1`; running it first (per this plan's
own convention) returned `7` instead, because `settings()` had already substituted `[5,6]` for the
empty array before the method's own defensive `$weekend === []` branch could ever run — making that
branch dead code unreachable through the public `weekendDays()` API. Confirmed this is unreachable in
production through normal use (`CalendarSettingsTest::test_weekend_days_rejects_an_empty_list` already
refuses an empty list at the form), so the fix is purely about the fallback checking the wrong
condition: changed `$institution?->weekend_days ?: self::DEFAULT_WEEKEND` to a null check
(`$institution?->weekend_days === null ? self::DEFAULT_WEEKEND : array_map('intval', ...)`), which
still defaults correctly when there is no institution row or the column is genuinely `NULL`, but now
respects an explicit `[]` as the real (if unusual) configuration it is. Full suite and every
Calendar-adjacent test file (`CalendarSettingsTest`, `InstitutionCalendarSettingsTest`,
`HolidayCrudTest`, `HolidayTest`, `GoldenFixtureTest`, `CalendarIsTheOnlyConverterTest`) stay green
after the change — nothing else relied on the old (incorrect) falsy-fallback behaviour.

**2026-08-09, Task 4 — the plan's stated expected total after Task 4 is arithmetically stale by
one.** The plan says "Expected: `1083 → 1094` (9 new `AssignmentIntegrityTest` + 2 replacing 1 in
`PeriodGenerationScreenTest`, so +1 there)." 9 + 1 = 10, not 11, so the correct total is `1093`, which
is what the measured run produced. Consistent with P1c's four recorded instances of the same class of
staleness — trusting the measured baseline over the document's arithmetic, per this section's own
standing instruction.

---

## Conventions every task follows

Verified against the tree; these are not preferences.

- **TDD, strictly.** Write the test, run it, **watch it fail for the reason you expect** (not a typo,
  not a missing class), then implement. A test that passes on first run has proved nothing.
- **Build before test, every time.** `npm run build && php artisan test` — finding 16.
- **Verify with Bash, not PowerShell.** PowerShell's PATH on this machine lacks `openssl` and backup
  tests silently self-skip. If PHP is not on PATH in a fresh shell:
  `export PATH="$LOCALAPPDATA/php84:$LOCALAPPDATA/composer-bin:$PATH"`.
- **Filter output.** `| tail -5` for a full run; `php artisan test --filter <TestName> | head -30` on a
  failure. Never dump a failing suite into context.
- **Assert over the whole set, never inside a `foreach`.** Every source-scanning guard in this
  codebase collects `$offenders[]` and ends with `assertSame([], $offenders, ...)`; the convention is
  documented at `CompiledCssIsLightOnlyTest.php:45-52` and cited by name in four other files.
- **Every route behind `auth` + a `cap:`.** Writes are POST/PATCH/DELETE + CSRF.
- **Eloquent/bindings only.** Never concatenate SQL.
- **Light theme only, semantic classes only.** No `dark:` utility, no raw Tailwind palette class, no
  hex in markup. `bg-panel`, `bg-ground-deep`, `text-muted`, `channel-tag`, `channel-bar-*`,
  `rounded-md`. There is no `bg-panel-soft` token (P1 finding 13) — it compiles to nothing.
- **New screens follow `Units.vue` / `Levels.vue` / `People.vue`**: mobile cards plus desktop table,
  `useForm`, `preserveScroll`, live regions, a computed column count. They do not invent.
- **The client performs no date arithmetic** — finding 7's ten needles, no allow-list.
- **`institution_id` is provenance.** Never a `where`, never inside an `index([...])`/`unique([...])`
  array — finding 9.
- **Audit by ids, field names and counts only.** Decision H.
- Commit at the end of each task with the message given, only after `npm run build` and
  `php artisan test` are both green.

---

# P1d-1 — tasks

---

### Task 1: `rota.view` and `rota.manage`, and the empty grid they open

**Why first:** every later task needs a gated route to hang a test on, and the capability catalog was
found stale twice (finding 12). The catalog, the seeder, `ROLE_DEFAULTS`, `AccessControlParityTest`'s
hardcoded list, the nav and `docs/spec/08-foundation.md` all move **in this one commit**.

**Files:**
- Modify: `database/seeders/AccessControlSeeder.php`
- Modify: `docs/spec/08-foundation.md`
- Modify: `routes/web.php`
- Modify: `resources/js/Layouts/AppLayout.vue`
- Modify: `tests/Feature/AccessControlParityTest.php`
- Modify: `tests/js/AppLayout.test.js`
- Create: `app/Http/Controllers/Admin/MasterRotaController.php`
- Create: `resources/js/Pages/Admin/MasterRota.vue`
- Create: `tests/Feature/Rota/RotaAccessTest.php`

**Step 1 — the failing test.** `tests/Feature/Rota/RotaAccessTest.php`:

```php
<?php

namespace Tests\Feature\Rota;

use App\Models\Capability;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib MR-02/MR-05. Two capabilities, deliberately separate:
 *
 *  - `rota.view` — READ the master rota. Defaults to EVERY seeded position, because MR-05's whole
 *    point is that residents read it. Position 1 (Nurse) is RETIRED and gets no defaults, ever.
 *  - `rota.manage` — EDIT it. Administrator-only by default (owner decision 6). Munawib §5 grants
 *    this to its "Scheduler" persona too, and this codebase has no Scheduler role; the closest
 *    fit is Chief Resident (position 5), and granting it is an Access Control click, not a code
 *    change.
 *
 * D7: both routes sit behind `auth`. There is no anonymous route anywhere in this platform and
 * this plan adds none.
 */
class RotaAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_both_capabilities_are_in_the_catalog(): void
    {
        $this->assertNotNull(Capability::where('key', 'rota.view')->first());
        $this->assertNotNull(Capability::where('key', 'rota.manage')->first());
    }

    public function test_the_catalog_document_lists_both_keys(): void
    {
        $doc = file_get_contents(base_path('docs/spec/08-foundation.md'));

        $this->assertStringContainsString('`rota.view`', $doc);
        $this->assertStringContainsString('`rota.manage`', $doc);
    }

    public function test_an_administrator_reaches_the_editor(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/admin/rota')->assertOk();
    }

    public function test_a_resident_is_refused_the_editor(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/rota')->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/rota')->assertRedirect('/login');
    }

    public function test_every_seeded_position_holds_rota_view_by_default(): void
    {
        foreach ([0, 2, 3, 4, 5] as $position) {
            $user = User::factory()->create(['position' => $position]);

            $this->assertTrue(
                \App\Support\AccessControl::allows($user, 'rota.view'),
                "position {$position} should hold rota.view"
            );
        }
    }

    public function test_only_an_administrator_holds_rota_manage_by_default(): void
    {
        foreach ([2, 3, 4, 5] as $position) {
            $user = User::factory()->create(['position' => $position]);

            $this->assertFalse(
                \App\Support\AccessControl::allows($user, 'rota.manage'),
                "position {$position} must not hold rota.manage by default"
            );
        }

        $this->assertTrue(
            \App\Support\AccessControl::allows(User::factory()->create(['position' => 0]), 'rota.manage')
        );
    }

    public function test_the_retired_nurse_position_gains_no_default(): void
    {
        $nurse = User::factory()->create(['position' => 1]);

        $this->assertFalse(\App\Support\AccessControl::allows($nurse, 'rota.view'));
        $this->assertFalse(\App\Support\AccessControl::allows($nurse, 'rota.manage'));
    }

    public function test_the_editor_route_is_not_under_the_endorsement_prefix(): void
    {
        // Unit::RESERVED_CODES is derived from routes under `endorsement/` by
        // ReservedUnitCodesTest, bidirectionally. A rota route under that prefix would demand a
        // matching reserved code in the same commit; this one deliberately avoids the question.
        $this->assertNotContains('ROTA', \App\Models\Unit::RESERVED_CODES);
    }
}
```

Run it — every case fails, the route cases with a 404 rather than a 403:

```bash
php artisan test --filter RotaAccessTest | head -30
```

**Step 2 — seed the capabilities.** In `database/seeders/AccessControlSeeder.php`, add to `CATALOG`:

```php
'rota.view' => 'View the master rota',
'rota.manage' => 'Create and edit master rota assignments and vacations',
```

and to `ROLE_DEFAULTS`: `'rota.view'` in **every** listed position array (0, 2, 3, 4, 5), and
`'rota.manage'` in position 0 only. Position 1 has no array and gains none.

**Step 3 — `AccessControlParityTest` (finding 11).** Adding to `ROLE_DEFAULTS[0]` reddens
`test_each_role_effective_set_matches_the_documented_server_gates` and `test_seeder_is_idempotent`,
which build their expected sets from a private `expectedByPosition()`. This is the legitimate kind of
red, not drift. Add `'rota.manage'` to the `$adminOnly` array **and** `'rota.view'` to whatever array
that method uses for the capabilities every position holds — read the whole method first; unlike P1b's
and P1c's single admin-only keys, this task adds a key that lands on **five** positions.

**Step 4 — the document (finding 12).** In `docs/spec/08-foundation.md`, extend the catalog sentence
to `…, `structure.manage`, `people.manage`, `rota.view`, `rota.manage`.` and append to the role-defaults
paragraph:

> `rota.view` (read the master rota — Munawib MR-05) defaults to **every seeded position**, because a
> rota residents cannot read fails the requirement it exists for. `rota.manage` (edit assignments and
> vacations — Munawib MR-02/MR-03/MR-06) defaults **Administrator-only**; Munawib §5 also grants it to
> its Scheduler persona, which maps to no role here, so granting it to Chief Resident is an Access
> Control change rather than a code change. Both added P1d 2026-08-09.

**Step 5 — the route and the controller.** In `routes/web.php`, after the `cap:people.manage` group:

```php
/*
 * Admin → Master Rota (Munawib MR-02/MR-03). `cap:rota.manage` — editing the rota is a
 * scheduling act, not a roster one. The READ view MR-05 requires is a separate route behind
 * `cap:rota.view` and is built in P1d-2; it deliberately does not live under `/admin`, because a
 * resident reading the rota is not doing administration.
 *
 * Deliberately NOT under `/endorsement`, so Unit::RESERVED_CODES is untouched
 * (ReservedUnitCodesTest asserts that list against the router bidirectionally).
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:rota.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/rota', [\App\Http\Controllers\Admin\MasterRotaController::class, 'index'])->name('rota');
    });
```

`MasterRotaController::index()` in this task renders `Admin/MasterRota` with the academic years that
exist (`Period::query()->select('academic_year')->distinct()->orderBy('academic_year')->pluck('academic_year')`)
and `'grid' => null`. The grid itself is Task 8. The screen shows a teaching empty state naming
Structure → Periods when no periods exist — UX-03's *"teaching empty states"*, and the honest answer,
because the rota's columns are periods and P1b is where they are generated.

**Step 6 — the nav.** In `resources/js/Layouts/AppLayout.vue`, add `|| can('rota.manage')` to
`canAdmin`, and a link inside the `v-if="canAdmin"` block, placed beside the Structure links:

```html
<Link v-if="can('rota.manage')" href="/admin/rota"
      :class="navClass(isActive('/admin/rota'))">
    Master Rota
</Link>
```

Extend `tests/js/AppLayout.test.js` with a `rota.manage`-alone case asserting the link renders and the
People/Units links do not.

**Step 7 — verify.**

```bash
npm run build && php artisan test | tail -5
npm test | tail -5
```

Expected: `1063 → 1073` (10 new `RotaAccessTest` cases; `AccessControlParityTest`'s existing methods
stay green once Step 3 lands). `npm test` `113 → 114`.

```bash
git commit -am "feat: a rota residents may read and an administrator may plan"
```

---

### Task 2: the week the department actually runs

**Why:** MR-03 books vacations at *week* granularity and MR-07 reports *"who is on vacation each
week"*, and this system has no definition of a week (finding 6). The weekend is department
configuration, so the answer is derived, never constant. It goes **inside `App\Support\Calendar`** —
`CalendarIsTheOnlyConverterTest` makes any other home a build failure, and AR-08 is the rule it
enforces.

**Files:**
- Modify: `app/Support/Calendar.php`
- Modify: `tests/Unit/CalendarTest.php`
- Modify: `tests/fixtures/calendar/golden.json`
- Modify: `tests/Feature/Calendar/GoldenFixtureTest.php`

**Step 1 — the failing test.** Add to `tests/Unit/CalendarTest.php`:

```php
public function test_the_week_starts_the_day_after_the_last_configured_weekend_day(): void
{
    // QCH: weekend is Friday(5) and Saturday(6) ISO, so the week starts Sunday(7).
    $this->setWeekend([5, 6]);
    $this->assertSame(7, Calendar::weekStartIsoDay());

    // A Saturday–Sunday weekend (6, 7) starts the week on Monday.
    $this->setWeekend([6, 7]);
    $this->assertSame(1, Calendar::weekStartIsoDay());

    // A one-day weekend still has an unambiguous answer.
    $this->setWeekend([5]);
    $this->assertSame(6, Calendar::weekStartIsoDay());

    // No weekend configured at all falls back to Monday rather than dividing by zero.
    $this->setWeekend([]);
    $this->assertSame(1, Calendar::weekStartIsoDay());
}

public function test_week_of_returns_the_containing_week_both_bounds_inclusive(): void
{
    $this->setWeekend([5, 6]);            // week runs Sunday .. Saturday

    // 2026-08-12 is a Wednesday.
    $week = Calendar::weekOf('2026-08-12');

    $this->assertSame('2026-08-09', $week['starts_on']);   // the Sunday
    $this->assertSame('2026-08-15', $week['ends_on']);     // the Saturday

    // The boundary days belong to their own week, not the neighbouring one.
    $this->assertSame('2026-08-09', Calendar::weekOf('2026-08-09')['starts_on']);
    $this->assertSame('2026-08-09', Calendar::weekOf('2026-08-15')['starts_on']);
    $this->assertSame('2026-08-16', Calendar::weekOf('2026-08-16')['starts_on']);
}

public function test_week_of_carries_dual_dated_labels_so_the_client_never_formats_one(): void
{
    $this->setWeekend([5, 6]);

    $week = Calendar::weekOf('2026-08-12');

    $this->assertSame('2026-08-09', $week['starts_label']['date']);
    $this->assertNotSame('', $week['starts_label']['hijri']);
    $this->assertSame('2026-08-15', $week['ends_label']['date']);
}

public function test_weeks_in_covers_every_week_touching_the_range_and_clips_to_it(): void
{
    $this->setWeekend([5, 6]);

    // A four-week block that does NOT start on a Sunday: 2026-08-12 (Wed) .. 2026-09-08 (Tue).
    $weeks = Calendar::weeksIn('2026-08-12', '2026-09-08');

    $this->assertCount(5, $weeks);                              // partial + 3 whole + partial

    $this->assertSame('2026-08-09', $weeks[0]['starts_on']);    // the TRUE week start
    $this->assertSame('2026-08-12', $weeks[0]['clipped_starts_on']);   // clipped to the range
    $this->assertSame('2026-08-15', $weeks[0]['clipped_ends_on']);

    $this->assertSame('2026-09-06', $weeks[4]['starts_on']);
    $this->assertSame('2026-09-08', $weeks[4]['clipped_ends_on']);
}

public function test_weeks_in_refuses_a_range_longer_than_a_year_and_a_half(): void
{
    $this->setWeekend([5, 6]);

    $this->expectException(\InvalidArgumentException::class);

    Calendar::weeksIn('2026-01-01', '2028-01-01');
}
```

`setWeekend()` is a small private helper in the test file that writes `weekend_days` on the
institution row and calls `Calendar::flush()` — copy the arrangement `InstitutionCalendarSettingsTest`
already uses; do **not** invent a second way to move calendar settings under test.

Run it and watch five failures for `Call to undefined method`:

```bash
php artisan test --filter CalendarTest | head -30
```

**Step 2 — implement in `app/Support/Calendar.php`,** beside `weekendDays()`/`isWeekend()`:

```php
/**
 * The ISO weekday (Mon=1 … Sun=7) a week begins on for THIS department.
 *
 * Munawib AR-05 gives vacations a `granularity: 'week'` and MR-07 reports availability "each
 * week", but the spec never says what a week is — while ST-01 makes weekend days department
 * configuration. Two departments with different weekends would otherwise snap the same leave to
 * different dates.
 *
 * The rule: the week begins the day after the LAST configured weekend day, wrapping. Friday and
 * Saturday off (the QCH default, [5, 6]) gives a Sunday start; a Saturday–Sunday weekend gives a
 * Monday start. An empty weekend list falls back to Monday rather than producing no answer.
 */
public static function weekStartIsoDay(): int
{
    $weekend = self::weekendDays();

    if ($weekend === []) {
        return 1;
    }

    sort($weekend);

    return (int) (max($weekend) % 7) + 1;
}

/**
 * The week containing a date, BOTH BOUNDS INCLUSIVE — the same idiom `Person::levelAt()` and
 * `Period::contains()` share. Labels are dual-dated (UX-04) because the client performs no date
 * formatting at all (Decision A, P1a).
 *
 * @return array{starts_on:string, ends_on:string, starts_label:array<string,mixed>, ends_label:array<string,mixed>}
 */
public static function weekOf(DateTimeInterface|string $date): array
{
    $day = self::coerce($date);
    $start = self::weekStartIsoDay();

    // How many days back to the most recent $start-weekday, 0..6.
    $back = ((int) $day->isoWeekday() - $start + 7) % 7;

    $from = $day->subDays($back);
    $to = $from->addDays(6);

    return [
        'starts_on' => $from->format(self::YMD),
        'ends_on' => $to->format(self::YMD),
        'starts_label' => self::label($from),
        'ends_label' => self::label($to),
    ];
}

/**
 * Every week INTERSECTING a range, in order. `starts_on`/`ends_on` are the true week bounds;
 * `clipped_*` are those bounds trimmed to the range, which is what a per-period week strip
 * (MR-07) actually renders — a period rarely begins on a week boundary.
 *
 * Capped, deliberately: an unbounded loop over a range built from a mistyped year is how a screen
 * becomes a memory exhaustion. 550 days is comfortably more than the longest academic year this
 * system generates (owner decision 4: 365 or 366 days, block 13 absorbing the remainder).
 *
 * @return list<array{starts_on:string, ends_on:string, clipped_starts_on:string, clipped_ends_on:string, starts_label:array<string,mixed>, ends_label:array<string,mixed>}>
 */
public static function weeksIn(DateTimeInterface|string $from, DateTimeInterface|string $to): array
{
    $rangeStart = self::coerce($from);
    $rangeEnd = self::coerce($to);

    if ($rangeEnd < $rangeStart) {
        throw new InvalidArgumentException('A week range ends before it starts.');
    }

    if ($rangeStart->diffInDays($rangeEnd) > 550) {
        throw new InvalidArgumentException('A week range may not exceed 550 days.');
    }

    $out = [];
    $cursor = self::parse(self::weekOf($rangeStart)['starts_on']);
    $endYmd = $rangeEnd->format(self::YMD);

    while ($cursor->format(self::YMD) <= $endYmd) {
        $week = self::weekOf($cursor);

        $out[] = $week + [
            'clipped_starts_on' => max($week['starts_on'], $rangeStart->format(self::YMD)),
            'clipped_ends_on' => min($week['ends_on'], $endYmd),
        ];

        $cursor = $cursor->addDays(7);
    }

    return $out;
}
```

`max()`/`min()` on two `Y-m-d` strings is a lexicographic comparison, which for this format is also
the chronological one — the same property `Period::contains()` already relies on.

**Step 3 — the golden fixture.** `tests/fixtures/calendar/golden.json` is *"a contract with P2, not a
convenience: changing a value in it without a stated reason is the drift it exists to catch."* This
task **adds a new top-level key and changes no existing value.** Add, as a sibling of `cases`:

```json
"weeks": [
  { "_description": "QCH: Friday+Saturday weekend, so weeks run Sunday..Saturday.",
    "weekend_days": [5, 6], "week_start_iso_day": 7,
    "of": "2026-08-12", "starts_on": "2026-08-09", "ends_on": "2026-08-15" },
  { "_description": "A Saturday+Sunday weekend runs Monday..Sunday.",
    "weekend_days": [6, 7], "week_start_iso_day": 1,
    "of": "2026-08-12", "starts_on": "2026-08-10", "ends_on": "2026-08-16" },
  { "_description": "A one-day (Friday) weekend still resolves.",
    "weekend_days": [5], "week_start_iso_day": 6,
    "of": "2026-08-12", "starts_on": "2026-08-08", "ends_on": "2026-08-14" }
]
```

Every value above must be **produced by running the code**, not written by hand — the fixture's own
`_purpose` states that rule. Add `test_the_week_fixtures_reproduce` to
`tests/Feature/Calendar/GoldenFixtureTest.php`, iterating `weeks`, setting `weekend_days`, flushing,
and asserting all three values.

**Step 4 — verify.**

```bash
npm run build && php artisan test | tail -5
```

Expected: `1073 → 1079` (5 new `CalendarTest` + 1 new `GoldenFixtureTest`).
`CalendarIsTheOnlyConverterTest` stays green — every new line lives in `Calendar.php`, the one file its
ICU carve-out names.

```bash
git commit -am "feat: the calendar knows which day this department's week begins"
```

---

### Task 3: the level each person held in each period, in one query

**Why:** finding 5. Thirteen columns means thirteen dates, and neither existing resolver answers that
without thirteen queries or 780. The predicate must not be re-written: `Person::inForceOn()` is
deliberately the single definition and its own docblock says why.

**Files:**
- Modify: `app/Models/Person.php`
- Create: `tests/Feature/Identity/LevelResolverParityTest.php`

**Step 1 — the failing test.** `tests/Feature/Identity/LevelResolverParityTest.php`:

```php
<?php

namespace Tests\Feature\Identity;

use App\Models\Level;
use App\Models\Person;
use App\Support\LevelAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `Person::levelSpansBetween()` fetches every span intersecting a date RANGE in one query;
 * `Person::levelFromSpans()` then resolves a date against that pre-fetched set in memory.
 *
 * The in-memory resolver is, unavoidably, a SECOND expression of `inForceOn()`'s rule in a
 * different language — a query predicate cannot be run against an array. This codebase's answer to
 * "two expressions of one fact" where one cannot be eliminated is a matrix test that proves they
 * agree, not a comment claiming they do: exactly what `PickerParityTest` does for
 * `SignoffPickers`. That is what this file is.
 */
class LevelResolverParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_in_memory_resolver_agrees_with_level_at_across_a_matrix(): void
    {
        $r1 = Level::factory()->create(['code' => 'X1', 'display_order' => 10]);
        $r2 = Level::factory()->create(['code' => 'X2', 'display_order' => 20]);

        // Three people with deliberately awkward histories.
        $never = Person::factory()->create();                       // no history at all
        $open = Person::factory()->create();                        // one open-ended span
        $promoted = Person::factory()->create();                    // a closed span then an open one

        LevelAssignment::assign($open, $r1, '2026-07-01');

        LevelAssignment::assign($promoted, $r1, '2026-07-01');
        LevelAssignment::assign($promoted, $r2, '2027-01-01');

        $people = [$never, $open, $promoted];

        $dates = [
            '2026-06-30',   // before everything
            '2026-07-01',   // the first span's opening day — inclusive
            '2026-12-31',   // the day before the promotion — the closed span still holds
            '2027-01-01',   // the promotion's own day — inclusive
            '2027-06-30',   // well after
        ];

        $spans = Person::levelSpansBetween($people, '2026-06-30', '2027-06-30');

        foreach ($dates as $date) {
            foreach ($people as $person) {
                $expected = $person->levelAt($date)?->code;
                $actual = Person::levelFromSpans($spans[(int) $person->getKey()] ?? [], $date)?->code;

                $this->assertSame(
                    $expected,
                    $actual,
                    "person {$person->getKey()} on {$date}: levelAt() said ".var_export($expected, true)
                    .' but the in-memory resolver said '.var_export($actual, true)
                );
            }
        }
    }

    public function test_the_range_fetch_is_exactly_one_query_whatever_the_headcount(): void
    {
        $level = Level::factory()->create(['code' => 'X3']);

        $people = Person::factory()->count(20)->create();

        foreach ($people as $person) {
            LevelAssignment::assign($person, $level, '2026-07-01');
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        Person::levelSpansBetween($people, '2026-07-01', '2027-06-30');

        // One SELECT for the spans, one for the eager-loaded levels. Never one per person.
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
    }

    public function test_every_person_passed_in_gets_a_key_even_with_no_history(): void
    {
        $person = Person::factory()->create();

        $spans = Person::levelSpansBetween([$person], '2026-07-01', '2027-06-30');

        $this->assertArrayHasKey((int) $person->getKey(), $spans);
        $this->assertSame([], $spans[(int) $person->getKey()]);
    }

    public function test_a_span_that_merely_touches_the_range_is_included(): void
    {
        $level = Level::factory()->create(['code' => 'X4']);
        $person = Person::factory()->create();

        // Opens long before the range and is still open — it must come back.
        LevelAssignment::assign($person, $level, '2020-01-01');

        $spans = Person::levelSpansBetween([$person], '2026-07-01', '2027-06-30');

        $this->assertCount(1, $spans[(int) $person->getKey()]);
    }
}
```

`Level::factory()->create(['code' => 'R1'])` **collides** with `ReferenceSeeder`'s seeded ladder
(`levels.code` is unique outright) — P1c's Task 3 and Task 7 amendments both recorded this trap. The
codes above (`X1`…`X4`) avoid it.

Run it and watch four failures for `Call to undefined method Person::levelSpansBetween()`.

**Step 2 — implement,** in `app/Models/Person.php` directly below `levelsAt()`:

```php
/**
 * Every level span for a set of people that INTERSECTS a date range, in one query, keyed by person
 * id and ordered oldest-first. The rota grid's question is not "the level on one date" but "the
 * level in each of thirteen periods" — thirteen `levelsAt()` calls is thirteen queries, and
 * `levelAt()` per cell is 780 (P1 finding 10, generalised).
 *
 * Shares `inForceOn()`'s BOTH-BOUNDS-INCLUSIVE semantics by construction: a span is in the range
 * if it starts on or before the range's end and has not ended before the range's start.
 *
 * Returns an entry for EVERY person passed in — an empty list where there is no history — so a
 * caller iterating it never hits an undefined index. Same contract as `levelsAt()`.
 *
 * @param  \Illuminate\Support\Collection<int, Person>|array<int, Person>  $people
 * @return array<int, list<PersonLevel>>
 */
public static function levelSpansBetween(iterable $people, string $fromYmd, string $toYmd): array
{
    $out = [];
    $ids = [];

    foreach ($people as $person) {
        $id = (int) $person->getKey();
        $out[$id] = [];
        $ids[] = $id;
    }

    if ($ids === []) {
        return [];
    }

    $spans = PersonLevel::query()
        ->whereIn('person_levels.person_id', $ids)
        ->whereDate('person_levels.effective_from', '<=', $toYmd)
        ->where(fn (Builder $q) => $q->whereNull('person_levels.effective_to')
            ->orWhereDate('person_levels.effective_to', '>=', $fromYmd))
        ->orderBy('person_levels.effective_from')
        ->with('level')
        ->get();

    foreach ($spans as $span) {
        $out[(int) $span->person_id][] = $span;
    }

    return $out;
}

/**
 * The level in force on `$on`, resolved against an ALREADY-FETCHED span list from
 * `levelSpansBetween()`. No query.
 *
 * This is the one place in this codebase where a predicate exists twice — once as SQL
 * (`inForceOn()`), once as PHP (here) — because a query builder cannot be run against an array.
 * `LevelResolverParityTest` proves the two agree across a matrix of span shapes and dates, which
 * is the same answer `PickerParityTest` gives for `SignoffPickers`' two-sided rule. If you change
 * either side, that test is what catches you.
 *
 * The spans arrive ordered oldest-first, so the LAST match wins — exactly `levelAt()`'s
 * `orderByDesc('effective_from')->first()`.
 *
 * @param  list<PersonLevel>  $spans
 */
public static function levelFromSpans(array $spans, string $on): ?Level
{
    $found = null;

    foreach ($spans as $span) {
        $from = $span->effective_from->format(Calendar::YMD);
        $to = $span->effective_to?->format(Calendar::YMD);

        if ($from <= $on && ($to === null || $to >= $on)) {
            $found = $span->level;
        }
    }

    return $found;
}
```

**Step 3 — verify.**

```bash
npm run build && php artisan test | tail -5
```

Expected: `1079 → 1083` (4 new). `LevelHistoryTest`'s existing 9 cases must stay green — they prove
`levelAt()`'s semantics did not move.

```bash
git commit -am "feat: one query answers the level held in every period of a year"
```

---

### Task 4: `master_rota_assignments`, and the delete path it makes dangerous

**Why one task:** finding 2. `PeriodController::destroy()`'s own docblock says this table is what makes
it unsafe, and there is no soft delete to recover from. Shipping the table in one commit and the guard
in the next leaves a window in which one typed academic year silently destroys a department's planned
year. They land together, and the pinning test that asserts today's behaviour is **replaced**, not
deleted.

**Files:**
- Create: `database/migrations/2026_08_15_120003_create_master_rota_assignments_table.php`
- Create: `app/Models/MasterRotaAssignment.php`
- Create: `database/factories/MasterRotaAssignmentFactory.php`
- Modify: `app/Http/Controllers/Admin/PeriodController.php`
- Modify: `app/Http/Requests/Admin/CalendarSettingsRequest.php`
- Modify: `tests/Feature/Admin/PeriodGenerationScreenTest.php`
- Create: `tests/Feature/Rota/AssignmentIntegrityTest.php`

**Step 1 — the failing tests.** `tests/Feature/Rota/AssignmentIntegrityTest.php`:

```php
<?php

namespace Tests\Feature\Rota;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * MR-02, owner decisions 2 and 3.
 *
 * Every row is a date-bounded span; a whole-period assignment is the degenerate split (one row
 * whose bounds equal the period's). There is no nullable "means the whole period" range, because
 * that would give one fact two representations and every reader would have to handle both.
 *
 * OVERLAPS ARE REFUSED. Two spans covering one day for one person is one person on two units that
 * day — which the grid cannot render and MR-04's future call roster cannot resolve. This is the
 * same reasoning `Period::booted()` refuses overlapping periods with, and the model guard is
 * modelled on it deliberately.
 *
 * GAPS ARE ALLOWED. A mid-block joiner and a half-planned year are both real; the grid renders the
 * uncovered days rather than refusing the state.
 */
class AssignmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Period $period;

    private Person $person;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->period = Period::factory()->create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->person = Person::factory()->create();
        // `Unit` has NO factory — verified before writing this (`database/factories/` contains
        // Holiday, Level, Period, Person, PersonLevel and User only). Every existing test builds
        // one with `Unit::create()`; follow that rather than adding a seventh factory here.
        //
        // `'active' => true` is NOT optional: `2026_08_08_120001_add_configuration_to_units.php`
        // defaults the column to FALSE, which `UnitScopeTest.php:131` records in its own comment.
        // A unit created without it is retired on arrival, and `Unit::query()->active()` — the one
        // predicate the cell picker offers from and the FormRequest validates against — will not
        // see it.
        $this->unit = Unit::create(['code' => 'RTA', 'name' => 'Rota Test A', 'active' => true]);
    }

    public function test_a_whole_period_assignment_is_one_row_spanning_the_period(): void
    {
        $row = MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($row->exists);
    }

    public function test_a_span_starting_before_its_period_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-06-30',
            'ends_on' => '2026-07-28',
        ]);
    }

    public function test_a_span_ending_after_its_period_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-29',
        ]);
    }

    public function test_a_span_ending_before_it_starts_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-10',
            'ends_on' => '2026-07-09',
        ]);
    }

    public function test_two_spans_overlapping_by_one_day_are_refused(): void
    {
        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-14',
        ]);

        $this->expectException(RuntimeException::class);

        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => Unit::create(['code' => 'RTB', 'name' => 'Rota Test B', 'active' => true])->getKey(),
            'starts_on' => '2026-07-14',      // the shared day
            'ends_on' => '2026-07-28',
        ]);
    }

    public function test_two_adjacent_spans_are_accepted(): void
    {
        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-14',
        ]);

        $second = MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => Unit::create(['code' => 'RTB', 'name' => 'Rota Test B', 'active' => true])->getKey(),
            'starts_on' => '2026-07-15',
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($second->exists);
    }

    public function test_a_gap_between_two_spans_is_accepted(): void
    {
        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-07',
        ]);

        $second = MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-21',   // fourteen uncovered days in between
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($second->exists);
    }

    public function test_two_people_may_hold_the_same_days_in_the_same_period(): void
    {
        MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $other = MasterRotaAssignment::create([
            'person_id' => Person::factory()->create()->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($other->exists);
    }

    public function test_updating_a_row_does_not_see_itself_as_an_overlap(): void
    {
        $row = MasterRotaAssignment::create([
            'person_id' => $this->person->getKey(),
            'period_id' => $this->period->getKey(),
            'unit_id' => $this->unit->getKey(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-14',
        ]);

        $row->update(['ends_on' => '2026-07-21']);

        $this->assertSame('2026-07-21', $row->fresh()->ends_on->format('Y-m-d'));
    }
}
```

And, in `tests/Feature/Admin/PeriodGenerationScreenTest.php`, **replace**
`test_delete_succeeds_today_with_no_assignment_table_to_check` (line 313) with:

```php
public function test_deleting_a_year_is_refused_while_a_rota_assignment_references_it(): void
{
    $admin = User::factory()->create(['position' => 0]);
    $period = Period::query()->where('academic_year', '2026-2027')->orderBy('starts_on')->first();

    MasterRotaAssignment::create([
        'person_id' => Person::factory()->create()->getKey(),
        'period_id' => $period->getKey(),
        'unit_id' => Unit::create(['code' => 'RTA', 'name' => 'Rota Test A', 'active' => true])->getKey(),
        'starts_on' => $period->starts_on->format('Y-m-d'),
        'ends_on' => $period->ends_on->format('Y-m-d'),
    ]);

    $this->actingAs($admin)
        ->delete('/admin/structure/periods/2026-2027', ['confirm_academic_year' => '2026-2027'])
        ->assertSessionHasErrors('confirm_academic_year');

    // Not one period gone — the refusal is total, never partial.
    $this->assertSame(13, Period::query()->where('academic_year', '2026-2027')->count());
}

public function test_deleting_a_year_still_succeeds_once_nothing_references_it(): void
{
    $admin = User::factory()->create(['position' => 0]);

    $this->actingAs($admin)
        ->delete('/admin/structure/periods/2026-2027', ['confirm_academic_year' => '2026-2027'])
        ->assertSessionHasNoErrors();

    $this->assertSame(0, Period::query()->where('academic_year', '2026-2027')->count());
}
```

(Adjust the seeded period count `13` to whatever that test class's own fixture generates — read its
`setUp()` first rather than trusting this number.)

Run both files and watch every case fail on the missing table and the missing guard.

**Step 2 — the migration,** `database/migrations/2026_08_15_120003_create_master_rota_assignments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib MR-02: a grid of people × periods, one unit per person per period, with split periods as
 * date-bounded sub-assignments.
 *
 * EVERY ROW IS A SPAN. `starts_on`/`ends_on` are NOT NULL, both bounds inclusive — the idiom
 * `Person::levelAt()` and `Period::contains()` already share. A whole-period assignment is the
 * degenerate split: exactly one row whose bounds equal its period's. There is deliberately no
 * nullable range meaning "the whole period", because that gives one fact two representations, and
 * no parent/child span pair, because a parent row for a split period has no correct `unit_id`.
 *
 * MR-02's "one unit per person per period" is therefore an invariant on the SET, not a unique
 * index: the rows for one (person, period) must not overlap, and each must lie wholly inside its
 * period. `App\Models\MasterRotaAssignment::booted()` enforces both — modelled directly on
 * `Period::booted()`, which refuses overlapping periods for the same reason (one person on two
 * units on one day is a state the grid cannot render and MR-04's future call roster cannot
 * resolve). `App\Support\Rota\RotaAssignment` is the only writer, per
 * `RotaWritersAreSingularTest`.
 *
 * A UNIQUE index cannot express any of this — SQLite has no exclusion constraint and MySQL 8.4 has
 * no range type — so the guarantee lives in the model and its one writer, exactly as
 * `person_levels`' overlap rule lives in `App\Support\LevelAssignment`.
 *
 * NO SOFT DELETE, deliberately (P1d Decision E). This is schedule structure, not a clinical row:
 * the grid's primary interaction is re-editing the same cell while a year is planned, and
 * tombstoning every superseded span would put a `whereNull('deleted_at')` on every read path for
 * history the hash-chained `audit_log` already holds, unedited and unerasable.
 *
 * `institution_id` is provenance and in-instance grouping only (D11). It is never a query filter
 * and never part of a key — `InstitutionProvenanceTest` guards both, and its index/unique pattern
 * has no allow-list at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_rota_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // cascadeOnDelete matches `person_levels`: people are SOFT-deleted (owner ruling), so
            // this FK never fires in practice; it exists so a hard delete in a future data-repair
            // script cannot leave an assignment pointing at nobody.
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete, NOT cascade: `PeriodController::destroy()` HARD-deletes an
            // academic year's periods, and a cascade there would silently take a department's
            // whole planned rota with it. The controller refuses the delete while any assignment
            // references the year; this constraint is the database's own last line behind that.
            $table->foreignId('period_id')->constrained()->restrictOnDelete();

            // restrictOnDelete: units are RETIRED (`active = false`), never deleted (UN-04).
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index(['person_id', 'period_id']);
            $table->index(['starts_on', 'ends_on']);
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_rota_assignments');
    }
};
```

**Step 3 — the model,** `app/Models/MasterRotaAssignment.php`, with `casts()` for the two dates
(`'date'`), `$fillable` for the six writable columns, `belongsTo` relations to `Person`, `Period` and
`Unit`, and:

```php
/**
 * The two invariants the database cannot express (see the migration's docblock). Modelled on
 * `Period::booted()`, deliberately — same shape, same reasoning, same `whereDate` caveat.
 *
 * Containment is checked against the row's OWN period, and periods never overlap each other
 * (`Period::booted()`), so a per-period overlap check is sufficient: one person's spans cannot
 * overlap across two different periods. A second, global per-person check would be a weaker
 * duplicate of a fact already guaranteed.
 *
 * `whereDate`, not equality — `Period::booted()` documents the live hazard: the `date` cast
 * round-trips from MySQL as 'Y-m-d 00:00:00', so equality against a plain 'Y-m-d' never matches.
 */
protected static function booted(): void
{
    static::saving(function (self $row): void {
        $period = Period::find($row->period_id);

        if ($period === null) {
            throw new RuntimeException('A master rota assignment must belong to a period.');
        }

        $from = Calendar::ymd($row->starts_on);
        $to = Calendar::ymd($row->ends_on);

        if ($to < $from) {
            throw new RuntimeException("A rota assignment ends ({$to}) before it starts ({$from}).");
        }

        $periodFrom = $period->starts_on->format(Calendar::YMD);
        $periodTo = $period->ends_on->format(Calendar::YMD);

        if ($from < $periodFrom || $to > $periodTo) {
            throw new RuntimeException(
                "A rota assignment ({$from}..{$to}) must lie inside \"{$period->label}\" "
                ."({$periodFrom}..{$periodTo}). A split that reaches outside its period is an "
                .'assignment to a period nobody is looking at.'
            );
        }

        $clash = static::query()
            ->where('person_id', $row->person_id)
            ->where('period_id', $row->period_id)
            ->when($row->exists, fn ($q) => $q->whereKeyNot($row->getKey()))
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->first();

        if ($clash !== null) {
            throw new RuntimeException(
                "This span ({$from}..{$to}) overlaps an existing one "
                ."({$clash->starts_on->format(Calendar::YMD)}..{$clash->ends_on->format(Calendar::YMD)}) "
                .'for the same person in the same period. One person on two units on one day is a '
                .'state the grid cannot render and the call roster cannot resolve.'
            );
        }
    });
}
```

Add `database/factories/MasterRotaAssignmentFactory.php` — it will be allow-listed by Task 5's guard,
exactly as `PersonLevelFactory` is by `PersonLevelsHaveOneWriterTest`.

**Step 4 — harden `PeriodController::destroy()`.** Replace the docblock's P1d hook with the real
check, placed **before** any delete and after the typed-year confirmation:

```php
$blocking = MasterRotaAssignment::query()
    ->whereIn('period_id', Period::query()->where('academic_year', $academicYear)->select('id'))
    ->count();

if ($blocking > 0) {
    throw ValidationException::withMessages([
        'confirm_academic_year' => "This academic year has {$blocking} master rota assignment(s) "
            .'against it. Deleting its periods would delete the rota with them, and there is no '
            .'soft delete to recover from. Clear the rota for this year first (Master Rota), then '
            .'delete the periods.',
    ]);
}
```

Update the method's docblock: the hook is closed, and name `AssignmentIntegrityTest` and the two
replacement tests as what pins it. `vacations` is deliberately **not** checked here — it carries no
`period_id` (Decision C), so deleting periods cannot orphan one.

**Step 5 — correct the unlock instruction (finding 3).** In `CalendarSettingsRequest`, the hard-lock
message becomes:

```
'Periods have already been generated against this setting. Delete this academic year's periods '
.'first (Structure → Periods) — which is itself refused while the master rota references them — '
.'then change this.'
```

**Step 6 — verify.**

```bash
npm run build && php artisan test | tail -5
```

Expected: `1083 → 1094` (9 new `AssignmentIntegrityTest` + 2 replacing 1 in
`PeriodGenerationScreenTest`, so +1 there). `InstitutionProvenanceTest` must stay green — read the new
migration once more and confirm `institution_id` appears in no `index([...])` or `unique([...])` array.

```bash
git commit -am "feat: a rota assignment is a span, and it cannot escape its period"
```

---

### Task 5: `App\Support\Rota\RotaAssignment` — the one writer, and the guard that keeps it the only one

**Files:**
- Create: `app/Support/Rota/RotaAssignment.php`
- Create: `tests/Feature/Build/RotaWritersAreSingularTest.php`
- Create: `tests/Feature/Rota/RotaAssignmentWriterTest.php`

**Step 1 — the failing guard.** `tests/Feature/Build/RotaWritersAreSingularTest.php`, following
`PersonLevelsHaveOneWriterTest`'s shape exactly (same scan triple, same `.php` filter, same
`assertSame([], $offenders)` ending, same staleness twin):

```php
private const ALLOW_LIST = [
    // The one writer of master_rota_assignments (P1d Decision F).
    'app/Support/Rota/RotaAssignment.php',
    // The one writer of vacations (Task 6).
    'app/Support/Rota/VacationBooking.php',
    // Factories are test scaffolding, not a production write path — the same carve-out
    // PersonLevelsHaveOneWriterTest makes for PersonLevelFactory.
    'database/factories/MasterRotaAssignmentFactory.php',
    'database/factories/VacationFactory.php',
];

private const NEEDLES = [
    'MasterRotaAssignment::create(',
    'MasterRotaAssignment::insert(',
    'MasterRotaAssignment::updateOrCreate(',
    "DB::table('master_rota_assignments')",
    'DB::table("master_rota_assignments")',
    'Vacation::create(',
    'Vacation::insert(',
    'Vacation::updateOrCreate(',
    "DB::table('vacations')",
    'DB::table("vacations")',
];
```

Two methods: `test_only_the_rota_writers_write_the_rota_tables` and
`test_every_allow_listed_file_still_exists`. Task 5 creates only the first two allow-list entries'
files; the `Vacation*` entries land in Task 6, so **write this guard with only the two
`RotaAssignment` entries now** and extend it in Task 6 — otherwise the staleness twin fails on files
that do not exist yet.

**Step 2 — the writer,** `app/Support/Rota/RotaAssignment.php`:

```php
<?php

namespace App\Support\Rota;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Support\Calendar;
use Illuminate\Support\Facades\DB;

/**
 * The ONE writer of `master_rota_assignments` (Munawib MR-02). `RotaWritersAreSingularTest` proves
 * it, the same way `PersonLevelsHaveOneWriterTest` proves `LevelAssignment` is for
 * `person_levels`.
 *
 * Every method REFUSES BEFORE IT WRITES. `split()` in particular validates the whole span set —
 * containment, ordering, mutual overlap — before opening the transaction that deletes what is
 * already there, so a rejected split never destroys a good one. That is `UnitMerge`'s
 * pre-check-and-refuse discipline and `AccessControlController::updateRoles()`' "authorize the
 * whole set before any write", applied here.
 *
 * `split()` REPLACES, never merges. Merging a partial span set into an existing one is where an
 * overlap sneaks past a check that only looked at what was submitted.
 *
 * There is no `restore()` and no undo: Decision E makes a clear a real delete, with the
 * hash-chained audit_log as the history. UX-03's undo/redo arrives with the Stage-2 workbench.
 */
final class RotaAssignment
{
    public const ASSIGNED = 'assigned';

    public const UNCHANGED = 'unchanged';

    public const REPLACED = 'replaced';

    public const CLEARED = 'cleared';

    public const NOTHING_TO_CLEAR = 'nothing_to_clear';

    /** One unit for the whole period. The degenerate split: exactly one row, period-wide. */
    public static function set(Person $person, Period $period, Unit $unit): string
    {
        $existing = self::spansFor($person, $period);

        if (count($existing) === 1
            && (int) $existing[0]->unit_id === (int) $unit->getKey()
            && $existing[0]->starts_on->format(Calendar::YMD) === $period->starts_on->format(Calendar::YMD)
            && $existing[0]->ends_on->format(Calendar::YMD) === $period->ends_on->format(Calendar::YMD)) {
            return self::UNCHANGED;
        }

        $had = $existing !== [];

        DB::transaction(function () use ($person, $period, $unit): void {
            self::deleteSpans($person, $period);

            MasterRotaAssignment::create([
                'institution_id' => $person->institution_id,
                'person_id' => $person->getKey(),
                'period_id' => $period->getKey(),
                'unit_id' => $unit->getKey(),
                'starts_on' => $period->starts_on->format(Calendar::YMD),
                'ends_on' => $period->ends_on->format(Calendar::YMD),
            ]);
        });

        return $had ? self::REPLACED : self::ASSIGNED;
    }

    /**
     * MR-02's date-bounded sub-assignments. Replaces every span this person holds in this period.
     *
     * Gaps between spans are ACCEPTED (owner decision 3): a mid-block joiner and a half-planned
     * year are both real states, and the grid renders uncovered days rather than refusing them.
     * Overlaps are refused here, before the transaction, and again by the model's own guard.
     *
     * @param  list<array{unit_id:int, starts_on:string, ends_on:string}>  $spans
     *
     * @throws \InvalidArgumentException when the set is empty, escapes the period, or self-overlaps
     */
    public static function split(Person $person, Period $period, array $spans): string
    {
        if ($spans === []) {
            throw new \InvalidArgumentException('A split needs at least one span. To remove an assignment, clear it.');
        }

        $periodFrom = $period->starts_on->format(Calendar::YMD);
        $periodTo = $period->ends_on->format(Calendar::YMD);

        $normalised = [];

        foreach ($spans as $span) {
            $from = Calendar::ymd($span['starts_on']);   // throws on anything but Y-m-d
            $to = Calendar::ymd($span['ends_on']);

            if ($to < $from) {
                throw new \InvalidArgumentException("A span ends ({$to}) before it starts ({$from}).");
            }

            if ($from < $periodFrom || $to > $periodTo) {
                throw new \InvalidArgumentException(
                    "A span ({$from}..{$to}) falls outside \"{$period->label}\" ({$periodFrom}..{$periodTo})."
                );
            }

            $normalised[] = ['unit_id' => (int) $span['unit_id'], 'starts_on' => $from, 'ends_on' => $to];
        }

        usort($normalised, fn (array $a, array $b): int => $a['starts_on'] <=> $b['starts_on']);

        for ($i = 1; $i < count($normalised); $i++) {
            if ($normalised[$i]['starts_on'] <= $normalised[$i - 1]['ends_on']) {
                throw new \InvalidArgumentException(
                    'Two spans in this split cover the same day. One person on two units on one day '
                    .'is a state the grid cannot render and the call roster cannot resolve.'
                );
            }
        }

        $had = self::spansFor($person, $period) !== [];

        DB::transaction(function () use ($person, $period, $normalised): void {
            self::deleteSpans($person, $period);

            foreach ($normalised as $span) {
                MasterRotaAssignment::create([
                    'institution_id' => $person->institution_id,
                    'person_id' => $person->getKey(),
                    'period_id' => $period->getKey(),
                    'unit_id' => $span['unit_id'],
                    'starts_on' => $span['starts_on'],
                    'ends_on' => $span['ends_on'],
                ]);
            }
        });

        return $had ? self::REPLACED : self::ASSIGNED;
    }

    public static function clear(Person $person, Period $period): string
    {
        if (self::spansFor($person, $period) === []) {
            return self::NOTHING_TO_CLEAR;
        }

        DB::transaction(fn () => self::deleteSpans($person, $period));

        return self::CLEARED;
    }

    /** @return list<MasterRotaAssignment> */
    private static function spansFor(Person $person, Period $period): array
    {
        return MasterRotaAssignment::query()
            ->where('person_id', $person->getKey())
            ->where('period_id', $period->getKey())
            ->orderBy('starts_on')
            ->get()
            ->all();
    }

    private static function deleteSpans(Person $person, Period $period): void
    {
        MasterRotaAssignment::query()
            ->where('person_id', $person->getKey())
            ->where('period_id', $period->getKey())
            ->delete();
    }
}
```

**Step 3 — `tests/Feature/Rota/RotaAssignmentWriterTest.php`,** eleven cases:
`set()` on an empty cell returns `ASSIGNED` and writes exactly one period-wide row; `set()` with the
same unit twice returns `UNCHANGED` and writes nothing; `set()` over a split returns `REPLACED` and
leaves exactly one row; `split()` with two contiguous spans writes two rows; `split()` with a gap
writes two rows and leaves the gap; `split()` with mutually overlapping spans throws
`InvalidArgumentException` **and leaves the previous assignment untouched** (assert the row count
before and after — this is the property the pre-check exists for); `split()` reaching outside the
period throws; `split()` with an empty array throws with the "clear it" message; `split()` with a date
that is not `Y-m-d` throws (`Calendar::parse()` is a normaliser, not a re-admission of `strtotime()`
leniency — P1 finding 3); `clear()` on an empty cell returns `NOTHING_TO_CLEAR` and writes nothing;
`clear()` removes every span for that person and period and **no other person's**.

**Step 4 — verify.**

```bash
npm run build && php artisan test | tail -5
```

Expected: `1094 → 1107` (11 `RotaAssignmentWriterTest` + 2 `RotaWritersAreSingularTest`).

```bash
git commit -am "feat: one writer for the rota, and a test that keeps it the only one"
```

---

### Task 6: `vacations` — week or exact date, and never keyed on a period

**Files:**
- Create: `database/migrations/2026_08_15_120004_create_vacations_table.php`
- Create: `app/Models/Vacation.php`
- Create: `database/factories/VacationFactory.php`
- Create: `app/Support/Rota/VacationBooking.php`
- Modify: `tests/Feature/Build/RotaWritersAreSingularTest.php`
- Create: `tests/Feature/Rota/VacationTest.php`

**Step 1 — the failing tests.** `tests/Feature/Rota/VacationTest.php`. The cases that matter, each
pinning a decision rather than an implementation detail:

- `test_a_week_booking_snaps_to_the_departments_own_week` — book `2026-08-12` (a Wednesday) at
  `week` granularity with `weekend_days = [5,6]`; assert stored `2026-08-09 .. 2026-08-15`. Then
  reconfigure to `weekend_days = [6,7]`, flush, book the same day, assert `2026-08-10 .. 2026-08-16`.
  **The department's configuration decides, not a constant.**
- `test_a_date_booking_is_stored_verbatim` — `2026-08-12 .. 2026-08-14` stays exactly that.
- `test_a_multi_week_booking_snaps_both_ends` — a `week` booking from a Wednesday to the following
  Tuesday covers two whole weeks.
- `test_a_vacation_carries_no_period_id` — `assertFalse(Schema::hasColumn('vacations', 'period_id'))`,
  with the decision's reasoning in the test's own docblock. This is the guard on Decision C.
- `test_a_vacation_spanning_two_periods_is_one_row` — create two adjacent periods, book leave across
  the boundary, assert `Vacation::count() === 1`.
- `test_a_vacation_survives_its_periods_being_deleted` — delete the academic year's periods (no
  assignments exist, so Task 4's guard permits it) and assert the vacation row is still there and
  still readable. This is the property Decision C exists for.
- `test_two_overlapping_vacations_for_one_person_are_refused` — `RuntimeException` from the model
  guard.
- `test_two_people_may_be_on_leave_the_same_week` — accepted.
- `test_a_vacation_ending_before_it_starts_is_refused`.
- `test_the_granularity_and_source_values_are_constrained_to_the_model_constants` — an unknown value
  throws rather than storing a fourth kind of vacation nobody handles.

**Step 2 — the migration,** `2026_08_15_120004_create_vacations_table.php`. Columns: `id`,
`institution_id` (nullable, provenance, `constrained()->nullOnDelete()`), `person_id`
(`constrained()->cascadeOnDelete()`), `starts_on` (date), `ends_on` (date), `granularity`
(string 10), `source` (string 20), timestamps. Indexes: `index('person_id')`,
`index(['starts_on', 'ends_on'])`. **No `period_id`, no soft delete, no notes column.**

The docblock carries Decision C in full: why there is no `period_id` (a vacation crosses period
boundaries; it is an overlay on an assignment, not a replacement for one; and periods are regenerable
and hard-deletable structure that a vacation must outlive), why the dates are always real dates while
`granularity` records only how the leave was entered and is edited, and that `source` ships `manual`
and `import` with AR-05's third source — an approved RQ-01 leave request — **named as the P3 hook it
is, not built**.

**Step 3 — the model,** `app/Models/Vacation.php`: constants `GRANULARITY_WEEK = 'week'`,
`GRANULARITY_DATE = 'date'`, `SOURCE_MANUAL = 'manual'`, `SOURCE_IMPORT = 'import'`; `casts()` for the
two dates; a `belongsTo(Person::class)`; a `scopeIntersecting(Builder $q, string $from, string $to)`
carrying the both-bounds-inclusive `whereDate` pair once so no caller writes it twice; and a
`booted()` `saving` guard refusing an inverted range, an unknown `granularity` or `source`, and an
overlap with another vacation **for the same person** (`whereKeyNot` when updating, `whereDate` not
equality — finding 10).

**Step 4 — the writer,** `app/Support/Rota/VacationBooking.php`, with `book(Person $person, string
$fromYmd, string $toYmd, string $granularity, string $source = Vacation::SOURCE_MANUAL): Vacation` and
`cancel(Vacation $vacation): void`. `book()` snaps when `granularity === Vacation::GRANULARITY_WEEK`:

```php
// The week is the DEPARTMENT's week (Calendar::weekOf(), Task 2) — derived from `weekend_days`,
// never a hardcoded Sunday or Monday. Munawib AR-05 specifies a 'week' granularity and never says
// what a week is; ST-01 makes the weekend configuration. This is where the two are reconciled.
if ($granularity === Vacation::GRANULARITY_WEEK) {
    $fromYmd = Calendar::weekOf($fromYmd)['starts_on'];
    $toYmd = Calendar::weekOf($toYmd)['ends_on'];
}
```

**Step 5 — extend the guard.** Add `'app/Support/Rota/VacationBooking.php'` and
`'database/factories/VacationFactory.php'` to `RotaWritersAreSingularTest::ALLOW_LIST`, each with its
prose justification in place, per the convention every allow-list in this codebase follows.

**Step 6 — verify.**

```bash
npm run build && php artisan test | tail -5
```

Expected: `1107 → 1117` (10 new `VacationTest`). `RotaWritersAreSingularTest`'s two cases stay green
with the widened list.

```bash
git commit -am "feat: leave that outlives the periods it happens to cross"
```

---

### Task 7: `PersonPresenter` stops handing every viewer an email address

**Why now, and why its own task:** finding 1. This is a security change, and it must land **before**
any prop-building code exists that a reviewer would have to read it alongside. The presenter's own
docblock prescribes the exact fix.

**Files:**
- Modify: `app/Support/PersonPresenter.php`
- Create: `tests/Feature/Admin/ContactProjectionNarrowsTest.php`

**Step 1 — the failing test.** `tests/Feature/Admin/ContactProjectionNarrowsTest.php`. Following P1c
Task 2's amendment, these call `PersonPresenter::one()` **directly** rather than through a route: every
existing caller sits behind `cap:people.manage`, which is also what `viewContact()`'s first branch
grants, so the refusing branch is unreachable through any current endpoint and the presenter is
precisely what is under test.

```php
public function test_email_is_absent_not_null_when_the_policy_refuses(): void
{
    // A resident: no people.manage, and the department has not opted members into contacts.
    $viewer = User::factory()->create(['position' => 4]);
    $person = Person::factory()->create(['email' => 'someone@example.test']);

    $projected = PersonPresenter::one($person, $viewer);

    $this->assertArrayNotHasKey('email', $projected);
    $this->assertArrayNotHasKey('phone', $projected);
    $this->assertSame($person->full_name, $projected['full_name']);
}

public function test_a_roster_manager_still_sees_the_email(): void
{
    $admin = User::factory()->create(['position' => 0]);
    $person = Person::factory()->create(['email' => 'someone@example.test']);

    $this->assertSame('someone@example.test', PersonPresenter::one($person, $admin)['email']);
}

public function test_the_members_setting_reveals_the_email_alongside_the_phone(): void
{
    // PE-02's department setting: `email` follows `phone` exactly, which is the whole point —
    // two contact fields governed by one decision, not one governed and one forgotten.
    ...
}

public function test_a_null_viewer_gets_no_contact_field_at_all(): void
{
    $this->assertArrayNotHasKey('email', PersonPresenter::one(Person::factory()->create(), null));
}
```

Run it and watch the first case fail: `email` is present today for every viewer.

**Step 2 — the change.** In `PersonPresenter::one()`, move `'email'` out of `$base` and into the
existing `viewContact` branch beside `phone`. Replace the long "safe TODAY only because" comment with
what is now true:

```php
// `email` and `phone` are both contact fields and are governed by ONE decision
// (`PersonPolicy::viewContact()`), not one governed and one forgotten. This was not always so:
// `email` shipped ungated in P1c because every caller then held `people.manage`, which is also
// what viewContact()'s first branch grants — a no-op distinction. P1d's rota grid is the first
// consumer with a narrower capability (`rota.view`, held by every resident), which is exactly the
// case that docblock predicted, so the gate is now real.
if ($viewer !== null && $viewer->can('viewContact', $person)) {
    $base['phone'] = $person->phone;
    $base['email'] = $person->email;
}
```

Note the `ContactFieldsAreProjectedOnceTest` interaction: `PersonPresenter.php` is already the first
entry on that guard's allow-list, so moving a line inside it changes nothing there.

**Step 3 — check the two existing consumers empirically, not by reasoning.** Run
`php artisan test --filter "PeopleAccessTest|PersonCrudTest|ContactVisibilityTest|RosterImportTest|PeopleBulkTest"`
and confirm green. `PersonController::exportTable()` reads `array_key_exists('phone', …)` to decide on
a Phone column; if the roster CSV also carries an Email column built the same way, it now needs the
same key-presence check — **read that method before assuming it does not.**

**Step 4 — verify.**

```bash
npm run build && php artisan test | tail -5
```

Expected: `1117 → 1121` (4 new). Any red in the five filters above is a real consumer this step
missed; fix the consumer, not the gate.

```bash
git commit -am "fix: an email address is a contact field, and now it is treated as one"
```

---

### Task 8: the grid — rows by level, columns by period, seven queries

**Files:**
- Create: `app/Support/Rota/RotaGrid.php`
- Modify: `app/Http/Controllers/Admin/MasterRotaController.php`
- Create: `app/Http/Requests/Admin/RotaCellRequest.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Admin/MasterRota.vue`
- Create: `tests/Feature/Rota/RotaGridTest.php`

**Step 1 — the failing test.** `tests/Feature/Rota/RotaGridTest.php`. The cases:

- `test_the_grid_renders_a_row_per_active_person_and_a_column_per_period` — 6 people, 13 periods.
- `test_rows_are_grouped_by_the_level_held_on_the_academic_years_start_date` — Decision G. Assert the
  group key, and assert a person promoted mid-year appears in **one** group.
- `test_a_cell_carries_the_level_held_at_its_own_period_when_it_differs_from_the_row_group` — the
  mid-year promotion is visible per cell, not hidden by the stable grouping.
- `test_a_retired_person_is_not_offered_a_row` — `Person::query()->active()`; finding 15.
- `test_every_date_reaching_the_client_is_server_formatted_and_dual_dated` — assert each period prop
  carries `starts_label.date` **and** `starts_label.hijri`.
- `test_a_cell_with_two_spans_reports_both_and_the_uncovered_days` — owner decision 3: the gap is
  counted and named, never silently absent.
- `test_a_vacation_intersecting_a_period_appears_on_that_periods_cell` — and on **both** cells when it
  straddles two periods.
- `test_the_whole_grid_is_a_bounded_number_of_queries` — the Decision G budget, asserted:

```php
public function test_the_whole_grid_is_a_bounded_number_of_queries(): void
{
    $this->seedYear(periods: 13, people: 60);      // the real shape: 780 cells

    $admin = User::factory()->create(['position' => 0]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($admin)->get('/admin/rota?year=2026-2027')->assertOk();

    $count = count(DB::getQueryLog());

    // Seven data queries (Decision G) plus the framework's own session/auth/capability reads.
    // The number that matters is that it does NOT grow with 60 people or 13 periods — a per-cell
    // `Person::levelAt()` would be 780 on its own, and `$assignment->unit` another 780.
    $this->assertLessThan(30, $count, "the grid ran {$count} queries for 780 cells");
}
```

Read what the framework already contributes to a logged-in Inertia request before fixing the `30` —
run the assertion once with a deliberately huge bound, print the count, then set the bound just above
the measured value. **Evidence before arithmetic**; do not copy the number from this document.

**Step 2 — `App\Support\Rota\RotaGrid`.** One `public static function forYear(string $academicYear,
?User $viewer): ?array`, returning `null` when the year has no periods. It runs exactly the seven
queries of Decision G, in that order, and builds:

```
periods:  [{ id, position, label, kind, starts_on, ends_on, starts_label, ends_label, weeks: [...] }]
levels:   [{ id, code, name }]                     // the row groups, in display order
units:    [{ id, code, name, bar_class }]          // the picker AND the per-cell hue map
rows:     [{ person: <PersonPresenter::one()>, group_level_id, cells: { <periodId>: {
              spans: [{ id, unit_id, starts_on, ends_on, starts_label, ends_label }],
              uncovered_days: <int>,
              level_id: <int|null>,                // the level held at THIS period's start
              vacations: [{ id, starts_on, ends_on, granularity, starts_label, ends_label }],
          } } }]
```

The `weeks` key on each period is `Calendar::weeksIn($period->starts_on, $period->ends_on)` — it costs
no query, and it is what P1d-2's MR-07 week strip renders from, so it is built once here rather than
recomputed there.

Non-negotiables inside this class, each of which is a named trap from Decision G:

- `Person::query()->active()->withExists(['user as has_account'])->orderBy('people.full_name')->get()`
  — whole models, never a narrowed `select()`; the `withExists` stops `PersonPresenter` running one
  EXISTS per row.
- `Person::levelSpansBetween($people, $yearStart, $yearEnd)` **once**, then
  `Person::levelFromSpans()` per (person, period) in memory. Never `levelAt()` inside a loop.
- Units resolved from the pre-built id-keyed map. **Never `$assignment->unit`.**
- `PersonPresenter::one($person, $viewer)` for the person object — the one projection, now correctly
  narrowed by Task 7. Never a second inline map.
- `Calendar::label()` is called for period bounds, split bounds, vacation bounds and week bounds
  **only** — never per day of a period.

**Step 3 — the endpoints.** Add to the `cap:rota.manage` group in `routes/web.php`:

```php
Route::patch('/rota/cell', [MasterRotaController::class, 'setCell'])->name('rota.cell');
Route::post('/rota/cell/split', [MasterRotaController::class, 'splitCell'])->name('rota.cell.split');
Route::delete('/rota/cell', [MasterRotaController::class, 'clearCell'])->name('rota.cell.clear');
Route::post('/rota/vacations', [MasterRotaController::class, 'bookVacation'])->name('rota.vacations.store');
Route::delete('/rota/vacations/{vacation}', [MasterRotaController::class, 'cancelVacation'])->name('rota.vacations.destroy');
```

`{vacation}` takes the default binding and needs no `->withTrashed()`: `Vacation` does not use
`SoftDeletes` (Decision E), so there is no trashed row for the binding to exclude. Note that inline, per
P1c Task 7's follow-up discipline — a reader should not have to work it out.

`RotaCellRequest` validates `person_id` and `period_id` with `Rule::exists` on the raw builder,
`unit_id` against **the same predicate the picker offers from** (`Unit::query()->active()`) — the
2026-07-26 audit's offer/validate parity rule, which `SignoffPickers` and `LevelPickers` both exist to
hold, and which `Rule::exists` running on the raw query builder (blind to Eloquent's global scopes) is
exactly why one predicate must serve both sides. Dates are `date_format:Y-m-d`, never `date` (P1
finding 3: `strtotime()` leniency put backdated clinical rows in production once already).

Each controller action delegates to `RotaAssignment`/`VacationBooking`, audits per Decision H, and
returns `back()` so Inertia re-renders the page with fresh props.

**Step 4 — the screen.** `resources/js/Pages/Admin/MasterRota.vue`, following
`Units.vue`/`People.vue`'s mobile-cards-plus-desktop-table shape:

- An academic-year selector (a plain `<select>` of `years`, submitted as `?year=`).
- Desktop: a table. First column is the person; one column per period. `<th>` carries the period label
  plus `starts_label.date` and `starts_label.hijri` as **server-supplied strings**. Row-group header
  rows carry the level code and name.
- `data-row-id="person-<id>"` on each row and `data-col-key="period-<id>"` on each cell —
  **stable ids, never indices**: the grid re-sorts by level and by name and the selector must not
  address a moving target.
- `const desktopColumnCount = computed(() => 1 + props.grid.periods.length)` — finding 14. Never a
  hardcoded colspan.
- Per-cell unit `<select>` firing `saveCell(personId, periodId, unitId)` on `change`. The
  `SaveStatus` machine is lifted from `Sheet.vue:91-127` verbatim, keyed `${personId}:${periodId}`,
  including `preserveScroll: true` **and `preserveState: true`** — finding 13; without the latter
  Inertia remounts the page and wipes the indicator before it can be read.
- The PATCH sends only the changed cell, never the grid.
- A cell with more than one span renders its spans read-only with a "Split" affordance opening
  Task 9's editor; the `<select>` is not the control for a split cell, because choosing a unit there
  would silently collapse deliberate work.
- Mobile: one card per person, each listing its periods vertically. Same handlers, same testids.
- Semantic classes only: `bg-panel`, `bg-ground-deep` for the header row, `text-muted`,
  `channel-tag` for the level and External chips, `channel-bar-*` from `unit.bar_class` for the cell's
  hue, `rounded-md`. No raw palette class, no hex, no `dark:`, no `bg-panel-soft` (it compiles to
  nothing — P1 finding 13).
- **No date arithmetic anywhere.** Finding 7's ten needles, no allow-list.

**Step 5 — verify.**

```bash
npm run build && php artisan test | tail -5
npm test | tail -5
```

Expected: `1121 → 1133` (about 12 new `RotaGridTest` cases; count the methods you actually wrote and
trust that over this number — four separate P1c tasks recorded the plan's own arithmetic being stale
before the task began). `CalendarIsTheOnlyConverterTest` and `CompiledCssIsLightOnlyTest` must both be
green, and the latter only proves anything **after** `npm run build`.

```bash
git commit -am "feat: a year of the department's rota on one screen"
```

---

### Task 9: splits in a cell, and the gap the plan allows

**Files:**
- Modify: `resources/js/Pages/Admin/MasterRota.vue`
- Modify: `app/Http/Requests/Admin/RotaCellRequest.php`
- Create: `tests/Feature/Rota/RotaSplitEndpointTest.php`
- Create: `tests/js/MasterRotaSplit.test.js`

**Step 1 — the failing tests.** `RotaSplitEndpointTest`: a two-span split persists two rows; a split
whose spans overlap returns a **422 naming the field** (`assertJsonValidationErrors` with an
`X-Inertia`/JSON `Accept` header — `assertSessionHasErrors` alone passes equally for a 302 *or* a 200
and does not pin the status; P1b Task 4's amendment is the precedent) and leaves the previous
assignment byte-identical; a split reaching outside its period is a 422; a split with a retired unit
is a 422 (offer/validate parity); a split of one span collapses to the same shape `set()` produces; a
split is audited once as `rota_split` with `person=<id>;period=<id>;spans=<n>` and **no name in the
detail**.

`tests/js/MasterRotaSplit.test.js`: the split editor renders one row per span with the period's own
bounds as the `min`/`max` on each `<input type="date">` (server-supplied strings, no client date
maths), the uncovered-day count updates from the **server's** `uncovered_days` after a save rather
than being recomputed client-side, and "Add span" appends a row whose dates start empty rather than
guessing.

**Step 2 — the editor.** A panel opened from a cell, listing the cell's spans; each row is a unit
`<select>` plus two `<input type="date">`. Submit posts the **whole span set** to
`POST /admin/rota/cell/split`; the writer replaces, never merges (Task 5). The panel shows the
server-computed `uncovered_days` with plain language — "4 days in this block are unassigned" — and
does **not** treat that as an error, because owner decision 3 makes a gap a legitimate state. A
"Remove span" control removes a row from the submission; removing the last one is disabled, with the
title naming Clear as the way to empty a cell (matching the writer's own refusal message, so the UI
and the exception say the same thing).

`<input type="date">` produces a `Y-m-d` string with no client-side date construction, so finding 7's
guard is satisfied by construction rather than by exception.

**Step 3 — verify.**

```bash
npm run build && php artisan test | tail -5
npm test | tail -5
```

Expected: `1133 → 1139` (6 new). `npm test` `114 → 117`.

```bash
git commit -am "feat: a block a person spends in two units, said once"
```

---

### Task 10: vacations on the grid, at week or exact date

**Files:**
- Modify: `resources/js/Pages/Admin/MasterRota.vue`
- Modify: `app/Http/Controllers/Admin/MasterRotaController.php`
- Create: `app/Http/Requests/Admin/VacationRequest.php`
- Create: `tests/Feature/Rota/VacationEndpointTest.php`
- Modify: `tests/js/MasterRotaSplit.test.js` (or a sibling `MasterRotaVacation.test.js`)

**Step 1 — the failing tests.** `VacationEndpointTest`: booking at `week` granularity stores the
department's own snapped week (assert against `Calendar::weekOf()`, not a literal Sunday — the whole
point of Task 2); booking at `date` granularity stores the submitted dates verbatim; an overlapping
booking for the same person is a 422, not a 500 (the model guard's `RuntimeException` must be caught
and converted, exactly as `PeriodController::store()` converts `Period::booted()`'s — P1b finding 14's
lesson, restated: a model guard reaching the user as a raw 500 is a bug in the controller, not the
guard); a booking is audited as `vacation_book` with `person=<id>;from=<date>;to=<date>;granularity=<key>`
— dates are schedule facts, not PHI, and they are what makes the row identifiable in an audit; a
cancellation is audited as `vacation_cancel`; a person on leave shows the leave on **every** period
cell it intersects, not only the first.

**Step 2 — the surface.** A per-cell "On leave" affordance opening a small form: a granularity toggle
(Week / Exact dates) and two date inputs. When Week is selected the form shows the snapped range the
server would store — obtained from the props' `periods[].weeks` list, which Task 8 already built, so
no client date arithmetic is needed to preview it. Existing leave renders in the cell as a
`channel-tag` with the server-formatted range, plus a Cancel control.

`VacationRequest` validates `person_id` (`Rule::exists`), `starts_on`/`ends_on`
(`date_format:Y-m-d`), `granularity` (`Rule::in([Vacation::GRANULARITY_WEEK,
Vacation::GRANULARITY_DATE])` — the constants, never string literals in two places), and refuses a
range longer than 550 days before `Calendar::weeksIn()` is ever asked to.

**Step 3 — verify.**

```bash
npm run build && php artisan test | tail -5
npm test | tail -5
```

Expected: `1139 → 1146` (7 new). `npm test` `117 → 119`.

```bash
git commit -am "feat: leave that the rota can see"
```

---

### Task 11: the e2e journey — the assignment survives a reload

**Why:** CLAUDE.md's rule, verbatim: *"Autosave is never fire-and-forget: per-field save-on-blur, UI
reflects the server response, e2e asserts persistence after reload — never the indicator alone."* A
green save indicator over a failed write is exactly the class of bug this rule exists for.

**Files:**
- Create: `tests/e2e/master-rota.spec.js`
- Modify: `tests/e2e/fixtures.js` (or `database/seeders/E2eSeeder.php`) — whichever the existing specs
  use to establish their world; read `tests/e2e/global-setup.js` first and follow it rather than
  inventing a second seeding path.

**Step 1 — the spec.** Signed in as the seeded administrator, at `/admin/rota?year=<seeded year>`:

1. Assert the grid renders a known person's row and a known period's column, addressed by
   `[data-row-id="person-N"] [data-col-key="period-M"]` — **never** by nth-child, because the grid
   re-sorts.
2. Choose a unit in that cell; assert the save indicator reaches its saved state.
3. **Reload the page.** Assert the cell still shows that unit. This assertion, not step 2, is what the
   test exists for.
4. Open the split editor on the same cell, submit two spans, reload, assert both spans render and the
   uncovered-day count matches the server's.
5. Book a week's leave, reload, assert the leave chip renders on **both** periods it straddles.
6. Clear the cell, reload, assert it is empty and the leave is still there — clearing an assignment is
   not cancelling leave, and the two must not be entangled.

**Step 2 — verify.**

```bash
npm run build && npm run test:e2e | tail -20
php artisan test | tail -5
```

The e2e world is self-contained; if the seeder has no periods, the grid correctly shows Task 1's
teaching empty state and the spec cannot run — extend the seeder rather than weakening the spec.

```bash
git commit -am "test: the rota is still there after a reload, which is the only proof that counts"
```

---

### Task 12: correct the documents this invalidates

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`
- Modify: `docs/RUNBOOK-DEPLOY.md`
- Modify: `docs/superpowers/plans/2026-08-08-p1-master-rota.md`
- Modify: `docs/spec/15-rulings.md`

**Step 1 — `CLAUDE.md`.**

- **Do NOT "fix" the hardcoded-units bullet.** Before writing anything, run
  `grep -n "AppLayout.vue" CLAUDE.md`: exactly one hit, and it already says both exceptions were
  closed by P1b Task 3. The *"Two known exceptions, pending"* wording survives only in cached copies
  handed to agents; reverting the on-disk file to match it would undo P1b's own correction. This is
  listed because it is a trap, not a task.
- **Add the rota's own non-negotiables**, in the same register as the existing ones:
  - A master-rota assignment is always a date-bounded span; overlaps are refused, gaps are allowed and
    counted. `App\Support\Rota\RotaAssignment` is the only writer, `RotaWritersAreSingularTest` proves
    it.
  - `vacations` carries no `period_id`, deliberately — a vacation crosses period boundaries and must
    outlive a regenerated or switched period system.
  - Deleting an academic year's periods is refused while any assignment references it. There is no
    soft delete on either rota table: the hash-chained `audit_log` is the history.
  - `PersonPresenter` gates `email` **and** `phone` behind `viewContact`. A rota surface reaches
    people through the presenter and never builds a second projection.
  - MR-04 — the rota driving on-call eligibility — is Stage 2. Nothing in the rota infers eligibility,
    and there is no `off_roster` flag.

**Step 2 — the design doc.**

- §6.3: annotate `master_rota_assignments` with the one-row-per-span shape and its reasoning, and
  `vacations` with the deliberate absence of `period_id` (Decision C).
- §7: record `Calendar::weekStartIsoDay()`/`weekOf()`/`weeksIn()` as the resolution of a genuine gap
  in Munawib's own spec — AR-05 gives vacations a `week` granularity while ST-01 makes the weekend
  configuration, and the two are reconciled by deriving the week start from `weekend_days`.
- §9.1: record that MR-05's read view is `cap:rota.view`, that tokenized share links remain P3, and —
  separately — that `masterRota` has no draft/published status in AR-05, so P1d ships no publish state
  machine and §18's versioning lands once in Stage 2 (Decision D).
- The override table: add the AR-05 `week` derivation, the `email` gating, and the P1d-2 CSV-only
  import (P1c Decision E, carried forward).
- §13's phase table: mark P1d-1 shipped with its scope, and name P1d-2's.
- §14 open items: add **"MR-04's eligibility derivation is unbuilt and its hook is recorded"** and
  **"the master rota has no publish state, by decision — revisit if the owner wants a gate"** as
  numbered items, so neither is remembered only inside a plan file.

**Step 3 — `docs/RUNBOOK-DEPLOY.md`.** Add the two migrations with their post-migration verification
queries (`SHOW CREATE TABLE master_rota_assignments;` / `vacations;` and a row count of each), and the
note that the owner runs them. Do **not** put the string `ancestor=mysql` in any document under
`docs/` outside `docs/superpowers/` — `HostScriptsAreInstanceScopedTest` scans that tree.

**Step 4 — the P1 plan.** In `docs/superpowers/plans/2026-08-08-p1-master-rota.md`, correct the
"Migration ordering" line that allocates `2026_08_15_*` to P1d (finding 4), and mark P1d's task list
as superseded by this document and its P1d-2 successor.

**Step 5 — `docs/spec/15-rulings.md`.** Add the P1d rulings that a future reader will otherwise
re-litigate: no publish state on the master rota; overlaps refused, gaps allowed; no soft delete on the
rota tables; MR-04 is Stage 2.

**Step 6 — assert MR-04's absence.** Add to `tests/Feature/Rota/RotaAccessTest.php`:

```php
public function test_nothing_in_the_rota_infers_on_call_eligibility(): void
{
    // Owner decision 1: MR-04 is Stage 2. It has nothing to drive — slots, call rosters and
    // per-person include/exclude overrides do not exist. This guard pins the absence so a later
    // plan reaching for "the rota already knows who is eligible" fails the build instead of
    // shipping half of a requirement.
    $offenders = [];

    foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        foreach (['off_roster', 'offRoster', 'callEligib', 'call_eligib'] as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = str_replace('\\', '/', $file->getRelativePathname()).": {$needle}";
            }
        }
    }

    $this->assertSame([], $offenders, 'MR-04 is Stage 2 (P1d owner decision 1) — see the plan.');
}
```

**Step 7 — final verification, the whole thing.**

```bash
npm run build && php artisan test | tail -5
npm test | tail -5
npm run test:e2e | tail -20
```

Expected: `1146 → 1147` (1 new). `npm test` unchanged. Then record what actually happened in
[Amendments](#amendments-made-during-execution) — every task's real count, every place this plan was
wrong, and anything found empirically rather than by inspection.

```bash
git commit -am "docs: correct what the master rota invalidates"
```

---

## Definition of done — P1d-1

- [ ] `php artisan test` green, run via **Bash**, after `npm run build`. `npm test` green.
      `npm run test:e2e` green.
- [ ] `rota.view` and `rota.manage` seeded, in `ROLE_DEFAULTS`, in `AccessControlParityTest`'s
      expectations, and in `docs/spec/08-foundation.md`'s catalog **and** role-defaults sentences.
- [ ] Every new route behind `auth` + a `cap:`. No unauthenticated route added (D7).
- [ ] `App\Support\Calendar` answers what a week is, derived from `weekend_days`, with golden-fixture
      entries produced by running the code.
- [ ] `Person::levelSpansBetween()`/`levelFromSpans()` exist, share `inForceOn()`'s semantics, and
      `LevelResolverParityTest` proves the in-memory resolver agrees with `levelAt()` across a matrix.
- [ ] `master_rota_assignments` and `vacations` exist. Every assignment row is a span; overlaps are
      refused by the model; gaps are accepted and counted; every span lies inside its period.
- [ ] `vacations` has no `period_id`, and a test asserts the column's absence with its reasoning.
- [ ] `App\Support\Rota\RotaAssignment` and `App\Support\Rota\VacationBooking` are the only writers,
      proven by `RotaWritersAreSingularTest` with a live staleness twin.
- [ ] `PeriodController::destroy()` refuses while assignments reference the year; the old pinning test
      is replaced, not deleted; `CalendarSettingsRequest`'s unlock message tells the truth.
- [ ] `PersonPresenter::one()` gates `email` behind `viewContact`, and no consumer regressed.
- [ ] The grid renders a bounded number of queries for 60 people × 13 periods, asserted with a
      measured bound.
- [ ] Every date on the screen is server-formatted and dual-dated. `CalendarIsTheOnlyConverterTest`
      green, including its ten-needle client scan.
- [ ] The e2e spec asserts persistence **after reload**, never the save indicator alone.
- [ ] `institution_id` appears in no `where`, no `index([...])` and no `unique([...])` in either new
      migration. `InstitutionProvenanceTest` green.
- [ ] No PHI model or column referenced from any rota class (design §9.2); `rota_*` audit details
      carry ids, dates and counts only.
- [ ] MR-04 is unbuilt and its absence is asserted.
- [ ] `CompiledCssIsLightOnlyTest` and `TextContrastMeetsAaTest` green; no `dark:`, no raw palette
      class, no hex in markup.
- [ ] The documents in Task 12 corrected, including CLAUDE.md's self-contradiction.
- [ ] [Amendments](#amendments-made-during-execution) records what this plan got wrong.

---

## P1d-2 — Read, summarise, move *(scoped, not executable — its own plan when P1d-1 merges)*

**Binding requirements:** MR-05, MR-06, MR-07. **This is where Stage 1's acceptance criterion lands**
(*"availability summaries match reality"*).

1. **MR-05 — the read view.** `/rota` behind `cap:rota.view` (owner decision 5): search by name, filter
   by level, a per-person period strip, and the MR-07 summaries below it. `PersonPresenter::one()`
   with a viewer who is a resident — Task 7 is what makes that safe, and P1d-2 must not build a second
   projection to get around it. No contact field renders on this screen at all. Read-only: no PATCH
   endpoint is reachable with `rota.view` alone, and a test asserts it.
2. **MR-07 — per-period availability summaries**, per level and per unit, including who is on vacation
   each week. `App\Support\Rota\AvailabilitySummary` is the one computation, used by both the editor
   and the read view — never two. It reads the `weeks` key `RotaGrid` already builds. It counts
   **uncovered days** as well as assignments, so owner decision 3's permitted gaps are visible in the
   number rather than rounded away.
3. **MR-06 — fill-down, fill-across, copy-period.** Bulk writes, so the codebase's bulk discipline
   applies without exception (P1 finding 12; `AccessControlController::updateRoles()` is the model):
   validate and authorize the **whole** set before any mutation, one transaction, delta computed inside
   it. Two explicit fill-down actions — "this level group" and "this whole column" — rather than one
   that guesses. Fill-across fills **forwards only**. All three **preview then confirm**, and a cell
   that carries a split is **skipped unless explicitly confirmed**: a blanket fill silently destroying
   deliberate split work is the silent-data-loss class this codebase keeps guarding against.
   One summary audit row per operation (`rota_fill`), never one per cell — 780 chain appends would
   serialize the audit tail (P1 finding 11) — **and `rota_fill` is added to `AuditAnomalies`' watch
   list**, which is the reason P1d-1 deliberately added none of its own actions there (Decision H).
4. **MR-06 — export.** Through `App\Support\Csv` (`CsvIsTheOnlyReaderWriterTest`), formula-neutralised,
   BOM-first. **Two files, not one**: `rota.csv` (one row per span) and `vacations.csv` — a single file
   mixing two row shapes is either sparse or ambiguous, and the importer would have to guess which it
   was reading. Columns identify a person by `short_name` (the app-wide handle, unique) plus
   `full_name` for humans; **no email, no phone**, so EX-02's contact-bearing-export question does not
   arise at all.
5. **MR-06 — import.** CSV only (owner decision 8), behind the existing `App\Support\Roster\RosterReader`
   port and its `CsvRosterReader`, which is already format-generic. `App\Support\Rota\RotaImport`
   copies `RosterImport`'s discipline exactly: `preview()` and `commit()` share **one** `analyse()`;
   the whole file is validated before any write; the commit is pinned to the digest of the exact bytes
   the preview ran against; outcomes are `CREATE` / `REPLACE` / `SKIP_UNKNOWN_PERSON` /
   `SKIP_UNKNOWN_UNIT` / `SKIP_UNKNOWN_PERIOD` / `ERROR`; **it never invents a person, a unit or a
   period.** Add `app/Support/Rota/RotaImport.php` to `RosterNeverMintsCredentialsTest::SCANNED_FILES`
   — an import path must not mint an account — and note that doing so brings that guard's bare
   `'->save()'` needle with it, so every persistence call in that file must be `create()`/`update()`.
   **Fixtures synthetic, always** (P1c owner decision 3): deliberately exercise a person not on the
   roster, a retired unit, a period from another academic year, a span outside its period, two spans
   that overlap, Arabic names, and a formula-injection cell that must round-trip through
   `Csv::neutralise()`/`unNeutralise()`.
6. **The MR-04 hook, restated, not built.**

---

## Owner decisions needed

None blocks P1d-1. Each blocks a specific task and each has a stated default.

1. **Does the master rota get an explicit publish gate?** Decision D ships none, on the evidence that
   Munawib AR-05 gives `masterRota` no status field while `schedules` has one, and that §18's
   publish/version/archive machinery is Stage 2. If the owner wants "residents see nothing until I
   publish it", that is a real requirement, not a misreading, and it is an additive nullable column
   plus one controller branch — not a rework. *Blocks:* P1d-2 item 1. *Default if unanswered:* no
   gate; the read view shows the current rota.

2. **Does Chief Resident (position 5) hold `rota.manage` by default?** Munawib §5 grants "Manage
   master rota & clinics" to its Scheduler persona, which maps to no role in this codebase; Chief
   Resident is the nearest fit and today holds exactly one scoped admin power
   (`users.manage_residents`). *Blocks:* nothing — it is one entry in `ROLE_DEFAULTS` and one line in
   the spec catalog, and an administrator can grant it from the Access Control screen at any time.
   *Default if unanswered:* Administrator-only, granted per department from the screen.

3. **Should a vacation booked at `week` granularity that is later edited to a shorter range silently
   become a `date` booking, or refuse?** P1d-1 stores the granularity that was used and re-snaps on
   every `week` save, so editing a week booking to non-week dates is only reachable by switching the
   toggle — which is explicit. Raised here only because a future importer (P1d-2) can produce the
   combination without a human seeing the toggle. *Blocks:* P1d-2 item 5. *Default if unanswered:* the
   importer's `granularity` column is authoritative and snapping is applied on import exactly as it is
   on the screen.

---

## Stage 1 acceptance (§35), after P1d-1

> *Accepted:* the pilot's real master rota and clinics live; residents claimed accounts; availability
> summaries match reality.

P1d-1 satisfies **none of the three outright**, and is not meant to. It makes the first and third
reachable for the first time: the rota has a table, a writer, an integrity guarantee and a screen an
administrator can plan a year on, and vacations exist as data at the granularity MR-03 asks for. The
availability summaries that must "match reality" are computed from exactly these rows, and they arrive
in **P1d-2**. "Clinics live" is **P1e**. "Residents claimed accounts" is **P1c-2**.

---

## Next plan

**P1d-2 — Read, summarise, move.** Scoped above. It is written when this one merges, per the
P0a–P0d/P1a–P1c convention, and it inherits three things from P1d-1 that it must respect: the
assignment shape is one row per span and nothing may reintroduce a "whole period" representation
beside it; `App\Support\Rota\RotaAssignment` and `App\Support\Rota\VacationBooking` are the only
writers and `RotaWritersAreSingularTest` fails the build for a second one; and `PersonPresenter` is
still the only path from a `Person` to Inertia props, now with `email` correctly gated — a read view
built for residents is precisely the surface that gating exists for.
