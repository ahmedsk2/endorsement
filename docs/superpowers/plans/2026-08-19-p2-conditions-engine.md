# P2 — The conditions engine

**Written 2026-08-19, against `main` at `0733665`.** Production is live and current; P0a–P1e are
shipped. Baseline this plan starts from and must not regress:

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
implementing the CG-10 contract `(schedule, config, conditions) → violations[]`, with **nine** of
CG-07's condition types, a calendar mirror validated against the corpus P1a and P1e built for it,
the severity/rank model, CG-04's plain-language previews, and a PHP-side context builder that
serialises the shipped master rota, vacations, clinics, levels and calendar into the engine's input
shape.

**It is not:**

- **Not the conditions gate screen.** CG-01/CG-02's drag-ranked gate, the `conditions` table, and
  everything that stores a condition are **P3** — the phase table places them there and this plan
  does not move them. P2 ships **no migration and no screen.**
- **Not slots, coverage templates, schedules or assignments.** All four are unbuilt (design §6.3)
  and all four are P3. P2 defines the *shape* the engine reads them in; it creates none of them.
- **Not the workbench.** WB-01..WB-08 is P3. P2 produces `violations[]`; nothing renders them yet.
- **Not the solver.** `services/solver`, §4.2's evaluation mode and §4.3's real cross-validation job
  are **P4**. See Decision F — the phase table's "the CI cross-validation job" cannot mean §4.3's
  job in P2, because the thing it would validate against does not exist until P4.
- **Not a production container.** `services/engine` waits for its first caller (Decision E).
- **Not duty-hour or fairness semantics.** Deferred deliberately and by name (Decision A).
- **Not a second implementation of anything except the calendar** — and that one is deliberate,
  already anticipated, and already has its contract sitting in the tree (Decision C).

---

## The premise this plan had to correct first

A scoping brief for this phase asserted that the design doc's phase table *"cites D13 while stating
the opposite of what D13 decided"*, and quoted D13 as reading *"Nine of the 21 condition types are
enough to schedule a real month…"*.

**That is inverted, and this plan must not repeat it.** Verified verbatim against the tree:

- `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md:36` —
  `| D13 | Condition catalog scope? | **All 21 types in P2.** Objection logged in §1.3. |`
- Line 67, the heading: `### 1.3 Objections logged, per Munawib §A8`. Lines 69–70, its preamble:
  *"Both were raised, both were reaffirmed by the owner, and both are being implemented as decided.
  Recorded so the reasoning survives, not to re-litigate."*
- Lines 77–82, **inside that section**, are the nine-type paragraph. It is the **objection to D13**,
  which the owner overruled — not D13's decision.
- Line 1052, the phase table: *"`packages/engine` with **all 21 CG-07 types** (D13)…"* — which
  **agrees** with line 36. The citation was correct.
- Line 1468, the risk register: *"D13 makes P2 long and undemoable | Accepted by the owner (§1.3).
  Mitigate by ordering the 21 types so the prototype's proven nine land first and are demoable,
  **even though all 21 ship before P3**."* The only sanctioned "nine" in the tree is an **ordering
  inside a 21-type P2**, and the same sentence forecloses reading it as a scope cut.

A plan that builds nine types **and cites D13 for it** would be the tenth time this document was
cited for the opposite of what it says. This plan builds nine anyway — the owner has directed nine —
and records it as what it actually is:

> **D13-R (2026-08-19): D13 is REVERSED. P2 ships nine named condition types; the remaining
> thirteen ship in P3 (five) and P5 / Stage 5 (eight).** The §1.3 objection is upheld. This is a new
> owner decision overturning a reaffirmed one, in the same shape as D3's reversal on 2026-08-08, and
> it belongs in §1.1's decision table with a dated strike-through. It is **not** an application of
> D13 and nothing in this plan will describe it as one.

Task 1 makes that edit. Everything after Task 1 rests on it.

### Three further defects in the number itself

**"21" matches nothing in the catalog.** CG-07's table (`docs/munawib/SPEC.md` §15) has **22 data
rows** carrying **23 distinct type keys** — 21 rows with one key each, plus `count_max / count_min`,
one row with two keys. The only arithmetic reaching 21 is *22 rows minus `forbidden_transition`*,
which the table itself marks `(Stage 5)` — a reading no document states. `21` appears at design doc
lines 36, 77, 1052 and 1468 and is wrong in all four.

**Therefore "the other twelve" is also wrong.** 22 − 9 = **thirteen** rows wait, not twelve; the
twelve inherits the bad 21. This plan never states a count without the list behind it: the nine are
named by type key in Decision A and the thirteen are named beside them.

**Nothing in the tree enumerates "the prototype's proven nine".** The phrase occurs once, at line
1468, and expands to nothing — `grep -rn -i '\bnine\b' docs/` returns twenty hits and every other
one is unrelated (nine migration columns, the nine-step setup checklist, nine `SET NULL` keys). The
prototype is not in this tree and D14 makes it *"idea curation only — not a code ancestor, not a
data source"*. **Decision A's nine is therefore a proposal built from tree-verifiable anchors, and
it is labelled as one at every point.** It is not a citation, because there is nothing to cite.

---

## Binding requirement IDs

From `docs/munawib/SPEC.md` unless noted.

**Built in P2:**

| ID | What P2 owes it |
|---|---|
| **CG-04** | Plain-language preview text auto-generated from parameters — for the nine, in the engine, with no renderer yet (P3 renders it). |
| **CG-05** | The **Hard** class: highest hint severity; the engine marks it. P2 does **not** build the publish block (P3). |
| **CG-06** | The **soft** class: rank-graded violations. P2 builds the rank model; the ignored-warnings ledger is P3. |
| **CG-07** | Nine of the twenty-two rows (Decision A). |
| **CG-10** | The stable contract: pure function `(schedule, config, conditions) → violations[{conditionId, severity, rank?, location, explanation}]`; new types additive. |
| **AR-03** | One pure TS conditions engine, cross-validated by a shared golden fixture suite in CI. P2 delivers the engine and the *calendar* half of the cross-validation (Decision F). |
| **AR-08** | `App\Support\Calendar` is the one converter — and the mirror is the one deliberate exception, contracted by `golden.json` (Decision C). |
| **UX-05** | Hints never block on network: the engine must run client-side on data the page already holds. |
| **NF-01** | < 100 ms p95 laptop, < 250 ms mid-phone — a **budget on the engine**, measured in P2 against a synthetic full month. |
| **D4** | One TypeScript engine, two runtimes. P2 ships the package and the browser bundle path; the server runtime waits (Decision E). |
| **D13-R** | Nine types, recorded as a reversal (Task 1). |

**Named so P2 does not preclude them, and built by nobody here:**

| ID | Why it constrains P2 |
|---|---|
| **CG-01 / CG-02 / CG-03** | The gate stores `type_key`, `params`, `scope`, `class`, `rank`, `active`, `source`. P2's condition object must be exactly that row, minus the row. CG-03's *"never retroactive on published schedules"* means the engine is always called with a **condition set as an argument** and never reads an ambient one. |
| **SL-01 / SL-02 / SL-03** | Slot kind, time window (may cross midnight), `counts_hours`, `tally_key`; *"post-duty semantics follow slot windows automatically"*; coverage templates carry per-slot min/target. P2's duty shape must carry enough of SL-01 for `min_gap`/`post_duty_exclusion` to be real, and must **not** absorb SL-03 (owner decision D). |
| **WB-03 / WB-04 / WB-05** | Live hints on a *prospective* placement; pickers exclude hard-ineligible; trackers show current vs target. The engine must therefore evaluate a **hypothetical** duty cheaply, not only a committed month. |
| **AU-02** | The solver's JSON contract: `request {periodSkeleton, roster, slots, templates, conditions, constraints, fixedAssignments, seed, timeLimitSec}`. P2's context must be **serialisable into** that request without a translation layer nobody planned. |
| **AU-05** | Client-side greedy fallback — needs `evaluate()` cheap enough to call in a loop. The NF-01 budget again. |
| **PU-03** | Publish dialog summarises outstanding warnings; consumes `violations[]` unchanged. |
| **§4.2 / §4.3** | The solver's reified evaluation mode and the real cross-validation job. **P4.** |

---

## Inherited invariants — stated as things a task must not break

Each was verified against the tree while writing this plan. A guard tells you *that* you are wrong,
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
   loophole, not a permission. Task 5 extends the scan to `packages/` with the allow-list still empty
   in both directions, and proves it by planting.
