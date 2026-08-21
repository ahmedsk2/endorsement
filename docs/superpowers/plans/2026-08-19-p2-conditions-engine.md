# P2 — The conditions engine

**Written 2026-08-19 against `main` at `0733665`. Revised 2026-08-20 for the full CG-07 catalog.**
Production is live and current; P0a–P1e are shipped. Baseline this plan starts from and must not
regress:

| Suite | Count |
|---|---|
| PHPUnit (`php artisan test`) | **1683** |
| Vitest (`npm test`) | **237** |
| Playwright (`npm run test:e2e`) | **24** |
| `npm run build` | green |
| CI (`test`, `audit`, `docker-build`) | green |

---

## What this plan is, and is not

**It is** the first TypeScript in this repository: `packages/engine`, a pure conditions engine
implementing the CG-10 contract `(schedule, config, conditions) → violations[]`, with **every
condition type in CG-07's shipped catalog except the one the catalog itself marks Stage 5** — the
enumeration is below and is stated as a list, never as a bare number — plus a calendar mirror
validated against the corpus P1a and P1e built for it, the severity/rank model, CG-04's
plain-language previews, the CG-08 preset bundles, and a PHP-side context builder that serialises the
shipped master rota, vacations, clinics, levels and calendar into the engine's input shape.

**It is not:**

- **Not the conditions gate screen.** CG-01/CG-02's drag-ranked gate, the `conditions` table, and
  everything that stores a condition are **P3** — the phase table places them there and this plan
  does not move them. P2 ships **no migration and no screen.**
- **Not slots, coverage templates, schedules or assignments.** All four are unbuilt (design §6.3)
  and all four are P3. P2 defines the *shape* the engine reads them in — Decision A, the most
  consequential decision in the phase — and creates none of them.
- **Not the workbench.** WB-01..WB-08 is P3. P2 produces `violations[]`; nothing renders them yet.
- **Not the solver.** `services/solver`, §4.2's evaluation mode and §4.3's real cross-validation job
  are **P4**. See Decision G — the phase table's "the CI cross-validation job" cannot mean §4.3's
  job in P2, because the thing it would validate against does not exist until P4.
- **Not a production container.** `services/engine` waits for its first caller (Decision F).
- **Not the duty-hours or equity *numbers*.** Every predicate ships; the numeric policy §37 still
  owes does not, and no plausible-looking default is invented in its place (Decision H).
- **Not a second implementation of anything except the calendar** — and that one is deliberate,
  already anticipated, and already has its contract sitting in the tree (Decision C).

---

## The catalog: the exact enumeration, and how it was counted

D13 stands as decided: **`| D13 | Condition catalog scope? | **All 21 types in P2.** Objection
logged in §1.3. |`** (`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md:36`,
verified verbatim). The nine-type paragraph at lines 77–82 sits **inside** §1.3 *"Objections
logged"*, whose preamble reads *"Both were raised, both were reaffirmed by the owner, and both are
being implemented as decided. Recorded so the reasoning survives, not to re-litigate."* There is no
reversal in the tree and this plan describes none.

### How the count was taken

CG-07's table is `docs/munawib/SPEC.md` lines 87–110: header at 87, separator at 88, **22 data rows
at 89–110**. Twenty-one rows carry one type key; one row — `count_max / count_min`, line 91 —
carries two. So:

- **22 catalog rows.**
- **23 distinct type keys.**
- Exactly one row, `forbidden_transition` at line 110, carries `(Stage 5)` **inside its own
  parameters cell**.
- **22 rows − 1 Stage-5 row = 21 rows**, which is exactly D13's number. **23 keys − 1 = 22 type
  keys.**

**D13's "21" is therefore self-consistent and means the whole shipped catalog except the Stage-5
row.** This corrects the committed draft of this plan, which asserted that *"'21' matches nothing in
the catalog"* and that *22 minus `forbidden_transition`* was *"a reading no document states"*. Three
tree facts state it between them:

1. CG-07's own marking, `docs/munawib/SPEC.md:110` — `| forbidden_transition | Shift A never followed
   by shift B | from/to kinds (Stage 5) |`.
2. §35, `SPEC.md:252` — *"**Stage 5 — Shift mode** (shift slots, hour accounting, coverage-first
   solver profile, **forbidden transitions**, progressive patterns…). Starts only on explicit
   go-ahead."*
3. §36 *"Not doing (and why)"*, `SPEC.md:257` — *"**Shift features before Stage 5.**"* This one is
   decisive: building `forbidden_transition` in P2 contradicts a **named non-goal**, not merely a
   stage ordering.

The derived claim in the committed draft that *"the other twelve is also wrong — thirteen, not
twelve"* falls with the same arithmetic. What survives from that passage, and is kept below, is the
underlying observation: **the catalog is 22 rows / 23 keys and no document says so.** That is a
clarification worth writing down, not a defect list.

### What P2 builds — the list

**21 catalog rows = 22 implemented type keys.** Stated as the list, every time; the plan never
states a count without it.

| # | Type key | Violation is located at | Lands in |
|---|---|---|---|
| 1 | `overlap_block` | placement | P2-1 |
| 2 | `vacation_block` | placement | P2-1 |
| 3 | `eligibility` | placement | P2-1 |
| 4 | `unwanted_day_block` | placement | P2-1 |
| 5 | `onboarding_grace` | placement | P2-1 |
| 6 | `dow_restriction` | placement | P2-1 |
| 7 | `clinic_conflict` | placement | P2-1 |
| 8 | `same_unit_conflict` | placement | P2-1 |
| 9 | `min_gap` | placement | P2-1 |
| 10 | `post_duty_exclusion` | placement | P2-1 |
| 11 | `consecutive_max` | placement | P2-1 |
| 12 | `count_max` | window | P2-2 |
| 13 | `count_min` | window | P2-2 |
| 14 | `target_per_period` | window | P2-2 |
| 15 | `composition` | window | P2-2 |
| 16 | `max_gap` | window | P2-2 |
| 17 | `free_day_min` | window | P2-2 |
| 18 | `rolling_hours_max` | window | P2-2 |
| 19 | `call_frequency_max` | window | P2-2 |
| 20 | `fairness_distribution` | cohort | P2-2 |
| 21 | `holiday_equity` | cohort | P2-2 |
| 22 | `we_pairing` | cohort | P2-2 |

**Plus one, registered and deliberately unimplemented:**

| 23 | `forbidden_transition` | — | **not built.** Registered with `implemented: false` and the three citations above. |

`forbidden_transition` is **named in the registry** rather than silently absent, so the gap reads as
a decision and not an oversight — the same device Decision C uses for Hijri, Task 5's coverage
manifest uses for `golden.json`'s unasserted blocks, and `UnitMerge::REFERENCES` uses for a table a
merge deliberately leaves alone. **An entry is a decision, not documentation.**

Task 8's catalog-parity guard derives the 23 keys **from `docs/munawib/SPEC.md`'s table itself** and
compares in both directions against the registry, so the count cannot drift again and a 24th row
appearing in the spec fails the build until somebody classifies it.

### A competing arithmetic, rejected on the record

23 keys − `forbidden_transition` − `overlap_block` also equals 21, reading *"Hard, built-in"* as
meaning `overlap_block` is not a department-configurable catalog entry. **Rejected:** it is a row in
a table the spec calls *"Shipped catalog"*, it carries a type key and a stated class, and the engine
must implement it either way. The useful framing is that **both readings produce the same build
set** — `overlap_block` is implemented under either, `forbidden_transition` under neither — so the
disputed number is a label and the enumeration is the contract.

### One design-doc inconsistency not to inherit

`…integration-design.md:36` records D13 with no enumeration; `:1052` repeats *"all 21 CG-07 types"*;
`:1468` says *"even though all 21 ship before P3"*. But §1.3's **objection** paragraph at `:77–79`
writes *"the rest (duty-hours, fairness, holiday equity, **shift transitions**) belong to modules
that ship in P5 and Stage 5"*, which places shift transitions **inside** the 21 and would require
some other row to be outside it. That paragraph is the overruled objection, not the decision, and it
is loose prose. **Task 1 states the enumeration once, cites `SPEC.md:110` and `:257`, and does not
treat `:77–79` as a scoping statement.** This document has been cited for the opposite of what it
says nine times; that is the tenth avoided.

### The ordering instruction survives D13 intact

`…integration-design.md:1468`, verbatim: *"Mitigate by **ordering the 21 types** so the prototype's
proven nine land first and are demoable, **even though all 21 ship before P3**."* The owner has
already sanctioned an ordering inside a 21-type P2. This plan's seam **is** that ordering, chosen on
a tree-verifiable criterion rather than on a "nine" that enumerates to nothing (`grep -rn -i
'\bnine\b' docs/` returns twenty hits and none of them is a list).

---

## Binding requirement IDs

From `docs/munawib/SPEC.md` unless noted.

**Built in P2:**

| ID | What P2 owes it |
|---|---|
| **CG-04** | Plain-language preview text auto-generated from parameters — for all 22 implemented keys, in the engine, with no renderer yet (P3 renders it). |
| **CG-05** | The **Hard** class: highest hint severity; the engine marks it. P2 does **not** build the publish block (P3). |
| **CG-06** | The **soft** class: rank-graded violations. P2 builds the rank model; the ignored-warnings ledger is P3. |
| **CG-07** | **All 22 implemented keys** (21 rows), plus `forbidden_transition` registered unimplemented with its citations. |
| **CG-08** | The preset bundles as **data in the package**: ACGME, residency (structure, numbers pending), SCFHS (present, empty, with a `pending` block). Decision H. |
| **CG-10** | The stable contract: pure function `(schedule, config, conditions) → violations[{conditionId, severity, rank?, location, explanation}]`; new types additive. |
| **AR-03** | One pure TS conditions engine, cross-validated by a shared golden fixture suite in CI. P2 delivers the engine and the *calendar* half of the cross-validation (Decision G). |
| **AR-08** | `App\Support\Calendar` is the one converter — and the mirror is the one deliberate exception, contracted by `golden.json` (Decision C). |
| **UX-05** | Hints never block on network: the engine must run client-side on data the page already holds. |
| **NF-01** | < 100 ms p95 laptop, < 250 ms mid-phone — a **budget on the engine**, measured in P2 against a synthetic full month with all 22 types active. |
| **D4** | One TypeScript engine, two runtimes. P2 ships the package and the browser bundle path; the server runtime waits (Decision F). |
| **D13** | All 21 catalog rows in P2, as enumerated above. |

**Named so P2 does not preclude them, and built by nobody here:**

| ID | Why it constrains P2 |
|---|---|
| **CG-01 / CG-02 / CG-03** | The gate stores `type_key`, `params`, `scope`, `class`, `rank`, `active`, `source` — and §30's model is `conditions/{condId} { typeKey, params, scope, class:'hard'\|'soft', rank?, active, source, note? }`, **one `typeKey` per row**. P2's condition object must be exactly that row, minus the row. CG-03's *"never retroactive on published schedules"* means the engine is always called with a **condition set as an argument** and never reads an ambient one — and it is why Decision D's carry-in tail is read-only context, never re-evaluated. |
| **SL-01 / SL-02 / SL-03 / SL-04** | Slot kind, time window (may cross midnight), `counts_hours`, `tally_key`; *"post-duty semantics follow slot windows automatically"*; coverage templates carry per-slot min/target; weekly-cadence slots share the model. P2's duty shape must carry enough of SL-01 and SL-04 for the eleven window-measured types to be real, and must **not** absorb SL-03 (owner decision K). |
| **WB-03 / WB-04 / WB-05** | Live hints on a *prospective* placement; pickers exclude hard-ineligible; trackers show current vs target. The engine must evaluate a **hypothetical** duty cheaply, not only a committed month — which is the seam's third argument. |
| **AU-02** | The solver's JSON contract: `request {periodSkeleton, roster, slots, templates, conditions, constraints, fixedAssignments, seed, timeLimitSec}`. P2's context must be **serialisable into** that request without a translation layer nobody planned. |
| **AU-05** | Client-side greedy fallback — needs `evaluate()` cheap enough to call in a loop. The NF-01 budget again. |
| **AU-07** | Infeasibility must return a conflict set, never a silent under-fill. It is why a Hard **floor** is a product hazard (Decision E) and why the registry records cap-vs-floor. |
| **PU-03** | Publish dialog summarises outstanding warnings; consumes `violations[]` unchanged — which is why `evaluate()`'s return type does **not** widen (Decision D). |
| **RQ-01 / §30** | Approved requests become engine constraints; §30 sketches `requests/{reqId} { type:'unwanted'\|'leave'\|'sick'\|'swap', … }`. That is where unwanted days live, and §35 puts requests in Stage 3 (P4). Owner decision R. |
| **TL-01 / TL-02 / TL-03** | The tallies, the equity dashboard and the duty-hour report are the **consumers** of `fairness_distribution`, `holiday_equity` and `rolling_hours_max`. They are P5. **A type and its dashboard are different deliverables** — see the note under the seam. |
| **§4.2 / §4.3** | The solver's reified evaluation mode and the real cross-validation job. **P4.** |

---

## Inherited invariants — stated as things a task must not break

Each was verified against the tree while revising this plan. A guard tells you *that* you are wrong,
never *why*, and the why is what stops it being reintroduced sideways.

1. **`App\Support\Calendar` is the only date converter, and the client allow-list is EMPTY.**
   `tests/Feature/Build/CalendarIsTheOnlyConverterTest.php` runs five whole-set scans. Two cover
   `resources/js` and both carry **no allow-list at all, deliberately**: ten date-construction
   needles (`new Date(`, `toISOString(`, `toLocaleString(`, `toLocaleDateString(`,
   `toLocaleTimeString(`, `Date.now(`, `Date.parse(`, `Date.UTC(`, `Intl.DateTimeFormat`,
   `getTimezoneOffset(`) and a quoted-whole-word weekday-vocabulary pattern. The docblock states the
   policy: *"a future PR reaching for `new Date()` needs a prop from the controller instead, not an
   entry added here."* **P2 must not add the first entry.**
2. **That scan's SCOPE is `resource_path('js')`, and `packages/` escapes it by construction.** A
   loophole, not a permission. Task 6 extends the scan to `packages/` with the allow-list still empty
   in both directions, and proves it by planting.
3. **A docblock is scanned source.** `CalendarIsTheOnlyConverterTest` matches comments on purpose
   (*"a file explaining the array it is about to build is not a file this guard should let past"*).
   `RotaAccessTest`'s *namespace* scan and `ClinicHooksTest` strip comments first, via the single
   definition `Tests\Support\SourceScanner::withoutComments()`, and each pins the stripper in **both**
   directions against a real file — a stripper that over-reached would return code-free source, every
   needle would miss, and the guard would be silently vacuous while looking identical to a clean
   tree. Any guard this plan adds picks one discipline and says which.
4. **An identifier that travels between files is scanned source, and THREE live scans will see P2's
   type keys.** The committed draft named two and understated the first; verified line by line:
   - `RotaAccessTest::test_nothing_in_the_rota_infers_on_call_eligibility` (`:212`) walks
     **`File::allFiles(app_path())` — every `.php` file under `app/`** — reading each with **raw
     `file_get_contents`, comments NOT stripped**, for `off_roster`, `offRoster`, `callEligib`,
     `call_eligib`. **No allow-list.** So `App\Support\Engine` may not contain those four strings
     anywhere in `app/`, *docblocks included* — and a context builder documenting MR-04 is exactly
     the file that would want to write "off-roster" in prose. Say it another way in the docblock.
     (Note the temptation is real: AR-05's own Munawib data model carries `units/{unitId} { …
     offRoster:boolean … }`.)
   - `RotaAccessTest::test_nothing_in_the_rota_namespace_infers_on_call_eligibility` (`:262`)
     needles those four plus bare **`eligib`**, `on_call`, `onCall`, `callRoster`,
     case-insensitively, comment-stripped, over `glob(app_path('Support/Rota/*.php'))` — **a glob, so
     a class added there joins the scan unasked** — plus the rota controllers, form requests and
     screens.
   - `ClinicHooksTest` needles `post_call`, `postcall`, **`condition`**, `severity`, `violation`,
     `hard_block`, `soft_block`, `rank_order`, comment-stripped, over
     `glob(app_path('Support/Clinics/*.php'))` (with `assertGreaterThanOrEqual(3, …)` non-vacuity)
     plus eight named clinic controllers, requests, models and both Vue screens
     (`assertGreaterThanOrEqual(11, …)`).

   **Consequence, binding on Task 22:** the PHP context builder is `App\Support\Engine\*`. It may not
   live in `App\Support\Rota` (the `eligibility` type key fires `eligib`) and it may not live in
   `App\Support\Clinics` (`clinic_conflict`'s reader would fire `condition`). **P2 adds ZERO
   allow-list entries to any of the three**, and that is an acceptance criterion, not a hope.
5. **One writer per table, guarded at source level: whole match set, `assertSame([], $offenders)`,
   allow-list plus staleness twin, proved on a plant.** P2 writes no table at all, so every existing
   single-writer guard must stay green **with no new allow-list entry**. `Model::query()->create(`
   is the sixth writer shape (ruling 66, found by planting exactly that file and watching the guard
   stay green) and `$model->update([...])` is this codebase's house idiom (ruling 50, measured green
   against a plant rewriting six columns across two guarded tables) — **both are known blind spots
   and both must be needled** in any guard this plan adds. They are also precisely the shapes a
   PHP-side "just cache the context" convenience would take. Task 22's guard asserts the context
   builder is a **reader**.
6. **`institution_id` is provenance and in-instance grouping, NEVER a query filter (D11).**
   `InstitutionProvenanceTest::test_no_query_filters_on_institution_id` is live. The context builder
   must not filter on it and must not "scope the engine to the institution" — the database is the
   boundary. **An index led by `institution_id` is a recurring mistake**, not a one-off (P1a Task 4's
   `periods` unique index, P1a Task 7's `holidays` index, both caught only empirically). P2 adds
   **no index at all**, which is the cheapest way to not make it a third time.
7. **No PHI and no names in `audit_log.detail`.** The column is `detail`, **singular**, `text`
   nullable, created at `database/migrations/2026_07_24_120002_create_core_tables.php:33` under the
   comment *"NEVER stores PHI in `detail` (ids/counts only)"*. P2's only command surface (Task 24)
   audits nothing at all, and Task 24 says why. A violation's `explanation` is generated *from* names
   and must never reach that column.
8. **Additive, nullable migrations, in a fresh date slot.** The newest migration in the tree is
   `2026_08_16_120002_create_demo_rows_table.php`, so **P2's slot is `2026_08_17_*`** if it needs
   one. It should not: Decision A and owner decision R both land on zero migrations, and *"P2 adds no
   migration"* is asserted the way P1d-2 asserted it — `git diff --stat <base>..HEAD --
   database/migrations` empty.
9. **A query budget is watched failing, not merely written.** `RotaGridTest` bounds the whole grid at
   `assertLessThan(20, …)` on a **populated** year (780 cells, splits, vacations, mid-year
   promotions and a stale row) *because a budget measured on an empty grid only ever proves the empty
   case*. `ClinicRosterTest` bounds three paths separately on a populated unit. Task 22's budget is
   measured the same way and **is observed breaching** — grow the fixture until it fails, record the
   number it failed at, then fix it — before it is trusted.
10. **A passing test must be shown capable of failing.** Every guard here is planted against before
    it is believed. Rulings 66–71: a guard is audited by planting, never by reading its needle list,
    and the ranking planting produces is the opposite of the one the lists suggest.
11. **Fixtures stay synthetic, permanently.** `tests/fixtures/roster/`'s rule generalises without
    amendment: **no real month's duty roster and no real staff list enters `packages/engine`'s
    corpus at any time.** The corpus exercises specific violation shapes — a gap of exactly the
    boundary value, a duty on a period's last day, a run spanning the 31st into the 1st, a person
    whose level changes mid-window — not a plausible department. AU-06's *"regenerate a past pilot
    month"* is P4, is the owner's to run against production, and D15 already moved it to synthesized
    fixtures.
12. **The two shipped read folds are not to be duplicated or routed around.**
    `App\Support\Rota\AvailabilitySummary` is *"pure, and query-free by construction"* and *"handles
    no dates (ST-06). Not one."* `App\Support\Clinics\ClinicRoster` *"subtracts no leave and must
    never become an eligibility computation"* and does not query the leave table at all. Task 22
    reads their **inputs**, never reaches into them, and adds no file to either directory. Its
    vacation-week rule is `AvailabilitySummary`'s **verbatim** (owner decision N).
13. **`RotaGrid::forYear()` and `ClinicRoster::forDate()` take NO viewer at all** — the parameter was
    removed rather than ignored, after passing the real user shipped every colleague's email and
    phone in the Inertia props with nothing rendering them (P1d-2 Decision C, a live disclosure).
    The engine context is built the same way: **no contact field and no free text, for any viewer**,
    asserted on the most permissive institution setting the system can produce, exactly as
    `RotaReadViewTest::test_the_editor_grid_is_contact_free_too` does. This binds harder now than it
    did at nine types: `unwanted_day_block` and `onboarding_grace` put a person's registered
    preferences and join date into a payload another person's browser holds (owner decision R).
14. **LIGHT THEME ONLY, semantic classes only.** P2 ships no markup; this is a constraint on what P2
    must not quietly introduce. No styling, no colour, no `dark:` utility near the package.
15. **Secrets are owner-managed and `.env.example` is CI's whole environment.** P2 introduces no
    environment variable. If an amendment adds one it needs the compose `environment:` block with an
    explicit `${VAR:-default}` (P0d Task 9) **and** an `.env.example` line that neuters no default
    (`EnvExampleNeverNeutersADefaultTest`, rulings 46–47).

---

## Findings — what is actually in the tree

Each verified while revising this plan; each changes what a task can assume.

1. **No condition type is evaluable end to end against the current schema — 22 for 22, none.**
   `slots`, `coverage_templates`, `conditions`, `schedules`, `assignments`, `ignored_warnings`,
   `archives` — zero `Schema::create` for any of them. The 44 shipped migrations create **37 tables**
   and none of them records a duty. Every CG-07 type constrains **duty assignments**, and the duty
   stream does not exist. This is not a blocker; it is the phase's defining fact. The engine is a
   pure function over JSON (CG-10 says so), and what the shipped schema supplies is the **context**
   half.
2. **The slot-kind vocabulary is stored nowhere.** `grep -rn "night_call\|day_call\|full_24h_call\|
   weekly_duty\|tally_key\|counts_hours" app/ database/ resources/js/` returns **zero hits**. SL-01
   names those kinds in prose only. So `Slot.kind` — and therefore `min_gap`'s `kinds`,
   `post_duty_exclusion`'s `from`/`to`, `count_max`'s `kinds`, `consecutive_max`'s `kinds` — validates
   against **nothing** in P2 and must be an opaque string (Decision A).
3. **What the shipped schema does supply, verified column by column.** *Fully:* `vacations`
   (`starts_on`/`ends_on`, `granularity`, `source`, no `period_id`, **no leave-type and no approval
   column — every row is a blocking absence**, and `Vacation::booted()` refuses per-person overlaps
   so a person's leave is a set of disjoint spans); `clinics` (`weekday` as a plain ISO 1–7
   `unsignedTinyInteger`, `session` as `string(2)` holding `'AM'|'PM'` **with no time window**,
   `unit_id`, `attendee_mode` of `'rotators'|'levels'|'named'`, `active`) and `clinic_attendees`;
   `master_rota_assignments` (one span row, both bounds inclusive, overlaps refused per (person,
   period) by `booted()`, gaps legal and counted); `people.joined_at` (nullable date, exists);
   `person_levels` via `Person::levelAt()`/`levelsAt()`/`levelSpansBetween()`/`levelFromSpans()`;
   `periods`; `holidays` (`calendar`, `month`, `day`, `year`, `duration_days`, **`equity_tracked`
   boolean default true, with no consumer anywhere — `holiday_equity` is its first**);
   `units.training_rotation` / `call_target` / `clinic_owner` (all `boolean default(false)`;
   `call_target`'s only consumers are `UnitController`, `UnitRequest`, `Unit`, `Units.vue` and two
   tests — so *"which units take call"* is configuration P2 reads, never something it infers);
   `institutions.block_weeks` (default `'[4,4,4,4,4,4,4,4,4,4,4,4,5]'` — **block 13 is five weeks, so
   a period-windowed number has a moving denominator**). *Nowhere:* unwanted days (`grep -rl unwanted
   app/ database/ resources/js/` returns **nothing**), slot time windows, `counts_hours`, any duty a
   person has ever held in any year.
4. **There is no public API for "which unit is person P on, on date D".** `MasterRotaAssignment`
   carries **no query scope at all**; the one date-shaped resolver is
   `ClinicRoster::rotatingOn(int $unitId, string $on)`, **`private`** (verified, `ClinicRoster.php:152`),
   and it answers the inverse (unit → person ids). `RotaGrid::forYear()` is year-and-period shaped.
   Every rota read in this codebase is period-shaped; every condition question is date-shaped.
   **Flattening spans into a per-date unit vector is the bridge, it must be built exactly once, and it
   belongs in the context builder — never in a condition.** The model's own guards make this a
   single-row answer by construction: `MasterRotaAssignment::booted()` refuses a span reaching outside
   its period and refuses an overlap for one person in one period, and `Period::booted()` keeps
   periods non-overlapping. **State that as the reason**, so a later implementer does not add a
   defensive "pick the first" that hides a real corruption.
5. **There is no per-person/per-date leave predicate either, and `Vacation::scopeIntersecting()` is
   the one SQL definition, not the one definition.** It has exactly two callers
   (`RotaGrid.php:140`, `RotaExport.php:170`) and uses `whereDate` on both bounds. Two further
   in-PHP copies over already-loaded rows exist today: `RotaGrid::cellFor()` (`RotaGrid.php:292-297`,
   vacation vs a PERIOD, `Y-m-d` string compare) and `AvailabilitySummary` (`:190-191`, vacation vs a
   WEEK against **clipped** bounds), whose own comment calls it *"the same idiom `RotaGrid::cellFor()`
   uses"*. **P2 adds a fourth shape nobody has — vacation vs a SINGLE DATE — and a fifth if the
   flattening happens server-side.** Say the number honestly rather than claiming one definition that
   has not existed since P1d-2; and do the flattening in PHP through `whereDate`/`Calendar::ymd`,
   because a bare `<=` against a `date`-cast column drops the boundary day.
6. **`Calendar::dayType()` is query-free but CPU-expensive, and the query-count discipline is the
   wrong axis for it.** `holidaysOn()` walks backwards `duration_days` per holiday and a Hijri rule's
   `anchoredOn()` constructs an `IntlCalendar` per probe (`Calendar.php:169-186`), while
   `activeHolidays()`/`settings()` are process-lifetime statics so the *query* cost is one, not N.
   Study measurement, reproduced here as the reason for Decision A's day vector: 30 consecutive days
   cost ~24 ms and **zero** queries with four holidays configured; the same 30 days re-asked nine
   times cost ~203 ms. With 22 types active the re-ask factor is worse, not better. **The day vector
   is computed once, server-side, and handed to the engine.**
7. **`RotaGrid`'s own docblock refuses per-day labelling as a cost decision**: *"`Calendar::label()`
   per DAY of a period → real CPU for no gain. The grid labels BOUNDARIES only."* So the day vector
   is a new computation, not a reuse — and finding 6 is why it is still the cheap answer.
8. **`tests/fixtures/calendar/golden.json` is already a binding P2 contract, version 2, and it names
   P2 explicitly.** `_purpose`: *"The shared contract between `App\Support\Calendar` (PHP, this repo,
   P1a) and the `packages/engine` calendar mirror P2 will build… Every value below was produced by
   RUNNING the code, not derived by hand."* Twelve top-level keys: `_purpose`, `version`, `timezone`
   (`Asia/Riyadh`), `cases`, `weeks`, `weekday_columns`, `hijri_month_boundary`, `day_boundary_cases`,
   `holiday_cases`, `period_runs`, `parse_rejects`, `hijri_labels`. `GoldenFixtureTest` asserts it
   from the PHP side in eleven methods. `weekday_columns` was added at version 2 *specifically* for
   CL-03. **This file is an INPUT to P2, not something P2 authors.**
9. **`golden.json` has NO coverage of `Calendar::weeksIn()`'s clipped bounds** — `grep -c clipped
   tests/fixtures/calendar/golden.json` returns **0**. The `weeks` block's entries carry
   `_description`, `weekend_days`, `week_start_iso_day`, `of`, `starts_on`, `ends_on`: that is
   `weekOf()`, three cases. `period_runs` covers block generation, not week clipping. But
   `weeksIn()`'s `clipped_starts_on`/`clipped_ends_on` are exactly what a `week`-windowed
   `count_max`/`count_min` and `target_per_period`'s vacation-week modifier consume. **Owner decision
   O resolves this without touching the fixture:** the mirror does not implement `weeksIn()`; week
   windows arrive **in the context**, computed server-side by the one converter.
10. **§7 Decision A is the live precedent for overruling this design doc's own architectural nouns.**
    The doc originally specified *"PHP plus a mirrored `packages/engine`"* for the calendar; P1a
    superseded it with *"ONE implementation, not two"*, built the golden fixture as the contract, and
    deferred the mirror to P2 — because shipping a second implementation early is *"two definitions
    of one fact — the same failure class `AuditChain::canonical()` and `Person::levelAt()` already
    carry docblocks against."* **P2 knowingly creates that second implementation. Decisions B and C
    exist to make it the smallest one possible**, and owner decision O makes it smaller still.
11. **The dual implementation is NOT PHP + TypeScript.** §4.1: one pure TS package, two *runtimes*
    (browser, Node sidecar), and `services/solver`'s CP-SAT mapping is *"the one permitted second
    implementation"*. Then, verbatim: **"No PHP implementation of the rules exists anywhere. That is
    the point."** Currently prose with nothing enforcing it — Task 11 enforces it.
12. **All five types §4.3 flags as hardest to reconcile between the TS engine and the CP-SAT mapping
    are on the far side of this plan's seam** — `fairness_distribution` (*"violation count vs. min-max
    objective"*), `rolling_hours_max` and `free_day_min` (*"sliding windows, including partial windows
    at period boundaries"*), `holiday_equity` (*"multi-year lookback reduced to a per-schedule
    violation"*), `we_pairing` (*"a shared definition of what 'preference broken' means"*). That is
    independent support for the seam and the concrete reason the ordering matters. **`call_frequency_max`
    has exactly the sliding-window shape and is NOT on §4.3's list — that is an omission in §4.3, not
    evidence the type is easy**, and Task 1 records it.
13. **CG-08's residency preset cannot be seeded from anything in this tree.** It says *"residency
    defaults seeded from the prototype's proven values"*; D14 makes the prototype *"idea curation
    only — not a code ancestor, not a data source"* and D15 records that the pseudonymised export it
    depended on *"is no longer available"*. The numeric defaults are an **owner input**, not a lookup
    (Decision H).
14. **Only three CG-07 rows carry a stated default class** — `vacation_block` (*Hard default*),
    `unwanted_day_block` (*top soft default*), `overlap_block` (*Hard, built-in*). The other twenty
    keys have **no default class anywhere in spec or design doc**, and §30 makes `class` a field on
    the condition **row**. **The engine must not hardcode a class for any type except
    `overlap_block`**, whose *"built-in"* additionally means it is not department-authored. This
    changes the registry's shape from the committed draft's `defaultClass` — see Decision E.
15. **`packages/`, `services/` and TypeScript do not exist.** No `packages`, no `services`, no
    `tsconfig*.json`, no `typescript` dependency, no `.ts` file under `resources/`. `vitest.config.js`
    includes `tests/js/**/*.test.js` only; `vite.config.js` has no alias and two inputs.
    `docker-compose.production.yml` has exactly two services (`app`, `db`) with fifteen invariants
    pinned by `DeploymentInvariantsTest`. CI has three jobs and `docker-build` builds the root
    Dockerfile alone. **P2 creates the toolchain from zero.**
16. **The two tree statements about how CL-03 asks its question do NOT flatly disagree, and the
    committed draft overstated it.** Design §14 item 22: *"the P2 condition reads `clinics.weekday`
    and `clinics.unit_id` against a date and a person's current unit. It needs no schema change here
    and it is not a clinic-module concern when it lands."* The P1e handoff (line 3440):
    *"`ClinicRoster` resolves and never stores, so a condition asking 'who is at this clinic on this
    date' asks it rather than reading a cached list; and the clinic module is guarded against becoming
    a conditions engine, so P2's first condition lives in the conditions module and reads clinics,
    never the other way round."* The handoff's **operative** claims are *never a cached list* and *the
    condition lives in the conditions module and reads clinics* — **item 22 satisfies both exactly**.
    They differ only on WHICH RESOLVER, and item 22 wins on four verified grounds: (i)
    `ClinicRoster::forDate(Clinic $clinic, string $date)` is **clinic-first** — one clinic in, people
    out — so "does person P have a clinic on date D" means iterating every active clinic per person
    per date; (ii) `rotatingOn()` is `private`; (iii) `forDate()` returns
    `PersonPresenter::contactFree()` **projections**, not ids, and takes **no viewer** by deliberate
    design (P1d-2 Decision C) — presenter output is the wrong shape for an engine context and
    re-opens the payload question; (iv) `golden.json`'s `weekday_columns._description` already decided
    it in writing — CL-03 *"must map a date to an ISO weekday and compare it to `clinics.weekday`
    client-side with no round trip (UX-05)"*, and `ClinicRoster` is a server call.
17. **Item 22's formulation is nonetheless INCOMPLETE, and the committed draft adopted it as
    written.** *"`clinics.weekday` + `clinics.unit_id` against the person's unit on the date"*
    resolves `attendee_mode = 'rotators'` correctly and is **wrong in both directions for the other
    two modes**: a `named` attendee (CL-02's external consultant, who rotates nowhere) has a clinic
    that day with no rota span on that unit and would never trigger; a `levels` clinic includes only
    rotators whose level **on that date** is in the attached set, so a plain unit match over-triggers.
    The context must carry `attendee_mode` and the attendee rows. Owner decision S.
18. **`people.joined_at` exists and is empty everywhere.** `database/migrations/2026_08_10_120001_
    create_people_and_link_users.php:65` declares it nullable; `PersonPresenter::one()` projects it
    **ungated** (line 43, unlike `phone`/`email` under `viewContact()`); `PersonRequest` validates it
    `['nullable','date_format:Y-m-d']`; `RosterImport::OPTIONAL_FIELDS` includes it. But **no seeder,
    no factory and no demo path sets it** — verified. On any existing instance, including the deployed
    QCH one, it is NULL for every person unless an administrator typed it. `onboarding_grace` therefore
    needs an explicit, stated answer to "no join date", and the only safe one is **no violation**, said
    out loud in the preview text (owner decision T).
19. **`same_unit_conflict` has one occurrence in the entire repository** — `docs/munawib/SPEC.md:100`.
    No Appendix B sentence, no §14/§16/§17 elaboration, no design-doc mention, no prototype note.
    There is nothing to disambiguate it against, which is why more reading cannot resolve it. Owner
    decision U, and it is the weakest default in this plan.
20. **`Calendar::datesBetween()` is UNCAPPED** (`Calendar.php:132`); only `weeksIn()` throws, at 550
    days (`:445`, a deliberate P1a guard against a mistyped year exhausting memory). The committed
    draft's Task 3 gives the TS `datesBetween` *"an explicit maximum span mirroring
    `Calendar::weeksIn()`'s 550-day throw"* — correct as written, but the mirror then **diverges from
    PHP's `datesBetween`, which has no cap**, and `golden.json` would not catch it. Task 3 states the
    divergence and the reason (a browser tab is the memory that matters), rather than calling it
    parity.
21. **`Calendar` exposes no public `isoWeekday()`.** The value is reachable only inside `isWeekend()`
    (`:282`) and `weekOf()` (`:411`). `golden.json` contracts `iso_weekday` **per date** in
    `cases[].dates[]`, so the mirror gets a function the PHP side does not expose as one. If the
    context builder emits an ISO weekday per day — it does, in the day vector — that is a **new PHP
    surface**, and `CalendarIsTheOnlyConverterTest`'s Carbon/DateTime needles forbid constructing one
    outside `App\Support\Calendar`. **It belongs on `Calendar`, never on `App\Support\Engine`.**

---

## Decision A: what P2 evaluates against — the duty shape

**This is the most consequential decision in the phase.** Every one of the 22 types constrains
duties; SL-01..SL-07 is P3; and P3 and P4 both inherit whatever P2 decides here. So P2 decides it
**deliberately and first**, rather than letting it accrete inside eleven predicates.

**P2 authors the duty shape as a TYPE in its own contract, and that type is the only definition of a
duty in this repository until P3 serialises into it.**

### The shapes

```
Slot {
  key: string            // OPAQUE. Not a foreign key. P3 maps its own primary keys onto it.
  kind: string           // OPAQUE. SL-01's vocabulary is stored NOWHERE in the tree (finding 2),
                         // so it is not validated against an enum in P2.
  unitKey?: string
  cadence: 'daily' | 'weekly'
  spanDays: number       // 1 for a daily slot; SL-04's weekly-cadence slots carry their real extent
  startMinute: number    // minutes from local midnight, 0..1439
  endMinute: number
  crossesMidnight: boolean
  countsHours: boolean   // SL-01's counts-toward-hours flag
  tallyKey?: string      // SL-01's tally key; fairness_distribution's `quantity` keys on it
}
Duty { personKey: string, date: Ymd, slotKey: string }
Schedule {
  horizon: { from: Ymd, to: Ymd, evaluableFrom: Ymd, evaluableTo: Ymd }
  duties: Duty[]
}
```

### The four conventions the spec does not state, and P2 must

Each is a real fork on real QCH configurations, each is invisible in review, and each is fixtured on
the case where the readings disagree.

**1. Intervals are HALF-OPEN, `[start, end)`.** Under SL-02's configurable split day/night the night
window begins exactly when the day window ends. Closed intervals would flag every legal split-call
department on every single day. Fixtured on the abutting pair.

**2. A duty's occupied interval is absolute minutes on one integer line.**
`absMinute(date, minute) = daysFromCivil(date) * 1440 + minute`; a crossing-midnight duty ends at
`end + 1440`; a weekly-cadence duty ends at `daysFromCivil(date) + spanDays - 1` days plus
`endMinute`.

> **CORRECTED 2026-08-20, off by one, found by implementing it.** This sentence read
> `+ spanDays` and contradicted this task's own acceptance case, which requires `spanDays: 7`
> to occupy **seven** dates, not eight. The error is not confined to weekly slots: applied to a
> daily slot (`spanDays: 1`) the old formula ends every duty on the FOLLOWING date, so every
> abutting split day/night pair overlaps and `overlap_block` fires on a correct rota.
> `date + spanDays - 1` is one formula for both cadences. Measured, not reasoned: the old
> formula was planted and produced **16 failures**, the abutting pair among them.
No `Date`, no instant, no timezone (Decision B).

**3. Duty→date attribution has THREE readings, and each type declares which it uses.** This is the
single largest source of silent divergence between two implementations of one catalog, and §4.3's
cross-validation will surface it as a mismatch rather than as a bug:

| Reading | What it means | Types that use it |
|---|---|---|
| **Anchor date** | the whole duty belongs to the calendar date its slot **starts** on | `vacation_block`, `unwanted_day_block`, `onboarding_grace`, `dow_restriction`, `clinic_conflict`, `same_unit_conflict`, `consecutive_max`, `count_max`, `count_min`, `target_per_period`, `composition`, `max_gap`, `call_frequency_max`, `fairness_distribution`, `holiday_equity`, `we_pairing`, `eligibility` |
| **Occupied interval** | the half-open absolute-minute interval above | `overlap_block`, `min_gap` (`hours`), `post_duty_exclusion`, `free_day_min` |
| **Split at midnight** | minutes apportioned to each civil date they fall on | `rolling_hours_max` **only** |

A Friday-night call is **one Friday call**, not two half-calls — and it is also twelve Friday hours
plus twelve Saturday hours in the one type that sums minutes into a day-bounded window. Both are
right for their family; what is fatal is each type picking one silently. Asserted as a **matrix**
fixture across every type touching a slot window, the `PickerParityTest` shape (every fixture ×
every type) rather than case by case.

**4. The horizon edge is this catalog's defining defect, and the contract carries the fix.** Every
window-measured and pairwise type measures a relationship that crosses the boundary of what is being
evaluated: the 10 h gap between the last night of month M and the first duty of M+1; a post-duty
exclusion carrying into the 1st; a consecutive run spanning the 31st→1st; a `max_gap` open at both
ends; a four-week average with a partial window on roughly the first 27 days. A draft is built one
period at a time (WB-01) and the preceding period is a **different, already-published schedule**.

```
EvaluationContext {
  …
  priorDuties: Duty[]      // already-published duties BEFORE horizon.from — read as CONTEXT
  followingDuties: Duty[]  // and after horizon.to; usually empty, never assumed empty
}
```

**Emission rule, asserted:** a violation is reported **only when its location falls inside
`[horizon.from, horizon.to]`**. Reading last month's duties as context is not re-evaluating last
month, so CG-03's *"never retroactive on published schedules"* stays intact. In P2 `priorDuties`
comes from fixtures; in P3 from the previously published schedule.

**Every one of the eleven affected types is fixtured AT THE SEAM.** A corpus that only contains
mid-month cases proves nothing about the case a scheduler hits first, on the 1st.

### One primitive set, built once

`absMinute`, `dutyInterval`, `occupiedDates`, `onDutyMinutesOn(date)`, `orderedDutiesFor(person)`,
`enumerateWindows(kind, length, horizon)`. All twenty-two types consume these; none re-derives them.
The codebase's own precedent is `AuditChain::canonical()` — two copies drifted the day `APP_TIMEZONE`
was set and the live system announced its whole audit trail as tampered, and nothing had been — and
`SignoffPickers`, one predicate per field applied to both the offer and the write rule. **Task 4
builds them beside the `Ymd` core, before any type exists.**

### Why a type and not a table

1. **No migration in P2, so nothing pre-commits P3's schema.** `slotKey` and `personKey` are opaque
   strings, not foreign keys.
2. **P3 adds a projector, not a second semantics.** `slots`/`assignments` serialise into `Slot`/`Duty`
   with no rule logic in the projection.
3. **The golden fixtures and the eventual §4.3 job have something to run against before any table
   exists**, which is what makes P2 testable at all.
4. **It is demoable.** A hand-authored synthetic month of JSON in, `violations[]` with plain-language
   explanations out (Task 24).
5. **It serialises into AU-02's request without a translation layer.** `days`+`periods` is
   `periodSkeleton`; `people` is `roster`; `slots` is `slots`; `Schedule.duties` is
   `fixedAssignments`; `conditions` is `conditions`. `templates` (SL-03) and `constraints` (RQ-01)
   have no P2 counterpart and are **absent rather than empty** — Task 7 states that, so P4 finds a
   hole rather than a wrong default.

### The rest of the context

```
EvaluationContext {
  timezone: string          // provenance and fixture identity ONLY; never used in arithmetic
  weekStartIsoDay: 1..7
  weekendDays: (1..7)[]
  today: Ymd
  days: Day[]               // one entry per date in the horizon, PRECOMPUTED server-side (finding 6)
  periods: Period[]         // { key, startsOn, endsOn, weeks: Week[] }   ← owner decision O
  people: Person[]          // { key, levelSpans, unitSpans, leaveDays: Ymd[], unwantedDays: Ymd[],
                            //   joinedAt?: Ymd, external: boolean,
                            //   eligibleDays: Ymd[],           ← the availability denominator
                            //   priorCredits?: Record<holidayKey, number|null> }  ← null = UNKNOWN
  slots: Slot[]
  clinics: Clinic[]         // { key, unitKey, isoWeekday, session, active,
                            //   attendeeMode: 'rotators'|'levels'|'named',
                            //   attendeeLevelKeys: string[], attendeePersonKeys: string[] }
  historyAvailableFrom: Ymd | null
  priorDuties: Duty[]
  followingDuties: Duty[]
}
Day  { date: Ymd, isoWeekday: 1..7, dayType: 'WD'|'WE'|'HOL',
       periodKey: string|null, holidays: { key: string, year: number }[] }
Week { startsOn: Ymd, endsOn: Ymd, clippedStartsOn: Ymd, clippedEndsOn: Ymd }
```

Four fields above exist because a specific type would otherwise be wrong, and each is named at its
type: `eligibleDays` (`fairness_distribution`'s pro-rated denominator, `max_gap`'s suppression,
`target_per_period`'s modifier — and it is buildable from **shipped** data today, which makes it the
only half of the far side of the seam that can be checked against reality in P2);
`periods[].weeks` with clipped bounds (owner decision O); `holidays[].year` carrying the holiday's
**own-calendar** year (owner decision W); `priorCredits` with `null` meaning UNKNOWN, distinct from a
known zero (owner decision W).

---

## Decision B: the engine holds no `Date`, no instant, and no timezone

**The problem, stated plainly.** Decision A of P1a says `resources/js` performs **no date arithmetic
at all, ever — not even to compute "today"** and its guard's allow-list is *deliberately empty*.
P2's whole premise is a conditions engine that runs in the browser (AR-03, UX-05, NF-01) and
computes dates for a living. The guard's scope is `resource_path('js')`, so `packages/engine`
escapes it — which is a loophole and would be a shameful way to satisfy an invariant.

**The resolution: the engine has no `Date` object anywhere, so there is nothing to allow-list.**

- Its date type is a branded `Ymd` string (`'2026-08-19'`), and all date arithmetic is **integer
  civil-date arithmetic** — days-from-civil / civil-from-days, the standard branchless algorithm,
  which needs no `Date`, no epoch and no ICU.
- Its time type is **minutes from local midnight**, an integer, and its duty arithmetic is a single
  integer line of absolute minutes (Decision A). `min_gap` in hours, `post_duty_exclusion`,
  `overlap_block`, `free_day_min` and `rolling_hours_max` are therefore integer arithmetic.
- **There is no instant, so there is no timezone, so there is no timezone trap.** This is the direct
  analogue of `AvailabilitySummary`'s *"IT HANDLES NO DATES (ST-06). Not one … four comparisons
  between `Y-m-d` STRINGS — a format that sorts correctly as text."*
- **"Today" is never computed.** It arrives in the context, from `Calendar::today()`, exactly as
  `resources/js` gets it today.

**Why this matters more than tidiness.** `golden.json` carries `"timezone": "Asia/Riyadh"` and a
`day_boundary_cases` block because the 00:00–03:00 UTC/Riyadh disagreement window is a real defect
class this codebase has already paid for. A Node/Vitest process with `TZ` unset runs at UTC; a
browser at +03:00 does not. That is the exact shape of the trap CLAUDE.md records for PHP —
*"`config(['app.timezone' => …])` alone does not move PHP's default timezone"*, which made a false
green indistinguishable from a real one. **An engine with no instants cannot have that bug**, and
that is a stronger guarantee than a test that remembers to set `TZ`.

The consequence for the guard is that it can be the *same* guard, with the *same* empty allow-list:
Task 6 runs the ten existing JS date needles over `packages/**/*.ts` with **no allow-list in either
direction**, and the mirror passes on its own merits rather than by exemption — the same property
`ClinicHooksTest` has (*"the absence is real rather than allow-listed"*).

---

## Decision C: the mirror implements no Hijri and no `weeksIn()`, and declares both

`Calendar` resolves Hijri through ICU `islamic-umalqura` plus a per-department
`institutions.hijri_offset_days`, and `golden.json` carries `hijri_month_boundary` and `hijri_labels`
blocks precisely because that is the fragile part. A browser's `Intl` build is not guaranteed to
agree with PHP's ICU, and `Intl.DateTimeFormat` is one of the ten forbidden needles besides.

**No Hijri in the mirror.** Holidays reach the engine as **already-resolved Gregorian dates** in the
day vector, computed server-side by `Calendar::holidaysOn()`, which already handles the Hijri rule,
the `duration_days` walk-back and the per-department offset. A Hijri **label** is display text,
arrives as a string prop if a screen wants one, and is never arithmetic.

**This survives `holiday_equity` entering scope, and it is the interesting case.** *"The same holiday
in successive years"* is a **Hijri** fact for Eid al-Fitr and Eid al-Adha — Shawwal 1 of successive
Hijri years, drifting ~11 days a Gregorian year and occasionally putting two occurrences of one
holiday inside one Gregorian year. A lookback keyed on Gregorian years is wrong at that seam. **The
fix is not Hijri in the mirror; it is the context carrying `{ key, year }` per holiday** rather than
a bare key, with `year` in the holiday rule's **own** calendar — which the holidays migration's own
comment already fixes as the convention (*"a Hijri rule's year is a Hijri year, a Gregorian rule's a
Gregorian one"*). The engine compares integers it was handed. Owner decision W.

**No `weeksIn()` in the mirror either** (owner decision O, and finding 9 is the evidence).
`golden.json` has **no** coverage of `clipped_starts_on`/`clipped_ends_on`, and those bounds are what
every `week`-windowed count and `target_per_period`'s vacation-week modifier consume. Rather than
add an unasserted second implementation of a per-department fact — or modify a fixture this plan
declares an input — **`periods[].weeks` arrives in the context**, computed by `Calendar::weeksIn()`.
Same device as the day vector, same device as the holiday resolution: the one converter resolves it
once, server-side.

So the mirror implements: `Ymd` parse/format, civil-date arithmetic, `isoWeekday`, `datesBetween`,
`weekdayColumns` (rotated to a **supplied** `weekStartIsoDay`), `weekOf`, `isWeekend` (from
**supplied** `weekendDays`) and `dayType` (from supplied `weekendDays` and a supplied resolved-holiday
set) — eight functions, every department-varying fact a parameter (owner decision X).

**And it declares what it does not implement.** Task 5 ships a coverage manifest naming every
`golden.json` top-level key as either *asserted by the mirror* or *deliberately out of scope, with
the reason*, plus a test that the union is the file's actual key set. Without it, `hijri_labels`
sitting unasserted looks identical to somebody forgetting — and *"we have not built it"* and *"we
have decided not to build it"* are different states, only the second of which is safe to build on
(design §14 item 18's treatment, applied here).

---

## Decision D: the CG-10 contract widens in ONE place — `location` — and the return type does not

CG-10 fixes the violation as `{conditionId, severity, rank?, location, explanation}` and leaves
`location` **unshaped**. The committed draft typed it `{ personKey, date, slotKey }`, which is correct
for the eleven placement types and **cannot express the other eleven**: `rolling_hours_max`,
`free_day_min`, `count_max`/`count_min`, `target_per_period`, `composition`, `max_gap` and
`call_frequency_max` violate per (person, **window**); `fairness_distribution`, `holiday_equity` and
`we_pairing` violate per **cohort**, with no date and no slot at all. `max_gap` forces it alone: its
violation is an interval between two duties, or an interval left **open** at the horizon edge.

**Widening `location` is contract-compatible** (CG-10 never shaped it) **but it must happen in Task
7, where the contract is authored** — not be retrofitted after eleven types are registered and the
JSON Schema and fixtures are written against the narrow shape.

```
type Location =
  | { kind: 'placement', personKey: string, date: Ymd, slotKey: string }
  | { kind: 'window',    personKey: string, from: Ymd, to: Ymd, contributing: Duty[] }
  | { kind: 'cohort',    personKeys: string[], scopeLabel: string, contributing?: Duty[] }
```

**`contributing` is MANDATORY on a window violation.** Without it a duty-hours violation is
unactionable in the workbench: it tells a scheduler a window is over budget and nothing about which
placement to move. WB-03 badges cells and WB-04 orders pickers by a fitness signal; both need duties,
not a range.

**The return type does NOT widen.** `evaluate(schedule, context, conditions): Violation[]` stays
exactly CG-10's sentence, because PU-03's publish dialog *"consumes `violations[]` unchanged"* and a
third severity beside CG-05's Hard and CG-06's soft would collide with `class` being authored data.

**Skipped windows are reported through a sibling function, not smuggled into the violation list.**

```
coverage(schedule, context, conditions): CoverageReport[]
// [{ conditionId, evaluatedWindows, skipped: [{ from, to, reason }] }]
```

A cap under-counts on a partial window and produces no false positive; a **floor or target
false-positives on every partial window**. So floors and targets evaluate only on windows fully
inside `[evaluableFrom, evaluableTo]` — and **a silently dropped window is a guard that looks
green**, which is why the drop is reported rather than implied. `coverage()` has real consumers in
P2-1 already: `min_gap`, `post_duty_exclusion` and `consecutive_max` all have an unevaluable left
edge when `priorDuties` is empty. It is a pure function beside `evaluate()` in the same shape
`AvailabilitySummary` sits beside `RotaGrid` — not a field on it.

`evaluate()` is otherwise unchanged from the committed draft: no I/O, no globals, no clock,
deterministic ordering of `violations[]` (by `conditionId`, then `location`) so a fixture comparison
is stable; an unknown `typeKey` **throws** rather than being skipped, because a silently ignored
condition is a control that appears to do nothing — rulings 41/49's failure shape, one layer inside
the engine.

---

## Decision E: the registry records what the engine may assert, and nothing more

Finding 14: three of twenty-three rows carry a class; §30 makes `class` a field on the condition row;
CG-01 lists it per condition and CG-02 rank-orders the soft ones. **So class is authored data and the
engine reads `Condition.class`.** The committed draft's `defaultClass` per registry entry would have
hardcoded a class for nineteen types that have none.

```
RegistryEntry {
  typeKey: string
  implemented: boolean            // false ONLY for forbidden_transition, with its citations
  evaluate?: (…) => Violation[]
  preview?: (…) => string
  paramsSchema?: JSONSchema
  assertedClass?: 'hard'          // present on `overlap_block` ALONE — CG-07 "Hard, built-in"
  catalogDefault?: 'hard' | 'soft-top'   // DOCUMENTATION of CG-07's markings. Never applied.
  direction: 'cap' | 'floor' | 'target' | 'block' | 'spacing' | 'equity'
  locationKind: 'placement' | 'window' | 'cohort'
  needsCarryIn: boolean
}
```

`direction` exists for one reason and it is a product hazard, not tidiness: **CG-05 makes Hard block
publishing and AU-02 makes the solver never violate it, so a Hard FLOOR or TARGET turns a staffing
shortage into AU-07 infeasibility plus a publish block instead of a ranked warning** — a materially
different behaviour reachable from one drag on a gate screen. A Hard CAP is safe; a violation is
always attributable to a placement. P3's gate warns before a floor is set Hard. **The engine still
never overrides the row.**

`locationKind` and `needsCarryIn` are what make the seam and the fixture corpus checkable rather than
asserted: Task 8's parity guard asserts every entry's `locationKind` matches the shape its fixtures
actually produce.

---

## Decision F: `services/engine` waits for its first caller; P2 ships a Node entrypoint instead

The phase table gives P2 `services/engine`. **P2 has no server-side consumer for it:** the publish
gate CG-05 and the workbench are P3, compliance reports TL-03 are P5, the solver is P4.

A container deployed with nothing calling it can be verified as *running* and cannot be verified as
*working* — the exact failure shape CLAUDE.md names: *"A Cloudflare trust fix once passed every test,
deployed healthy, and changed nothing at all — the compose default it edited was dead code."* The
cost is not zero either: `docker-compose.production.yml` has two services and fifteen pinned
invariants in `DeploymentInvariantsTest` (digest pinning, env passthrough, healthchecks); CI's
`docker-build` job builds the root Dockerfile alone; `docker/instance-env.sh` selects a stack's
containers.

**Recommended (owner decision Y): defer `services/engine` to P3, where CG-05's publish gate is its
first real caller.** P2 ships `packages/engine/bin/evaluate.mjs` — reads the CG-10 JSON on stdin,
writes `violations[]` on stdout — exercised in CI (Task 23). That proves the compiled package runs
outside a bundler, under plain Node, with the same code the browser gets, and deploys nothing.

---

## Decision G: what P2's "cross-validation job" actually is

The phase table gives P2 *"the CI cross-validation job"*. **§4.3's job compares the TS engine against
the Python solver's §4.2 evaluation mode, and both `services/solver` and that evaluation mode are P4
deliverables** (phase table line 1054). So the P2 row names a job whose second implementation does
not exist for two more phases. This is a real internal inconsistency in the phase table, and Task 1
corrects it rather than letting a later reader take it as a commitment already missed.

**What P2 can honestly deliver, and does:**

| Job | What it is | Genuinely two implementations? |
|---|---|---|
| **`golden.json` two-sided** (Tasks 5, 23) | The same framework-free corpus asserted by `App\Support\Calendar` (PHP, `GoldenFixtureTest`, shipped) **and** by the TS mirror (new). A divergence fails the build. | **Yes.** This is the repository's first and, until P4, only real cross-implementation check. |
| **The catalog-parity guard** (Task 8) | The registry's key set derived from and compared against CG-07's table in `SPEC.md`, in **both** directions. | Not a second implementation — a second *source*, which is the `UnitMergeCoversEveryUnitReferenceTest` device. |
| **The conditions golden-fixture gate** (Tasks 10–20, 23) | A corpus of `(schedule, context, conditions) → expected violations[]` cases asserted by the TS engine. | **No** — one implementation. This is NF-08/QA-01 regression coverage and Task 23 labels it as such rather than as cross-validation. |
| **§4.3's real job** | TS engine vs CP-SAT mapping, identical verdicts. | Yes — **and it arrives in P4** with the solver. Recorded, not built. |

The discipline `golden.json` inherits is `SignoffPickers`': *"a predicate written once as Eloquent and
once as raw SQL is two predicates that drift"*, and `PickerParityTest` asserts it **as a matrix**
(every fixture × all four fields) rather than case by case. Task 5's mirror assertion and Decision
A's attribution matrix are the same shape, and Task 23 makes a divergence fail the build.

---

## Decision H: presets are DATA in the package, and an empty one says so

CG-08 names three: residency defaults *"seeded from the prototype's proven values"*, the ACGME
duty-hours bundle, and *"an empty SCFHS/local preset slot"*.

**What a preset can physically be in P2 is a JSON data file inside `packages/engine`, nothing more.**
P2 ships no migration and there is no `conditions` table (P3 builds it). So a preset is data the
engine can be *called* with, and that ST-01's setup wizard later *imports* into `conditions`. It is
**not seed data**, and calling it a seeder would require the table that does not exist. The file says
so, because *"seeded"* is CG-08's own word and a later reader will take it literally.

- **`preset:acgme`** — five conditions, mapping onto `rolling_hours_max` {80 h / 7 d, averaging 4},
  `call_frequency_max` {1-in-3, averaged}, `free_day_min` {1-in-7, averaged 4}, `min_gap` {10 h,
  end-to-start} and `consecutive_max` {`unit: 'hours'`, 24, `transitionMinutes`} (owner decision V).
  All **soft at a high rank, `active: true`** — a preset that installs inert is another control that
  appears to do something. CG-05 already contemplates promotion (*"a department may relax to
  warn-only"*, read in the other direction), and setting an untested duty-hours rule Hard on day one
  blocks the department's first real publish.
  **It states the two clauses it cannot implement**: SPEC Appendix A's *"in-house time during home
  call counts"* has no timekeeping surface anywhere in the platform and §36 puts time/payroll out of
  scope, so the figure **excludes** it; and there is no baseline daytime-hours model in Munawib at
  all (`master_rota_assignments` records which unit, never how long), so 80 h summed over call slots
  alone is a **floor, not an audited total**. Both collide with Stage 4's acceptance criterion that
  the compliance report *"reproduces hand-computed results"* (TL-03), and saying so now is cheaper
  than discovering it at the Stage-4 gate.
- **`preset:residency`** — a **structure with named, un-filled numbers** plus a manifest entry saying
  the values are pending owner input. D14 and D15 make the prototype's numbers unobtainable (finding
  13). A wrong residency default is worse than an empty one, because it looks authoritative on the
  gate screen; and *"a stub returning a plausible value"* is what CLAUDE.md forbids outright.
- **`preset:scfhs`** — a **present, well-formed record with zero conditions** and a mandatory
  `pending` block naming what is awaited (§37's *"the SCFHS/local duty-hour policy in numeric form"*),
  who supplies it, and the date last checked. An empty array is indistinguishable from a failed load
  and from nobody having written it yet.

**A build-failing test asserts the preset registry's key set equals the declared manifest set**, so a
fourth preset appearing without an entry fails — the same shape as Task 5's coverage manifest.

**This is the mechanism that makes all-21 honest.** §37 still owes the numeric policy and §38's
second unvalidated assumption is that it maps onto the catalog. That blocks the **numbers**, not the
**predicates**. Every type ships; no numeric default is invented for any type whose numbers §37 still
owes; and the residency preset ships with those entries **absent rather than guessed**. An absent
preset entry and a guessed one look identical on a gate screen and only the first is safe.

---

## Decision I: the PHP context builder is `App\Support\Engine`, and it is a reader

**Where it lives is forced, not chosen** (invariant 4). `App\Support\Rota\*` is globbed by
`RotaAccessTest` with `eligib` among its needles, so the `eligibility` type key alone would fail the
build there. `App\Support\Clinics\*` is globbed by `ClinicHooksTest` with `condition` among its
needles, so `clinic_conflict`'s reader would fail the build there. And the **app-wide raw scan**
forbids `off_roster`/`offRoster`/`callEligib`/`call_eligib` anywhere under `app/`, docblocks
included. **`App\Support\Engine\` is clear of all three, and P2 adds no allow-list entry to any of
them.** That is not a workaround: MR-04's rule is that *the rota* must not infer eligibility, and
CL-03's is that *the clinic module* must not evaluate conditions. Both are satisfied by the crossing
living in the engine's own namespace and reading those modules' data, which is exactly what design
§14 item 22 already specifies.

**"No PHP implementation of the rules exists anywhere" (§4.1) is scoped to rule SEMANTICS, not to
data access, and the plan says so out loud** — because a loader is exactly the artifact that reads as
a violation of that sentence in review. Loading `master_rota_assignments` and flattening spans to a
per-date unit vector implements no rule. **The real leak risk is not PHP rules code; it is the
serialiser**, where a rule will try to sneak in as an optimisation: sending only "eligible" people is
`eligibility` re-implemented as a `where`. Task 22's guard needles for exactly that.

Without one builder, every P3 consumer builds its own context and the bounded-query property is lost
the first time — the same argument that made `AvailabilitySummary` one fold feeding two screens.

---

## The seam: P2-1 and P2-2

Too large for one branch, as P1c, P1d and P1e all were — and larger now than the committed draft
assumed. **The old seam was the parameterisation line: types needing no owner decision versus types
needing one. With all 21 rows in scope that line no longer separates a built set from a deferred
one, and it is gone.**

### The new seam is the shape of the violation

- **P2-1 — the substrate, the contract in full, and every type whose violation is a PLACEMENT**
  (`{personKey, date, slotKey}`): `overlap_block`, `vacation_block`, `eligibility`,
  `unwanted_day_block`, `onboarding_grace`, `dow_restriction`, `clinic_conflict`,
  `same_unit_conflict`, `min_gap`, `post_duty_exclusion`, `consecutive_max` — **eleven keys.**
- **P2-2 — every type whose violation is a WINDOW or a COHORT**, plus the PHP context builder, the
  preset registry, the Node entrypoint, the CI wiring and the demo command: `count_max`, `count_min`,
  `target_per_period`, `composition`, `max_gap`, `free_day_min`, `rolling_hours_max`,
  `call_frequency_max`, `fairness_distribution`, `holiday_equity`, `we_pairing` — **eleven keys.**

### Why this line and not another

1. **It is the line the contract itself draws.** Decision D's `Location` is a discriminated union of
   three members. P2-1 implements every type that uses one member; P2-2 the two that need the others.
   A seam cut along the contract's own discriminator means **P2-2 adds predicates and touches no
   shared shape** — which is what makes CG-10's *"new types are additive"* true rather than
   aspirational.
2. **It is the line the evaluation context draws.** P2-1's types read facts resolvable per date: the
   day vector, `leaveDays`, `unwantedDays`, unit and level spans, clinic rows, slot windows,
   `joinedAt`. P2-2's types additionally need **window enumeration, an eligible-day denominator,
   partial-window semantics and cohort scope** — four context features that are genuinely new work
   and that cluster entirely on one side.
3. **It is the line WB-03 draws.** A live hint on a *prospective* placement is exactly a
   placement-located violation, evaluated before every add or move. So P2-1 ends with the set the
   Stage-2 workbench needs first — which honours `…design.md:1468`'s *"order the 21 types so the
   prototype's proven nine land first and are demoable"* on a **tree-verifiable criterion** instead
   of on a nine that enumerates to nothing.
4. **It is the line §4.3 draws.** All five types §4.3 names as hardest to reconcile with the CP-SAT
   mapping — `fairness_distribution`, `rolling_hours_max`, `free_day_min`, `holiday_equity`,
   `we_pairing` — are in P2-2 (finding 12). The reconciliation risk concentrates on one side.
5. **It is deployable at the seam and demoable at it.** P2 ships no screen and no migration, so
   *deployable* is trivially satisfied in both halves; the stronger property is that at P2-1's end
   `evaluate()` answers eleven real questions about a real month, with plain-language explanations,
   through the Node entrypoint — an artifact, not a package with no predicates in it.

### The two alternatives, and why each was rejected

- **Hard-versus-soft.** Rejected on a correctness ground, not a taste one: **only three of
  twenty-three rows carry a stated class and §30 makes `class` a field on the condition row**
  (finding 14). A seam drawn on class would encode into the branch structure a per-type class
  assertion the engine is **forbidden** to make. The one class the engine may assert is
  `overlap_block`'s, which is a single type, not a seam.
- **Substrate-versus-types.** Rejected on risk distribution: it leaves twenty-two predicates in one
  branch and none in the other, so P2-1 merges an engine that implements nothing and P2-2 carries the
  whole of the phase's uncertainty. It also fails the demoability test above.

### One thing the seam authors early, deliberately

P2-1 authors the **window** and **cohort** `Location` members, `coverage()`, `priorDuties`/
`followingDuties`, and the registry's `direction`/`locationKind` fields — before P2-2's types consume
them. That is the same move Task 2 makes with the `@engine` Vite alias (*"added and asserted even
though nothing imports it yet, because a package the bundler cannot resolve is not the browser
runtime AR-03 requires, and discovering that in P3 is discovering it at the worst moment"*), and it
is stated here rather than left implicit because an unused contract field is otherwise exactly the
§1.3 objection's own argument. Two mitigations, both real: `coverage()` and `priorDuties` have three
consumers **inside P2-1** (`min_gap`, `post_duty_exclusion`, `consecutive_max` at the left edge), and
Task 7's corpus carries a `contract-shapes` case that constructs each `Location` member and
round-trips it through `validate()` and the deterministic ordering — asserting the serialiser and the
ordering, which is genuine, rather than asserting a rule that does not exist yet, which would not be.

### A note the phase table will otherwise be read against

The design phase table gives **P5** *"equity and holiday equity, duty-hour compliance (ACGME
preset)"* and §35 puts them at Stage 4. **That is not a contradiction of D13.** Those rows are
TL-02's dashboard and TL-03's report — the **consumers**. D13 gives P2 the condition **types**. An
unconsumed condition type is what all of P2 is: nothing renders a violation until P3's workbench.
Task 1 states the distinction in the design doc, because a later reader comparing the P2 row against
the P5 row will otherwise read a conflict where there is a division of labour — and this document has
been read against itself wrongly nine times already.

---

## Standing rules for every task

- **Failing test first, watched red, then implement.** Capture exit codes:
  `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"` — `| tail -3` returns *tail's* status.
- **Run tests via Bash, not PowerShell** (PowerShell's PATH here lacks `openssl`; the backup tests
  self-skip there — a false green identical to a real one).
- **Every guard is planted against**: write a file of exactly the shape the guard exists to catch,
  watch it go red, revert. Record what the plant was in the guard's own docblock. State every
  residual the needle set cannot reach — a residual stated is a residual somebody can close later; a
  residual implied is a blind spot.
- **Measure a needle before adding it** (ruling 42). A needle that forces you to allow-list the file
  where the next real offender would be born is worse than no needle.
- **Every fixture carries a `why`, and every type is observed failing on a planted defect.** With
  twenty-two types the corpus is the plan's largest artifact and its largest vacuity risk: a type
  green because its inputs are empty is indistinguishable, on a green suite, from a type that works.
- **`npm run build` and `npm test` green before every commit**, alongside `php artisan test`. Once
  the package exists, `npm run build` must actually bundle it — a package that only the test runner
  can resolve is not the browser runtime AR-03 requires.
- **Filter output.** `| tail -5`, `--filter <Name> | head -30`. Never dump a failing suite.
- **Tree deployable after every commit.**

---

# P2-1 — tasks

### Task 1: record the enumeration, correct the count, open the §14 items

**Files touched:** `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`,
`docs/munawib/SPEC.md`, `docs/INVARIANTS.md`, `CLAUDE.md`.

**Failing test first:** none — this task is documentation, and inventing a test for prose is the
vacuity this codebase guards against. Its verification is `grep`, below. It runs **first** because
every later task's legitimacy rests on it. **D13 is not touched; it stands as decided.**

**Implementation:**

1. A footnote under CG-07's table in `docs/munawib/SPEC.md`: the table is **22 data rows carrying 23
   distinct type keys** (`count_max / count_min` shares a row); `forbidden_transition` is marked
   `(Stage 5)` in its own cell, is named in §35's Stage 5 list and is covered by §36's *"Shift
   features before Stage 5"* non-goal; **22 − 1 = 21 rows = D13's number, and 23 − 1 = 22 implemented
   type keys.**
2. The design doc's phase-table P2 row rewritten to what P2 actually ships: the package, the 22 keys
   (as a list, not a number), `forbidden_transition` registered unimplemented, the calendar mirror
   against `golden.json`, the severity/rank model, CG-04 previews, the CG-08 preset bundles, the
   `App\Support\Engine` context builder and the Node entrypoint. It states that `services/engine`
   moves to P3 (Decision F) and §4.3's cross-validation job to P4 (Decision G), so a later reader does
   not find a P2 commitment apparently missed.
3. A sentence beside §1.3's D13 bullet: it is the **objection**, overruled, and its parenthetical
   *"shift transitions"* is loose prose that does not scope the 21 — see the footnote. **The paragraph
   itself is not edited**; it is a record of what was argued.
4. The risk table's *"D13 makes P2 long and undemoable"* row keeps its mitigation and gains: *"The
   ordering is the P2 plan's seam — placement-located types first (eleven keys, demoable through
   `php artisan engine:evaluate`), window- and cohort-located types second."*
5. A line recording that **§4.3's five hard-to-reconcile types omit `call_frequency_max`**, which has
   the same sliding-window shape — an omission in §4.3, not evidence the type is easy.
6. New §14 open items, continuing after item 27:
   - **28.** `services/engine` moved to P3 with its first caller; §4.3's job moved to P4 with the
     solver. What P2's CI job actually is (Decision G).
   - **29.** `forbidden_transition` is registered unimplemented with three citations; P7/Stage 5 owns
     it, and what it needs is a `shift` slot kind, not engine code.
   - **30.** `unwanted_day_block` has no store anywhere; `people.constraints` is free-form JSON
     validated only as `['nullable','array']`. **The spec's own answer is an approved RQ-01 request
     (§22, §30's `requests/{reqId}`), which §35 places at Stage 3 = P4** — recorded now so P3 does not
     re-open it as an unanswered gap.
   - **31.** `same_unit_conflict` is three-way ambiguous and `SPEC.md:100` is its only occurrence in
     the repository. The chosen reading and its date (owner decision U).
   - **32.** `clinics.session` is `string(2)` with no time window, so CL-03's stricter same-day
     variant cannot be a *time* overlap without a session→minutes configuration that does not exist.
   - **33.** Design §14 item 22's clinic formulation is incomplete for `attendee_mode` `named` and
     `levels` (finding 17), and the P1e handoff sentence is clarified rather than contradicted
     (finding 16).
   - **34.** `people.joined_at` is populated by no seeder, factory or demo path, so `onboarding_grace`
     must treat NULL as *no violation* and say so in its preview text (finding 18).
7. `docs/INVARIANTS.md` gains an **§Engine** section: the mirror is the one deliberate second
   implementation and `golden.json` is its contract in both directions; the engine holds no `Date`,
   no instant and no timezone; **the three attribution readings and which types use each**; the
   half-open interval convention; the horizon-edge emission rule; `App\Support\Engine` is a reader;
   and the **three** glob- or app-scanned namespaces it may not live in, and why.
8. `CLAUDE.md`'s area table gains the row: `packages/engine`, `App\Support\Engine`, conditions →
   §Engine.

**How to verify:**
`grep -n "22 data rows" docs/munawib/SPEC.md` returns the footnote;
`grep -c "all 21 CG-07 types" docs/superpowers/specs/*.md` returns 0 (replaced by the list);
`grep -n "forbidden_transition" docs/superpowers/specs/*.md docs/INVARIANTS.md` returns the new items;
`grep -rln "D13-R" docs/ CLAUDE.md` returns **only this plan file** — where the string survives in these
verification lines alone. The committed draft's Task 1 was never executed, so the reversal framing
never reached the design doc, `docs/INVARIANTS.md` or `CLAUDE.md`; this task must not put it there.
`grep -rn "D13-R" docs/superpowers/specs/ docs/munawib/ docs/INVARIANTS.md CLAUDE.md` returns **0**;
`php artisan test --filter=Build 2>&1 | tail -3` still green (documentation must not trip a
source-scanning guard — `CLAUDE.md` and `docs/` are outside every scan's scope, and this confirms it).

---

### Task 2: the toolchain — `packages/engine`, from zero

**Files touched:** `package.json`, `tsconfig.base.json`, `packages/engine/package.json`,
`packages/engine/tsconfig.json`, `vitest.config.js`, `vite.config.js`, `.github/workflows/ci.yml`.

**Failing test first:** `packages/engine/test/smoke.test.ts` — imports `version` from
`packages/engine/src/index.ts` and asserts it. Run `npm test`: it fails because `vitest.config.js`
includes `tests/js/**/*.test.js` only and there is no TypeScript loader. **Watch that exact failure**
before touching config; a suite that silently does not run the new file is the vacuity mode here, and
it looks identical to green.

**Implementation:**

- npm workspaces (`"workspaces": ["packages/*"]`) — the smallest thing that makes one repo hold two
  packages, and it needs no new tool. `typescript` and `@types/node` as root devDependencies.
- `tsconfig.base.json`: `strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`,
  `target: ES2022`, `moduleResolution: bundler`, `lib: ["ES2022"]` — **not `DOM`**, so the package
  cannot reach a browser global by accident. No `declaration` emit yet; nothing consumes types across
  a package boundary until P3.
- `vitest.config.js`: add `packages/*/test/**/*.test.ts` to `include`. Both suites run under one
  `npm test` — a second command is a second thing to forget in CI.
- `vite.config.js`: a `resolve.alias` for `@engine` so the browser bundle can import it. **The alias
  is added and asserted in this task even though nothing imports it yet**, because a package the
  bundler cannot resolve is not the browser runtime AR-03 requires, and discovering that in P3 is
  discovering it at the worst moment.
- `.github/workflows/ci.yml`: `npx tsc --noEmit -p packages/engine` in the existing `test` job. A
  type error must fail CI, not merely annoy the author.
- `packages/engine/src/index.ts`: exports `version` and nothing else, yet.

**How to verify:**
`npm test 2>&1 | tail -5` — 237 + 1 = **238** Vitest tests, and the new file is *named* in the output
(not merely counted). `npx tsc --noEmit -p packages/engine; echo "rc=$?"` → `rc=0`. Then plant
`const x: number = "s";` in `src/index.ts`, re-run, confirm `rc=1`, revert — otherwise the CI step is
decoration. `npm run build 2>&1 | tail -3` green. `php artisan test 2>&1 | tail -3` still **1683**.

---

### Task 3: the `Ymd` core — civil-date arithmetic with no `Date` object

**Files touched:** `packages/engine/src/calendar/ymd.ts`, `packages/engine/test/ymd.test.ts`.

**Failing test first:** `ymd.test.ts` asserts `isoWeekday('2026-08-19') === 3` and
`addDays('2026-02-28', 1) === '2026-03-01'` (2026 is not a leap year) and
`addDays('2028-02-28', 1) === '2028-02-29'` (2028 is). Fails: the module does not exist.

**Implementation:** `parseYmd` (strict `^\d{4}-\d{2}-\d{2}$` **and** a real-date check, so
`2026-02-30` is rejected — `golden.json`'s `parse_rejects` block exists for this), `formatYmd`,
`daysFromCivil`/`civilFromDays` (the standard branchless algorithm), `addDays`, `diffDays`,
`isoWeekday`, `datesBetween`. Every function is total and pure; **no `Date`, no `Intl`, no epoch, no
timezone** (Decision B).

**One divergence from PHP, stated rather than papered over** (finding 20): `datesBetween` takes an
explicit maximum span and throws beyond it, mirroring `Calendar::weeksIn()`'s 550-day guard. **PHP's
own `Calendar::datesBetween()` is uncapped**, so this is a deliberate difference, not parity, and
`golden.json` would not catch it either way. The reason is that the memory being protected is a
browser tab's. The docblock says both halves.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Cases include both leap
boundaries, a century non-leap (`1900-02-28` → `1900-03-01`), a century leap (`2000-02-28` →
`2000-02-29`), the year boundary, and every `parse_rejects` input from `golden.json`. Then **prove
the tests can fail**: replace `isoWeekday`'s modulus with `+ 1`, watch red, revert.

---

### Task 4: the duty-time core — absolute minutes, intervals, ordering, windows

**Files touched:** `packages/engine/src/duty/{interval.ts,order.ts,windows.ts}`,
`packages/engine/test/duty-core.test.ts`.

**Failing test first:** assert `absMinute('2026-08-19', 480) === daysFromCivil('2026-08-19') * 1440 +
480`; that a night call `{date:'2026-08-19', startMinute:1200, endMinute:480, crossesMidnight:true}`
has an interval ending at `absMinute('2026-08-20', 480)`; that **two abutting windows do not
intersect** (half-open, the SL-02 split-day/night case); and that a weekly-cadence duty with
`spanDays: 7` occupies seven dates. All fail: the module does not exist.

**Implementation:** Decision A's one primitive set — `absMinute`, `dutyInterval`, `intersects`,
`occupiedDates`, `onDutyMinutesOn(date)`, `orderedDutiesFor(person, duties)` (stable ordering over
`priorDuties ++ duties ++ followingDuties`), and `enumerateWindows(kind, lengthDays, horizon)`.
**Built before any type exists, so all twenty-two consume one definition** — the
`AuditChain::canonical()` precedent, stated in the docblock with the incident it names.

`onDutyMinutesOn` implements the **split-at-midnight** reading and is documented as used by
`rolling_hours_max` alone; `occupiedDates` implements the **occupied-interval** reading;
`Duty.date` is the **anchor-date** reading and needs no function. All three appear in the docblock
with Decision A's type table, because the failure mode is a future author picking one silently.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Plant a closed-interval
comparison (`<=` for `>`), confirm the abutting-windows case goes red, revert — that is the one
change that would flag every split-call department on every day and is invisible in review.

---

### Task 5: the calendar mirror against `golden.json`, and its coverage manifest

**Files touched:** `packages/engine/src/calendar/index.ts`,
`packages/engine/test/golden.test.ts`, `packages/engine/test/golden-coverage.test.ts`.
**`tests/fixtures/calendar/golden.json` is READ and NOT MODIFIED** — it is an input to this phase.

**Failing test first:** `golden.test.ts` loads the fixture by relative path and asserts the `cases`
block through the mirror. It fails on the first case. **Then plant the drift this whole file exists
to catch**: change one expected value in a *copy* of the fixture, confirm red, discard the copy. The
fixture itself is never edited to make a test pass — that is the one move that would make the
contract worthless.

**Implementation:** the mirror implements `isoWeekday`, `datesBetween`, `weekdayColumns(weekStartIsoDay)`,
`weekOf`, `isWeekend(weekendDays)`, `dayType(weekendDays, holidayDates)` — and, per owner decision O,
**not `weeksIn()`**: `golden.json` has zero coverage of its clipped bounds (finding 9) and week
windows arrive in the context instead. Every department-varying fact — `weekendDays`,
`weekStartIsoDay`, the resolved holiday set — is a **parameter**, never a module default (owner
decision X): a default in the package is a second definition of a per-department fact, which is what
`golden.json` exists to prevent.

`golden-coverage.test.ts` is the honesty half. It declares two lists — `ASSERTED` and `OUT_OF_SCOPE`
(each with a one-line reason: `hijri_month_boundary` and `hijri_labels` per Decision C; `period_runs`
because period boundaries arrive in the context rather than being generated client-side;
`day_boundary_cases` because Decision B removes instants and the PHP-side assertion remains the one
that matters; `timezone`/`version`/`_purpose` as metadata) — and asserts their union **equals** the
fixture's actual top-level key set. It additionally records that **`weeks` covers `weekOf()` only and
that no block covers `weeksIn()`'s clipped bounds**, with owner decision O as the reason. When
`golden.json` reaches version 3 with a new block, this test fails until somebody decides which list
it joins. Absence becomes a decision instead of an oversight.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Then add a throwaway key to a
*copy* of the fixture, point the coverage test at the copy, confirm it fails with the key named,
revert. Confirm `php artisan test --filter=GoldenFixtureTest 2>&1 | tail -3` still green — both sides
now assert one file and neither has moved it.

---

### Task 6: extend the date guard to `packages/`, allow-list empty in both directions

**Files touched:** `tests/Feature/Build/CalendarIsTheOnlyConverterTest.php`.

**Failing test first — the plant comes before the guard.** Write `packages/engine/src/scratch.ts`
containing `const t = new Date();` and `const days = ['Mon','Tue'];`. Run
`php artisan test --filter=CalendarIsTheOnlyConverterTest 2>&1 | tail -3` — **green**, because the
scan's scope is `resource_path('js')`. That green is the finding, and it is recorded in the guard's
docblock as the measurement that justified it.

**Implementation:** a `tsFilesUnderPackages()` collector (`.ts`/`.mts`/`.js`/`.mjs` under
`base_path('packages')`, excluding `node_modules` and `dist`) fed into **both** client-side scans:
the ten date-construction needles and the quoted-whole-word weekday pattern. **No allow-list, in
either direction** — the mirror passes on its own merits because Decision B leaves nothing to
exempt, which is `ClinicHooksTest`'s *"the absence is real rather than allow-listed"* property. A
non-vacuity floor asserts the collector found at least the files Tasks 3–5 created, because a guard
iterating an empty set is green for the wrong reason and a moved directory is exactly how one gets
there.

The docblock records, in the house style: what was planted, that the guard was green before, the
measurement that the needle set costs zero allow-list entries against the tree as it stands, and one
stated residual — **a date library added as an npm dependency is invisible to a source scan of our
own files.** Closing that would mean scanning `packages/engine/package.json`'s dependency list; it is
**measured and bought** here, because the list is short, the check is one `assertSame([], …)` over
declared dependencies against an allow-list of the zero runtime dependencies the package has, and the
staleness twin is free. `dayjs`/`date-fns`/`luxon`/`moment` arriving as a transitive dependency of a
devDependency is **not** covered and is stated as the residual.

**How to verify:** with `scratch.ts` still present,
`php artisan test --filter=CalendarIsTheOnlyConverterTest 2>&1 | tail -5` → **red**, naming
`scratch.ts` for both `new Date(` and `'Mon'`. Delete `scratch.ts`, re-run → green. Then plant a
runtime `"dayjs": "^1"` in `packages/engine/package.json`, confirm red, revert. Full suite: **1683 +
2** (the dependency check and its staleness twin) = **1685**.

---

### Task 7: the CG-10 contract — types, JSON Schema, the three location shapes, `coverage()`

**Files touched:** `packages/engine/src/contract/{types.ts,schema.json,validate.ts}`,
`packages/engine/src/{evaluate.ts,coverage.ts}`, `packages/engine/test/contract.test.ts`,
`packages/engine/test/fixtures/README.md`, `packages/engine/test/fixtures/contract-shapes.json`.

**Failing test first:** `contract.test.ts` calls `evaluate(schedule, context, [])` on a minimal
synthetic month and asserts `[]`; calls it with a condition whose `typeKey` is unknown and asserts it
**throws a named error**; and asserts each of the three `Location` members validates and orders
deterministically. Fails: nothing exists.

**Implementation:** Decision A's `EvaluationContext`/`Day`/`Week`/`Slot`/`Duty`/`Schedule` and
Decision D's `Location` union and `Violation`, verbatim. A hand-written JSON Schema plus a
`validate()` that runs it — **no schema library**, because a runtime dependency in the browser bundle
is a cost, the shape is small, and Task 6's dependency check would have to allow-list it.

`evaluate()` is pure: no I/O, no globals, no clock, deterministic ordering of `violations[]` (by
`conditionId`, then `location`) so a fixture comparison is stable. An unknown `typeKey` **throws**
rather than being skipped, because a silently ignored condition is a control that appears to do
nothing — rulings 41/49's failure shape, one layer inside the engine.

`coverage()` ships beside it (Decision D), returning per condition what it evaluated and what it
skipped with a reason. **The emission rule is asserted here**: a violation whose location falls
outside `[horizon.from, horizon.to]` is not emitted, even when `priorDuties` made it computable —
CG-03's *"never retroactive on published schedules"*.

`fixtures/README.md` fixes the corpus format — one JSON file per case: `{ name, why, context,
schedule, conditions, expected: Violation[], expectedCoverage? }` — and states, in the file, that
**the corpus is synthetic permanently** (invariant 11) and that `why` is mandatory because a fixture
whose purpose nobody wrote down is a fixture nobody dares change.

`contract-shapes.json` is the case that keeps P2-1's early authoring honest: it constructs one
violation of each `Location` member by hand and round-trips it through `validate()` and the ordering,
asserting the **serialiser and the ordering**, not a rule.

`templates` and `constraints` from AU-02 are **documented as deliberately absent**, with the reason,
so P4 finds a hole rather than a wrong default (Decision A).

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`; `npx tsc --noEmit -p
packages/engine` → `rc=0`. Prove `evaluate()` is deterministic: run one fixture 100× and assert
identical JSON output. Prove the unknown-key throw by planting `typeKey: 'min_gap'` **before** Task 14
implements it and confirming the named error. Prove the emission rule by moving a fixture's horizon
one day later and watching the boundary violation disappear.

---

### Task 8: the registry, and the catalog-parity guard derived from `SPEC.md`

**Files touched:** `packages/engine/src/registry.ts`,
`packages/engine/test/{registry,catalog-parity}.test.ts`.

**Failing test first:** `catalog-parity.test.ts` parses CG-07's table out of
`docs/munawib/SPEC.md`, splits `count_max / count_min` on the slash, and asserts the resulting key
set **equals** the registry's key set **in both directions**. It fails on the first run because the
registry is empty. It is written before any type exists **on purpose**: it is the artifact that keeps
the count from drifting a second time.

**Implementation:** Decision E's `RegistryEntry`, one entry per key. Twenty-two carry
`implemented: true` (with `evaluate`/`preview`/`paramsSchema` filled in by Tasks 10–20);
`forbidden_transition` carries `implemented: false` and its three citations as a string, so `grep`ing
the registry answers "why is this missing" without opening the spec.

**`assertedClass` is present on `overlap_block` alone** (finding 14). `catalogDefault` records
CG-07's markings for `vacation_block` and `unwanted_day_block` as **documentation the engine never
applies** — a comment would rot, a field with a test cannot. A test asserts that
`evaluate()` reads `Condition.class` and **ignores** `catalogDefault`: build one condition with
`class: 'soft'` on `vacation_block` and assert `severity: 'soft'`.

**Measured, not assumed — `count_max` and `count_min` are TWO registry keys**, sharing one evaluator
module and one params schema. CG-01 and §30 store one `typeKey` per condition row, CG-04's preview
text differs by direction, and a department will enable a cap without a floor. A single key with a
direction parameter would also fail the parity guard, because CG-07 names two.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Plant a 23rd row in a **copy** of
`SPEC.md`, point the parity test at the copy, confirm it fails naming the unregistered key, revert.
Then delete a registry entry, confirm it fails in the other direction. Both directions, per the
`UnitMergeCoversEveryUnitReferenceTest` discipline.

---

### Task 9: the severity/rank model and CG-04's plain-language previews

**Files touched:** `packages/engine/src/severity.ts`, `packages/engine/src/preview.ts`,
`packages/engine/test/{severity,preview}.test.ts`.

**Failing test first:** assert that a `hard` condition yields `severity: 'hard'` regardless of rank;
that two `soft` conditions at ranks 1 and 5 grade differently; and that `preview()` for a
fully-parameterised condition returns a sentence containing every parameter value. All fail.

**Implementation:** CG-05/CG-06's model — hard sits above all soft; soft grades monotonically by
rank, so **rank ordering is the only input** and no numeric weight is invented here (AU-02 says the
solver's penalties are *"weighted monotonically by rank"*; a weight curve chosen in P2 would be a
second definition of the same fact, in the wrong repository).

`preview()` generates CG-04 text **from parameters**, per type, in English only (AR-07 — translations
are future work and the generator takes a message table so that stays possible). One property is
asserted across **every implemented type**, matrix-style like `PickerParityTest`: the preview names
every parameter the type reads, so a parameter added without its preview fails the build.

**Two previews carry a worked example, because the parameter is otherwise unreadable on a gate
screen** (owner decisions H and V): `min_gap` in `days` renders *"at least 3 days apart — 1 Aug then
4 Aug is allowed, 1 Aug then 3 Aug is not"*, and `rolling_hours_max` with averaging renders the
multiplication in words (*"80 h a week averaged over 4 weeks — at most 320 h in any 28 consecutive
days"*). An off-by-one and a mis-read averaging rule are both invisible in review and both change a
month of behaviour.

**The rulings 41/49 hazard, stated because it applies here in an unusual form.** Preview text is
generated in P2 and rendered by nothing until P3's gate screen — a string produced under a key no
screen consumes, which is precisely the shape that shipped three times as a control that appeared to
do nothing. The two halves cannot be asserted together yet because the render site does not exist.
What P2 does instead: the preview is asserted **against the parameter set** (above), and Task 1's
open item 28 records that **P3's gate screen owes the render-site half** — a Vitest assertion that
the gate renders `condition.preview` — so the pair is completed rather than forgotten. Stating a
half-finished pair is not the same as finishing it, and this plan does not claim otherwise.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Plant a new parameter on a type
without extending its preview; confirm the matrix test goes red naming the parameter; revert.

---

### Task 10: `overlap_block`, `vacation_block`, `eligibility`

**Files touched:** `packages/engine/src/conditions/{overlap_block,vacation_block,eligibility}.ts`,
their tests and fixtures.

**Failing test first, per type**, each from a fixture case whose `why` names the shape:

- `overlap_block` — two duties for one person whose occupied intervals intersect, **including a night
  call crossing midnight into the next date's duty**, plus the abutting split-day/night pair that must
  **not** violate (Decision A convention 1). Also asserted: `overlap_block` is **per person**, not per
  slot — a slot filled twice is SL-03 coverage-template territory and lands in P3; a fixture with two
  people on one slot expects **no** violation, and the `why` says why.
- `vacation_block` — a duty on a date inside `leaveDays`, and the boundary cases on the first and last
  day (both bounds inclusive, matching `vacations`).
- `eligibility` — a person whose level on the duty's date is not in the slot's allowed set, **and** the
  mid-window promotion case where the same person is eligible on one date and not the next.
  `eligibility`'s *"auto-fill order"* parameter is **not implemented** and its absence is asserted
  (owner decision Q: one type, one contract; ordering is WB-04 fitness, P3).

**Implementation:** three pure functions over the context, registered. `overlap_block` is the only
entry carrying `assertedClass: 'hard'`; the other two read `Condition.class` like everything else.
Params for `eligibility` name **stable keys** — `units.code`, level code, a person key that is not
`people.id` — per owner decision P.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Plant, per type, the defect its
fixture exists to catch (closed intervals; an exclusive leave bound; a level read at the horizon start
rather than at the duty's date), watch red, revert.

---

### Task 11: `RulesLiveOnlyInTheEngineTest` — no PHP implements a rule

**Files touched:** `tests/Feature/Build/RulesLiveOnlyInTheEngineTest.php`.

**Failing test first:** plant `app/Support/FakeRules.php` containing `function minGap(...)` with a
`'min_gap'` literal, and confirm the tree is **green** before the guard exists. That green is the
measurement.

**Implementation:** enforces §4.1's *"No PHP implementation of the rules exists anywhere"*, which is
currently prose with nothing behind it. Modelled on `CalendarIsTheOnlyConverterTest`: whole match set,
`assertSame([], $offenders)`, allow-list plus staleness twin. Needles are the **23 catalog type keys**
plus `violation`, `hard_block`, `soft_block`, `rank_order`, scanned over `app/` **with comments
stripped** (`Tests\Support\SourceScanner`), because `App\Support\Engine`'s docblocks will legitimately
say what they are for — and a guard that fails the build on the documentation of its own rule trains
people to delete the documentation (`RotaAccessTest`'s recorded departure, adopted here for the same
reason). The stripper is pinned in **both** directions against a real file, per invariant 3.

**Measure before adding, per ruling 42, and the measurement is larger now that all 23 keys are
needles.** `condition` as a bare needle is **not** bought: it matches `config`-adjacent prose and
Laravel's own vocabulary too widely, and buying it would mean allow-listing files where a real
offender would be born. `composition` as a bare needle is **not** bought either — it collides with
ordinary English in docblocks about object composition. `eligibility` **is** bought: it would collide
with `AvailabilitySummary`'s docblock, which the comment stripper removes, and the measurement is
recorded. The allow-list starts **empty**; if `App\Support\Engine` must name a type key in code (it
will, to key the context it builds), that is one entry with a stated reason and a staleness twin, and
Task 22 records the measurement.

**How to verify:** with `FakeRules.php` still present,
`php artisan test --filter=RulesLiveOnlyInTheEngine 2>&1 | tail -5` → red, naming the file; delete it.
Then plant it **inside a docblock only** and confirm the guard stays **green** — that is the stripper
proving it strips. Full suite: **1685 + 2** = **1687**.

---

### Task 12: `unwanted_day_block`, `onboarding_grace`, `dow_restriction`

**Files touched:** `packages/engine/src/conditions/{unwanted_day_block,onboarding_grace,dow_restriction}.ts`,
their tests and fixtures.

**Failing test first, per type:**

- `unwanted_day_block` — a duty on a date in `person.unwantedDays`; and a fixture where the person has
  an empty list, asserting no violation. Reads the context and **adds no table** (owner decision R).
- `onboarding_grace` — a duty inside `joinedAt + N` days; the boundary at day `N` and day `N+1`; and
  **the case the tree makes most likely: `joinedAt` absent, expecting NO violation** (finding 18,
  owner decision T). The `why` names the reason: no seeder, factory or demo path populates the column,
  so treating null as "joined today" would block an entire department silently.
- `dow_restriction` — a person banned on ISO weekday 5; a rotation-scoped ban resolved through the
  person's unit on the date; and a `days` parameter given as **ISO integers**, with a fixture asserting
  a string weekday name is **rejected** by the params schema (`['Mon']` must never ship, and
  `CalendarIsTheOnlyConverterTest`'s quoted-weekday needle would catch it in the package anyway).

**Implementation:** three pure functions, all anchor-date readings (Decision A). `dow_restriction`
reads `Day.isoWeekday` off the precomputed day vector and never recomputes it (finding 21, AR-08).

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Plant `joinedAt ?? today` in
`onboarding_grace`, watch the null-join-date fixture go red, revert — that is the department-wide
lockout this type's default exists to prevent.

---

### Task 13: `clinic_conflict` and `same_unit_conflict`

**Files touched:** `packages/engine/src/conditions/{clinic_conflict,same_unit_conflict}.ts`, their
tests and fixtures.

**Failing test first:**

- `clinic_conflict`, **all three attendee modes** (finding 17, owner decision S): a `rotators` clinic
  matched through the person's unit on the date; a `named` clinic whose attendee **rotates nowhere**
  and which the unit-only formulation would miss entirely; and a `levels` clinic that must **not**
  match a rotator whose level on that date is outside the attached set. Plus the post-call variant
  ON and the same-day variant OFF (SPEC §4's frozen default), and a fixture asserting the same-day
  variant is a **calendar-day** overlap, not a time overlap — `clinics.session` is `string(2)` with no
  minutes and no session→minutes configuration exists (finding 3, open item 32).
- `same_unit_conflict` — one fixture per reading is **not** written. The chosen reading (owner
  decision U) gets fixtures; the two rejected readings are named in the type's docblock with the
  reason, because `SPEC.md:100` is the type's only occurrence anywhere and a later reader will ask.

**Implementation:** `clinic_conflict` derives **one** post-duty window — `postDutyWindow(duty, slot)
→ [fromAbs, toAbs)`, per SL-02's *"post-duty semantics follow slot windows automatically"* — and
**shares it with `post_duty_exclusion`** (Task 14). This is the plan's answer to a real double
definition: `post_duty_exclusion` tests duties against that window and `clinic_conflict` tests clinic
sessions against **the set of dates the window touches**, so CL-03's day-granular rule reads off the
hour-granular window instead of re-deriving *"the day after a call"*. On a real configuration the two
otherwise disagree — a 24 h call ending Tue 08:00 with a Tue PM clinic is a violation under CL-03's
day reading and clean under an `H=4` hour reading — and a scheduler seeing one warning and not the
other cannot tell which is right.

**The trap, named explicitly:** do **not** model clinics as slots or duties to reuse
`post_duty_exclusion`. It would make clinics assignable, collide with CL-04's coverage subtraction and
CL-05's map (which deliberately ships no person-shaped value at all), and give `attendee_mode` two
meanings.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Plant item 22's incomplete
formulation (unit match only) and confirm the `named` and `levels` fixtures both go red — that is the
defect the committed draft would have shipped. Revert.

---

### Task 14: `min_gap`, `post_duty_exclusion`, `consecutive_max` — and the horizon-edge corpus

**Files touched:** `packages/engine/src/conditions/{min_gap,post_duty_exclusion,consecutive_max}.ts`,
`packages/engine/src/duty/post-duty-window.ts`, their tests and fixtures.

**Failing test first, and every one of these is a seam case:**

- `min_gap` — `hours` measured **end-to-start**; `days` measured between **start dates**; the
  off-by-one boundary in both directions (`N=3`: 1 Aug → 4 Aug legal, 1 Aug → 3 Aug not); and **the
  case the two readings disagree on** — a 24 h call ending 08:00 and a night call starting 20:00 the
  following day — because a fixture both readings pass proves nothing about the decision. Plus the
  carry-in case: the last night of the prior month against the first duty of this one, expecting a
  violation located **inside** the horizon.
- `post_duty_exclusion` — anchored on the **end** of duty `a`; testing duty `b` by **start-in-window**
  (owner decision H); a weekly-cadence `from` duty, whose end is defined by `spanDays` (owner decision
  K); a `from`/`to` intersection case, where the type degenerates into `min_gap`-in-hours and the
  preview must make that visible; and the carry-in case, an exclusion window opened on the 31st and
  closing on the 1st.
- `consecutive_max` — a run of exactly `count` (no violation) and `count + 1` (violation, located at
  **the date that broke the cap**, the only location that gives a scheduler an actionable cell); a
  crossing-midnight duty contributing its **anchor date only** (Decision A); two matching duties on one
  date counting as **one** date; and a run spanning the 31st into the 1st, which horizon-local
  evaluation would report as a run of 1.

**Implementation:** three pure functions over Task 4's primitives. `postDutyWindow()` is extracted
here and consumed by both `post_duty_exclusion` and Task 13's `clinic_conflict`. `consecutive_max`
carries the `unit: 'days' | 'nights' | 'hours'` parameter of owner decision V, with `'hours'`
measuring a **contiguous duty chain** — duties joined when the gap between them is `<=
transitionMinutes` — which is where CG-08's *"24 h continuous cap"* lands without adding a
twenty-third type key.

**All three consume `priorDuties` and all three report through `coverage()`** when the carry-in is
absent. That is what makes Decision D's early authoring real rather than speculative, and the
`expectedCoverage` half of each fixture asserts it.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Plant, per type: start-to-start
for `min_gap`'s `hours`; anchoring `post_duty_exclusion` on `a`'s **start**; resetting
`consecutive_max`'s run at the period boundary. Each must go red on its seam fixture and green
everywhere else — a plant that only fails mid-month means the seam fixture is not doing its job.

---

## Definition of done — P2-1

- [ ] `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"` → `rc=0`, **1687** tests (1683 + Task 6's 2
      + Task 11's 2).
- [ ] `npm test 2>&1 | tail -5` → `rc=0`, 237 Vitest plus the engine suite, **and the engine files
      named in the output** — a suite that silently skipped them looks identical to green.
- [ ] `npx tsc --noEmit -p packages/engine; echo "rc=$?"` → `rc=0`, and observed `rc=1` on a planted
      type error.
- [ ] `npm run build 2>&1 | tail -3` green, and the `@engine` alias resolves.
- [ ] **All 23 catalog keys registered**; twenty-two `implemented: true` with `evaluate`, `preview`
      and `paramsSchema`, of which the **eleven placement-located** ones are implemented in this half;
      `forbidden_transition` `implemented: false` with its citations. Task 8's parity guard green in
      **both** directions and observed red in each.
- [ ] Every implemented type has a fixture whose `why` names the shape, and **has been observed
      failing on a planted defect**.
- [ ] Every carry-in type (`min_gap`, `post_duty_exclusion`, `consecutive_max`) has a **seam fixture**
      at a period boundary, and `coverage()` reports its unevaluable edge.
- [ ] **No migration:** `git diff --stat main..HEAD -- database/migrations` empty.
- [ ] **No new allow-list entry** on `RotaAccessTest`, `ClinicHooksTest`, or any single-writer guard:
      `git diff main..HEAD -- tests/Feature/Rota/RotaAccessTest.php tests/Feature/Clinics/ClinicHooksTest.php`
      empty.
- [ ] `resources/js`'s date allow-list still empty; the guard now covers `packages/` with an empty
      allow-list too; both observed red on a plant.
- [ ] `golden.json` **unmodified**: `git diff main..HEAD -- tests/fixtures/calendar/golden.json` empty.
- [ ] Design doc, `docs/INVARIANTS.md` and `CLAUDE.md` carry the enumeration;
      `grep -rn "D13-R" docs/superpowers/specs/ docs/munawib/ docs/INVARIANTS.md CLAUDE.md` returns
      **0**; no bare "21" survives without its list.

---

# P2-2 — tasks

**Written when P2-1 merges**, per the P0a–P1e convention. Scoped and sized here; not specified to
Tasks 1–14's depth, deliberately — P2-1 will teach things about the contract that would make a fully
specified P2-2 written today wrong in the way this programme has repeatedly caught.

### Task 15: `count_max` and `count_min` — the window machinery

Two registry keys, one evaluator, one params schema (`kinds; levels; count; window`). Windows are
`period` and `week` only; **`day` is not added** — Appendix B routes the owner's own words *"Per-level,
per-unit nightly counts"* to *"§14 SL-03"*, and a per-day cap here would build coverage templates
twice (owner decision K). The week is the **department's**, from `periods[].weeks` in the context
(owner decision O), never recomputed and never a literal. Floors evaluate only on windows fully inside
the evaluable range and report the rest through `coverage()` — the clipped-week asymmetry means a
partial window can only under-count, which is harmless for a cap and a **false positive every time**
for a floor (owner decision L). Fixtures must include a clipped week at a period edge and a person who
joined mid-period.

### Task 16: `target_per_period` and `composition`

Level-keyed targets with the closed modifier grammar of owner decision M, and the WD/WE/HOL mix of
owner decision N. Two facts do most of the work here and both are already decided elsewhere in the
tree: the **vacation-week rule is `AvailabilitySummary`'s verbatim** — any overlap with the week's
**clipped** bounds counts as a whole week, so a Thu–Mon leave is two weeks and a Sun–Thu leave is one
— carried in the context rather than recomputed, or the engine and the rota screen will report
different counts for the same person in the same period; and **`Calendar::dayType()` is three-valued
with holiday deliberately winning over weekend**, so `composition`'s two-bucket `{WD, WE}` parameter
would silently drop every holiday duty. Owner decision N adds an optional `HOL` bucket folded into
`WE` when absent, and **never flattens `dayType()`**.

### Task 17: `max_gap` and `free_day_min`

The two absence-shaped types — violated by something **not** happening, which is why both are
window-located and why both are the hardest to make actionable. `max_gap`'s trailing gap at the
horizon edge is **not** evaluated and is reported through `coverage()` (owner decision I); its clock
stops during leave and before `joinedAt` and does **not** stop for an off-roster rotation, because
MR-04's per-person include/exclude override has no column anywhere and `units.call_target` is
department-level configuration, not a per-person fact (owner decision I). `free_day_min`'s *"fully
free day"* is **no on-duty minute intersecting the date** — materially stronger than *"no duty row
dated D"*, and the fixture that separates them is a 24 h call on the 5th with nothing on the 6th.
Leave counts as free, as a parameter defaulting true.

### Task 18: `rolling_hours_max` and `call_frequency_max`

The two duty-hours density types, and the only two consuming **split-at-midnight** attribution.
`rolling_hours_max`'s averaging is a **proportionally longer window with a proportionally larger
cap** — *"80 h/week averaged over 4 weeks"* is *"≤ 320 h in any 28 consecutive days"*, not *"the mean
of four weekly totals ≤ 80"* and not *"each week ≤ 80"*; totals of 100/100/60/60 pass one reading and
fail another, and that case is in the corpus. `countsHours: false` slots are excluded entirely.
`call_frequency_max` is **density, not spacing** — 1-in-3 averaged over four weeks permits two calls
on consecutive days while a `min_gap` of three days forbids them; both ship and the corpus contains a
case where they disagree. Its window is **rolling and expressed in days** with an explicit unit, never
"4 weeks", so a department changing `weekend_days` cannot silently move a duty-hours rule
(`weekStartIsoDay()` is derived from that list). ~~Its denominator is **calendar days**, following
`App\Support\MissedDays`' own precedent, and owner decision J flags that ACGME's intent is arguably
the availability reading.~~ **SUPERSEDED BY THE ANSWER TO OWNER DECISION J (2026-08-20): the
denominator is ELIGIBLE DAYS.** The sentence above was written before J was answered and was never
updated, so it stated the opposite of the shipped rule for two days — recorded here rather than
silently rewritten, because a task brief contradicting an answered decision is exactly the trap the
answer's own "stated once so it is on the record" paragraph exists to prevent.

### Task 19: `fairness_distribution` and `holiday_equity`

The two cohort-located types. `fairness_distribution` compares each person against a **pro-rated
expected share** computed from `eligibleDays`, not against a raw count — comparing raw counts flags
the person on two weeks' leave as *under*-loaded, and the fix a solver would find is to pile duties
onto the few days they are available, which is the precise opposite of fair. Tolerance is **absolute,
default 1**, because the counts are small integers and a proportional band on a base of 3 rounds to
either "no tolerance" or "one whole duty" (owner decision Q2). Its soft **penalty** is defined as
`Σ max(0, |actual − expected| − tolerance)` — linear, expressible in CP-SAT with abs-value
auxiliaries, computable identically in TypeScript — so P4's *"violation count vs min-max objective"*
reconciliation has one quantity to match rather than two that correlate. `holiday_equity` splits into
in-schedule spread (always evaluable) and the multi-year lookback (evaluable only when
`historyAvailableFrom` covers it); **`priorCredits` is `number | null` and `null` means UNKNOWN, never
zero** (owner decision W) — encoding year-one absence as zero makes everyone maximally deserving, the
lookback half silently does nothing, and in year two it actively mis-schedules. The lookback fetches
holiday resolutions **one year at a time** and builds no day vector for the lookback horizon:
`Calendar::weeksIn()` throws over 550 days and `datesBetween()` is uncapped, so one multi-year range
would throw on the first and loop unboundedly on the second.

### Task 20: `we_pairing` — the predicate half only

Ship the predicate under the reading owner decision Z picks; **`fallbacks` does not ship in P2.** An
ordered list of acceptable alternatives produces no violation when a fallback is used — it produces a
worse-but-acceptable placement, which is WB-04 fitness ordering and AU-02's rank-weighted penalty
terms. That is exactly the split owner decision Q already makes for `eligibility`'s *"auto-fill
order"*, applied consistently. §4.3's open *"shared definition of what 'preference broken' means"* is
resolved as: a violation is raised when the honoured pairing is neither the preferred pairing nor any
listed fallback.

### Task 21: the CG-08 preset registry and its manifest

Decision H, built: `preset:acgme` (five conditions across five type keys, soft at high rank, active,
with its two unimplementable clauses stated in the file), `preset:residency` (structure, numbers
pending), `preset:scfhs` (present, empty, `pending` block). A build-failing test asserts the registry
key set equals the declared manifest set. Presets are **data the engine can be called with**, not seed
data — there is no `conditions` table until P3, and the file says so.

### Task 22: `App\Support\Engine` — the context builder, and its two guards

The single bounded-query loader producing an immutable array, then a pure fold — `RotaGrid` →
`AvailabilitySummary`'s shape, one layer along. It flattens rota spans and vacations into per-date
vectors (findings 4 and 5), builds the day vector once (finding 6), computes `periods[].weeks` through
`Calendar::weeksIn()` (owner decision O), computes `eligibleDays` per person, and carries **no contact
field and no free text for any viewer** (invariant 13). Any new per-date date function it needs —
notably a public ISO weekday — lands on `App\Support\Calendar`, not here (finding 21). Two guards,
both planted:

- **A reader, not a writer** (invariant 5): needles for `create(`, `insert(`, `update(`, `save(`,
  `delete(`, `upsert(`, `firstOrCreate(`, `updateOrCreate(`, `truncate(`, `destroy(`, **plus the two
  known blind spots — `Model::query()->create(` and `$model->update([`** — over
  `app/Support/Engine/*.php` as a glob, so a class added there joins unasked.
- **No rule in the serialiser** (Decision I): the builder must not pre-filter people, dates or slots
  by anything a condition evaluates. Needles for `->where(` combined with an eligibility-shaped
  column, plus a positive assertion that the built context contains **every** active person in the
  horizon including those a condition would exclude — the check that catches "send only eligible
  people", which is `eligibility` re-implemented as a query.

**And it must not contain the four app-wide raw needles** — `off_roster`, `offRoster`, `callEligib`,
`call_eligib` — **anywhere, docblocks included** (invariant 4).

**The query budget is watched breaching first** (invariant 9): grow the fixture — a populated
academic year, splits, mid-year promotions, vacations, a stale row, four clinics, a multi-year holiday
set — until the bound fails, record the number it failed at in the docblock, then bring it under. A
budget that never failed is a budget nobody has measured.

### Task 23: the Node entrypoint and the CI job, named honestly

`packages/engine/bin/evaluate.mjs`: CG-10 JSON on stdin, `violations[]` on stdout, non-zero exit on a
schema failure. Added to CI's `test` job: build the package, run the whole fixture corpus through the
**compiled** entrypoint (not the test runner's transform — a package that only Vitest can load is not
the browser runtime), and assert every case. The `golden.json` mirror assertion and Task 8's catalog
parity are wired as build-failing checks.

**Named honestly, per Decision G:** the calendar half is genuine cross-implementation validation; the
catalog-parity guard is a second *source*, not a second implementation; the conditions half is
single-implementation regression coverage. §4.3's real job arrives in P4. The CI step's own name and
comment say which is which, because the phase table's wording is exactly what a later reader would
take as a commitment already met.

**NF-01 is measured here**, not asserted: the full corpus plus a synthetic 31-day, 20-person, 3-slot
month with **all 22 implemented types active** through `evaluate()`, timed, with the number recorded.
Twenty-two types is the number the budget must survive, not eleven. If it exceeds 100 ms the number is
recorded anyway and the gap is stated — a budget quietly missed is worse than a budget missed out
loud.

### Task 24: the demoable artifact — `php artisan engine:evaluate`

A **local, owner-facing** command: takes a period and a duty-JSON file, builds the real context from
this department's shipped master rota, vacations, clinics, levels and calendar via Task 22, pipes it
through the Node entrypoint, and prints the violations grouped by severity with their CG-04
plain-language explanations — plus `coverage()`'s skipped windows, so the demo shows what could not be
checked as well as what failed.

Three things it is not, each stated in the command's own docblock and asserted:

- **Not a production path.** The production server runtime is P3's `services/engine` (Decision F). The
  command refuses to run when `app()->environment('production')`, so a convenience does not become an
  undocumented dependency on `node` being present in the app container.
- **Not a writer.** It writes nothing and **audits nothing** — there is no clinical or access event
  here to record, and a violation's `explanation` is generated from people's names, so it must never
  approach `audit_log.detail` (invariant 7).
- **Not fed by real data in tests.** Its test fixture is synthetic (invariant 11). The owner may of
  course point it at this department's real rota — that is the demo — but nothing real enters the
  repository.

---

## Definition of done — P2-2

- [x] `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"` → `rc=0`: **1738 passed**, the 1719 baseline
      plus Task 24's nineteen (eight on `EvaluationRequest`, eleven on the command).
- [x] `npm test` **811 passed** (807 plus the two-fixture pair × two assertions),
      `npx tsc --noEmit -p packages/engine` `rc=0`, `npm run build` `rc=0`, `npm run engine:corpus`
      `rc=0` over **92 fixtures**.
- [x] **All 22 implemented type keys** have an evaluator, a preview, a params schema and fixtures whose
      `why` names the shape; each has been observed failing on a planted defect. `forbidden_transition`
      remains registered and unimplemented.
- [x] Every window- and cohort-located type has a **partial-window** fixture and asserts its
      `coverage()` output; no window is silently dropped.
- [x] The three preset bundles ship, the manifest test is green, and **no numeric default is invented
      for any figure §37 still owes**.
- [x] Task 22's query budget **observed breaching**, with the numbers recorded: **13 measured** on a
      populated block, bound **17**, and the two planted regressions ran **223** (a per-span level
      lookup) and **113** (`$assignment->unit` per span). A third planted trap — a narrowed
      `select()` on the person query — is CHEAPER than the correct code and no budget can see it;
      a behavioural assertion on the join date is what caught it.
- [x] NF-01 measured with **all 22 types active** and the number recorded, met or not — **NOT MET**:
      93 duties (20 people × 3 slots × 31 days), 998 findings from 14 conditions, `evaluate()` median
      **~120 ms** on this machine against the 100 ms budget (76/94/104/112/122/123 across six runs;
      the two consecutive quiet-machine runs both read ~122, so 76 was an early outlier). The case is
      deliberately violation-dense and is therefore an upper bound, `evaluate() + coverage()` is
      roughly double because `coverage()` is a second full traversal, and the CI runner's own figure
      is unknown — the harness prints it on every run. See Task 23's amendment.
- [x] **No migration in P2 at all:** `git diff --stat main..HEAD -- database/migrations` is empty.
- [x] `RotaAccessTest`, `ClinicHooksTest` and every single-writer guard unchanged. TWO OTHER guards
      DID change at Task 24 and neither is on that list: `EngineIsAReaderTest` gained the demo command
      by name (a widening, not an exemption), and `RulesLiveOnlyInTheEngineTest` gained ONE per-needle
      allow-list entry — which contradicts acceptance item 7 and is recorded there.
- [x] `docker-compose.production.yml` unchanged; `DeploymentInvariantsTest` untouched and green.
- [x] `php artisan engine:evaluate` demonstrated and the output pasted into the amendments — against a
      POPULATED FIXTURE DEPARTMENT, because this machine holds no real department data. Running it on
      production's real period is the owner's, and the command refuses to run there by design.

---

## Owner decisions needed

Every one carries a recommended default, so nothing blocks on an answer. Silence takes the default.

### The catalog

**A. Does "all 21" include `forbidden_transition`?**
*Default: **NO.** P2 builds 21 catalog rows = **22 type keys**; `forbidden_transition` is registered
`implemented: false` with three citations.* CG-07's own cell marks it `(Stage 5)`, §35 names it in the
Stage 5 deliverable list, and §36 makes *"Shift features before Stage 5"* an explicit non-goal.
Building it is cheap in code — Decision A makes `slot.kind` opaque, so the predicate needs no shift
substrate — but there would be no shift kind to parameterise it with, no preset to seed it, no gate
screen to offer it and no real input to prove it against. **A type whose only fixture is one its
author invented, with no consumer and no policy, is a stub with tests.** Registering it unimplemented
makes the absence a decision.

**B. `count_max` / `count_min` — one registry key or two?**
*Default: **TWO keys**, sharing one evaluator module and one params schema.* CG-01 and §30 store one
`typeKey` per condition row; CG-04's preview differs by direction; a department will enable a cap
without a floor; and Task 8's parity guard derives two keys from CG-07's slash-separated cell.

### The duty shape (Decision A)

**C. Does P2 author `Slot`/`Duty` in its own contract, ahead of SL-01..07?**
*Default: **YES.*** There is no alternative that is not worse: the types cannot be written against
nothing, and letting each one invent a duty shape is twenty-two definitions of one fact. P3 adds a
projector, not a second semantics.

**D. Half-open intervals, and the three attribution readings.**
*Default: intervals are `[start, end)`; the three readings are as tabled in Decision A, declared per
type, and asserted as a matrix.* The abutting split-day/night pair and the Friday-night call are the
two cases that decide it, and both are fixtured.

**E. Weekly-cadence slots (SL-04) — do they carry a real extent?**
*Default: **YES** — `cadence: 'daily'|'weekly'` plus `spanDays`, so `overlap_block`, `min_gap` in
hours and `post_duty_exclusion` have a defined end against a home-call or backup duty.* The
alternative, excluding weekly duties from window-measured types, is defensible but must then be
stated; leaving it undefined is not, and they are exactly what a department names in a `from` set.

**F. The carry-in tail.**
*Default: `priorDuties` and `followingDuties` in the context, read-only; violations emitted only when
their location falls inside `[horizon.from, horizon.to]`; every affected type fixtured at the seam.*
Without it the eleven affected types are systematically wrong at exactly the dates a scheduler hits
first.

### Parameterisation

**G. Ids or stable keys in condition params?**
*Default: **stable keys** — `units.code`, level code, and a person key that is **not** `people.id`.*
`people.id` and `users.id` are independent sequences; `units.code` carries an institution-blind UNIQUE
index by D11's one-way commitment; `RotaExport` already refuses ids for person identity on the stated
ground that *"ids are instance-local"*. This decision lands in five types (`eligibility`,
`dow_restriction`, `same_unit_conflict`, `onboarding_grace`, and every level-keyed map) and must be
made once.

**H. `min_gap` endpoints, and the off-by-one.**
*Default: the parameter carries an explicit `unit`. **`hours` measures END-to-START** (ACGME's *"10 h
between duties"*). **`days` measures the difference between START DATES**, and `N` means **at least N
apart** — 1 Aug → 4 Aug is legal at `N=3`. `post_duty_exclusion` anchors on the END of `a` and tests
`b` by **start-in-window**.* Both `min_gap` semantics ship on one type, which is what CG-07's *"days
or hours"* implies. The off-by-one is a month of different behaviour and is invisible in review, so
CG-04's preview renders a worked example (Task 9).

**I. `max_gap` — the unfinished trailing gap, and what stops the clock.**
*Default: an **unfinished trailing gap is NOT evaluated** and is reported through `coverage()`; the
clock **stops** during leave and before `joinedAt`, and does **NOT** stop for an off-roster rotation.*
Evaluating the trailing gap flags nearly every person nearly every month; ignoring it silently is
worse, which is what `coverage()` is for. Off-roster has no per-person column anywhere (MR-04), and
inventing one from `units.call_target` would be exactly the inference `RotaAccessTest` exists to
refuse.

**J. `call_frequency_max` — window alignment and denominator.**
> **ANSWERED 2026-08-20 — the default was OVERRIDDEN. Denominator = ELIGIBLE DAYS.** Days on leave,
> before `joinedAt`, and off the roster are removed from the denominator. This is the alternative this
> entry itself flagged ("ACGME's intent is arguably the availability denominator"), so the answer
> follows the standard's intent rather than `MissedDays`' precedent. **Window alignment is unchanged:
> rolling, anchored on the evaluated duty's date, length in days.**
>
> **The consequence, stated once so it is on the record rather than discovered:** the constraint
> TIGHTENS as somebody takes leave. A person with 14 days' leave in a 28-day window is measured
> against 14 eligible days, so `one in 4` permits 3 calls rather than 7. That is the intended reading
> — it protects people from being back-loaded around their own leave — and it is the opposite of what
> a reader assuming a calendar denominator would expect, so the plain-language preview (CG-04) must
> say which denominator it used.

*Superseded default: **rolling**, anchored on the evaluated duty's date, with the window length in **days** and
an explicit unit; denominator = **calendar days**.* A calendar-aligned window lets a person run
1-in-2 across a block boundary and pass every window. The calendar denominator follows
`App\Support\MissedDays`' precedent (owner decision 6: every calendar day, `dayType()` deliberately
never consulted) — **but flag it**: ACGME's intent is arguably the availability denominator, and the
owner may want the opposite.

**K. `count_max` / `count_min` — whose count, and which windows?**
*Default: **per PERSON**, with `levels` as a **scope filter** (which people the cap applies to), not a
cohort total. Windows are `period` and `week` only; **`day` is not added**.* Appendix B routes *"Per-
level, per-unit nightly counts"* to §14 SL-03 — coverage templates, P3 — and building a per-day cap
here builds SL-03 twice. Note also that `levels` inside params duplicates CG-01's `scope`; the
default is that **`scope` selects the population and a level-keyed MAP supplies per-level VALUES**,
with a bare `levels` list documented as intersecting `scope` and fixtured.

**L. Partial windows, and unequal period lengths.**
*Default: **floors and targets evaluate only on windows fully inside `[evaluableFrom, evaluableTo]`**
and skipped windows are reported through `coverage()`; **period-windowed numbers are ABSOLUTE**, not
scaled by period length.* `institutions.block_weeks` defaults to twelve four-week blocks and a
**five**-week block 13, so a "6 per period" cap is 25% looser there; the scaling question is real and
the absolute reading is the one a department can state on a gate screen. Owner decision M adds one
modifier predicate that can express the difference where it matters.

**M. `target_per_period` — the modifier grammar.**
> **ANSWERED 2026-08-20 — default CONFIRMED. A matching modifier REPLACES the target**, never
> adjusts it. Ordered list, first match wins.
>
> The reason to keep replace over adjust once implementers start finding `-2` more natural to write:
> a delta grammar lets two modifiers compound to a target below zero silently, and a reader cannot
> see the resulting number without doing the arithmetic themselves. Replace makes the effective
> target readable at both branches, which is also what CG-04's preview has to print.

*Confirmed default follows:*
*Default: an ordered list of `{ when, target }`; **first match wins**; **replace, not adjust**; the
predicate vocabulary is **closed to two**: `vacationWeeksAtLeast: N` and `periodWeeksAtMost: N`; the
person's level is read at the **PERIOD START**.* The spec gives one example and no syntax, and an open
predicate language is CG-09's builder, which is Stage 4 and explicitly *"no free-form scripting"*. The
second predicate exists because block 13 is five weeks and one vacation predicate cannot say so.

**N. The vacation-week rule, and where holidays go in `composition`.**
*Default: the vacation-week rule is **`AvailabilitySummary`'s verbatim** — any overlap with the week's
**clipped** bounds counts as a whole week — carried in the context, never recomputed. `composition`'s
buckets are **`{WD, WE, HOL}` with `HOL` OPTIONAL and folded into `WE` when absent**; `Calendar::
dayType()` is never flattened to two values.* `dayType()` makes holiday win over weekend
deliberately (*"a coverage template that asks for holiday staffing must get it on a holiday that
happens to fall on a Friday"*), so a two-bucket parameter would silently drop every holiday duty —
green on exactly the days it most matters. `composition`'s window is the **period**, stated explicitly
rather than inherited.

**O. Does the mirror implement `weeksIn()`?**
*Default: **NO.** Week windows arrive in the context as `periods[].weeks` with clipped bounds,
computed server-side by `Calendar::weeksIn()`.* `golden.json` has **zero** coverage of
`clipped_starts_on`/`clipped_ends_on` (verified), so a mirror implementation would be an unasserted
second definition of a per-department fact — and the alternative, extending `golden.json` to v3,
modifies a file this plan declares an input. Same device as the day vector and the holiday resolution.

**P. `eligibility` — a violation, or a picker filter?**
*Default: **a Hard violation** on a committed assignment. The "auto-fill order" half is NOT a
condition and does not ship in P2.* CG-07 leaves the type unmarked while WB-04 says pickers *"exclude
hard-ineligible people"*, which makes it Hard in the workbench; ordering produces no violation at all,
so one type would be carrying two contracts. Ordering is WB-04 fitness, P3. *(Note: this is the
committed draft's decision F, renumbered, and it is unchanged.)*

**Q. `fairness_distribution` — base, tolerance and mode.**
> **ANSWERED 2026-08-20 — default OVERRIDDEN. Tolerance is PROPORTIONAL at 10%, WITH A FLOOR OF 1:**
> `tolerance = max(1, ceil(0.1 * proRatedTarget))`. The pro-rated base is unchanged.
>
> **CORRECTED 2026-08-20 — the formula stands, the reason first given for it was wrong.** The
> original note argued bare 10% is stricter than absolute-1 for targets under ten because "0.4
> floors to zero". That describes rounding DOWN; the formula rounds UP. `ceil(0.1 * 4) = 1`, so
> bare 10%-with-ceil already equals the absolute-1 default across the whole small regime and is
> never stricter than it. **The claim was false and is withdrawn.**
>
> **Keep the floor anyway, for the one input where it changes the answer: a pro-rated target of
> ZERO** — somebody on leave for an entire period. `ceil(0.1 * 0) = 0`, so without the floor the
> condition admits no deviation at all for a person who could not have worked. Deleting the floor
> was planted and the suite stayed GREEN, which is why `toleranceFor(0) === 1` is asserted directly
> rather than left to the catalog matrix.
>
> The second reason to keep it is the next reach: `Math.round(0.1 * 4)` is 0, so an implementer who
> swaps `ceil` for `round` as a tidy-up reintroduces the whole small-regime problem the original
> note only imagined. The floor makes that swap harmless.
>
> Behaviour, unchanged either way: an expected share of 4 allows 1; an expected share of 40 allows 4.
>
> CG-04's plain-language preview must state the tolerance it actually applied as a NUMBER, not as
> `10%` — a reader told `10%` on a 4-duty target will predict 0 and be wrong.

*Superseded default follows:*
*Default: **pro-rated expected share** from `eligibleDays` (not raw counts); tolerance **absolute,
default 1**; `mode: 'deviation'` by default with `'spread'` available; `excludeExternal` defaults
**true**.* Raw counts flag the person on leave as under-loaded and a solver's fix is to overload their
few available days. A proportional tolerance on a base of 3 rounds to either nothing or a whole duty.
`spread` (max−min) says nothing about **who** to fix, which is what WB-04's reason chips need.

### The three types the tree cannot resolve

**R. `unwanted_day_block` — where do unwanted days live, and who may see them?**
> **ANSWERED 2026-08-20 — default CONFIRMED. P2 stores nothing.** Unwanted days arrive in the
> evaluation context from the caller; the store and its screen land with the requests feature
> (RQ-01) that owns them. P2 stays an engine rather than a half-built feature, and does not commit
> to a schema the owning feature would then have to change. **The disclosure half of this question
> is therefore deferred with the store** — nothing in P2 can leak a preference it never holds.
*Default: **nowhere in P2**; the days arrive in the engine context. **The store is an approved RQ-01
request** per §22 and §30's `requests/{reqId} { type:'unwanted'|… }`, which §35 places at Stage 3 =
**P4** — not P3, and the plan says so rather than leaving it re-openable.* `people.constraints` is not
a candidate as it stands: `json` nullable, validated only `['nullable','array']`, with one documented
example in a textarea helper. **And the disclosure question is real and is the owner's:** the engine
runs in the browser for WB-03, so one person's unwanted days enter another person's Inertia props; the
nearest analogue today, `people.constraints`, is released only under `people.manage`, and a Scheduler
holds `rota.manage`, which is not that.

**S. `clinic_conflict` — which resolver, which variant, and the three attendee modes?**
*Default: **the post-call variant ON, same-day-overlap OFF** (SPEC §4, frozen); it reads clinic rows
from the context against the date and the person's unit-on-that-date — **design §14 item 22's
formulation, not `ClinicRoster::forDate()`** — **extended to carry `attendee_mode` and its attendee
rows**, without which it is wrong in both directions for `named` and `levels` clinics (finding 17).
The same-day variant, when enabled, is a **calendar-day** overlap, because `clinics.session` is
`string(2)` with no minutes.* Finding 16 records that item 22 and the P1e handoff are reconcilable
rather than contradictory, and Task 1 clarifies the handoff sentence rather than overruling it.

**T. `onboarding_grace` — N, the unit, and a missing join date.**
*Default: a **missing `joined_at` is NO violation**, said out loud in the preview text; the window is
**calendar days**; **day 1 is the join date**; `levels` is a scope filter; `external` people are in
scope.* Finding 18: the column is populated by no seeder, factory or demo path, so the alternative
would block an entire department silently. **N has never been stated by any owner** and the residency
preset ships it absent rather than guessed (Decision H).

**U. `same_unit_conflict` — which of three readings, and do exceptions lift or apply?**
> **ANSWERED 2026-08-20 — reading (a) CONFIRMED by the owner**, so it is no longer an inference and
> the `levels.terminal` precedent no longer applies. Two people **rotating on the same unit** are
> never on call together; the person's unit on the date comes from the master rota, which `RotaGrid`
> already answers, so no new store is needed. `day exceptions` LIFT the ban, per the default below.

*Default, and it was the weakest default in this plan until it was answered: reading **(a)** — two people **rotating on the
same unit** are never on call together — with `day exceptions` being days on which the ban **LIFTS**.*
`SPEC.md:100` is the type's only occurrence in the entire repository; the key name says *same unit*,
the Meaning says *"Pairs never together"* (people?), and the parameters say *"unit pairs"*
(cross-unit?) — (a) and the parameters' reading are **opposite predicates over the same input**, and
the Meaning's reading needs a people-pair store that does not exist. The key name is the only one of
the three signals that is internally unambiguous, and it needs no new store. **This should be routed
to the owner before the type is implemented**, on the standing precedent of Owner Decision A on
`levels.terminal` (2026-08-09), which rejected an inference outright because a wrong marker failed
silently in two directions.

### The bundle, and the rest

**V. The 24 h continuous cap, and the transition allowance.**
*Default: **`consecutive_max` gains `unit: 'days' | 'nights' | 'hours'`**, where `'hours'` measures a
**contiguous duty chain** — duties joined when the gap between them is `<= transitionMinutes` — plus
an explicit `transitionMinutes` parameter. **No new type key.*** CG-08's *"24 h continuous cap"* maps
onto no catalog row as written: `consecutive_max` counts days, `rolling_hours_max` is rolling rather
than contiguous, and the two genuinely differ. `transitionMinutes` lands SPEC Appendix A's *"with
limited transition time"*, which CG-08 drops entirely — and without it the preset either forbids a
legitimate handover overlap or silently permits an unbounded one.

**W. `holiday_equity` — unknown versus zero, and which year.**
> **ANSWERED 2026-08-20 — default OVERRIDDEN. `priorCredits` starts at ZERO for everyone**, not
> `null`/unknown. Year one distributes on that year's own assignments alone.
>
> **LABEL CORRECTED AT TASK 19.** This block shipped saying *"default CONFIRMED"* while the
> default printed beneath it says `null` means **UNKNOWN** — the two are opposite readings of the
> same field, and the entry contradicted itself. The ANSWER was never in doubt (zero); only the
> word was wrong, and an entry a reader can catch out is one they stop trusting. The default
> below is therefore marked SUPERSEDED rather than confirmed.
>
> **The limitation is accepted knowingly and belongs in the preview, not just here:** duty covered on
> paper rotas before this system existed is invisible, so year one spreads evenly on top of a past
> that may not have been. If that becomes a complaint, the fix is an operator-entered prior-credit
> field, which is additive and needs no schema change to the condition.

*Superseded default follows:*
*Default: `priorCredits[person][holiday]` is **`number | null`** with `null` meaning **UNKNOWN**;
`historyAvailableFrom` states how far back history reaches; when it does not cover the requested
lookback the type evaluates the **in-schedule spread only** and reports the years it could not see
through `coverage()`. `yearBasis: 'ruleCalendar'` — a Hijri rule's year is a Hijri year — and the
context carries `{ key, year }` per holiday. Working any part of a multi-day holiday is **one** credit
for that holiday-year.* Encoding year-one absence as zero makes the lookback half silently do nothing
in year one and actively mis-schedule in year two. **Two product facts belong with this decision:**
the first real lookback year is no earlier than one year after P3 ships `assignments` and PU-01 ships
`archives`; and the only way to have a real lookback sooner is an owner-entered seed of who worked Eid
al-Fitr / Eid al-Adha / National Day in preceding years — cheap, needing a screen and a migration, so
**not P2**, but worth proposing now rather than discovering in P5.

**X. How does the engine learn `weekendDays` and `weekStartIsoDay`?**
*Default: **from the context object only** — never a module default, never a literal in the package.*
A bundled default is a second definition of a per-department fact, which is precisely what
`golden.json` and AR-08 exist to prevent, and Task 6's weekday-vocabulary scan over `packages/`
enforces the literal half.

**Y. Does `services/engine` ship as a production container in P2?**
*Default: **NO — P3**, with CG-05's publish gate as its first caller (Decision F).* P2 ships the Node
entrypoint exercised in CI. A container nothing calls can be verified running and cannot be verified
working, which is CLAUDE.md's named failure shape; and a third compose service touches fifteen pinned
deployment invariants for zero callers.

**Z. `we_pairing` — which reading, and does `fallbacks` ship?**
*Default: pairs of **DAYS** — the weekend is covered as a **block** by one person rather than split
between two, which is what gives everyone else a genuinely free weekend — and **`fallbacks` does NOT
ship in P2**.* The competing reading (named pairs of **people**) needs a person-pair store that does
not exist anywhere in the tree, which is the tie-breaker; if the owner prefers it, the store is a P3
migration and the predicate is unchanged in shape. `fallbacks` produces no violation when used, so it
is solver preference, not a condition — the same split as decision P.

**AA. Does the mirror implement Hijri?**
*Default: **NO** (Decision C).* Holidays are resolved server-side into Gregorian dates, and
`holiday_equity`'s multi-year keying is solved by carrying the holiday's own-calendar **year** in the
context (decision W), not by putting ICU in the browser. `Intl.DateTimeFormat` is a forbidden needle
besides. The absence is declared in Task 5's coverage manifest so it reads as a decision.

**AB. CG-08's residency numbers.**
*Default: the residency preset ships as a **structure with named, un-filled numbers** and a manifest
entry recording that the values are pending owner input; the SCFHS preset ships **present, empty, with
a `pending` block**; the ACGME preset ships its numbers **and states the two clauses it cannot
implement**.* D14 and D15 make the prototype's numbers unobtainable. A wrong residency default looks
authoritative on a gate screen, and an absent preset entry and a guessed one are indistinguishable
there — only the first is safe.

---

## Acceptance

**P2 is done when:**

1. `packages/engine` implements **the 22 type keys enumerated at the top of this plan** against the
   CG-10 contract, with `forbidden_transition` registered and deliberately unimplemented; with a
   synthetic fixture corpus in which every case carries a `why`; and with every type observed failing
   on a planted defect.
2. Task 8's catalog-parity guard derives CG-07's key set from `docs/munawib/SPEC.md` and matches the
   registry **in both directions**, observed red in each.
3. The calendar mirror asserts `golden.json` from TypeScript, `App\Support\Calendar` still asserts it
   from PHP, **the file itself is unchanged**, and the coverage manifest names every block as asserted
   or deliberately out of scope — including that no block covers `weeksIn()` and why.
4. Every window- and cohort-located type has a partial-window fixture and asserts `coverage()`; every
   carry-in type has a seam fixture at a period boundary. No window is silently dropped.
5. The date guard covers `resources/js` **and** `packages/`, with an empty allow-list on both, and has
   been observed red on a plant in each.
6. `App\Support\Engine` builds the context at a bound that was **observed breaching** before it was
   met, carries no contact field and no free text for any viewer, is guarded as a reader that
   implements no rule, and contains none of the four app-wide raw needles.
7. No migration, no index, no new environment variable, no compose change, no allow-list entry on any
   existing guard. **NOT MET, in its last clause only, and it could not be** — Task 24's amendment
   records why: item 9's command has to read CG-10's array, that array is literally named
   `violations`, and `RulesLiveOnlyInTheEngineTest` buys `violation` case-insensitively. One
   per-file AND per-needle entry; `severity` was deliberately not bought. Everything else in this
   item holds.
8. The three CG-08 preset bundles ship as package data, with **no invented number** for any figure §37
   still owes.
9. `php artisan engine:evaluate` runs against this department's real period and prints real violations
   with plain-language explanations, and real coverage gaps — the phase's demoable artifact.
10. The design doc, `docs/INVARIANTS.md` and `CLAUDE.md` record the enumeration, the corrected catalog
    count, `forbidden_transition`'s exclusion with its citations, and the two relocations (Decisions F
    and G). `grep -rn "D13-R" docs/superpowers/specs/ docs/munawib/ docs/INVARIANTS.md CLAUDE.md`
    returns nothing.

**What P2 explicitly does NOT accept, and must not be read as accepting:** Munawib Stage 2 (§35),
which needs slots, templates, the gate, the workbench, publishing, the board and the tallies — all
P3. *"P2 is complete"* and *"Stage 2 is accepted"* are different claims and only the first is a
developer's to make (design §14 item 27's distinction, applied one phase along).

**And the one thing all 21 rows does NOT buy:** the duty-hours and equity **numbers**. Every predicate
ships; §37 still owes the SCFHS/local policy in numeric form; §38's second unvalidated assumption is
that it maps onto the catalog. A department enabling `rolling_hours_max` in P3 will find the type
present and the figure absent, which is the correct state and is stated on the preset itself.

---

## Next plan

`docs/superpowers/plans/<date>-p2-2-<slug>.md`, written when P2-1 merges. After P2: **P3 — Munawib
Stage 2**, which brings `slots`, `coverage_templates`, `conditions`, `schedules`, `assignments`, the
gate screen with drag ranking, the workbench with live hints, and `services/engine` with its first
caller. P3 also owes the render-site half of Task 9's CG-04 preview pair (rulings 41/49), and the
gate's cap-versus-floor warning (Decision E). `forbidden_transition` waits for **P7 / Stage 5**, with
the shift slots it constrains.

---

## Recommended additions found during execution

*(Appended as tasks land. Not part of the original plan; each is a proposal with its reason.)*

### From Task 5/6 (2026-08-20) — narrowing the drift residual the mirror cannot close

The mirror's stated residual is that it and `App\Support\Calendar` can drift on a **new** `Calendar`
method added in P3, because `golden.json` only grows when somebody remembers to grow it. The coverage
manifest closes this for fixture *blocks* and cannot close it for the PHP surface.

**The cheap narrowing, using the same device the manifest already proved:** a PHP-side
`CalendarSurfaceIsManifestedTest` that reflects `App\Support\Calendar`'s public static methods and
compares them, **in both directions**, against a classified list of *mirrored* vs *server-side only,
with the reason*. One reflection call and a roughly thirty-name list. A new public method then fails
the build until somebody classifies it.

It does not write the assertion for you — it converts **silent drift into a forced decision**, which
is exactly the property the golden manifest buys on the fixture side. **Recommended for Task 22 or
early P3**, whichever touches `Calendar` first.

*Two smaller findings from the same tasks, recorded so they are not rediscovered:* the manifest is
keyed on top-level fixture blocks, so a new **field inside an existing block** is invisible to it
(the `clipped_starts_on` probe is bought explicitly because that field is the one already identified
as likely); and a TSDoc line containing `lang/*/calendar.php` closes the block comment and is a parse
error.

### From Task 7/8 (2026-08-20) — four places the plan and the tree disagreed, and how each was settled

None is a change of scope; each is recorded because the next reader will otherwise take the plan's
wording as the tree's state.

**1. `contract/schema.json` is `contract/schema.ts`.** Task 7's file list names a `.json` file. The
tree already refuses that and says why: `packages/engine/src/index.ts` records that a JSON import
*"would need `resolveJsonModule` and would resolve differently under the bundler, under plain Node
and under `tsc`, which is three answers to a question worth none."* Reading it from disk instead is
worse — the package ships to the browser, where `node:fs` is fatal. It is a JSON Schema document by
value; only the extension differs, and the deviation is stated in the file's own docblock. **If P3
or P4 needs a `.json` artifact for a non-TypeScript consumer, emit it from Task 23's Node entrypoint
rather than checking a second copy in.**

**2. `RegistryEntry.evaluate` does not return `Violation[]`.** Decision E types it
`(…) => Violation[]`. It cannot: Decision D's `coverage()` needs a per-type producer for the windows
a floor skipped, and two independent functions per type can disagree — a type reporting a window as
skipped in one and firing on it in the other, invisible on a green suite, which is the exact failure
`coverage()` exists to surface one level up. So a type answers ONE call returning
`{ findings, coverage }`, and `evaluate()`/`coverage()` are two projections of it. A `Finding` is
`{ location, explanation }`: severity, rank and `conditionId` are stamped centrally from the
condition row, which makes *"the engine never overrides the row"* structural rather than a rule
twenty-two files each have to remember. **Decision E's field list should be read as amended.**

**3. Task 7 creates `registry.ts`, empty.** It is not in Task 7's file list, but Task 8's own
sentence — *"It fails on the first run because the registry is empty"* — requires the file to exist
first. It lands at Task 7 carrying `RegistryEntry` and `CATALOG = []`, and Task 8 fills it.

**4. `catalogDefault` covers THREE rows, not two.** Task 8's prose names `vacation_block` and
`unwanted_day_block`. CG-07 marks a third — `overlap_block`, *"(Hard, built-in)"* — and Decision E
itself says *"three of twenty-three rows carry a class"*. All three carry `catalogDefault`;
`assertedClass` remains on `overlap_block` alone. **This turned out to be worth more than a
correction:** because the marking is machine-readable in the parameters cell, the parity guard
derives the marked SET AND THE VALUE from `SPEC.md` and compares both against the registry, so the
documentation cannot rot away from the thing it documents. That is a third parity beside the key set
and the `(Stage 5)` marking, and it was free.

*Two smaller findings from the same tasks.* Task 1's footnote under CG-07's table shipped citing §35
and §36 at lines 252 and 256 — their positions **before the footnote's own two dozen lines were
inserted**; corrected to 276 and 280, and the guards now anchor on the table's HEADER and on the
citation TEXT rather than on any line number. And the `timezone` needle for *"read by nothing"* had
to be narrowed from the bare word to the read shapes (`.timezone`, `{ timezone`), because the bare
word matched `calendar/index.ts`'s own docblock sentence declaring that there is no timezone here —
a guard failing on its own explanation. Bracket access is a stated residual: its needle is the quoted
word, which already appears in `schema.ts`'s `required` array, so buying it would cost the first
entry in an allow-list whose emptiness is the point.

### From Task 9 (2026-08-20) — the tolerance floor binds at one input, and it is not the one the answer names

Owner decision Q fixes `tolerance = max(1, ceil(0.1 × proRatedTarget))` and argues the floor from
*"10% of a 4-weekend target is 0.4, which floors to a tolerance of ZERO"*. **That argument describes
rounding DOWN and the formula rounds UP.** `ceil(0.1 × 4)` is already 1, and so is `ceil(0.1 × n)`
for every `n` from 1 to 10 — so with `ceil`, `max(1, …)` changes the answer at exactly one input: a
pro-rated target of **zero**, which is a real one (a person whose eligible days are all leave, or a
quantity with no duties in the schedule at all).

**Found by planting, not by reading.** The floor was deleted and the whole suite stayed GREEN,
including the preview's own worked example at an expected share of 4 — the very number the decision
reasons from. `toleranceFor(0) === 1` is the assertion that catches it, and it is now in
`preview.test.ts` with the plant recorded beside it.

**The floor stays and the formula is implemented verbatim.** It is one character from becoming
load-bearing across the whole under-ten range again (`Math.round(0.1 * 4)` is 0, and `round` is the
more natural reach), so a later author finding it redundant would be deleting a guard whose
redundancy depends on a rounding mode nobody wrote down. `fairness_distribution.ts`'s docblock says
so where that author will be standing.

**A second thing this settles, and it is a scope question rather than a defect.** Decision Q also
requires the preview to *"state the tolerance it actually applied as a NUMBER"*. The applied number
needs the pro-rated target, which needs the SCHEDULE — and `ConditionPreview` receives the condition
and the context and, correctly, not the schedule: CG-04 previews a RULE on the gate screen before any
draft exists, and a preview that moved as a draft was edited would be a different artifact from the
one CG-01 lists beside a drag handle. So the sentence prints the tolerance FUNCTION as two worked
points spanning both regimes — *"an expected share of 4 allows 1, an expected share of 40 allows 4"* —
which removes the mis-prediction the decision names without pretending to a number nothing has
computed. **The applied number belongs in the VIOLATION's explanation, where the target is known, and
Task 19 owes it there.**

*Three smaller findings from the same task.* Task 9's file list names `severity.ts` and `preview.ts`
and no others; the previews needed a params schema to be a preview OF something, and a schema
belongs beside the predicate that will read it, so `src/conditions/{min_gap,rolling_hours_max,
fairness_distribution,target_per_period}.ts` land here carrying `PARAMS_SCHEMA`, `readParams` and
`preview` — and Tasks 14, 16, 18 and 19 add `evaluate` to files that already exist. `messages.ts` is
a fifth file the list does not name, and it exists because `preview.ts` must import the registry to
dispatch while the type modules must import the sentences: one file would be a cycle. And
`ConditionPreview` gained a third parameter, the message table, because AR-07's *"translations are
future work"* is only true if the table is an ARGUMENT — `preview.test.ts` proves it by handing in a
second table and watching the sentence change.

### From Task 10 (2026-08-20) — a pruning optimisation made the phase's defining fixture unfalsifiable

`overlap_block`'s pair scan was written with the obvious optimisation: the duties are sorted by
interval start, so stop scanning once a later duty starts at or after this one's end. The plan's own
plant for Decision A convention 1 — swap `<` for `<=` inside `intersects()` — was then applied, and
**the suite stayed GREEN.** The `>=` in the loop's stop condition had already skipped the abutting
pair before `intersects()` was consulted.

**That is two definitions of the half-open rule, one of them invisible, three lines below the
docblock sentence explaining the first** — `AuditChain::canonical()`'s defect, in the file whose
whole purpose is the one comparison operator. The pruning is removed and its absence is stated in
the module docblock, because it is exactly the change a later reader will propose as an obvious win.
The scan is per person over one month; there is nothing there worth buying with a second copy of the
rule. With it gone the plant fires, and so do four more: `overlap_block` keyed per slot rather than
per person; `priorDuties` dropped; the emission rule disabled; and the vacation bound made exclusive.

**The general lesson, since this is the second green plant in two tasks:** a plant that stays green
is worth more than one that goes red, and the two tasks found different species of it. Task 9's was a
guard asserted at an input where the defect cannot appear; Task 10's was a SECOND implementation
short-circuiting the first. Neither is visible in review, and neither would have been found by
reading the code — only by planting the defect the fixture claims to catch and watching what happens.

*Three smaller findings from the same task.* A docblock is scanned source, for the SEVENTH time in
this phase: `eligibility.ts`'s docblock names `autoFillOrder` while explaining why the ordering half
is absent, so the absence scan failed the build on the documentation of its own rule and now strips
comments (pinned both directions, `SourceScanner`'s discipline). `src/conditions/support.ts` is a
file the plan does not name and the three types forced: `spanKeyAt` is the one definition of *"the
fact this person holds on this date"*, and twenty-two predicates each deciding that for themselves
would disagree only on the dates a promotion or a rotation change falls — which is to say, on the
dates it matters. And a **violation's `explanation` does not go through the message table**:
`ConditionPreview` takes it as an argument (AR-07) and `ConditionEvaluator`, fixed at Task 7, does
not. **Recommended for early P2-2, before nineteen more types hardcode English** — threading the
table through `evaluate()`/`coverage()` is a contract change worth making once, and it is cheaper at
three types than at twenty-two.

### From Task 11 (2026-08-20) — §4.1 was prose, `composition` is affordable, and the needle count is five not two

**The measurement the task asked for, taken.** `app/Support/FakeRules.php` was planted with
`public static function minGap()`, the literals `'min_gap'`, `'severity' => 'hard'` and
`'eligibility'`, and a loop building a `$violations` array — two catalog types implemented in PHP.
`php artisan test` returned **rc=0, 1685 passing**. Design §4.1's *"No PHP implementation of the
rules exists anywhere"* had nothing behind it, and now does.

**Three needle decisions differ from the task's text, each measured over the tree rather than
predicted.**

1. **`composition` IS bought.** The task declines it on the ground that it *"collides with ordinary
   English in docblocks about object composition"*. Over `app/`, `routes/` and `database/` the word
   appears in no docblock at all — and the scan strips docblocks regardless, which is the same
   argument that bought `eligibility`. Zero hits, so it costs nothing.
2. **`severity` IS bought**, though the task's list does not name it. Zero hits, and a PHP rule
   engine grades violations.
3. **`violation` is bought CASE-INSENSITIVELY, at the price of one allow-list entry.** Lowercase
   `violation` measures zero and misses `class Violation`, `$violations` and `ViolationChecker` —
   every form a PHP implementation would actually take, so a case-sensitive needle would be measuring
   zero for the wrong reason. Case-insensitively it hits one file:
   `RosterImport`'s `UniqueConstraintViolationException`, Laravel's own vocabulary in a CSV importer,
   which is not the file a scheduling rule is born in — the test ruling 42 actually sets.

**So the allow-list does NOT start empty, and that is the better outcome.** It carries one entry, per
file **and per needle** — `RosterImport` is exempt from `violation` alone and still scanned for the
other twenty-seven, where a whole-file exemption would have blinded the guard to a `min_gap` landing
in that file later. And because the list has an entry, the staleness twin iterates something: Task
6's finding was that a staleness check over an allow-list empty by design passes on a healthy tree, a
deleted directory and a renamed module alike, and this one does not have that problem.

**Two other departures.** The scope is `app/`, `routes/` and `database/` rather than `app/` alone —
`CalendarIsTheOnlyConverterTest`'s three roots, for its recorded reason (I1: narrow scope is the
recurring weakness in these guards), and all three measured zero so the widening is free. And the
class is **five tests, not the two the task's arithmetic assumes** (`1685 + 2 = 1687`): the guard
itself, the staleness twin, a non-vacuity floor on the file scan, a non-vacuity floor on the CG-07
parse, and the stripper pinned in both directions. **1685 + 5 = 1690.**

**Proved in four directions, each by planting.** The rule in code → red, naming the file and four
needles. The identical text moved entirely inside a docblock → GREEN, which is the stripper proving
it strips. A changed CG-07 table header → the parse floor red rather than the needle set silently
collapsing to five. A stale allow-list entry, a stripper returning the empty string, and the scan
pointed at a missing directory → each twin red.

**A residual worth naming rather than implying:** `resources/js` is not scanned. A rival rule
implementation there would be in the engine's own language, and a needle set of snake_case type keys
is the wrong shape to find one. `@engine` resolving through the Vite alias makes the real engine
reachable; nothing yet makes a rival unreachable.

### ACCEPTED 2026-08-20 — thread `explanation` through the message table, FIRST TASK OF P2-2

`ConditionEvaluator` was fixed at Task 7 without a message table, so every type hardcodes its
violation English inline. **The owner has accepted threading it through, as the first task of P2-2,
before the remaining types are written.**

Why the ordering is the whole point: it is a contract change to `evaluate()`/`coverage()`. Done now
it costs roughly half a task. Done after P2-2 it means unpicking nineteen types that have each
hardcoded a sentence, and by then the shape is set by whichever type happened to be written first.

It also decides whether **AR-07** — *"strings are externalized from launch so a future locale is
translation work, not a rewrite"* — holds for violations the way it already holds for weekday names
(`lang/en/calendar.php`) and Hijri months. Today it does not. `preview` goes through the table for Task 9's four
types (proved by handing in a second table and watching the sentence change) — **but NOT for Task
10's three, which carry English inline. That correction is owed here: an earlier note in this file
said `preview` "already goes through the table" without qualification, and it is only half true.**
So P2-2's first task threads `explanation` through *and* migrates those three previews; both halves
of the same omission, and cheaper together than twice.

**The argument for delaying, considered and rejected:** P2-2's types are where one learns what a good
explanation reads like, so threading a table now fixes the shape before that knowledge exists. It is
rejected because the table is a *lookup*, not a schema — a key and an interpolation map — and
learning better wording later changes the values, not the shape. Hardcoded English is the thing that
would be expensive to revisit.

### From Tasks 12–14 (2026-08-20) — P2-1 complete: what the answers did not cover, and one fixture that could not catch its own parameter

**The eleven placement types are shipped.** Six findings are recorded here because the next reader
will otherwise take the plan's wording, or an owner decision's wording, as the whole of the answer.

**1. Owner decision T is answered, and on the live instance it is indistinguishable from a broken
rule.** `joined_at` is written by no seeder, factory or demo path anywhere in this repository
(Task 1's finding 18) and production holds people without one, so *"a missing join date is NO
violation"* and *"`onboarding_grace` never fired"* produce identical output. The decision is
implemented verbatim and the state is made VISIBLE rather than silent: every person whose join date
is unknown **and who holds a placement the condition would otherwise have judged** is named in
`coverage()` with the count of placements not evaluated, and the CG-04 preview says the same thing in
words on the gate screen. A person with no join date and no duty is deliberately NOT reported — a row
per roster gap would appear on almost every evaluation and train a reader to ignore the field, which
is `carryInLeftEdge()`'s own recorded reason for refusing that noise. Planting `joinedAt ?? today`
turns three assertions red, in both halves (violations and coverage).

**2. One step beyond decision T, taken deliberately: a duty BEFORE the join date is a violation.**
The literal reading of *"the first N days"* is `[joinedAt, joinedAt + N - 1]`, and a rota drafted
before somebody starts — precisely when the rule earns its keep — puts duties outside it on the early
side, where a range test reports nothing at all. The grace therefore opens at the start of time, and
the two shapes carry different explanations because *"day 0"* on a gate screen is a number nobody
would believe.

**3. `clinic_conflict` does NOT need the carry-in tail, and the registry said it did.** Corrected at
Task 13 by measurement: every finding this type produces is located at a DUTY, so one derived from a
tail duty is dropped by the emission rule before anybody sees it — reading the tail changes no output,
and the seam fixture Task 14's corpus guard would have demanded for the claim could have asserted
nothing. What the type reaches past the horizon for is a CLINIC, and clinics are a weekly recurrence
carried in the context for every weekday. **The guard is the reason this was caught**: derive a claim
from the registry in both directions and it stops being a comment.

**4. `consecutive_max` carries TWO duty→date readings, and Decision A's table did not say so.** Owner
decision V's `unit: 'hours'` measures a chain joined by the GAP between two duties, which anchor dates
cannot express, so `DUTY_DATE_READING.consecutive_max` is `['anchor-date', 'occupied-interval']` —
the second entry to carry two after `min_gap`, and for the same reason: a parameter of its own picks.
Decision A's table predates the `hours` unit. Declaring one reading for a type that has two is exactly
the silent divergence that table exists to prevent.

**5. A FIXTURE THAT COULD NOT CATCH ITS OWN PARAMETER, found by planting.** The first version of
`consecutive-max-hours-joins-a-chain-across-a-short-transition` gave its second person a duty that
stayed under the 24 h cap whether or not the chain was joined, so deleting the `transitionMinutes`
comparison entirely left the case GREEN — the parameter owner decision V exists to add was untested by
the case written to test it. The second person now holds the same evening duty as the first, 9.5 hours
after their night ends: joined it is 26 h, unjoined it is two clean stretches. This is Task 9's species
of green plant (an assertion made at an input where the defect cannot appear) in the corpus rather than
in a guard, and it is the third green plant of the phase.

**6. `src/duty/post-duty-window.ts` landed at Task 13, not Task 14.** Its first consumer is
`clinic_conflict`, and a shared definition that arrives after its first caller has been written is
shared in name only. Task 14 added `startsWithin()` to it for `post_duty_exclusion`.

*Smaller things from the same three tasks.* The preview sentences of all seven new types go through
`messages.ts`, because the accepted P2-2 note asserts *"`preview` already goes through the table"* —
which is true of Task 9's four and **not** of Task 10's three (`overlap_block`, `vacation_block` and
`eligibility` carry their English inline). New types follow the table so the residual shrinks rather
than doubles; migrating those three is a five-line job for P2-2's first task, which is already
chartered to thread the table through `explanation`. `preview.test.ts`'s probe generator learned
`$ref` and pattern-constrained strings so that `same_unit_conflict`'s `exceptDates: $ref Ymd` is
varied by the matrix instead of crashing it — one pattern in the table, throwing for any other, so a
second is a decision somebody takes. And the quoted-weekday scan over `packages/` bites on the TEST
that proves a weekday NAME is refused, so the name is assembled from two literals there: the eighth
*"a docblock is scanned source"* of the phase, and the first where the scanned file is the test
asserting the rule the scan enforces.


### ANSWERED 2026-08-20 (round three) — all three confirmed the stated defaults

- **`clinic_conflict` variant — POST-CALL ONLY.** A clinic the morning after a night on call is
  refused; a clinic and a call starting that same evening is permitted. The per-clinic-configurable
  option was offered and declined: it would add a field, a screen control, and a decision per clinic.
- **`onboarding_grace` with an unknown `joined_at` — NO VIOLATION, REPORTED.** Decision T stands, but
  the state is made visible rather than silent, which matters because `joined_at` is written by no
  seeder, factory or demo path and is therefore empty for everybody on the live instance. A silent
  skip would be indistinguishable from the rule working. Task 12 reports it three ways: a `coverage()`
  skip row naming the person and the placement count, exclusion from `evaluatedWindows` (so the reader
  sees "1 evaluated, 1 skipped" rather than "2 evaluated, clean"), and a sentence in the CG-04 preview.
  A person with no join date **and no duty** is deliberately not reported — that is noise.
- **`consecutive_max` does NOT reset at a period boundary.** A run of five nights ending on the last
  day of a block and continuing into the next is a run of five-plus, and the cap sees it. This is what
  the carry-in tail exists for, and a block boundary is an administrative artefact while the fatigue is
  not — a scheduler working block-by-block is precisely who would not otherwise notice.

### DONE 2026-08-20 (P2-2 Task 1) — `explanation` threaded, and the "already goes through the table" note corrected in code

**Both halves of the chartered task shipped.** `ConditionEvaluator` takes a fourth argument,
`ViolationMessages`; the eleven placement types render every `explanation` and every `coverage()`
reason through it; and `overlap_block`, `vacation_block` and `eligibility` render their previews
through it too. The English is byte-identical — the 34-case corpus compares `explanation` verbatim
and was untouched, which is the migration's own regression gate.

**The shape, and the two alternatives it was chosen over.**

- *Keys and an interpolation map on the `Finding`, rendered later.* Refused by CG-10: `Violation` is
  exactly five fields and `explanation` is a `string`, PU-03's publish dialog consumes it unchanged,
  so a deferred render would have widened the one shape this phase exists to keep still. Rendering
  therefore happens inside the type, which is also where `ConditionPreview` already does it — one
  mechanism, not two.
- *One `Messages` interface for both halves.* Refused for a smaller reason but a real one: a preview
  describes a RULE before a draft exists and a violation describes a PLACEMENT in one that does, and
  they are read on different screens. `Vocabulary` is shared; `PreviewMessages` and
  `ViolationMessages` are separate; `EN` implements `Messages`, which is both. A P2-2 type adding a
  violation sentence does not widen the type every preview is written against.

**`messages` is REQUIRED on `ConditionEvaluator` and on `runConditions()`, and defaulted only on
`evaluate()`/`coverage()`.** A default one layer down would let a caller thread a second table into
one projection while the other silently kept English — the two disagreeing about one evaluation is
precisely what a single shared producer exists to prevent.

**Two second definitions died with it.** `support.ts`'s `list()` was a second `conjoin`, and its
`hoursText()` decided a decimal separator outside the table that a locale would have to change.
`min_gap`'s `shortfall()` returned `"1 day"` / `"9 h"` and now returns a NUMBER — which is the
concrete reason the ordering mattered: **owner decision Q's applied tolerance is now reachable.** A
proportional allowance must print the number actually applied, a preview cannot know it (it has the
parameters, not the schedule), and the predicate is handed the table on the same call that measures
it. Task 19 owes the sentence; it no longer owes the plumbing. Decision M's effective target reaches
`targetPerPeriod` the same way and always did.

**Proved in three directions, because one was demonstrably not enough.** The second table is DERIVED
from `EN`'s own keys — every method returning its own name, vocabulary included — so a method added
tomorrow is covered without anybody remembering, and a preview that called `conjoin` and wrapped a
literal around it returns a tag with text around it rather than a tag. `messages.test.ts` asserts the
tag per TYPE over the whole corpus (eleven types, fourteen sentence shapes); `preview.test.ts`'s
equivalent was widened from `min_gap` alone to all fourteen previewable entries; and
`conditions.test.ts` scans `src/conditions/` for an `explanation:`/`reason:` not beginning
`messages.`, comments stripped, planted against a bare literal AND a ternary of two.

*Why the source scan as well.* The behavioural check sees only the sentence shapes the CORPUS
produces, and several types carry a branch no case reaches — `min_gap`'s overlapping pair,
`consecutive_max` under a unit its fixtures do not use. A type routing one branch through the table
and keeping a literal in the other would have been green. **STATED RESIDUAL:** a module calling a
local helper that itself built English still passes; the needle for that would be *"no long string
literal in this directory"*, which matches every parameter's schema `description` and would need nine
allow-list entries — blinding the guard exactly where a real offender is born (ruling 42).

*Why the ternary is in the plant.* Ten of the eleven migrated sites were ternaries of literals, so a
needle anchored on a quote would have matched only the first branch and passed the rest.

**The correction this section owes, made in code rather than in prose.** The accepted note said
`preview` goes through the table for Task 9's four and not Task 10's three. That was right, and the
reason the gap survived review is worth recording: `preview.test.ts`'s parameter MATRIX only asks
that a sentence react to a parameter, and two of those three types have no parameters at all, so
they passed every check in the file while carrying English inline. A property asserted at the one
input where the defect cannot appear is P2-1's recurring green plant, and this was its fifth
instance — caught by widening an existing assertion rather than by reading the modules.

**Nothing else under `packages/` still hardcodes English that a user reads.** What remains is
`Error`/`RangeError` message text — the two dispatcher throws, `NoPreviewForConditionTypeError`,
`personIndex`/`slotIndex`/`dayIndex`'s refusals and the schema validator's — and it stays, stated
rather than implied: a throw is a defect report to a developer about input the engine could not be
given honestly, not a sentence on a gate screen, and routing it through a locale table would put a
translator between a crash and the person debugging it. Schema `description` strings are the one
genuinely borderline set: they are authored documentation of a parameter, they are not rendered by
anything today, and P3's gate screen is where that decision is owed.

**One residual found AFTER the three proofs were green, by surveying the package's string literals
rather than by a guard — and it is the residual named two paragraphs up, with an occupant.**
`target_per_period`'s `clauseFor()` routed both of its predicate clauses through the table and kept
`'the period is any period at all'` and a `' and '` joiner inline. It was green under every check in
`preview.test.ts`, and STRUCTURALLY so: a type may assemble a FRAGMENT and pass it into a table
sentence, and the outer tag swallows the fragment whole — the shouting table returns
`«targetPerPeriod»` whatever `modifiers[].clause` says. Fixed (`anyPeriodClause()`, and the joiner is
now `conjoin`, since a modifier has exactly two possible members and a local `' and '` was a second
definition of one connective), and asserted at the FRAGMENT's own boundary, which is the only place
it is visible: `clauseFor()` is called with a table whose leaves shout and whose `conjoin` is real, so
the composition shows rather than collapsing to one tag. Planted by restoring the inline literal —
red on the new check, green on the whole-catalog one and on the parameter matrix, which is the
measurement that says the new check earns its place rather than duplicating one.

*And the mechanical lesson, paid for once more.* The plant script's `git checkout --` restored
`target_per_period.ts` from HEAD and took an UNCOMMITTED fix with it. The plan's own warning names
this; it costs a re-application every time it is ignored.

### From Tasks 15–17 (2026-08-20) — the first six window-located types, and seven plants that stayed green

**The seam held.** `count_max`/`count_min`, `target_per_period`/`composition` and
`max_gap`/`free_day_min` are six predicates behind an unchanged contract: `Violation` is still five
fields, `evaluate()` still returns `Violation[]`, `Location` is still a three-member union, and
skipped windows still leave through `coverage()`. CG-10's *"new types are additive"* is now
measured rather than asserted. **One thing inside the contract did have to be corrected, and it was
the first floor that found it — see below.**

#### 1. `contributing` carried `minItems: 1`, and a floor's most important violation has none

`Location`'s window member shipped at Task 7 with `contributing: { minItems: 1 }`, on the stated
argument that a duty-hours violation naming no duty is unactionable in the workbench. **That is
right for a CAP and exactly inverted for a FLOOR:** `count_min` fires hardest on the person who
holds NOTHING in the window, and an empty list is that person's whole answer rather than a missing
field. The constraint would have forced the type to suppress precisely the person a floor exists to
find.

The KEY stays mandatory, which is the half Task 7 was protecting — `contributing` **absent** means a
type forgot to say, `contributing: []` means a type said *none* — and `contract.test.ts` now asserts
the two apart rather than together, because one check covering both is what made the difference
invisible until a floor existed to expose it. The union, `Violation`'s five fields and
`evaluate()`'s return type are untouched.

#### 2. Owner decision M's clauses were in the wrong interface, and decision M's own argument says so

Decision M keeps *replace* over *adjust* because *"replace makes the effective target readable at
both branches"*. That was only half true in the tree: the modifier clauses lived in
`PreviewMessages` and `ConditionEvaluator` is handed `ViolationMessages`, so a violation could print
the effective target and had no way to say which branch produced it — a figure with the reason for
it withheld, one screen along from the defect decision M rejects *adjust* to avoid. They are now in
`Vocabulary`, in the strict sense that interface means: a FRAGMENT both halves compose into their
own sentence, like `conjoin` and `hours`. Duplicating them would have been two renderings of one
predicate, disagreeing the moment somebody reworded one.

#### 3. Owner decision L has a per-PERSON half the decision does not state, and it is a decision

The plan requires `count_min`'s corpus to include *"a person who joined mid-period"* and does not
say what happens to them. **Answered as decision L applied one axis along: a floor and a target
evaluate only a WHOLE window, and a window can be partial because of the person as well as because
of the data.** Somebody who joined on the 5th did not have the block that began on the 2nd, and
judging their single duty against an absolute number reports a shortfall they could not have made
up, on their first block. The window is left unjudged and the row NAMES them.

**Leave deliberately does NOT work this way, and the two are one line apart.** A person on leave for
three weeks of a block HAD the whole window and was simply unavailable in it; suppressing or
pro-rating for that is exactly the scaling decision L refuses (*"period-windowed numbers are
ABSOLUTE"*). Both halves are stated in `onRosterThroughout`'s docblock and both are fixtured.

#### 4. Two skip shapes, deliberately different, and the reason is readability rather than tidiness

A window clipped by the evaluable range is named INDIVIDUALLY, because which window it was is the
actionable half and the answer differs per window. A window whose left part no supplied history
reaches is covered by `carryInLeftEdge`'s SINGLE row, because the answer is identical for every one
of them and one row apiece would print one fact until a reader stopped reading them —
`carryInLeftEdge`'s own recorded reason for refusing noise, applied to the shape it already owns.
`evaluatedWindows` falling is the other half of that statement, which is why the pair is read
together.

The distinction underneath is between a window that is SHORTER and a window whose left part is
UNKNOWN. A clipped week at a period edge is a genuinely smaller window and counting over it is a
correct answer to a smaller question; a window whose first four days were never supplied is a wrong
answer to the right one. `historyReaches()` is that line, and it is why a CAP — which owner decision
L lets evaluate a clipped window — still declines the second shape.

#### 5. `max_gap`'s `days` is derived from owner decision H by symmetry, and no document states it

CG-07 gives `min_gap` *"days or hours; value"* and `max_gap` *"days"*, and decision H settles the
first as *"the difference between START DATES, and N means at least N apart"*. The second is the
same word reversed, so `days` measures the same quantity and N means **at most N apart**. Recorded
here because it is an inference rather than a citation — a small one, and the alternative is two
types measuring one quantity two ways with nothing saying which. The preview renders the boundary on
dates, which is decision H's own device for the same hazard.

#### 6. Owner decision I names the trailing gap; its MIRROR IMAGE goes the same way

Decision I makes an unfinished TRAILING gap reported rather than evaluated. The gap BEFORE somebody's
first duty is unfinished for identical reasons and the decision does not mention it — because the
trailing one is the one a scheduler notices. Both are now reported, in one shape, and a person with
no counted duty at all is one open gap over the horizon. The single exception is the shape
`carryInLeftEdge` already reports, where one row speaks for everybody (finding 4).

#### 7. `target_per_period` and `composition` have NO `kinds` parameter, and that is CG-07's doing

Their cells are *"level→target; modifiers"* and *"level→{WD,WE}"* and name none, so every duty in
the period counts. Adding one would invent a parameter no document states; a department wanting a
per-kind target has `count_max`/`count_min`, whose cell does name `kinds`. Stated because the
absence otherwise reads as an oversight beside three neighbours that have it.

#### 8. SEVEN PLANTS STAYED GREEN out of forty-seven, and five of them were one defect

**The count.** Forty-seven mutations were planted across the three tasks; forty went red naming
their own cases. The seven that did not:

1. **`personInScope` deleted from `count_max`/`count_min`** — no count case set CG-01's scope.
2. **Both filters moved from the window's START to its END** — no case held a person whose level
   moved inside a window. A window-located type must CHOOSE that date where a placement-located one
   uses the duty's, and owner decision M fixes the choice at the period start.
3. **`periodWeeksAtMost` answering `true` unconditionally** — the only case exercising it had a
   five-week block against a limit of five, so the predicate was asserted where it MATCHES and
   nowhere where it must not.
4–7. **`personInScope` deleted from `max_gap`, `free_day_min`, `composition` and
   `target_per_period`** — all four at once, one task after the identical probe caught `count_max`.

**Five of the seven are one defect: a scope carried and never asserted.** That is P2-1 review's
thirteen-instance finding, and it reappeared on every window-located type written before the probe
became a habit. **So it is now a standing item rather than something to rediscover: a new type's
first plant is `personInScope` → `true`, before the type's own narrowings.** Each of the five is
closed by a person in the type's own defining fixture who would be flagged on their own figures and
is excluded by the scope alone; two of them need a THIRD person, because owner decision K's sentence
is that a bare `levels` list INTERSECTS the scope rather than replacing it, and an intersection
cannot be pinned with two.

Findings 2 and 3 are Task 9's species — *a property asserted at the one input where the defect
cannot appear* — bringing that count to five instances in the phase.

#### 9. Three smaller things

**The message-table source guard bit on a ternary of two TABLE calls**, at `count_max`/`count_min`'s
shared `explanation:`. It is a true positive by the guard's letter: it cannot tell that shape from
the ternary of two LITERALS it is planted against, and relaxing it to admit the one would admit the
other. The two directions now push at their own sites, which is also where their own comparison
lives. Recorded because the next type with two directions will meet it.

**`preview.test.ts`'s *"an implemented row with no preview yet"* exemplar had to move**, from
`count_max` to `holiday_equity`. A probe pointed at a row that has since been implemented does not
fail — it throws the SCHEMA's refusal instead, a different error class reaching the same `toThrow`.
The exemplar moves as tasks land and the assertion now pins `preview` as undefined first, so the
next move is forced rather than optional.

**`followingDuties` had no corpus case at all until Task 17.** The contract says it is *"usually
empty and never ASSUMED empty"* and nothing asserted the second half;
`max-gap-the-gap-that-begins-in-the-published-month` supplies one, and needs to — it is what closes
the trailing gap so the seam guard's single expected coverage row is not competing with an open-edge
row.

### From Tasks 18–20 (2026-08-20) — the catalog is complete, and owner decision L's line was in the wrong place

**The last five predicates.** `rolling_hours_max`/`call_frequency_max`,
`fairness_distribution`/`holiday_equity` and `we_pairing` ship, so **all 22 implemented registry
entries now carry an evaluator, a preview and a params schema.** `implemented: true` was a
DECLARATION for most of this phase — an entry could say so while carrying no predicate at all — and
`registry.test.ts` now asserts the declaration and the reality as one named set. `Violation` is
still five fields, `evaluate()` still returns `Violation[]`, `Location` is still a three-member
union: **P2-2 added eleven predicates and no shared shape.**

#### 1. Owner decision L's dividing line is NOT cap-versus-floor. It is AUTHORED versus DERIVED limit

The decision lets a **cap** evaluate a window the engine can only see part of, on the stated ground
that *"a count that is too low never exceeds a limit"*. That argument silently assumes the limit is
a number a department wrote down.

`call_frequency_max`'s is not. Owner decision J's answer makes the allowance
`floor(availableDays / n)` — computed from the window's OWN contents — so a partial window shrinks
the allowance alongside the count and false-positives **exactly as a floor does**. One measured
example is in the corpus: the window reaching two days outside the evaluable range shows one call
against an allowance of zero. It therefore calls `wholeWindowVerdict`, and `rolling_hours_max` — a
cap with an authored figure, landed in the same task — does not. Both are asserted on ONE world so
the pair cannot drift into looking like two unrelated choices.

`partialWindowSkip`'s stated reason was widened with it. It shipped saying *"a count that is short
cannot exceed a cap, but it can fall below a floor every time"*, which is FALSE for this type, and a
coverage row a reader can catch out is one they stop reading — `carryInSkip`'s own recorded lesson,
in the sentence beside it.

**Decision J's per-PERSON half goes the other way too.** Decision L suppresses a floor for somebody
who joined mid-window, because an absolute number they could not have reached is a false positive.
This rule's number is not absolute — the days before they joined are already out of their
denominator — so `midWindowJoinSkip` is deliberately NOT called, and suppressing anyway would delete
the rule for every new starter's first window, which is when a department is likeliest to over-call
them.

#### 2. Owner decision W's entry contradicted itself, and the plan said the opposite of the shipped rule twice

Two documentation defects found by implementing against them, both corrected above rather than
silently rewritten:

- **W was labelled *"default CONFIRMED"*** while the default printed beneath it says `null` means
  UNKNOWN — the opposite of the answer's own sentence (*"starts at ZERO for everyone"*). The answer
  was never in doubt; the word was wrong, and the default is now marked superseded.
- **Task 18's own brief still said the denominator is *"calendar days, following `MissedDays`'
  precedent"***, written before J was answered and never updated. It stated the opposite of the
  shipped rule.

The contract carried the same defect in code: `contract/types.ts` and `contract/schema.ts` both
asserted `null` means UNKNOWN. Both now record the answer. **The SHAPE is deliberately unchanged** —
`Record<holidayKey, number | null>` stays, so `App\Support\Engine` may serialise either spelling and
`holiday_equity`'s `carriedCredits()` is the one definition of the reading.

#### 3. `holiday_equity` per-holiday is structurally incapable of firing, and the threshold is a definition

Written per holiday key first, and unfalsifiable: a one-month horizon holds at most one YEAR of any
one holiday, so every person holds nought or one of it and `max − min` can never exceed one. The
rule would have been unable to produce a finding in the very year decision W says it must distribute
in. **The comparison is over the NAMED SET**, which is also what CG-07's cell says — *"spread named
holidays across people & years"*, plural on both axes.

`max − min <= 1` is a DEFINITION rather than an invented number: a credit is indivisible, so the
fairest reachable allocation of `k` credits over `n` people has a spread of at most one, and
anything wider contains a credit that could have gone to somebody holding fewer. CG-07 gives this
row no tolerance parameter and none was invented. **STATED RESIDUAL: availability does not enter** —
somebody on leave across a whole holiday cannot take it, and `fairness_distribution` is the type
that owns pro-rating.

**`lookbackYears` cannot be verified for DEPTH inside the engine.** `priorCredits` arrives already
aggregated, and turning `historyAvailableFrom` into a count of rule-calendar years needs the Hijri
conversion decision AA keeps out of this package. What it can prove is the case where the caller had
nothing to aggregate, and that is reported through `coverage()`.

#### 4. `fairness_distribution`'s `spread` threshold is DERIVED from `deviation`'s

The sum of the two extremes' own tolerances — the widest gap `deviation` would have permitted
between them — so a schedule clean under one mode is clean under the other **by construction**. A
threshold of its own lets one mode of one rule call a draft fair while the other calls it unfair,
with nothing on either screen able to adjudicate. Recorded as an inference; no document states it.

#### 5. `we_pairing`: the parameter shape was chosen by the PROBE GENERATOR, and one branch was dead

A pair is a `{first, second}` OBJECT rather than a two-element array because `preview.test.ts`'s
probe generator builds an array's low probe as a ONE-element list: an inner `minItems: 2` would be
refused by the very schema the matrix is probing, and the matrix would report a crash instead of an
ignored parameter. Worth knowing before the next type reaches for a tuple.

A **SPLIT** is a violation and a **GAP** is not — one day covered and the other held by nobody is a
coverage requirement (SL-03, P3) rather than a pairing preference. Both readings are in the corpus
on one world.

And the emission-rule check inside its scan was **DEAD CODE**: for a two-day pair,
`windowTouchesHorizon` holds for every start in `[from − 1, to]`, which is exactly what
`candidateStarts` returns. It is deleted, the rule is stated once in the bounds that do the work,
and a property in `conditions.test.ts` ties the two together in both directions.

#### 6. FORTY-THREE PLANTS, and the ranking is what is worth keeping

Red, naming their own case: 38. Green: 3, plus 2 more found in the same sweeps and closed with them.
**The standing `personInScope` → `true` first plant went RED on all five types** — the habit works,
and this is the first phase task in which no type shipped with an unasserted scope.

The three that stayed green, and their species:

1. **`spread` never updating `quietest`** — the world had TWO people, so the array head already WAS
   the quietest. Closed by a third person listed FIRST and neither extreme.
2. **`holiday_equity`'s `holidays` filter deleted** — no case carried a holiday in its day vector
   that the rule did not name. *A filter asserted only where it MATCHES*, which is Tasks 15–17's
   finding 3 and Task 9's species, now at seven instances in the phase.
3. **`we_pairing`'s explicit `windowTouchesHorizon` check** — not a corpus gap at all. See item 5.

Two more, closed in the same passes: **`fairness_distribution`'s and `holiday_equity`'s horizon
filters** (no case had a duty, or a holiday day, outside its own horizon — and for the first, a
fixture cannot express one without becoming confusing corpus data, so it is asserted in
`conditions.test.ts` byte-identically rather than by length). And a sixth, at `we_pairing`'s far
edge: **`candidateStarts` stopping one day early**, because no case had a preferred pair beginning
on its LAST horizon date. That case is only the second in the whole package to supply a
`followingDuties` the contract says must never be assumed empty.

**One plant is green BY CONSTRUCTION and is not a hole.** Relaxing `<=` to `<` in either fairness
comparison changes nothing on any input: `1 <= 1 + 1e-9` and `1 < 1 + 1e-9` are both true, so
`COMPARISON_EPSILON` — not the operator — is the control, and the corpus pins the BOUNDARY either
side of it. Recorded on the constant rather than left for the next author to rediscover.

**One process note, paid for once.** The plant runner reverts with `git checkout --`, so an
uncommitted fix made between two sweeps is destroyed and the next sweep reports RED for an import
error rather than for the defect. *"Commit before planting"* is not advice about hygiene; it is what
makes the sweep's own result readable.

#### 7. Two smaller things

**`preview.test.ts`'s *"an implemented row with no preview yet"* exemplar has run out of real rows.**
It moved from `count_max` to `holiday_equity` to `we_pairing` as each was written, and at Task 20
the catalog is complete — so it is a CONSTRUCTED entry now, with a floor asserting that no shipped
row is in that state either. A probe pointed at a row that has since been written passes while
checking nothing.

**A cohort location has no date, so CG-03 is the TYPE's to keep.** `evaluate()`'s emission rule is
unconditionally true for one, so all three cohort-located types filter their own inputs to the
horizon. That is not visible in the union and it cost two green plants to establish.

### From Task 22 (2026-08-20) — the context builder, one duplicate removed from `Calendar`, and a green plant that split the two guards

`App\Support\Engine\ContextBuilder::forHorizon()` is shipped. **Reused rather than restated**, which
was the task's binding constraint: `Person::levelSpansBetween()` for level history,
`Vacation::scopeIntersecting()` for the leave read, `Calendar::weeksIn()` for the clipped week
windows owner decision O keeps server-side, `Calendar::dayFacts()` for the day vector. `RotaGrid`'s
stale-row union is the one structure copied rather than called, and for a sharper reason than the
grid has: a duty naming a person the context does not describe THROWS, so a departed colleague still
holding a rotation must be described or one stale row kills a whole evaluation.

**THE BUDGET: 13, bound 17, watched breaching at 223 and 113.** The populated fixture is thirteen
periods, seventy people, 1170 spans, sixty leave rows, thirty mid-year promotions, four clinics and a
multi-year holiday set. A per-span level lookup ran **223**; `$assignment->unit` per span ran **113**.
Both planted, both red, both reverted. A second measurement — one person versus thirty-one, same
count — had to be taken **cold both times**: `Calendar`'s settings and holiday reads are memoized per
process, so a warm second call is two queries cheaper for a reason that has nothing to do with the
roster, and the first version of that test failed reporting a saving the cache had made.

**1. THE THIRD N+1 TRAP IS CHEAPER THAN THE CORRECT CODE, SO NO BUDGET COULD EVER HAVE FOUND IT.**
The task names a narrowed `select()` on a person query as the one that bites hardest. It bites
differently here: `full_name`/`position`'s read-through-accessor symptom (P0c) is ABSENT, because
this context reads neither attribute. Planted as `select(['id', 'external'])`, the JOIN DATE silently
vanished for everybody — which on the far side of the contract is `onboarding_grace` reporting every
person as unknown and firing on nobody, the exact state owner decision T's coverage row exists to
make visible. The query count went **down**. What caught it was a behavioural assertion on the join
date. A budget is not a defence against a projection.

**2. A GREEN PLANT SPLIT THE TWO GUARDS ALONG A LINE NEITHER WAS DESIGNED FOR.** `where('external',
false)` was planted on the roster query: the source half named the column, and the BEHAVIOURAL half
stayed GREEN — because the stranded-span union re-adds anybody still holding a rotation, and the
fixture's external person holds one. The second shape, a `filter()` over the loaded collection
keeping only people with a rotation, reversed it exactly: source half green, behavioural half red,
naming the one person no union can rescue. **Neither half is redundant and neither is sufficient**,
and reading the two lists would never have shown it. Fourth green plant of the phase.

**3. `Calendar` gained three members and LOST a duplicate that was already in the tree.**
`label()` carried its own copy of *"holiday wins over weekend"*, three methods away from
`dayType()`'s — two definitions of one three-branch decision, unnoticed. `dayFacts()` is now the one
definition and both project it. Added: `holidayOccurrencesOn()`, `dayFacts()`, and the public
`isoWeekday()` finding 21 asked for. This is where finding 21's rule lands in practice — a new
per-date date function belongs on the one converter, never on the engine's namespace.

**4. THE HOLIDAY YEAR IS THE ANCHOR'S, NOT THE QUERIED DATE'S, AND NO DOCUMENT SAYS SO.** Owner
decision W fixes `yearBasis: 'ruleCalendar'` and the contract carries `{ key, year }` per holiday. It
does not say which year a multi-day span's later days carry. A four-day Gregorian rule anchored 30
December covers 2 January: that day belongs to the **2026** occurrence, and `holiday_equity` keying
it to 2027 would split one holiday's credits across two years for the people who worked its tail.
The walk that finds the anchor already computed it and threw it away, so the year is returned from
inside that loop rather than re-derived — a caller re-deriving it would be a second definition
disagreeing only on the spans that cross a year end, which is to say only where it matters.

**5. THE PLAN IS WRONG AGAINST THE TREE IN ONE PLACE, AND THE TREE IS RIGHT.** Finding 4 says
*"flattening spans into a per-date unit vector is the bridge, it must be built exactly once, and it
belongs in the context builder"*. The shipped contract does not have that shape: `Person.unitSpans`
is `Span[]`, and `support.ts`'s `spanKeyAt` — authored at Task 10 as *"the one definition of the fact
this person holds on this date"* — is where the per-date resolution lives. Finding 4 predates the
contract. The builder therefore emits SPANS with their real bounds and flattens nothing, and the
"exactly once" property is honoured on the engine's side rather than this one. Leave is different and
genuinely is flattened here (`leaveDays`, `eligibleDays`), which is finding 5's fourth shape of the
leave predicate — a fourth SHAPE, not a fourth copy: nobody had *"vacation versus a single date"*
because no screen ever needed one.

**6. `Span.to` IS NOT NULLABLE, AND CLIPPING AN OPEN LEVEL SPAN WOULD LIE.** `spanKeyAt` reads a date
outside every span as *"holds nothing"*, which is a real state — a person between rotations. A level
span with no `effective_to` is the COMMON case (that is what `LevelAssignment::assign()` writes), and
clipping it to the horizon would tell the engine that everybody holds no level on the day after the
last horizon date, which is precisely where `clinic_conflict`'s post-duty window looks. It is carried
open at `ContextBuilder::NO_KNOWN_END`.

**7. THERE IS NO STABLE PERSON CODE IN THIS SCHEMA AND P2 MAY NOT ADD ONE.** Owner decision G asks
for *"a person key that is not `people.id`"*, on the ground that ids are instance-local and that
`people.id`/`users.id` are independent sequences. The second hazard is real and is removed
structurally by a prefixed derived key (`p{id}`), which cannot be moved between the two tables by
accident; the first is a non-question for a payload that never leaves the instance, and the only
alternative — a real code column — is a migration P2 is forbidden to ship. Units and levels use their
genuine codes; periods use `academic_year` + `position`, which is the table's own UNIQUE key.

**8. `PersonPresenter` IS DELIBERATELY NOT USED, AND THAT IS NOT A BREACH OF "THE ONLY PATH".** It is
the one path from a person to a SCREEN's props, and its entire output — a name, a short name, a
position — is what an engine context must not acquire. Routing through it would ADD the disclosure
this file exists without. The context names nobody at all, carries no contact field and no free text,
takes no viewer, and is asserted over the SERIALISED payload by key name on the most permissive
institution setting the system can produce. Proved both ways: planting an address read made
`ContactFieldsAreProjectedOnceTest` name the file and both needle spellings, and made the payload
assertion name the key path it had appeared under.

**9. STATED RESIDUAL — `holidays.equity_tracked` HAS NO FIELD IN THE CG-10 `Day.holidays` SHAPE.**
The contract carries `{ key, year }`. So the engine cannot tell a tracked holiday from an untracked
one, and `holiday_equity` will count every holiday the day vector names. The loader carries every
resolved holiday rather than pre-filtering on the flag, because filtering would be the loader taking
that type's decision away silently — which is the defect its own guard exists for. Closing it needs a
contract field and belongs to whoever finishes `holiday_equity`, not to a P2 workaround. Task 1's
finding that the column *"has no consumer anywhere"* remains true after this task.

**10. THE AVAILABILITY DENOMINATOR IMPLEMENTS TWO OF OWNER DECISION J'S THREE CLAUSES.** Leave and
the dates before a join date come out. The third — days somebody is away from the department on a
rotation elsewhere — has no per-person column anywhere (MR-04), and the only thing resembling one is
the presence or absence of a rotation span. Inferring it there would make the rota decide who may
take call, which is what `RotaAccessTest` exists to refuse and what owner decision I already refuses
in those words for `max_gap`'s clock. A person with no rotation counts as available, and the file
says so where the next implementer will be standing.

**11. THE RECOMMENDED `CalendarSurfaceIsManifestedTest` IS BUILT, AND THIS TASK IS WHY IT WAS DUE.**
The Task 5/6 note proposed it *"for Task 22 or early P3, whichever touches `Calendar` first"*. This
task touched it — three new public methods, which is exactly the drift shape the mirror's stated
residual describes. Every public static is now classified MIRRORED or SERVER_SIDE_ONLY with the
reason, compared in both directions so a stale entry fails as loudly as an unclassified method, and
the MIRRORED half carries the name the package exports for it, checked against the package's own
source — without that, *"this one is mirrored"* is a claim nobody verifies and the mirror could drop
`weekOf` tomorrow with the list still asserting it. Planted twice: a new unclassified method → red;
a counterpart renamed to one the package does not export → red. **Nine mirrored names against
twenty-four server-side ones**, which is worth reading as a measurement rather than a gap: it is how
small Decisions B and C succeeded in making the second implementation.

*Two smaller things.* The no-rule guard's column needles had to be a `where`-family REGEX rather than
substrings — `InstitutionProvenanceTest`'s idiom — because the loader legitimately writes an
`external` projection key, and a bare literal would have fired on the file's own correct output. And
the reader guard's needles are VERB-ONLY rather than model-qualified, which is affordable here in a
way it is not in a single-writer guard: those must name a model because they scan the whole
application, this one scans one namespace whose entire job is to read, so the widest needle costs
nothing and reaches a writer of a table nobody has invented yet. Both known blind spots — ruling 66's
`query()->create(` and ruling 50's `->update([` — are needled explicitly anyway, and both were named
by the plant.

### From Task 21 (2026-08-20) — the three presets, and one claim this file made that the plants refuted

**Shipped as Decision H and owner decision AB specify.** `preset:acgme` is five soft, active rows at
one rank; `preset:residency` is structure only, seven types with every parameter awaited and no
number anywhere; `preset:scfhs` is present, empty and pending. `packages/engine/src/presets/` is data
plus one lookup, and `evaluate()`, `Violation`, `Location` and `coverage()` are untouched — **a
preset added configuration and no shape**, which is CG-10's *"new types are additive"* holding for a
bundle as well as for a type.

#### 1. The manifest fails in FOUR directions, and the two-direction claim in this file was too weak

The task asks for two: a preset naming a type the catalog does not have, and a type silently dropped
from a preset claiming it. Two more were free once the first two existed and each was planted: a row
a bundle GREW without declaring, and a declared `state` that is not the state the contents produce.
The last is what makes *"present and empty"* checkable — a bundle whose rows were all deleted
declares `ready` and derives `empty`.

**The direction-two case shipped with a wrong claim in its own docblock, and the plant is what
caught it.** It said deleting a row leaves every other check green. It does not: deleting `min_gap`
from the ACGME bundle reddens THREE cases (the manifest claim, the count, and the row's own figure
case), and retyping the row so the count is unchanged reddens FIVE. **Corrected in place rather than
quietly rewritten**, and what the case actually buys is now stated exactly: it is the only check
anchored on a DECLARATION rather than on the bundle's own contents, so it is the only one that
survives an author who deletes a row and then tidies the tests that named it. A manifest derived
from the presets would fire in neither shape.

#### 2. The five ACGME figures are PARSED out of CG-08's sentence, which cost nothing and buys a lot

`catalog-parity.test.ts`'s device, applied to numbers instead of keys. Planted by editing SPEC.md's
own CG-08 line — 80 → 90, 1-in-3 → 1-in-4, and a transition figure added — and three cases went red
naming their own clause. **`transitionMinutes` is the one number in the bundle that no document in
this repository states**: CG-08 drops the clause entirely, Appendix A names it in words only, so the
preset carries ACGME's published four hours and the ABSENCE at the source is asserted too, because a
figure appearing in CG-08 later would make the bundle's own limitation false and nothing else would
notice.

**A third limitation was found by parameterising rather than by reading**, and it is a property of
how `consecutive_max` measures rather than of the platform: owner decision V's allowance is what
JOINS two duties into one measured stretch, so the gap counts INSIDE the 24 h, where the standard
permits four hours ON TOP of them. The preset over-reports rather than under-reports, which is the
right direction for a soft warning and is one number to change for a department that disagrees. It
sits in `limitations` beside Decision H's two, because a limitation recorded only in a plan is one
the person reading the gate screen never sees.

#### 3. `residency`'s awaited lists are DERIVED from each type's schema, and that decided its contents

`awaiting` is compared against `PARAMS_SCHEMA.required` rather than restated, so a schema gaining a
parameter fails the build instead of leaving the structure reading as complete. Planted by dropping
`unit` from `min_gap`'s list; red, naming the type.

**Which seven types are in it was the real question, since no document enumerates the bundle.** D14
and D15 make the prototype's values unobtainable, and its type LIST is equally unobtainable —
`grep -rn -i '\bnine\b' docs/` still returns twenty hits and none of them is a list. The seven come
from **SPEC Appendix A's requirements line** (*"spacing, monthly caps, weekday/weekend distribution,
vacations, unwanted days, clinic–post-call"* — six phrases, six catalog rows) plus `onboarding_grace`
from Decision H's own sentence about it. Every draft carries the phrase it came from, so the mapping
is checkable rather than trusted. Two of the seven publish EMPTY schemas and await nothing; they stay
drafts because **a preset a department installs half of is a preset that looks finished on a gate
screen** — and `clinic_conflict`'s `variant` is awaited even though this department answered it,
because a preset ships to every customer and one department's answer is not a default for the others.

#### 4. A preset is configuration, not code — asserted twice, and a plant split the two halves

The runtime half is `JSON.parse(JSON.stringify(PRESETS))` deep-equalling `PRESETS`: no function, no
`undefined`, no class instance survives that trip, so Decision H's *"a preset can physically be a
JSON data file"* is a property of the VALUE rather than a claim about a file. The source half
requires `import type` only and no `function`/`=>`, comments stripped.

**Neither is redundant and reading the two lists would never have shown it.** A function PROPERTY
reddens both; an arrow IIFE computing `describes` reddens only the SOURCE half, because the value it
leaves behind is a plain string the round trip is happy with. That is Task 22's finding 2 one package
along. **STATED RESIDUAL, measured:** a value assembled by a METHOD call (`['2026', '08',
'20'].join('-')`) carries neither needle and passes both halves. Left unbought, and the import check
is why it is harmless — no VALUE may be imported into a preset file, so such a computation can only
assemble literals out of literals in the same file.

The ONE deviation from Decision H is the file extension, on `contract/schema.ts`'s own precedent: a
JSON import *"would resolve differently under the bundler, under plain Node and under `tsc`"*, and a
preset has exactly those three consumers.

#### 5. THE BUNDLE IS RUN, because parameters that merely validate are inert

`windowDays: 7` with no averaging validates against `rolling_hours_max`'s schema and asks for a
quarter of what CG-08 says. So the five rows go through `evaluate()` on two generated worlds — one
person on call every day of a 35-day horizon, where all five fire, and a light month where none does.
Planting `active: false` on all five (Decision H's own named hazard) reddens the evaluated case as
well as the declaration; the quiet world was probed at a call every OTHER day and goes red, so it is
near a boundary rather than trivially clean.

#### 6. `eligibleDays` IS A FACT ABOUT THE EVALUABLE RANGE, AND THE QUIET WORLD IS WHAT FOUND IT

Handed availability for the HORIZON alone, `call_frequency_max` reads every window reaching back past
`horizon.from` as *"this person was available on one day of the 28"*, permits `floor(1/3) = 0` calls,
and fires on a schedule that breaches nothing. `ContextBuilder::forHorizon()` builds `days` and
`eligibleDays` over the single range it is given, so **its caller must pass the EVALUABLE range** and
set `horizon.evaluableFrom`/`evaluableTo` to match. Absence of data and unavailability are
indistinguishable in a list of dates and only the caller can tell them apart. **Task 24 is the first
caller and owes this**; it is recorded in `docs/INVARIANTS.md` §Engine rather than only here.

#### 7. Two smaller things

`withoutComments` moved to `test/support/source.ts` rather than being copied into a second suite — a
stripper written twice is two definitions of what counts as a comment, agreeing until the day one is
taught something the other is not. It is not a `.test.ts`, so no runner collects it and importing it
does not re-register another file's cases.

And **rank**: all five ACGME rows sit at rank 1 rather than at 1..5. CG-02's drag rank is the
department's own gesture over its own list, and five distinct ranks would be five importance
judgements no document makes, arriving pre-made on the screen whose whole purpose is to make them.
`comparePrecedence` returns 0 between them, which is the honest answer to *"which of these five
matters more"*.

### From Task 23 (2026-08-20) — the Node entrypoint, what CI actually gained, and forty CSS rules compiled out of documentation

`packages/engine/bin/evaluate.mjs` ships, with `bin/corpus.mjs` as its CI harness, two steps in the
`test` job, and **no new job**: the plan says *"added to CI's `test` job"* and a second job would
re-run checkout, `setup-node` and `npm ci` to save nothing.

**1. THE NAME IS ON THE STEP, AND IT SAYS WHAT THE STEP IS NOT.** `Engine corpus and CLI through the
compiled Node entrypoint (one implementation, not cross-validation)`. The parenthesis is the whole
point of the task's *"named honestly"*: the phase table's original *"the CI cross-validation job"* is
what a later reader takes as a commitment already met, and Task 1 had to rewrite that row for the same
reason. The step's comment names the three things separately — this corpus is NF-08/QA-01 regression
coverage over ONE implementation; `golden.test.ts` under `npm test` is the repository's only genuine
cross-implementation check and needs nothing from this step; §4.3's real job arrives in P4 with the
solver that gives it a second side.

**2. WHAT CI GENUINELY GAINED, ESTABLISHED BEFORE ANYTHING WAS ADDED.** The task's warning was right
to insist: the plan's list is out of date, because `npx tsc --noEmit -p packages/engine` is already a
step and `npm test` already collects `packages/*/test/**/*.test.ts`, so **all 90 fixtures' ANSWERS
were already asserted in CI** and re-asserting them buys nothing. Three things were not covered:

- **Plain Node.** Vitest transpiles per file through Vite and runs in jsdom. Neither it nor
  `tsc --noEmit` answers whether the graph bundles or whether the result runs outside a bundler.
- **The public surface.** Every test in `packages/engine/test` imports by deep path
  (`../src/evaluate`). `smoke.test.ts` and `tests/js/EngineAlias.test.js` read exactly one export
  between them — `version` — so `index.ts` re-exporting `evaluate` at all was asserted by NOTHING,
  and `@engine` resolves to that file. This is the gap P3's workbench would have found while wiring
  live hints.
- **The CLI contract per case** — stdin, stdout, exit code — rather than described.

A fourth candidate was considered and rejected: `npm run build` does not bundle the engine, because
nothing imports `@engine` yet. The standing rule *"`npm run build` must actually bundle it"* is
therefore not true today and is not made true here — adding a lib build to that script would put an
artifact into the production image that nothing in the image calls (owner decision Y), and
`npm run build` is exactly what the Dockerfile's asset stage runs.

**3. THE PROOF THE STEP ADDS COVERAGE IS THE PLANT, NOT THE ARGUMENT.** `export * from './evaluate'`
removed from `src/index.ts`: `tsc --noEmit` **rc=0**, `npm test` **773 passed, rc=0**, the new step
**rc=1 with 95 named failures**. That is the second bullet above, demonstrated rather than asserted.
Four more plants: a `cohort` location made unreportable (13 fixtures red); `EXIT_BAD_REQUEST` flipped
to 0 (five refusal cases red, by name); and the three CSS plants in item 6.

**4. THE HARNESS FAILED ITS OWN FIRST PLANT — BY CRASHING INSTEAD OF REPORTING.** Plant 3 collected
ninety fixture failures and then printed ONE `TypeError` from the benchmark, because the report was
the last statement in the file and a throw skipped it. A harness whose failure tells the reader less
than its success did is not a gate. The run is now inside one try, a throw is one more entry in the
failure list, and the refusal phase's final `JSON.parse` is guarded the same way. **This is only
findable by planting** — the finished tree is green and the defect is invisible in it.

**5. THE ENTRYPOINT HAS NO "VIOLATIONS WERE FOUND" EXIT CODE, AND THAT IS THE LOAD-BEARING ONE.**
0 evaluated, 2 not the contract, 3 the engine refused a type key, 1 a bug. A CLI whose status means
both *"I could not do this"* and *"I did it and the answer was non-empty"* is one every caller has to
special-case, and the caller that forgets treats a malformed context as a clean schedule. 2 and 3 are
separated for the reason `evaluate()` raises two distinguishable errors: *"your JSON is the wrong
shape"* and *"your condition names a type this engine does not implement"* are repaired by different
people. All eight cases are asserted by **exit code AND message** — rulings 41/49's pairing, one layer
along, where a refusal nothing asserts is a control that appears to do nothing.

Node cannot load the sources at all, and both reasons were measured on Node v24.15.0 rather than
assumed: `moduleResolution: bundler` makes `import … from './calendar'` a directory import the ESM
resolver refuses (`ERR_UNSUPPORTED_DIR_IMPORT`), and strip-only mode refuses `evaluate.ts`'s
constructor parameter properties (`ERR_UNSUPPORTED_TYPESCRIPT_SYNTAX`). The first fires first and
would fire on a JavaScript tree. So the bundle is not a convenience; it is the only way this runtime
exists. `dist/` is gitignored, `.dockerignore`d, and rebuilt by CI before every run, so it cannot
drift in the tree and cannot vary with whether the building machine ran a script — which is Task 2's
finding restated.

**6. UNPLANNED AND NOT SMALL: TAILWIND WAS COMPILING PRODUCTION CSS OUT OF DOCUMENTATION, DOCKER
CONFIGS AND TESTS.** Found by diffing the built bundle before and after adding `bin/corpus.mjs`,
while checking the Dockerfile hazard the task named. One new rule appeared — `.block-10`, from a
benchmark period key — and the diff also showed `.block-13` **already present on the clean tree**.
Automatic source detection scans every tracked, non-gitignored file and extracts anything that spells
a utility; it does not care that the file is a supervisord config or a Markdown plan. The shipped
stylesheet was carrying, at minimum:

- `.[program:nginx]`, `.[program:php-fpm]`, `.[program:scheduler]`, `.[no-new-privileges:true]` —
  from `docker/supervisord.conf` and `docker-compose.production.yml`. Four rules whose declarations
  are not CSS.
- `.min-h-[34vh]`, `.rounded-2xl`, `.bg-blue-600`, `.text-gray-600` and the rest of a raw palette
  this project forbids in markup — from a **superseded draft** inside
  `docs/superpowers/specs/2026-07-26-login-redesign-design.md`. The layout that ships uses `34dvh`,
  so the emitted rule was not even the one on screen.
- `.block-3` and `.block-13` from `packages/engine`, and a dozen bare English words — `container`,
  `static`, `shrink`, `blur`, `outline`, `uppercase`, `shadow` — picked out of prose.

Fixed with `@import 'tailwindcss' source(none)` plus `@source "../js"` and `@source "../views"`.
**Not** an exclusion list — the three offenders are in three different directories, so a list has to
guess where the next one is — and **not** a rename, which moves the collision to the next slice
nobody is diffing CSS in. Verified class by class over the whole diff: nothing removed traces to
`resources/js` or `resources/views`, and a `find` for `.blade.php` outside `resources/views` or
`.vue` outside `resources/js` returns nothing. 1719 PHPUnit, 773 Vitest, 24 Playwright green after it.
`TemplateScanningIsNarrowTest` asserts four halves — detection off, both roots declared, each canary
still present in its own file, and no rule from either in the bundle — with **two canaries from two
directories**, since a narrowing that survived for `packages/` alone would otherwise pass. Planted
three ways: `source(none)` removed and rebuilt (2 red), `@source "../js"` removed (2 red), the canary
renamed in `messages.ts` (2 red).

*A note on the comment that documents it:* the first version of that comment named the offending
strings in prose and **re-emitted both rules**, because `resources/css/app.css` was itself being
scanned. Under `source(none)` it no longer is, which is why the shipped comment can describe the
defect at all.

**7. THE DATE GUARD FIRED TWICE, THE SECOND TIME ON THE DOCBLOCK EXPLAINING THE FIRST.**
`CalendarIsTheOnlyConverterTest` refused `bin/corpus.mjs`'s first draft, which walked the calendar
with the UTC epoch helpers behind a docblock arguing that a benchmark harness is different. It is not
different, and the fix was better than the excuse: the synthetic month is now built with the package's
own `addDays`/`datesBetween`/`isoWeekday`, so the benchmark's dates come from the same civil-date
arithmetic the evaluators consume. Then the guard refused the sentence *describing* the removed call,
because the needles are substrings over whole files. **A docblock is scanned source.** Both written
around; the allow-list stays empty in both directions.

**8. NF-01 IS MISSED, AND THE NUMBER IS RECORDED RATHER THAN MASSAGED.** 93 duties (20 people ×
3 slots × 31 days), all 22 implemented types active, 998 findings from 14 conditions. `evaluate()`
median across six runs on this machine: **76, 94, 104, 112, 122, 123 ms** against the 100 ms budget.
The last two are consecutive and taken with nothing else running, so ~120 ms is the stable reading and
**76 ms was an early outlier, not the truth**. A single sample would have been a precision the
measurement does not have, so the harness takes nine after a warm-up and prints median with best and
worst — on the settled runs, best ~107 and worst ~149.

Three things belong with the number rather than after it:

- **The case is deliberately violation-DENSE.** Duties are dealt round-robin, so spacing and cap types
  fire across most of the month and 998 findings are constructed, each with a generated sentence. A
  schedule a department would actually publish produces a handful. This is an upper bound, and
  whether the budget is missed on a REAL month is not answered here.
- **`coverage()` is a second full traversal**, so `evaluate() + coverage()` — what the entrypoint does
  per request — is roughly double at 181–231 ms. If the budget matters at request scale, the second
  traversal is the first place to look, not the evaluators.
- **The CI runner's figure is unknown**; this is one developer machine. The harness prints the number
  on every run, so the first CI log answers it.

Recorded, not fixed: a budget quietly missed is worse than a budget missed out loud, and the fix — one
traversal feeding both projections at the caller, or cheaper finding construction — is a change to
`runConditions()`'s callers that belongs to whoever needs the budget, with a measurement in front of
it rather than behind.

The measurement does not fail the build, per the plan. A step that cannot fail is worse than none, so
it is gated on not being VACUOUS instead: the run must produce findings from more than one condition,
because a benchmark of an engine that resolved nothing measures process startup and reports it as
headroom.

**9. TWO SMALLER THINGS.** `bin/*.mjs` is plain JavaScript and is **not type-checked** —
`packages/engine/tsconfig.json` includes only `src/` and `test/`, and pointing `checkJs` at `bin/`
would fail on the `../dist/engine.mjs` import that does not exist at check time. The compensation is
stronger than types for this file: every fixture and every exit code goes through it in CI. And the
entrypoint matches the engine's two deliberate errors **by `name`, not `instanceof`** — it imports a
bundle, and identity across a module boundary is a promise a second copy of the graph would quietly
break.

### From Task 24 (2026-08-21) — the demo command, the widening closed structurally, and two things the first real run found

`php artisan engine:evaluate <period-key> <document.json>` ships, with
`App\Support\Engine\EvaluationRequest` as the one place CG-10's three arguments are assembled. It is
the first real caller of the context builder and the Node entrypoint together, and it is the last
task of P2.

#### 1. THE WIDENING IS CLOSED STRUCTURALLY, AND THE CASE THAT FIRES IS A PAIR IN THE CORPUS

The task's own warning was the whole design constraint. `ContextBuilder::forHorizon()` builds `days`
and `eligibleDays` over the one range it is handed, so a caller that builds over the PERIOD and then
widens `horizon.evaluableFrom` to reach the tail makes `call_frequency_max` read every window
overlapping that tail as *"available on one day of the twenty-eight"*, permit `floor(1 / 3) = 0`
calls, and fire on a month that breaches nothing.

**Both options the task offers were considered and the second was taken**: the builder answers over
the widened range. Making the widening impossible — pinning `evaluableFrom = from` — would have been
sound and useless, because it discards the carry-in tail the eight window types are written around,
and the demo would then have reported every window as partial on every run.

The fix is not discipline. **The horizon's evaluable bounds are read OFF the day vector that was
built** — `days[0]['date']` and the last entry's — rather than computed alongside it and passed in
parallel. There is no expression in that file able to hand the horizon a date the context does not
describe; a caller widening the horizon has to widen the read that answers it, because they are the
same statement. The range itself is derived rather than configured: the smallest one containing the
period and every date a supplied duty OCCUPIES, `date + spanDays - 1`, so a weekly slot anchored on
a period's last date drags the right edge across the six dates it really covers.

`historyAvailableFrom` is deliberately NOT folded into the range. It is the caller's claim about how
far back it looked; the engine already declines a window the context cannot cover, so a claim
reaching further back buys nothing there, and clamping it would be this file answering a question
nobody asked.

**The proof is a PAIR of corpus fixtures differing in exactly one field** —
`call-frequency-max-availability-for-the-horizon-alone-fires-on-a-clean-month` and
`…-for-the-whole-evaluable-range-is-clean`. Same world, same schedule, same rule, two calls sitting
exactly at the allowance: six honest available days permit two and the month is clean; availability
for the horizon alone reads as two available days, permits zero, and reports a breach. They are the
only cases in that corpus asserting a CALLER's defect rather than a type's, they run under both
runtimes with no PHP involved, and the honest half is green AT the boundary rather than with room to
spare — a case passing comfortably would not show that the denominator is what moved.

`EngineEvaluateCommandTest` performs the same comparison through the REAL compiled engine on the
request the command actually builds, narrowing `eligibleDays` afterwards and watching the identical
world come back with a breach in it.

**PLANTED, and one half stayed green.** The wrong reading — context over the period, horizon widened
afterwards — reddened three PHP cases: the structural one (`evaluableFrom` no longer being the day
vector's first date), *"availability covers the whole evaluable range"*, and the leave case that
depends on it. **`test_the_right_edge_reaches_the_last_date_a_duty_occupies` stayed GREEN**, because
nothing in the base document reaches past the period and the two right-hand bounds happened to
agree. It asserted the widening ARITHMETIC and not the BINDING. Corrected in place: it now also
asserts that the day vector reaches the last date the duty occupies, and both edges are bound.

#### 2. WHAT THE COMMAND PRINTS, AND THE THREE THINGS IT IS NOT

Summary first (period, evaluable range with the number of days and where it came from, context
sizes, schedule sizes, condition counts), then the findings grouped by class with the engine's own
CG-04 sentence on each, then `coverage()` — what was measured and what was left unjudged, printed
even when nothing was flagged and especially then. On the populated fixture department below,
`call_frequency_max` measures ZERO windows and leaves forty-one unjudged, which is the demo's most
useful line: a four-week rule over a two-week block with three days of tail cannot be answered at
all, and without `coverage()` that reads as a clean schedule.

Not a production path (refuses on `app()->environment('production')` — owner decision Y). Not a
writer and it audits nothing (asserted over `audit_log` and over eight tables' row counts).
Fixtures synthetic, permanently. **`EngineIsAReaderTest`'s scan gained this file BY NAME** rather
than the command growing a copy of the writer-needle list — one definition of what a writer looks
like, since a second list is two lists agreeing until one of them is taught a new shape.

Exit codes are the entrypoint's, mirrored: 0 evaluated, 2 not something to evaluate, 3 a type key it
cannot resolve, 1 a bug or `node` would not start — and no *"violations were found"* code, for the
reason Task 23 recorded. The consequence that matters here is the opposite one: **a missing bundle
must never read as a clean schedule.** Every non-zero child exit surfaces `stderr` verbatim, returns
that code, and prints no verdict.

#### 3. THE MISSING BUNDLE IS THE ENTRYPOINT'S SENTENCE, AND THE SKIP HAS A GUARD

`dist/` is gitignored, so absent-bundle is the commonest failure a first-time reader will hit. The
entrypoint already answers it in one sentence naming `npm run build:engine` and exits 2; the command
prints that sentence rather than carrying a second copy of the check — one definition of *"is the
engine built"*, on the side that knows. Verified by hand as well as by the faked case: with `dist/`
absent, `engine:evaluate` returns 2 and prints
`The compiled engine is missing at …\packages\engine\dist\engine.mjs. / Build it first: npm run build:engine`.

The pass-through is asserted with a FAKED child, because the two states this suite can be in cannot
both hold in one run, and the assertion pairs *"the code and the message came through"* with
`doesntExpectOutputToContain('Nothing was flagged')`. The two cases that spawn real `node` SKIP
without the bundle — `CompiledCssIsLightOnlyTest`'s shape for a build artifact this suite does not
produce — and **`test_ci_builds_the_bundle_before_the_php_suite` is what stops that skip becoming
permanent**: it fails if `.github/workflows/ci.yml` ever runs `php artisan test` before
`npm run build:engine`. A test that is not collected is indistinguishable from a passing one, and a
test that always skips is the same thing one step along.

#### 4. ONE ALLOW-LIST ENTRY, AND IT CONTRADICTS ACCEPTANCE ITEM 7

`RulesLiveOnlyInTheEngineTest` buys `violation` case-insensitively. CG-10's array is literally named
`violations`, the entrypoint returns it under that key, and there is no honest spelling of
`$answer['violations']` that avoids the needle — so the command carries **one entry, per file AND
per needle**. **Acceptance item 7 says *"no allow-list entry on any existing guard"*, and it cannot
hold together with Task 24's own requirement to print violations from PHP.** Recorded rather than
worked around.

`severity` was NOT bought, and that is the part worth reading. The report groups by
`Condition.class` off the rows it supplied, which is the same answer by construction — Decision E
makes `evaluate()` stamp severity FROM the row — and it additionally lets the report name the type
key and the rank that a `Violation` deliberately does not carry. So the file is still scanned for
`severity`, for all 23 catalog type keys and for the other three engine needles, which matters
because this is exactly the file where a *"quick PHP pre-check so we do not have to spawn node"*
would be born. Proved by planting `'severity' => 'hard'` and a `min_gap` literal in the command's
code: red on both, naming the file and the needle.

Order inside a group is `evaluate()`'s own — by condition, then by location — and the command does
not re-sort. CG-02's precedence lives in `comparePrecedence()`; a second definition here would be
one that drifts. The rank is printed, not applied.

#### 5. `json_decode($raw, true)` DESTROYS THE ONE THING `Condition.params` NEEDS, AND THE FIRST REAL RUN FOUND IT

`{}` and `[]` decode to the same PHP value and `json_encode` turns both into `[]`. So `"params": {}`
— the correct spelling for `vacation_block`, `overlap_block` and `unwanted_day_block`, which is half
of what a department switches on first — arrived at the entrypoint as an array and was refused by
the contract. **Measured, not predicted:** the first run of this command against a populated
department exited 2 with `conditions[0]/params: expected object, got an array` on two rows.

`EngineEvaluate::withEmptyObjectsKept()` decodes objects as objects and converts them to arrays only
when they hold something; a non-empty map re-encodes as an object anyway, and an empty one keeps the
only evidence PHP has that it was ever one. STATED RESIDUAL: an object whose keys are all decimal
integers becomes a list. No key in the CG-10 contract has that shape — person keys carry owner
decision G's `p` prefix — and the alternative is a per-key allow-list that would silently miss the
next object-valued field somebody adds.

#### 6. NF-01 IS MADE VISIBLE, AND THE LINE SAYS WHAT THE NUMBER IS NOT

Every run prints the round trip. On the fixture department below it is **~350 ms for a 14-duty,
4-person, 5-condition block** — and the printed line states, in the same breath, that this is node
start-up plus JSON both ways plus `evaluate()` AND `coverage()`, that NF-01's 100 ms budget is
`evaluate()` alone, and that Task 23 already measured that budget MISSED at ~120 ms. Printing the
number without those three clauses would read as a much worse miss than the measurement supports.
Nothing here re-measures NF-01 and nothing here papers over it.

#### 7. UNPLANNED: EVERY `$this->line()` IN THIS APPLICATION COLLAPSES RUNS OF SPACES

The first draft of the report was built out of indentation and `→`. Measured on this machine:
`$this->line('  x')` renders with ONE leading space, interior runs of spaces collapse the same way,
`→` is dropped while `—` and `·` survive, and `...` arrives as `..` — while `fwrite(STDOUT, …)` on
the same pipe preserves all of it. It is **pre-existing and application-wide, not this task's**:
`InstanceShow`'s own two-space continuation lines have always rendered with one, and `php artisan
list` (which writes through Symfony's own output rather than Laravel's) is unaffected. Bisected with
three markers in one run — `$this->line()`, `$this->getOutput()->writeln()` and `fwrite` — the first
two collapsed and the third did not.

A report whose hierarchy is built out of leading spaces would therefore have arrived flat, and the
demo would have looked broken for a reason that has nothing to do with the engine. Hierarchy is
carried by blank lines, `[condition-id]` headers and `- ` items; ranges are `from..to`; there is no
character outside ASCII in any line this command composes. The engine's OWN sentences pass through
untouched and render their em dashes correctly. Recorded in `docs/INVARIANTS.md` §Engine so the next
author does not "fix" the alignment without re-measuring. **Not chased further**: the cause is in
the framework or the Windows console layer, it affects every command in this application equally,
and it is not P2's to fix.

#### 8. THE RUN — a populated department, `Block 2`, five condition rows

Against the self-contained fixture department (four people, two blocks, four rotation spans, two
leave rows, two clinics) with a synthetic duty document. **This machine holds no real department
data**, so the acceptance item's *"this department's real period"* is the owner's to run against
production; what is pasted is the same command on a populated instance.

```
engine:evaluate - local demo of packages/engine. Not a production path.

period 2026-2027-02 | 2026-08-15..2026-08-28 | Block 2
evaluable 2026-08-12..2026-08-28 | 17 days | the period plus every date a supplied duty occupies, which is also the range the day vector and availability were built over
context people 4 | slots 1 | clinics 2 | tail history from 2026-08-01
schedule duties in the period 14 | before it 3 | after it 0
conditions rows 5 | active 4 | request 7 KB

HARD - CG-05, these block a publish

[no-call-on-leave] vacation_block - 3 found
- p2 | 2026-08-15 | picu-night - On leave on 2026-08-15.
- p4 | 2026-08-17 | picu-night - On leave on 2026-08-17.
- p4 | 2026-08-21 | picu-night - On leave on 2026-08-21.

[two-days-between-calls] min_gap - 1 found
- p2 | 2026-08-15 | picu-night - 1 day between this duty and "picu-night" on 2026-08-14, counted between the dates they start on; at least 2 are required.

SOFT - CG-06, ranked advice

[not-on-a-day-they-asked-off] unwanted_day_block, rank 2 - 1 found
- p3 | 2026-08-26 | picu-night - 2026-08-26 is registered as an unwanted day.

COVERAGE - what was measured, and what could not be

[no-call-on-leave] vacation_block - 14 measured, 0 left unjudged

[two-days-between-calls] min_gap - 14 measured, 0 left unjudged

[not-on-a-day-they-asked-off] unwanted_day_block - 14 measured, 0 left unjudged

[one-in-three] call_frequency_max - 0 measured, 41 left unjudged
- 2026-07-19..2026-08-15 - The window 2026-07-19 to 2026-08-15 is not wholly inside the evaluable range 2026-08-12 to 2026-08-28, so part of it could not be counted. A count that is short cannot exceed an authored cap, but it can fall below a floor, miss a target, or shrink a limit the window's own contents decide — so this window was left unjudged.
- plus 21 more (--all prints them)

[not-switched-on-yet] rolling_hours_max - 0 measured, 1 left unjudged
- 2026-08-15..2026-08-28 - The condition is inactive (CG-01 on/off), so nothing was evaluated.

engine round trip 353 ms | node start-up + JSON both ways + evaluate() + coverage()
NF-01 budgets 100 ms for evaluate() ALONE and P2 measured it MISSED (~120 ms on a violation-dense month); coverage() is a second full traversal. See docs/INVARIANTS.md.
```

Three things in that output are worth reading rather than skimming. **A Hard rule fired on real
leave** — `p2` and `p4` are booked off in the fixture department's own `vacations` rows, and nothing
in the duty document says so; the context builder is what carried it. **`min_gap` fired across the
seam**, on a duty in the period against one in the carry-in tail, which is the whole reason the tail
is read at all. And **`call_frequency_max` measured nothing**: a 28-day rolling rule cannot be
answered over a 17-day evaluable range, so all forty-one windows are reported through `coverage()`
rather than judged. Under the defect this task closed, those same windows would have been judged
against availability that stopped at the period start.

#### 9. TWO SMALLER THINGS

The command refuses a duty naming a slot the document does not supply, by name, before anything is
spawned. That is document integrity rather than a rule: the engine throws on exactly that input and
the entrypoint reports a throw as a BUG with a stack trace, so refusing here turns an exit code
meaning *"the engine is broken"* into a sentence naming the slot.

And an unknown period key lists the ones the instance actually has. No screen in this platform shows
a period KEY, so the alternative is an operator guessing at the spelling of something they have
never seen.

### From the P2-2 ADVERSARIAL REVIEW (2026-08-21) — the TypeScript half: twenty-four green plants, one species

*(The PHP half of the same review is a separate branch. Nothing below touches `app/`, `tests/` or
`routes/`.)*

**The species, stated once because every finding below is an instance of it:** *a claim asserted
only where it MATCHES, and never where it must not.* It is Task 9's green plant, Tasks 15–17's
finding 3 and Tasks 18–20's second green, and this review found it another twenty-four times in one
package. The recipe is unchanged and is the only thing that finds it: **write the case, plant the
mutation it exists to catch, watch it go red, revert.**

**The measurement.** Twenty-eight mutations were planted as a BATCH before any fix was written —
the method Tasks 15–17 recorded — and **twenty-three stayed green.** Six more were found while
fixing those (four opened by the fixes themselves), and three of the five that went red were
re-probed on a different axis, which turned one rejection back into a real finding. Final state:
**every plant red except one, which is examined and rejected below with its measurement.**

#### 1. THE SAME CLIP, TWO TERMS APART — and closing one said nothing about the other

`fairness_distribution` clips BOTH the numerator and the denominator to the horizon.
`conditions.test.ts` closed the numerator at Task 19 by prepending a tail DUTY. **A tail duty adds
no tail ELIGIBLE DAY**, so `availableDaysIn`'s identical filter was never exercised: deleting it
left 571/571 green and the corpus green.

`ContextBuilder` builds `eligibleDays` over the whole range it is asked for, tail included, while
this rule compares over the horizon alone — so the defect is live on every context with a carry-in
tail, which is all of them. The defining fixture now carries a genuine tail: clipped, the expected
shares are 3.3 and 0.7; unclipped they are 3.1 and 0.9.

**Unlike the numerator's case, a fixture CAN express this one.** Task 19 recorded that a tail duty
"is not something a fixture can express without becoming confusing corpus data". An eligible day in
the already-published week is ordinary carry-in and confuses nothing.

#### 2. WHEN a type reads CG-01's scope, which is one axis from WHETHER it reads it

The standing first plant (`personInScope` → `true`) is habitual now and goes red on every type. It
says nothing about the DATE handed in. Moving `window.from` to `window.to` at **all nine window- and
cohort-located sites** left the suite green.

The reason it survived review is worth keeping: the LEVEL-filter half of the same question is
fixtured on two of those very sites (`count-max-the-level-filter-is-read-at-the-window-start`,
`target-per-period-the-level-is-read-at-the-period-start`), so the sites look covered. They are
covered for `params.levels` and were not for `condition.scope` — two filters, one call site, one
case between them.

**One matrix, and it needed TWO devices.** `bounded` clips every rotation to end on the latest date
the type may read (byte-identical answer) and then to start the day after (no violation at all).
That device was written for the three ROLLING types first **and stayed green on all three**, because
its `readAt` there is `horizon.to` and no fixture carries a violation in a window running past the
horizon — this review's own species reappearing inside the case written to close it. Those three now
clip the rotation to ONE date that is a violating window's start and require every violation to be
located there.

#### 3. Five more filters and boundaries, each closed by the input it was never asked about

- **`periodWindows`' `windowTouchesHorizon`, in BOTH branches.** No corpus case carried a block or a
  week missing the horizon entirely. What goes when it goes is not a wrong violation — the emission
  rule drops a window location that does not touch `[from, to]`, so `evaluate()` is identical either
  way. It is `coverage()` that moves, toward MORE work apparently done: windows measured whose
  results were thrown away. **The edge weeks in the new case RAW-overlap the horizon while their
  clipped bounds do not**, which closes a third plant the first two could not — raw bounds are a
  superset of clipped ones, so no world without a genuinely clipped edge week can tell the spellings
  apart.
- **`composition`'s level at the PERIOD START.** Owner decision M binds it and the sibling rule in
  `target_per_period` is fixtured, which is exactly why it looked covered.
- **`onRosterThroughout`'s inclusive boundary** — a join date ON the window's first day. No case put
  one on a window bound. It bites in the expensive direction: a floor going quiet on somebody who
  genuinely had the window, behind a coverage row that reads like a considered decision.
- **Owner decision N's CLIPPED vacation-week bounds.** The raw pair was green because every block in
  the corpus starts on the department's own week start. That is fixture convenience, not a calendar —
  block 13 is five weeks and a year does not divide evenly.
- **`max_gap`'s trailing open gap at the LAST horizon date, and `measuredGap`'s strictly-between
  filter.** The first was asserted everywhere except at itself; the second only bites when the
  closing duty's own date is a stopped day.

#### 4. A COVERAGE ROW THAT WAS FALSE ABOUT SOMEBODY THE RULE WAS NEVER ABOUT

`max_gap`'s corpus expected *"the gap for p-zaid … has only one end, so it was not measured"*. Both
halves are false: p-zaid's two duties are ten days apart, so the gap has two ends, and the reason it
was not measured is that the scope names PICU and p-zaid rotates on NICU. `exposure()` applies the
scope per duty date, so an excluded person arrives at the loop with the SAME empty list as somebody
who genuinely holds nothing, and the open-gap row was written for the second.

Rulings 41/49 pointing the other way — not a control that appears to do nothing, but one that
appears to have looked at somebody it never considered. `everInScope` is the gate, asked over the
horizon rather than at one date because this type reads the scope per duty date and a mid-month
rotation is real.

**The fix opened two plants of its own, and one fourth person closes both.** With only p-zaid to
distinguish them, reading the scope at a single date and deleting the per-duty filter were both
green: p-zaid is out of scope on every date, so one date is as good as thirty-one, and the per-duty
filter has nothing left to do once the gate has removed them. `p-rotates-off` is on PICU to the 7th
and NICU from the 8th with a duty each side.

#### 5. A THIRD LEFT-EDGE SHAPE THAT WAS DROPPED RATHER THAN REPORTED

`wholeWindowVerdict` answered `{measure: false, skip: null}` for every window reaching back before
the horizon without history behind it, on the ground that `carryInLeftEdge`'s single row speaks for
all of them. That is true of the TWO shapes that function owns — no history at all, and history
beginning at or after `horizon.from` — and **false of a third**: history that reaches back PAST the
horizon but not as far as this window.

There, `carryInLeftEdge` is silent (it saw real history before the 1st) and the verdict was silent
(it believed `carryInLeftEdge` was speaking). The window was measured by nobody and reported by
nobody; `evaluatedWindows` simply fell. **That is the state `coverage()` exists to prevent**, one
branch from the state already reported correctly. Block 13 opening 26 July, a horizon opening 1
August, history from 28 July is the whole of it.

`historyShortOfWindowSkip` names the window individually, for the reason the clipped shape is named
individually: which window went, and how much further back the history must reach, are the window's
own answer.

#### 6. `composition` THREW ON A CONTEXT THAT IS EXACTLY WHAT THE CONTRACT PROMISES

`Day` is documented as *"one date of the horizon"*. This type's window is the PERIOD, which
routinely opens before the horizon — the corpus has a seam case for precisely that — and every duty
in the window went through `dayIndex().get()`, which THROWS on an undescribed date. So the
commonest shape this type meets produced a `RangeError`. The corpus never saw it because its own
seam case supplies day rows across the whole tail, which is generous rather than required.

There is no honest local answer (`dayType` is never re-derived — AR-08, holiday beats weekend
deliberately), so the window goes to `coverage()`: the device `clinic_conflict` already uses for the
one question that legitimately reaches past the vector, and `find()` versus `get()` is the line
between them. **Per person, because the buckets are** — a colleague every one of whose duties the
vector describes still gets judged.

#### 7. THE NF-01 BENCHMARK WAS PARTLY MEASURING A DEGENERATE INPUT, AND IT IS NOW MET

**The finding.** Every synthetic person carried `eligibleDays: []`.
`fairness_distribution` divides by the cohort's total available days, so a zero denominator is its
early exit: it reported `evaluatedWindows: 0`, did no work, and was timed as free — for the whole of
P2. `call_frequency_max`'s allowance is `floor(availableDays / n)` (owner decision J), so zero
available days permits zero calls and **706 of the 998 headline violations were that artefact**. The
honest figure is **299 violations from 14 conditions**.

**A SECOND vacuity gate catches the shape the first could not.** The existing one asks whether the
world produced findings, which twenty-one neighbours satisfied on fairness's behalf. The new one
asks whether every type WORKED and fails on any active condition reporting `evaluatedWindows: 0`.
Planted by restoring the empty list: red, naming `nf01-fairness_distribution`.

**And the number itself: 121 ms MISSED → 56 ms MET.** Two causes, both reuse rather than pruning —
the distinction Task 10's defect turns on.

- `orderedDutiesFor` scans all three streams and SORTS, and `rolling_hours_max`, `free_day_min` and
  `call_frequency_max` each called it inside `for window { for person { … } }`. `orderedByPerson`
  memoizes per evaluation, **lazily**, so the set of resolutions is unchanged — an eager version
  would throw on the unsupplied slot of a person the scope was about to exclude, which is a
  different answer for the same input.
- `onDutyMinutesOn` re-ran `dutyInterval`, and therefore `assertSlot`, on every one of ~41k calls,
  discarding the interval `PositionedDuty` already carries. `minutesOfIntervalOn` is the one
  definition now and `onDutyMinutesOn` delegates to it.

Warm per-type medians: `rolling_hours_max` 34.6 → 7.1 ms, `free_day_min` 13.6 → 9.4,
`call_frequency_max` 6.4 → 0.9. **The corpus is byte-identical throughout, which is what says the
hoists changed no answer.**

#### 8. Six smaller ones, and the two that a first probe wrongly rejected

Closed: `carryInLeftEdge`'s empty-window guard (a horizon with no tail otherwise reports a row
running from the 1st back to the 31st — asserted as a property over the whole corpus AND on the
no-tail world, since the corpus cannot reach that state); `holiday_equity`'s credit key carrying the
YEAR (a month-long horizon cannot hold two occurrences of one holiday, so only the multi-day half
was fixtured); `we_pairing`'s holder de-duplication (a duplicated duty row otherwise reports a
weekend split between a person and themselves); a GAP not being a SPLIT in EITHER direction;
`target_per_period` calling owner decision L's per-person half (`count_min` had the fixture, two
types shared one case); and `rosterFor` resolving strangers in all THREE streams, where only the
middle one was asserted although the tail is where a departed colleague is likeliest to appear.

**Two rejections were re-probed on a different axis and one came back.**
`holiday_equity`'s *"never reached"* check answering `false` or `true` unconditionally is caught by
the corpus; **which days it looks at is not.** Pointing it at the unfiltered day vector stayed green,
and that shape fails silently in both halves at once: credits are counted over the horizon alone, so
a holiday in the tail credits nobody, and an unfiltered check would also conclude it was reached and
print no row. The rule would do nothing and say nothing. **A finding rejected on one probe is not a
finding rejected.**

#### 9. THE ONE PLANT KEPT GREEN, WITH ITS MEASUREMENT

`we_pairing`'s slot union. Narrowing `slotKeys` from both days to the first alone stays green, and
that is a true observation with a false conclusion: the scan carries a symmetric PAIR — the union,
and the `first.length === 0` half of the gap guard — and either alone can go while both together
change nothing. **Dropping each guard half goes RED.** Deleting the union would move the dead branch
rather than remove one.

It is kept because the answer must not depend on which of a pair's two days the enumeration starts
from, and the symmetry is now asserted on both sides. Recorded here so the next reader does not
re-derive the deletion.

#### 10. Gates

`npm test` **811 → 843**; `npx tsc --noEmit -p packages/engine` green; `npm run build` green;
`npm run engine:corpus` green, **92 fixtures**, NF-01 **MET**; `php artisan test` **1738**,
unchanged — no file under `app/`, `tests/` or `routes/` was touched.