3. **A docblock is scanned source.** `CalendarIsTheOnlyConverterTest` matches comments on purpose
   (*"a file explaining the array it is about to build is not a file this guard should let past"*).
   `RotaAccessTest` and `ClinicHooksTest` strip comments first, via the single definition
   `Tests\Support\SourceScanner::withoutComments()`, and each pins the stripper in **both**
   directions against a real file — a stripper that over-reached would return code-free source, every
   needle would miss, and the guard would be silently vacuous while looking identical to a clean
   tree. Any guard this plan adds picks one discipline and says which.
4. **An identifier that travels between files is scanned source, and two live scans will see P2's
   type keys.**
   - `RotaAccessTest::test_nothing_in_the_rota_namespace_infers_on_call_eligibility` needles
     `off_roster`, `offRoster`, `callEligib`, `call_eligib`, **`eligib`**, `on_call`, `onCall`,
     `callRoster`, case-insensitively, over `glob(app_path('Support/Rota/*.php'))` — **a glob, so a
     class added there joins the scan unasked** — plus the rota controllers, form requests and
     screens, with a `assertGreaterThanOrEqual(15, …)` non-vacuity floor.
   - `ClinicHooksTest` needles `post_call`, `postcall`, **`condition`**, `severity`, `violation`,
     `hard_block`, `soft_block`, `rank_order` over `glob(app_path('Support/Clinics/*.php'))` plus
     the clinic controllers, requests, models and both Vue screens.

   **Consequence, binding on Task 12:** the PHP context builder is `App\Support\Engine\*`. It may not
   live in `App\Support\Rota` (the `eligibility` type key fires `eligib`) and it may not live in
   `App\Support\Clinics` (`clinic_conflict`'s reader would fire `condition`). **P2 adds ZERO
   allow-list entries to either guard**, and that is an acceptance criterion, not a hope.
5. **One writer per table, guarded at source level: whole match set, `assertSame([], $offenders)`,
   allow-list plus staleness twin, proved on a plant.** P2 writes no table at all, so every existing
   single-writer guard must stay green **with no new allow-list entry**. `Model::query()->create(`
   is the sixth writer shape (ruling 66, found by planting exactly that file and watching the guard
   stay green) and `$model->update([...])` is this codebase's house idiom (ruling 50, measured green
   against a plant rewriting six columns across two guarded tables) — **both are known blind spots
   and both must be needled** in any guard this plan adds. They are also precisely the shapes a
   PHP-side "just cache the context" convenience would take. Task 12's guard asserts the context
   builder is a **reader**.
6. **`institution_id` is provenance and in-instance grouping, NEVER a query filter (D11).**
   `InstitutionProvenanceTest::test_no_query_filters_on_institution_id` is live. The context builder
   must not filter on it and must not "scope the engine to the institution" — the database is the
   boundary. **An index led by `institution_id` is a recurring mistake**, not a one-off (P1a Task 4's
   `periods` unique index, P1a Task 7's `holidays` index, both caught only empirically). P2 adds
   **no index at all**, which is the cheapest way to not make it a third time.
7. **No PHI and no names in `audit_log.detail`.** The column is `detail`, **singular**, `text`
   nullable, created at `database/migrations/2026_07_24_120002_create_core_tables.php:33` under the
   comment *"NEVER stores PHI in `detail` (ids/counts only)"*. P2's only command surface (Task 14)
   audits nothing at all, and Task 14 says why. A violation's `explanation` is generated *from* names
   and must never reach that column.
8. **Additive, nullable migrations, in a fresh date slot.** P1e used `2026_08_16_*` for both its
   tables, so **P2's slot is `2026_08_17_*`** if it needs one. It should not: Decision D and owner
   decision H both land on zero migrations, and *"P2 adds no migration"* is asserted the way P1d-2
   asserted it — `git diff --stat <base>..HEAD -- database/migrations` empty.
9. **A query budget is watched failing, not merely written.** `RotaGridTest` bounds the whole grid at
   `assertLessThan(20, …)` on a **populated** year (780 cells, splits, vacations, mid-year
   promotions and a stale row) *because a budget measured on an empty grid only ever proves the empty
   case*. `ClinicRosterTest` bounds three paths separately on a populated unit. Task 12's budget is
   measured the same way and **is observed breaching** — grow the fixture until it fails, record the
   number it failed at, then fix it — before it is trusted.
10. **A passing test must be shown capable of failing.** Every guard here is planted against before
    it is believed. Rulings 66–71: a guard is audited by planting, never by reading its needle list,
    and the ranking planting produces is the opposite of the one the lists suggest.
11. **Fixtures stay synthetic, permanently.** `tests/fixtures/roster/`'s rule generalises without
    amendment: **no real month's duty roster and no real staff list enters `packages/engine`'s
    corpus at any time.** The corpus exercises specific violation shapes — a gap of exactly the
    boundary value, a duty on a period's last day, a person whose level changes mid-window — not a
    plausible department. AU-06's *"regenerate a past pilot month"* is P4, is the owner's to run
    against production, and D15 already moved it to synthesized fixtures.
12. **The two shipped read folds are not to be duplicated or routed around.**
    `App\Support\Rota\AvailabilitySummary` is *"pure, and query-free by construction"* and *"handles
    no dates (ST-06). Not one."* `App\Support\Clinics\ClinicRoster` *"subtracts no leave and must
    never become an eligibility computation"* and does not query the leave table at all. Task 12
    reads their **inputs**, never reaches into them, and adds no file to either directory.
13. **`RotaGrid::forYear()` and `ClinicRoster::forDate()` take NO viewer at all** — the parameter was
    removed rather than ignored, after passing the real user shipped every colleague's email and
    phone in the Inertia props with nothing rendering them (P1d-2 Decision C, a live disclosure).
    The engine context is built the same way: **no contact field, for any viewer**, asserted on the
    most permissive institution setting the system can produce, exactly as `RotaReadViewTest` does.
14. **LIGHT THEME ONLY, semantic classes only.** P2 ships no markup; this is a constraint on what P2
    must not quietly introduce. No styling, no colour, no `dark:` utility near the package.
15. **Secrets are owner-managed and `.env.example` is CI's whole environment.** P2 introduces no
    environment variable. If an amendment adds one it needs the compose `environment:` block with an
    explicit `${VAR:-default}` (P0d Task 9) **and** an `.env.example` line that neuters no default
    (`EnvExampleNeverNeutersADefaultTest`, rulings 46–47).

---

## Findings — what is actually in the tree

Each verified while writing this plan; each changes what a task can assume.

1. **No condition type is evaluable end to end against the current schema.** `slots`,
   `coverage_templates`, `conditions`, `schedules`, `assignments`, `ignored_warnings` — zero
   `Schema::create` for any of them across 44 migrations, no model, no controller. Every CG-07 type
   constrains **duty assignments**, and the duty stream does not exist. This is not a blocker; it is
   the phase's defining fact. The engine is a pure function over JSON (CG-10 says so), and what the
   shipped schema supplies is the **context** half.
2. **What the shipped schema does supply, verified column by column.** *Fully:* `vacations`
   (`starts_on`/`ends_on`, `granularity`, no leave-type or approval column — every row is a blocking
   absence); `clinics` (`weekday` as a plain ISO 1–7 integer, `session`, `unit_id`, `attendee_mode`,
   `active`) and `clinic_attendees`; `master_rota_assignments` (one span row, both bounds inclusive,
   overlaps refused per (person, period) by `booted()`, gaps legal and counted); `people.joined_at`
   (nullable date, exists); `person_levels` via `Person::levelAt()`/`levelsAt()`/
   `levelSpansBetween()`/`levelFromSpans()`; `periods`; `holidays` (`calendar`, `month`, `day`,
   `year`, `duration_days`, `equity_tracked`); `units.training_rotation` / `call_target` /
   `clinic_owner` (all `boolean default(false)`, `call_target` with no consumer beyond the units CRUD
   screen — so *"which units take call"* is configuration P2 reads, not something it infers).
   *Nowhere:* unwanted days (see owner decision H), slot time windows, `counts_hours`.
3. **There is no public API for "which unit is person P on, on date D".** `MasterRotaAssignment`
   carries **no query scope at all**; the one date-shaped resolver is
   `ClinicRoster::rotatingOn(int $unitId, string $on)`, **private**, and it answers the inverse
   (unit → person ids). `RotaGrid::forYear()` is year-and-period shaped. Every rota read in this
   codebase is period-shaped; every condition question is date-shaped. **Flattening spans into a
   per-date unit vector is the bridge, it must be built exactly once, and it belongs in the context
   builder — never in a condition.** The model's own guards make this a single-row answer by
   construction: `MasterRotaAssignment::booted()` refuses a span reaching outside its period and
   refuses an overlap for one person in one period, and `Period::booted()` keeps periods
   non-overlapping.
4. **There is no per-person/per-date leave predicate either.** `Vacation` exposes only
   `scopeIntersecting(string $fromYmd, string $toYmd)`; `RotaGrid::cellFor()` does the intersection
   inline with `Y-m-d` **string** comparisons. Same conclusion: one flattening, in the context
   builder.
5. **`Calendar::dayType()` is query-free but CPU-expensive, and the query-count discipline is the
   wrong axis for it.** `holidaysOn()` walks backwards `duration_days` per holiday and a Hijri rule's
   `anchoredOn()` constructs an `IntlCalendar` per probe (`Calendar.php:169-186`), while
   `activeHolidays()`/`settings()` are process-lifetime statics so the *query* cost is one, not N.
   Study measurement, reproduced here as the reason for Decision D's day vector: 30 consecutive days
   cost ~24 ms and **zero** queries with four holidays configured; the same 30 days re-asked nine
   times cost ~203 ms. An engine that asks Calendar per condition per day pays ~200 ms per month on
   the WB-03 live-hint path — which fires before **every** prospective add or move, and NF-01's
   budget is 100 ms p95 for the whole evaluation. **The day vector is computed once, server-side,
   and handed to the engine.**
6. **`RotaGrid`'s own docblock refuses per-day labelling as a cost decision**: *"`Calendar::label()`
   per DAY of a period → real CPU for no gain. The grid labels BOUNDARIES only."* So the day vector
   is a new computation, not a reuse — and finding 5 is why it is still the cheap answer.
7. **`tests/fixtures/calendar/golden.json` is already a binding P2 contract, version 2, and it names
   P2 explicitly.** `_purpose`: *"The shared contract between `App\Support\Calendar` (PHP, this repo,
   P1a) and the `packages/engine` calendar mirror P2 will build (Decision A, design doc §7). Every
   value below was produced by RUNNING the code, not derived by hand."* Twelve top-level keys:
   `_purpose`, `version`, `timezone` (`Asia/Riyadh`), `cases`, `weeks`, `weekday_columns`,
   `hijri_month_boundary`, `day_boundary_cases`, `holiday_cases`, `period_runs`, `parse_rejects`,
   `hijri_labels`. `GoldenFixtureTest` asserts it from the PHP side in eleven methods.
   `weekday_columns` was added at version 2 *specifically* for CL-03: *"a P2 condition that must map
   a date to an ISO weekday and compare it to `clinics.weekday` client-side with no round trip
   (UX-05), so P2's `packages/engine` mirror needs this exact function."* Restated as a binding
   invariant at `docs/INVARIANTS.md:43-47`. **This file is an INPUT to P2, not something P2 authors.**
8. **§7 Decision A is the live precedent for overruling this design doc's own architectural nouns.**
   The doc originally specified *"PHP plus a mirrored `packages/engine`"* for the calendar; P1a
   superseded it with *"ONE implementation, not two"*, built the golden fixture as the contract, and
   deferred the mirror to P2 — because shipping a second implementation early is *"two definitions
   of one fact — the same failure class `AuditChain::canonical()` and `Person::levelAt()` already
   carry docblocks against."* **P2 knowingly creates that second implementation. Decisions B and C
   exist to make it the smallest one possible.**
9. **The dual implementation is NOT PHP + TypeScript.** §4.1: one pure TS package, two *runtimes*
   (browser, Node sidecar), and `services/solver`'s CP-SAT mapping is *"the one permitted second
   implementation"*. Then, verbatim: **"No PHP implementation of the rules exists anywhere. That is
   the point."** Currently prose with nothing enforcing it — Task 8 enforces it.
10. **Four of the five types §4.3 flags as hardest to reconcile between the TS engine and the CP-SAT
    mapping fall outside Decision A's nine**: `fairness_distribution`, `rolling_hours_max`,
    `free_day_min`, `holiday_equity` (the fifth, `we_pairing`, is also deferred). That is independent
    support for the deferral, and the concrete reason the ordering matters: the cross-validation job
    is easy on the nine and hard on the thirteen.
11. **CG-08's residency preset cannot be seeded from anything in this tree.** It says *"residency
    defaults seeded from the prototype's proven values"*; D14 makes the prototype idea curation only.
    The numeric defaults are an **owner input**, not a lookup (owner decision B's note).
12. **Only three CG-07 rows carry a stated default class** — `vacation_block` (Hard default),
    `unwanted_day_block` (top soft default), `overlap_block` (Hard, built-in). The other nineteen
    have no default class anywhere in spec or design doc.
13. **`packages/`, `services/` and TypeScript do not exist.** No `packages`, no `services`, no
    `tsconfig*.json`, no `typescript` dependency, no `.ts` file under `resources/`. `vitest.config.js`
    includes `tests/js/**/*.test.js` only; `vite.config.js` has no alias and two inputs.
    `docker-compose.production.yml` has exactly two services (`app`, `db`) with fifteen invariants
    pinned by `DeploymentInvariantsTest`. CI has three jobs and `docker-build` builds the root
    Dockerfile alone. **P2 creates the toolchain from zero.**
14. **The two tree statements about how CL-03 asks its question disagree, and one of them must win.**
    Design §14 item 22: *"the P2 condition reads `clinics.weekday` and `clinics.unit_id` against a
    date and a person's current unit. It needs no schema change here and it is not a clinic-module
    concern when it lands."* The P1e plan's handoff (line 3440): *"`ClinicRoster` resolves and never
    stores, so a condition asking 'who is at this clinic on this date' asks it rather than reading a
    cached list."* They are different questions with different costs. Owner decision G resolves it.

---

## Decision A: the nine, by type key — and the thirteen that wait

**This is a proposal.** Nothing in the tree enumerates a nine. What follows is built only from
anchors that *are* in the tree, each cited, and the two judgement calls in it are flagged as such and
routed to owner decision B.

### The anchors

- **CG-07's own default markings** (the only per-type class statements that exist anywhere):
  `overlap_block` — *"Hard, built-in"*; `vacation_block` — *"Hard default"*; `unwanted_day_block` —
  *"top soft default"*.
- **SPEC Appendix B**, the stakeholder traceability row mapping the owner's own words onto §15/§17,
  verbatim: *"Automatic arrangement under modifiable, importance-ranked conditions (**spacing,
  monthly caps, weekday/weekend distribution, vacations, unwanted days, clinic–post-call**) → §15,
  §17."*
- **WB-04/WB-05**, which name two types the Stage-2 workbench cannot render without: *"pickers
  exclude hard-ineligible people"* → `eligibility`; *"current vs target"* and the *"2 below target"*
  chip → `target_per_period`.
- **`golden.json` v2 and design §14 item 22**, which both commit `clinic_conflict` to P2 **in
  writing, in this tree**, and **SPEC §4's frozen decision**: *"Clinic conflict default: post-call
  variant on; same-day-overlap variant off."*

### The nine

| # | Type key | Class | Anchor | Context it needs, and where it already exists |
|---|---|---|---|---|
| 1 | `overlap_block` | **Hard** | CG-07 *"Hard, built-in"* | The duty stream alone. Nothing else in the catalog is coherent without it — every other type presumes one duty per person per overlapping window. |
| 2 | `vacation_block` | **Hard** | CG-07 *"Hard default"*; Appendix B *"vacations"* | `vacations.starts_on`/`ends_on`, shipped P1d-1. No leave-type or approval column exists, so every row blocks — stated, not assumed. |
| 3 | `eligibility` | **Hard** (owner decision F) | WB-04 | `master_rota_assignments` + `person_levels` + `units.training_rotation`, all shipped. Slot identity is an opaque key in P2 (Decision D). |
| 4 | `unwanted_day_block` | soft, rank 1 | CG-07 *"top soft default"*; Appendix B *"unwanted days"* | **Nothing stores this.** Owner decision H: the days arrive **in the engine context**; the store lands in P3 with the screen that fills it. |
| 5 | `min_gap` | soft | Appendix B *"spacing"* | The duty stream + slot windows. Owner decision C fixes the measurement. CG-08's ACGME *"10 h between duties"* is this same type, later parameterised. |
| 6 | `count_max / count_min` | soft | Appendix B *"monthly caps"* | The duty stream + `periods` + the department's week (`Calendar::weekStartIsoDay()`). Owner decision D fixes whose count. |
| 7 | `target_per_period` | soft | WB-05 *"current vs target"*; CG-07's own parameter example | `periods` + `vacations` — the *"≥2 vacation weeks"* modifier is computable today via `Calendar::weeksIn()`. Owner decision E fixes the grammar. |
| 8 | `post_duty_exclusion` | soft | Appendix B *"clinic–post-call"* — which cannot be evaluated without a notion of post-call; CG-07 calls this *"generalized post-call"* | The duty stream + slot kinds. Its production parameters cannot be authored until P3 ships `slots`; in P2 it is fixtured against slot shapes declared in the engine's own contract. |
| 9 | `clinic_conflict` | soft | Appendix B *"clinic–post-call"*; `golden.json` v2 `_purpose`; design §14 item 22; SPEC §4's frozen default | `clinics.weekday` (ISO 1–7) + `clinics.unit_id` + the person's unit on the date. All shipped P1e-1. Owner decision G fixes which question it asks. |

Three Hard, six soft. The three Hard are exactly the three with **no parameterisation ambiguity**,
which is why they form the seam (see The split).

### The thirteen that wait, each with the reason it waits

**P3 (with the gate, the slots and the workbench that give them a consumer) — five:**

| Type key | Why it waits |
|---|---|
| `dow_restriction` | The cheapest of all to add later — a day-of-week set tested against a person or rotation, needing nothing the nine do not already provide. It is the only member of the prototype's proven set with no Appendix B sentence and no CG-07 default marking. Deferring it costs least; that is exactly why it is the one deferred. Named tenth. |
| `composition` | Appendix B **does** name *"weekday/weekend distribution"*, so this is a judgement call and is routed to owner decision B. Reasons it waits: it is a **tally rendered as a condition** (WB-05's WD/WE mix column, TL-01), it produces no actionable hint on a single prospective placement until a month is nearly full, and its neighbour in the same "even spread" family — `fairness_distribution` — is Stage 4 by §35. Building it in P2 fixes half of an equity semantics before the equity module exists, which is the §1.3 objection's own argument. Named eleventh. |
| `max_gap` | *"Regular exposure"*. No prototype precedent, no Appendix B sentence, no consumer before the workbench. |
| `same_unit_conflict` | **Its key name, its Meaning and its parameters disagree three ways.** Key says *same unit*; Meaning says *"Pairs never together"* (people?); parameters say *"unit pairs; day exceptions"* (cross-unit?). Implementing it means choosing between *"two rotators on the same unit never on call together"* and *"a person from unit A never on call with a person from unit B"* — opposite rules under one name. Not a developer's call, and not one P2 needs to make. |
| `onboarding_grace` | `people.joined_at` exists and this is nearly free — but it has no consumer before the workbench and no owner has stated N. Named twelfth. |

**P5 / Stage 5 — eight:**

| Type key | Why it waits |
|---|---|
| `rolling_hours_max` | The CG-08 ACGME duty-hours bundle, which §35 places at **Stage 4** and the phase table at **P5**. |
| `free_day_min` | Same bundle. Also one of §4.3's five hard-to-reconcile types (sliding windows including partial windows at period boundaries). |
| `call_frequency_max` | Same bundle. |
| `consecutive_max` | Same bundle. |
| `fairness_distribution` | §35 Stage 4 (*"equity + holiday equity"*). §4.3: violation count vs min-max objective — the hardest of the five to reconcile. |
| `holiday_equity` | §35 Stage 4. §4.3: multi-year lookback reduced to a per-schedule violation. `holidays.equity_tracked` exists and waits. |
| `we_pairing` | §4.3: *"a shared definition of what 'preference broken' means"* — undefined in the spec. No consumer before the workbench. |
| `forbidden_transition` | CG-07's own table marks it **(Stage 5)**. |

**The duty-hour bundle has a second, harder justification than "it is Stage 4", and the plan uses
it:** §37 lists *"the SCFHS/local duty-hour policy in numeric form"* among the **remaining human
inputs**, and §38's second unvalidated assumption is *"the SCFHS/local duty-hour policy exists in
numeric form and maps onto the catalog — request now; map condition by condition."* Building
`rolling_hours_max`, `free_day_min` and `call_frequency_max` in P2 fixes their semantics **before the
policy they exist to encode has arrived**. That is precisely the §1.3 objection's *"fixes fairness
and duty-hour semantics before a single real month has tested them"*, and it is the strongest single
argument for D13-R.

**Note on `min_gap`:** the ACGME bundle's fifth element, *"10 h between duties"*, is `min_gap` in
hours — the same type as the prototype's three-day gap. So `min_gap` ships in P2 and only its ACGME
**parameterisation** waits. The bundle is deferred as a preset, not as four-and-a-half types.

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
- Its time type is **minutes from local midnight**, an integer. A slot window is
  `{ startMinute, endMinute, crossesMidnight }`, exactly SL-01's *"time window (may cross midnight)"*.
  A duty is `{ date: Ymd, startMinute, endMinute }`. `min_gap` in hours and `post_duty_exclusion` in
  hours are therefore integer minute arithmetic over a date sequence.
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
Task 5 runs the ten existing JS date needles over `packages/**/*.ts` with **no allow-list in either
direction**, and the mirror passes on its own merits rather than by exemption — the same property
`ClinicHooksTest` has (*"the absence is real rather than allow-listed"*).

---

## Decision C: the mirror implements no Hijri, and says so where absence could be mistaken for oversight

`Calendar` resolves Hijri through ICU `islamic-umalqura` plus a per-department
`institutions.hijri_offset_days`, and `golden.json` carries `hijri_month_boundary` and `hijri_labels`
blocks precisely because that is the fragile part. A browser's `Intl` build is not guaranteed to
agree with PHP's ICU, and `Intl.DateTimeFormat` is one of the ten forbidden needles besides.

**None of Decision A's nine reads a Hijri date.** The one type that would — `holiday_equity`,
*"spread named holidays across people & years"* — is deferred to Stage 4. Holidays reach the engine
as **already-resolved Gregorian dates** in the day vector, computed server-side by
`Calendar::holidaysOn()`, which already handles the Hijri rule, the `duration_days` walk-back and the
per-department offset. A Hijri **label** is display text, arrives as a string prop if a screen wants
one, and is never arithmetic.

So the mirror implements: `Ymd` parse/format, civil-date arithmetic, `isoWeekday`, `datesBetween`,
`weekdayColumns` (rotated to a supplied `weekStartIsoDay`), `weekOf`/`weeksIn`, `isWeekend` and
`dayType` — the last two from **supplied** `weekendDays` and a **supplied** resolved-holiday set.

**And it declares what it does not implement.** Task 4 ships a coverage manifest naming every
`golden.json` top-level key as either *asserted by the mirror* or *deliberately out of scope, with
the reason*, plus a test that the union is the file's actual key set. Without it, `hijri_labels`
sitting unasserted looks identical to somebody forgetting — and *"we have not built it"* and *"we
have decided not to build it"* are different states, only the second of which is safe to build on
(design §14 item 18's treatment, applied here).

---

## Decision D: the CG-10 input contract is a TYPE, never a table — and P2 fixes it

Every condition evaluates a duty stream that has no table. P2 cannot avoid defining that shape, so
it should define it **deliberately and first**, rather than letting it accrete.

**The shape (P2 authors it; P3's `slots`/`assignments` tables serialise *into* it):**

```
EvaluationContext {
  timezone: string            // carried for provenance and fixture identity ONLY; never used in arithmetic
  weekStartIsoDay: 1..7
  weekendDays: (1..7)[]
  today: Ymd
  days: Day[]                 // one entry per date in the horizon, PRECOMPUTED server-side
  periods: Period[]           // { key, startsOn, endsOn }
  people: Person[]            // { key, levelByDate | levelSpans, unitByDate | unitSpans,
                              //   leaveDays: Ymd[], unwantedDays: Ymd[], joinedAt?: Ymd }
  slots: Slot[]               // { key (OPAQUE STRING), kind, unitKey?, startMinute, endMinute,
                              //   crossesMidnight, countsHours, tallyKey? }
  clinics: Clinic[]           // { key, unitKey, isoWeekday, session }
}
Day { date: Ymd, isoWeekday: 1..7, dayType: 'WD'|'WE'|'HOL', periodKey: string|null, holidayKeys: string[] }
Duty { personKey, date: Ymd, slotKey }
Schedule { horizon: { from: Ymd, to: Ymd }, duties: Duty[] }
Condition { id, typeKey, params, scope, class: 'hard'|'soft', rank?: number, active: boolean, source }
evaluate(schedule, context, conditions) -> Violation[]
```

Four properties make this the right call, and each is checkable:

1. **No migration in P2, so nothing pre-commits P3's schema.** `slotKey` is an **opaque string**, not
   a foreign key; `personKey` likewise. P3 chooses its own primary keys and maps them.
2. **P3 adds a projector, not a second semantics.** `slots`/`assignments` serialise into `Slot`/`Duty`
   with no rule logic in the projection.
3. **The golden fixtures and the eventual §4.3 job have something to run against before any table
   exists**, which is what makes P2 testable at all.
4. **It is demoable.** A hand-authored synthetic month of JSON in, `violations[]` with plain-language
   explanations out (Task 14) — the §1.3 objection's *"nothing demoable at its end"* answered without
   a screen and without cutting scope further.

**The day vector is the load-bearing part** and finding 5 is why: `Calendar::dayType()` is ~0.8 ms
per day and the engine would otherwise re-ask it per condition per day, blowing NF-01's 100 ms
budget on the WB-03 hint path. Computed once, server-side, per horizon.

**It must be serialisable into AU-02's request without a translation layer.** AU-02 is
`{periodSkeleton, roster, slots, templates, conditions, constraints, fixedAssignments, seed,
timeLimitSec}`. `EvaluationContext.days`+`periods` is `periodSkeleton`; `people` is `roster`; `slots`
is `slots`; `Schedule.duties` is `fixedAssignments`; `conditions` is `conditions`. `templates`
(SL-03) and `constraints` (RQ-01) have no P2 counterpart and are **absent rather than empty** — Task
6 states that, so P4 finds a hole rather than a wrong default.

---

## Decision E: `services/engine` waits for its first caller; P2 ships a Node entrypoint instead

The phase table gives P2 `services/engine`. **P2 has no server-side consumer for it:** the publish
gate CG-05 and the workbench are P3, compliance reports TL-03 are P5, the solver is P4.

A container deployed with nothing calling it can be verified as *running* and cannot be verified as
*working* — the exact failure shape CLAUDE.md names: *"A Cloudflare trust fix once passed every test,
deployed healthy, and changed nothing at all — the compose default it edited was dead code."* The
cost is not zero either: `docker-compose.production.yml` has two services and fifteen pinned
invariants in `DeploymentInvariantsTest` (digest pinning, env passthrough, healthchecks); CI's
`docker-build` job builds the root Dockerfile alone; `docker/instance-env.sh` selects a stack's
containers.

**Recommended (owner decision I): defer `services/engine` to P3, where CG-05's publish gate is its
first real caller.** P2 ships `packages/engine/bin/evaluate.mjs` — reads the CG-10 JSON on stdin,
writes `violations[]` on stdout — exercised in CI (Task 13). That proves the compiled package runs
outside a bundler, under plain Node, with the same code the browser gets, and deploys nothing.

---

## Decision F: what P2's "cross-validation job" actually is

The phase table gives P2 *"the CI cross-validation job"*. **§4.3's job compares the TS engine against
the Python solver's §4.2 evaluation mode, and both `services/solver` and that evaluation mode are P4
deliverables** (phase table line 1054). So the P2 row names a job whose second implementation does
not exist for two more phases. This is a real internal inconsistency in the phase table, and Task 1
corrects it rather than letting a later reader take it as a commitment already missed.

**What P2 can honestly deliver, and does:**

| Job | What it is | Genuinely two implementations? |
|---|---|---|
| **`golden.json` two-sided** (Task 4, Task 13) | The same framework-free corpus asserted by `App\Support\Calendar` (PHP, `GoldenFixtureTest`, shipped) **and** by the TS mirror (new). A divergence fails the build. | **Yes.** This is the repository's first and, until P4, only real cross-implementation check — and it is the one P1a built the corpus for. |
| **The conditions golden-fixture gate** (Tasks 8–11, 13) | A corpus of `(schedule, context, conditions) → expected violations[]` cases asserted by the TS engine. | **No** — one implementation. This is NF-08/QA-01 regression coverage and Task 13 labels it as such rather than as cross-validation. |
| **§4.3's real job** | TS engine vs CP-SAT mapping, identical verdicts. | Yes — **and it arrives in P4** with the solver. Recorded, not built. |

The discipline `golden.json` inherits is `SignoffPickers`': *"a predicate written once as Eloquent and
once as raw SQL is two predicates that drift"*, and `PickerParityTest` asserts it **as a matrix**
(every fixture × all four fields) rather than case by case. Task 4's mirror assertion is the same
shape — every case × every implemented function — and Task 13 makes a divergence fail the build.

---

## Decision G: the PHP context builder is `App\Support\Engine`, and it is a reader

**Where it lives is forced, not chosen** (invariant 4). `App\Support\Rota\*` is globbed by
`RotaAccessTest` with `eligib` among its needles, so the `eligibility` type key alone would fail the
build there. `App\Support\Clinics\*` is globbed by `ClinicHooksTest` with `condition` among its
needles, so `clinic_conflict`'s reader would fail the build there. **`App\Support\Engine\` is clear
of both, and P2 adds no allow-list entry to either guard.** That is not a workaround: MR-04's rule
is that *the rota* must not infer eligibility, and CL-03's is that *the clinic module* must not
evaluate conditions. Both are satisfied by the crossing living in the engine's own namespace and
reading those modules' data, which is exactly what design §14 item 22 already specifies.

**"No PHP implementation of the rules exists anywhere" (§4.1) is scoped to rule SEMANTICS, not to
data access, and the plan says so out loud** — because a loader is exactly the artifact that reads as
a violation of that sentence in review. Loading `master_rota_assignments` and flattening spans to a
per-date unit vector implements no rule. **The real leak risk is not PHP rules code; it is the
serialiser**, where a rule will try to sneak in as an optimisation: sending only "eligible" people is
`eligibility` re-implemented as a `where`. Task 12's guard needles for exactly that.

Without one builder, every P3 consumer builds its own context and the bounded-query property is lost
the first time — the same argument that made `AvailabilitySummary` one fold feeding two screens.

---

## The split: P2-1 and P2-2

Too large for one branch, as P1c, P1d and P1e all were. **The seam is the parameterisation line, and
it is chosen so P2-1 can start before the owner has answered anything.**

- **P2-1 — the substrate and the three Hard types.** Everything with **no outstanding owner
  decision**: toolchain, the `Ymd` core, the calendar mirror against `golden.json`, the guards, the
  CG-10 contract and fixture format, the severity/rank model, CG-04 previews, and `overlap_block` +
  `vacation_block` + `eligibility` — the three types CG-07 and WB-04 have already classed and whose
  parameters are unambiguous. Its acceptance is that a real condition runs end to end through the
  contract.
- **P2-2 — the six soft types and the PHP context.** Every type whose parameterisation needed an
  owner answer (decisions C, D, E, G, H), plus the `App\Support\Engine` context builder with a
  measured query budget, the Node entrypoint, the CI wiring, and the demoable command.

**Written in the P0a–P1e convention: P2-2's plan is written when P2-1 merges**, not now. Tasks 9–14
below are scoped and sized, not specified to the same depth as 1–8, and that is deliberate — P2-1
will teach things about the contract that would make a fully-specified P2-2 written today wrong in
the way this programme has repeatedly caught.

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
- **`npm run build` and `npm test` green before every commit**, alongside `php artisan test`. Once
  the package exists, `npm run build` must actually bundle it — a package that only the test runner
  can resolve is not the browser runtime AR-03 requires.
- **Filter output.** `| tail -5`, `--filter <Name> | head -30`. Never dump a failing suite.
- **Tree deployable after every commit.**

---

# P2-1 — tasks

### Task 1: record D13-R, and correct every document this invalidates

**Files touched:** `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`,
`docs/INVARIANTS.md`, `CLAUDE.md`.

**Failing test first:** none — this task is documentation, and inventing a test for prose is the
vacuity this codebase guards against. Its verification is that the edits are checked back with
`grep`, listed below. It runs **first** because every later task's legitimacy rests on it.

**Implementation:**

1. §1.1's decision table, D13 row, in D3's exact reversal shape:
   `| D13 | Condition catalog scope? | ~~All 21 types in P2.~~ **REVERSED 2026-08-19 (D13-R) → NINE named types in P2; thirteen follow in P3 and P5/Stage 5.** The §1.3 objection is upheld. See the P2 plan, Decision A. |`
2. §1.3's D13 bullet gains a closing sentence: *"Upheld 2026-08-19 (D13-R). Recorded here as the
   objection it was; the decision it objected to is struck in §1.1."* The paragraph itself is
   **not** edited — it is a record of what was argued.
3. The phase table's P2 row is rewritten to the nine, by type key, and to what P2 actually ships:
   the package, the calendar mirror against `golden.json`, the severity/rank model, CG-04 previews,
   the `App\Support\Engine` context builder, and the Node entrypoint. It states that `services/engine`
   moves to P3 (Decision E) and that §4.3's cross-validation job moves to P4 with the solver
   (Decision F), so a later reader does not find a P2 commitment apparently missed.
4. The risk table's *"D13 makes P2 long and undemoable"* row: mitigation replaced with *"Closed by
   D13-R, 2026-08-19 — P2 ships nine types and a fixture-driven evaluation command; the remaining
   thirteen ship with the consumers that need them."*
5. **Correct the count everywhere.** CG-07 has **22 rows / 23 type keys**. Add a footnote under
   CG-07's table in `docs/munawib/SPEC.md` stating the count and that `count_max`/`count_min` share a
   row; replace every bare "21" in the design doc with the list or with 22.
6. New §14 open items, continuing after item 27:
   - **28.** `services/engine` moved to P3 with its first caller; §4.3's job moved to P4 with the
     solver. What P2's CI job actually is (Decision F).
   - **29.** The five P3 types and the eight P5/Stage-5 types, by key, with the reason each waits —
     including `same_unit_conflict`'s three-way self-contradiction, recorded so it is resolved once
     rather than re-discovered.
   - **30.** `unwanted_day_block` has no store anywhere; `people.constraints` is free-form JSON
     validated only as `['nullable','array']`. The store is P3's, with the screen that fills it
     (owner decision H).
7. `docs/INVARIANTS.md` gains an **§Engine** section: the mirror is the one deliberate second
   implementation, `golden.json` is its contract in both directions, the engine holds no `Date` and
   no timezone, `App\Support\Engine` is a reader, and the two glob-scanned namespaces it may not
   live in and why.
8. `CLAUDE.md`'s area table gains the row: `packages/engine`, `App\Support\Engine`, conditions →
   §Engine.

**How to verify:**
`grep -n "D13-R" docs/superpowers/specs/*.md docs/INVARIANTS.md CLAUDE.md` returns all four;
`grep -cn "all 21 CG-07" docs/superpowers/specs/*.md` returns 0;
`grep -n "22 rows" docs/munawib/SPEC.md` returns the footnote;
`php artisan test --filter=Build 2>&1 | tail -3` still green (documentation must not trip a
source-scanning guard — `CLAUDE.md` and `docs/` are outside every scan's scope, and this confirms
it).

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
`isoWeekday`, `datesBetween` with an explicit maximum span mirroring `Calendar::weeksIn()`'s 550-day
throw. Every function is total and pure; **no `Date`, no `Intl`, no epoch, no timezone** (Decision B).

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Cases include both leap
boundaries, a century non-leap (`1900-02-28` → `1900-03-01`), a century leap (`2000-02-28` →
`2000-02-29`), the year boundary, and every `parse_rejects` input from `golden.json`. Then **prove
the tests can fail**: replace `isoWeekday`'s modulus with `+ 1`, watch red, revert.

---

### Task 4: the calendar mirror, asserted against `golden.json` — and its coverage manifest

**Files touched:** `packages/engine/src/calendar/index.ts`,
`packages/engine/test/golden.test.ts`, `packages/engine/test/golden-coverage.test.ts`.
**`tests/fixtures/calendar/golden.json` is READ and NOT MODIFIED** — it is an input to this phase.

**Failing test first:** `golden.test.ts` loads the fixture by relative path and asserts the `cases`
block through the mirror. It fails on the first case. **Then plant the drift this whole file exists
to catch**: change one expected value in a *copy* of the fixture, confirm red, discard the copy. The
fixture itself is never edited to make a test pass — that is the one move that would make the
contract worthless.

**Implementation:** the mirror implements `isoWeekday`, `datesBetween`, `weekdayColumns(weekStartIsoDay)`,
`weekOf`, `weeksIn`, `isWeekend(weekendDays)`, `dayType(weekendDays, holidayDates)`. Every
department-varying fact — `weekendDays`, `weekStartIsoDay`, the resolved holiday set — is a
**parameter**, never a module default (owner decision K): a default in the package is a second
definition of a per-department fact, which is what `golden.json` exists to prevent.

`golden-coverage.test.ts` is the honesty half. It declares two lists — `ASSERTED` and
`OUT_OF_SCOPE` (each with a one-line reason: `hijri_month_boundary` and `hijri_labels` per Decision
C; `period_runs` because `Period` boundaries arrive in the context rather than being generated
client-side; `day_boundary_cases` because Decision B removes instants and the block's PHP-side
assertion remains the one that matters; `timezone`/`version`/`_purpose` as metadata) — and asserts
their union **equals** the fixture's actual top-level key set. When `golden.json` reaches version 3
with a new block, this test fails until somebody decides which list it joins. Absence becomes a
decision instead of an oversight.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Then add a throwaway key to a
*copy* of the fixture, point the coverage test at the copy, confirm it fails with the key named,
revert. Confirm `php artisan test --filter=GoldenFixtureTest 2>&1 | tail -3` still green — both sides
now assert one file and neither has moved it.

---

### Task 5: extend the date guard to `packages/`, allow-list empty in both directions

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
non-vacuity floor asserts the collector found at least the files Tasks 3 and 4 created, because a
guard iterating an empty set is green for the wrong reason and a moved directory is exactly how one
gets there.

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

### Task 6: the CG-10 contract — types, JSON Schema, fixture format, registry

**Files touched:** `packages/engine/src/contract/{types.ts,schema.json,validate.ts}`,
`packages/engine/src/registry.ts`, `packages/engine/src/evaluate.ts`,
`packages/engine/test/contract.test.ts`, `packages/engine/test/fixtures/README.md`.

**Failing test first:** `contract.test.ts` calls `evaluate(schedule, context, [])` on a minimal
synthetic month and asserts `[]`; then calls it with a condition whose `typeKey` is unknown and
asserts it **throws a named error**. Fails: nothing exists.

**Implementation:** Decision D's types, verbatim. A hand-written JSON Schema for
`EvaluationContext`/`Schedule`/`Condition` plus a `validate()` that runs it — **no schema library**,
because a runtime dependency in the browser bundle is a cost, the shape is small, and Task 5's
dependency check would have to allow-list it. `registry.ts` maps `typeKey` → `{ evaluate, preview,
defaultClass }`; an unknown key **throws** rather than being skipped, because a silently ignored
condition is a control that appears to do nothing — rulings 41/49's failure shape, one layer inside
the engine. `evaluate()` is pure: no I/O, no globals, no clock, deterministic ordering of
`violations[]` (by `conditionId`, then `location`) so a fixture comparison is stable.

`fixtures/README.md` fixes the corpus format — one JSON file per case: `{ name, why, context,
schedule, conditions, expected: Violation[] }` — and states, in the file, that **the corpus is
synthetic permanently** (invariant 11) and that `why` is mandatory because a fixture whose purpose
nobody wrote down is a fixture nobody dares change.

`templates` and `constraints` from AU-02 are **documented as deliberately absent**, with the reason,
so P4 finds a hole rather than a wrong default (Decision D).

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`; `npx tsc --noEmit -p
packages/engine` → `rc=0`. Prove `evaluate()` is deterministic: run one fixture 100× and assert
identical JSON output. Prove the unknown-key throw by planting `typeKey: 'min_gap'` **before** Task 9
implements it and confirming the named error.

---

### Task 7: the severity/rank model and CG-04's plain-language previews

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

### Task 8: the three Hard types, and the guard that no PHP implements a rule

**Files touched:** `packages/engine/src/conditions/{overlap_block,vacation_block,eligibility}.ts`,
their tests and fixtures, `tests/Feature/Build/RulesLiveOnlyInTheEngineTest.php`.

**Failing test first, per type**, each from a fixture case whose `why` names the shape:
`overlap_block` — two duties on one date whose slot windows overlap, including one that crosses
midnight into the next date's duty; `vacation_block` — a duty on a date inside a `leaveDays` range,
and the boundary cases on the first and last day (both bounds inclusive, matching `vacations`);
`eligibility` — a person whose level on the duty's date is not in the slot's allowed set, **and** the
mid-window promotion case where the same person is eligible on one date and not the next.
`eligibility`'s "auto-fill order" parameter is **not implemented** and its absence is asserted (owner
decision F: one type, one contract; ordering is WB-04 fitness, P3).

**Implementation:** three pure functions over the context, registered. Each returns
`{conditionId, severity, rank?, location: {personKey, date, slotKey}, explanation}`.

`RulesLiveOnlyInTheEngineTest` enforces §4.1's *"No PHP implementation of the rules exists
anywhere"*, which is currently prose with nothing behind it. Modelled on
`CalendarIsTheOnlyConverterTest`: whole match set, `assertSame([], $offenders)`, allow-list plus
staleness twin. Needles are the **nine type keys** plus `violation`, `hard_block`, `soft_block`,
`rank_order`, scanned over `app/` **with comments stripped** (`Tests\Support\SourceScanner`), because
`App\Support\Engine`'s docblocks will legitimately say what they are for — and a guard that fails the
build on the documentation of its own rule trains people to delete the documentation
(`RotaAccessTest`'s recorded departure, adopted here for the same reason). The stripper is pinned in
**both** directions against a real file, per invariant 3.

**Measure before adding, per ruling 42.** `condition` as a bare needle is **not** bought: it matches
`config`-adjacent prose and Laravel's own vocabulary too widely, and buying it would mean
allow-listing files where a real offender would be born. `eligibility` as a needle over all of `app/`
would collide with `AvailabilitySummary`'s docblock — which the comment stripper removes, so it is
affordable, and the measurement is recorded. The allow-list starts **empty**; if `App\Support\Engine`
must name a type key in code (it will, to key the context it builds), that is one entry with a stated
reason and a staleness twin, and Task 12 records the measurement.

**How to verify:** `npx vitest run packages/engine 2>&1 | tail -5`. Plant
`app/Support/FakeRules.php` containing `function minGap(...)` with a `'min_gap'` literal; confirm
`php artisan test --filter=RulesLiveOnlyInTheEngine 2>&1 | tail -5` goes red naming the file; delete.
Then plant it **inside a docblock only** and confirm the guard stays **green** — that is the stripper
proving it strips. Full suite: **1685 + 2** = **1687**.

---

## Definition of done — P2-1

- [ ] `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"` → `rc=0`, **1687** tests (1683 + Task 5's 2
      + Task 8's 2).
- [ ] `npm test 2>&1 | tail -5` → `rc=0`, 237 Vitest plus the engine suite, **and the engine files
      named in the output** — a suite that silently skipped them looks identical to green.
- [ ] `npx tsc --noEmit -p packages/engine; echo "rc=$?"` → `rc=0`, and observed `rc=1` on a planted
      type error.
- [ ] `npm run build 2>&1 | tail -3` green, and the `@engine` alias resolves.
- [ ] **No migration:** `git diff --stat main..HEAD -- database/migrations` empty.
- [ ] **No new allow-list entry** on `RotaAccessTest`, `ClinicHooksTest`, or any single-writer guard:
      `git diff main..HEAD -- tests/Feature/Rota/RotaAccessTest.php tests/Feature/Clinics/ClinicHooksTest.php`
      empty.
- [ ] `resources/js`'s date allow-list still empty; the guard now covers `packages/` with an empty
      allow-list too; both observed red on a plant.
- [ ] `golden.json` **unmodified**: `git diff main..HEAD -- tests/fixtures/calendar/golden.json` empty.
- [ ] Every fixture in `packages/engine/test/fixtures/` is synthetic and carries a `why`.
- [ ] Design doc, `docs/INVARIANTS.md` and `CLAUDE.md` carry D13-R; no bare "21" survives.

---

# P2-2 — tasks

**Written when P2-1 merges**, per the P0a–P1e convention. Scoped and sized here; not specified to
Tasks 1–8's depth, deliberately.

### Task 9: `min_gap` and `post_duty_exclusion`

The two window types. Both are integer-minute arithmetic over a date sequence (Decision B), and both
depend on owner decision C's measurement semantics. `post_duty_exclusion`'s production parameters
cannot be authored until P3 ships `slots` (SL-02: *"post-duty semantics follow slot windows
automatically"*), so P2 fixtures it against slot shapes declared in the contract and says so.
Fixtures must include the case the two candidate `min_gap` readings **disagree** on — a 24 h call
ending 08:00 and a night call starting 20:00 the following day — because a fixture that both readings
pass proves nothing about the decision.

### Task 10: `count_max / count_min` and `target_per_period`

The two counting types, over `period` and `week` windows. The week is the **department's**
(`weekStartIsoDay` from the context, never a literal). Owner decision D fixes whose count;
owner decision E fixes the modifier grammar. `target_per_period`'s vacation-weeks modifier is
computed from `leaveDays` against `Calendar::weeksIn()`'s week boundaries — carried in the context,
not recomputed. Fixtures must include a **partial window at a period boundary**, which §4.3 names as
one of the genuinely hard reconciliation cases and which P4 will have to match.

### Task 11: `unwanted_day_block` and `clinic_conflict`

The two day-marking types. `unwanted_day_block` reads `person.unwantedDays` from the context and
**adds no table** (owner decision H). `clinic_conflict` reads `clinics.isoWeekday`/`unitKey` against
the day vector and the person's unit-on-that-date, per owner decision G, with the post-call variant
on and same-day off (SPEC §4's frozen default). **This is the type that validates the whole calendar
mirror in anger** — `golden.json`'s `weekday_columns` block was added at version 2 for exactly this
computation, so a mirror bug surfaces here rather than in P3.

### Task 12: `App\Support\Engine` — the context builder, and its two guards

The single bounded-query loader producing an immutable array, then a pure fold — `RotaGrid` →
`AvailabilitySummary`'s shape, one layer along. It flattens rota spans and vacations into per-date
vectors (findings 3 and 4), builds the day vector once (finding 5), and carries **no contact field
for any viewer** (invariant 13). Two guards, both planted:

- **A reader, not a writer** (invariant 5): needles for `create(`, `insert(`, `update(`, `save(`,
  `delete(`, `upsert(`, `firstOrCreate(`, `updateOrCreate(`, `truncate(`, `destroy(`, **plus the two
  known blind spots — `::query()->create(` and `$model->update([`** — over `app/Support/Engine/*.php`
  as a glob, so a class added there joins unasked.
- **No rule in the serialiser** (Decision G): the builder must not pre-filter people, dates or slots
  by anything a condition evaluates. Needles for `->where(` combined with an eligibility-shaped
  column, plus a positive assertion that the built context contains **every** active person in the
  horizon including those a condition would exclude — the check that catches "send only eligible
  people", which is `eligibility` re-implemented as a query.

**The query budget is watched breaching first** (invariant 9): grow the fixture — a populated
academic year, splits, mid-year promotions, vacations, a stale row, four clinics — until the bound
fails, record the number it failed at in the docblock, then bring it under. A budget that never
failed is a budget nobody has measured.

### Task 13: the Node entrypoint and the CI job, named honestly

`packages/engine/bin/evaluate.mjs`: CG-10 JSON on stdin, `violations[]` on stdout, non-zero exit on
a schema failure. Added to CI's `test` job: build the package, run the whole fixture corpus through
the **compiled** entrypoint (not the test runner's transform — a package that only Vitest can load
is not the browser runtime), and assert every case. The `golden.json` mirror assertion is wired as a
build-failing check.

**Named honestly, per Decision F:** the calendar half is genuine cross-implementation validation; the
conditions half is single-implementation regression coverage. §4.3's real job arrives in P4. The CI
step's own name and comment say which is which, because the phase table's wording is exactly what a
later reader would take as a commitment already met.

**NF-01 is measured here**, not asserted: the full corpus plus a synthetic 31-day, 20-person,
3-slot month through `evaluate()`, timed, with the number recorded. If it exceeds 100 ms the number
is recorded anyway and the gap is stated — a budget quietly missed is worse than a budget missed
out loud.

### Task 14: the demoable artifact — `php artisan engine:evaluate`

The answer to the §1.3 objection's *"nothing demoable at its end"*. A **local, owner-facing** command:
takes a period and a duty-JSON file, builds the real context from this department's shipped master
rota, vacations, clinics, levels and calendar via Task 12, pipes it through the Node entrypoint, and
prints the violations grouped by severity with their CG-04 plain-language explanations.

Three things it is not, each stated in the command's own docblock and asserted:

- **Not a production path.** The production server runtime is P3's `services/engine` (Decision E).
  The command refuses to run when `app()->environment('production')`, so a convenience does not
  become an undocumented dependency on `node` being present in the app container.
- **Not a writer.** It writes nothing and **audits nothing** — there is no clinical or access event
  here to record, and a violation's `explanation` is generated from people's names, so it must never
  approach `audit_log.detail` (invariant 7).
- **Not fed by real data in tests.** Its test fixture is synthetic (invariant 11). The owner may of
  course point it at this department's real rota — that is the demo — but nothing real enters the
  repository.

---

## Definition of done — P2-2

- [ ] `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"` → `rc=0`; count stated and matched.
- [ ] `npm test`, `npx tsc --noEmit -p packages/engine`, `npm run build` all `rc=0`.
- [ ] All nine types implemented, each with fixtures whose `why` names the shape, each planted
      against.
- [ ] Task 12's query budget **observed breaching** before it was fixed, with the number recorded.
- [ ] NF-01 measured and the number recorded, met or not.
- [ ] **No migration in P2 at all:** `git diff --stat main..<P2-2 head> -- database/migrations` empty.
- [ ] `RotaAccessTest`, `ClinicHooksTest` and every single-writer guard unchanged.
- [ ] `docker-compose.production.yml` unchanged; `DeploymentInvariantsTest` untouched and green.
- [ ] `php artisan engine:evaluate` demonstrated on this department's real period, output pasted into
      the plan's amendments.

---

## Owner decisions needed

Every one carries a recommended default, so nothing blocks on an answer. Silence takes the default.

**A. Reverse D13 to nine types?**
*Default: **YES**, recorded as D13-R (Task 1).* The tree as it stands says all 21 in P2 and records
the nine-type argument as raised and overruled. Nine is defensible — §35 places the full catalog at
Stage 2 (= P3), so a nine-type P2 followed by the remainder in P3 still satisfies Stage 2's gate —
but it is a **new decision reversing a reaffirmed one**, and this plan will not describe it as an
application of D13.

**B. Which nine — the ninth slot.**
*Default: **`clinic_conflict`**, per Decision A.* It is committed to P2 in this tree twice
(`golden.json` v2's `_purpose`, design §14 item 22) and SPEC §4 freezes its default variant. The
named alternates, each with a real claim: **`composition`** (Appendix B's *"weekday/weekend
distribution"* is the owner's own words; it loses on being a tally whose family ships at Stage 4) and
**`dow_restriction`** (prototype-proven; it loses on being the cheapest to add later). Swapping one in
is a one-line registry change plus its fixtures — roughly a day — and the cost of getting it wrong is
low, which is why it is not worth blocking on.

**C. `min_gap` — measured between which endpoints, and what does `days` mean?**
*Default: the parameter carries an explicit `unit`. **`hours` measures END-to-START in minutes**
(ACGME's *"10 h between duties"*); **`days` measures the difference in CALENDAR DATES between the two
duties' start dates** (the prototype's three-day gap).* Both semantics shipped, both fixtured, on one
type — which is what CG-07's *"days or hours"* actually implies. With split day/night and 24 h call
both configurable (SL-02) the two readings differ by up to a full day on exactly the QCH
configuration, so the fixture corpus must contain the case they disagree on.

**D. `count_max / count_min` — whose count?**
*Default: **per PERSON**, with `levels` as a SCOPE filter (which people the cap applies to), not a
cohort total. Windows are `period` and `week` only; **`day` is not added**.* CG-07's parameters are
*"kinds; levels; count; window (period/week)"* and `day` is not among them. The prototype's actual
usage was a per-slot-per-day cap — which in Munawib is **SL-03 coverage-template** territory (*"ordered
level requirements with min and target"*), lands in P3, and must not be built twice. Task 1's open
item 29 records that explicitly so a P3 implementer does not rediscover it as a gap.

**E. `target_per_period` — the modifier grammar.**
*Default: an ordered list of `{ when: { vacationWeeksAtLeast: N }, target: M }`; **first match wins**;
**replace, not adjust**; exactly one modifier predicate ships and the grammar is closed to it.* The
spec gives one example and no syntax; the prototype had exactly one modifier (R1: 6 → 3), which is a
data point, not a grammar. An open predicate language is CG-09's condition builder, which is Stage 4
and explicitly *"no free-form scripting"*.

**F. `eligibility` — a violation, or a picker filter?**
*Default: **a Hard violation** on a committed assignment. The "auto-fill order" half is NOT a
condition and does not ship in P2.* CG-07 leaves the type unmarked while WB-04 says pickers *"exclude
hard-ineligible people"*, which makes it Hard in the workbench; and ordering produces no violation at
all, so one type would be carrying two contracts. Ordering is WB-04 fitness, P3.

**G. `clinic_conflict` — which question does it ask, and which variant?**
*Default: **the post-call variant ON, same-day-overlap OFF** (SPEC §4, frozen), and it reads
`clinics.weekday` + `clinics.unit_id` against the date and the person's unit-on-that-date — **design
§14 item 22's formulation, not `ClinicRoster::forDate()`**.* The two tree statements disagree
(finding 14); item 22 wins because the engine receives a pre-resolved context and cannot query at
all, and because `ClinicRoster` is unit-first and per-clinic — the wrong shape and the wrong cost for
a per-person-per-date test. `ClinicRoster` remains the right answer for CL-04's *"who is at this
clinic on this date"* in P3. Task 1 corrects the P1e handoff sentence so the two stop disagreeing.

**H. `unwanted_day_block` — where do unwanted days live?**
*Default: **nowhere in P2**. The days arrive in the engine context; the store lands in P3 with the
screen that fills it.* Nothing stores them today: `people.constraints` is free-form JSON validated
only as `['nullable','array']`, whose sole documented example is `{"no_nights": true}` in a textarea
helper — defining a schema for it is an owner decision, not a developer's, and RQ-01's `requests`
table is P4. Since P2 has no screen, it needs no store, and the question moves to the phase that
grows the screen. The alternative — a small `unwanted_days` table now — costs a migration in a phase
that otherwise needs none, and would fix the shape before the screen that fills it exists.

**I. Does `services/engine` ship as a production container in P2?**
*Default: **NO — P3**, with CG-05's publish gate as its first caller (Decision E).* P2 ships the Node
entrypoint exercised in CI. A container nothing calls can be verified running and cannot be verified
working, which is CLAUDE.md's named failure shape; and a third compose service touches fifteen pinned
deployment invariants for zero callers.

**J. Does the calendar mirror implement Hijri?**
*Default: **NO** (Decision C).* No type in the nine reads a Hijri date; the one that would
(`holiday_equity`) is Stage 4; ICU/`Intl` disagreement between PHP and a browser is a real risk with
a per-department offset on top; and `Intl.DateTimeFormat` is a forbidden needle. Hijri holidays are
resolved server-side into Gregorian dates. The absence is declared in Task 4's coverage manifest so
it reads as a decision.

**K. How does the engine learn `weekendDays` and `weekStartIsoDay`?**
*Default: **from the context object only** — never a module default, never a literal in the
package.* A bundled default is a second definition of a per-department fact, which is precisely what
`golden.json` and AR-08 exist to prevent, and Task 5's weekday-vocabulary scan over `packages/`
enforces the literal half.

---

## Acceptance

**P2 is done when:**

1. `packages/engine` implements the nine types of Decision A against the CG-10 contract, with a
   synthetic fixture corpus in which every case carries a `why`, and every type has been observed
   failing on a planted defect.
2. The calendar mirror asserts `golden.json` from TypeScript, `App\Support\Calendar` still asserts it
   from PHP, **the file itself is unchanged**, and the coverage manifest names every block as
   asserted or deliberately out of scope.
3. The date guard covers `resources/js` **and** `packages/`, with an empty allow-list on both, and
   has been observed red on a plant in each.
4. `App\Support\Engine` builds the context at a bound that was **observed breaching** before it was
   met, carries no contact field for any viewer, and is guarded as a reader that implements no rule.
5. No migration, no index, no new environment variable, no compose change, no allow-list entry on any
   existing guard.
6. `php artisan engine:evaluate` runs against this department's real period and prints real
   violations with plain-language explanations — the phase's demoable artifact.
7. The design doc, `docs/INVARIANTS.md` and `CLAUDE.md` record D13-R, the corrected catalog count,
   the thirteen deferred types with reasons, and the two relocations (Decisions E and F).

**What P2 explicitly does NOT accept, and must not be read as accepting:** Munawib Stage 2 (§35),
which needs slots, templates, the gate, the workbench, publishing, the board and the tallies — all
P3. *"P2 is complete"* and *"Stage 2 is accepted"* are different claims and only the first is a
developer's to make (design §14 item 27's distinction, applied one phase along).

---

## Next plan

`docs/superpowers/plans/<date>-p2-2-<slug>.md`, written when P2-1 merges. After P2: **P3 — Munawib
Stage 2**, which brings `slots`, `coverage_templates`, `conditions`, `schedules`, `assignments`, the
gate screen with drag ranking, the workbench with live hints, `services/engine` with its first
caller, and the five deferred P3 types. P3 also owes the render-site half of Task 7's CG-04 preview
pair (rulings 41/49).
