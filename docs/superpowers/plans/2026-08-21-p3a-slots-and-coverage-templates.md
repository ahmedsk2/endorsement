# P3a — The duty vocabulary: slots, kinds, coverage templates, call eligibility

The first slice of P3. It builds everything that must exist **before a duty can be stored**, and
nothing that stores one.

Companion document: `2026-08-21-p3-scope-and-decomposition.md` holds the phase's scope, the slicing
argument and the owner-decision table (`D-P3-01` … `D-P3-34`). This plan cites those decisions by id
and does not re-argue them.

---

## What this plan is, and is not

**It IS** the department's call structure as CONFIGURATION: the slot-kind vocabulary, the slots
themselves, SL-03's coverage templates, MR-04's call-eligibility derivation, ST-03's two launch
presets, and the projection that turns a stored slot into the frozen CG-10 `Slot` P2 authored.

**It is NOT** a schedule. No `schedules` table, no `assignments` table, no placement, no workbench,
no publish. The absence is asserted at source level, not merely left unimplemented (Task 12): *"we
have not built it"* and *"we have decided not to build it"* are different states, and only the
second is safe to build P3b on top of.

**It is NOT** a rule. No `conditions` table, no evaluation, no `@engine` import in the application
bundle. The one place this slice touches the engine is the projection (Task 5) and the demo command
(Task 13), both of which hand the engine data and read nothing back but its own output.

**It does NOT re-author the duty shape.** `packages/engine/src/duty/interval.ts` fixed it and
`packages/engine/src/contract/schema.ts` closes it with `additionalProperties: false`. Every table
column below is either a projection into that shape or a server-side fact that never leaves the
server, and Task 5 exists to make the difference checkable rather than asserted.

---

## Binding requirement IDs

| ID | What P3a owes it |
|---|---|
| **SL-01** | Slot: name, kind, time window (may cross midnight), cadence daily\|weekly, days it runs with day-type overrides, covered unit (optional), counts-toward-hours flag, tally key. |
| **SL-02** | Call structure as configuration — a single 24-hour call, or a split day/night pair with department-set boundaries. Post-duty semantics follow the windows (P2 already implements the following; P3a only has to store windows that make it correct). |
| **SL-03** | Coverage template per slot per day type: ordered level requirements with min and target. |
| **SL-04** | Weekly-cadence slots share the model. |
| **SL-05** | Slot and template edits reach future drafts only. P3a owes the half that is expressible here — an edit never rewrites history in place — and the other half lands with the publish snapshot in P3d (D-P3-21). |
| **MR-04** | The master rota drives on-call eligibility automatically, with per-unit and per-person overrides. |
| **ST-03** | The two launch presets, *"Residency on-call (split day/night)"* and *"Residency on-call (24-hour)"* — the slot and coverage-template half. |
| **ST-01 / ST-02** | The setup wizard's *"slots and coverage templates from a preset"* step, revisitable in Settings. |
| **UN-04** | A slot that stopped running is deactivated, never deleted. |
| **NF-01 / NF-02** | The benchmark is re-pointed at NF-02's own scale figures and its invalid condition class is fixed (Task 2). |

Requirements this slice deliberately does **not** advance: SL-03's *"optional composition"* clause
(Decision F below), CG-\*, WB-\*, PU-\*, MC-\*, WO-\*, PS-\*, TL-\*, EX-\*, L1.

---

## Findings — what is actually in the tree

Every claim here was run, not remembered.

1. **None of P3's tables exists.** `ls database/migrations/` ends at
   `2026_08_16_120002_create_demo_rows_table.php`. No `slots`, `coverage_*`, `conditions`,
   `schedules`, `assignments`, snapshot or `feeds` migration.

2. **SL-01's vocabulary is stored nowhere.**
   `grep -rn "night_call\|day_call\|full_24h_call\|weekly_duty\|counts_hours\|tally_key\|coverage_template"
   app/ database/ resources/js/ routes/` returns nothing. The engine's own fixture corpus uses
   `call`, `backup`, `home-call`, `clinic-cover`, `admin` — disjoint from SL-01's five except
   `backup` — which is the corpus's business and not a vocabulary.

3. **The engine `Slot` is ten members, eight required, closed.**
   `key, kind, unitKey?, cadence, spanDays, startMinute, endMinute, crossesMidnight, countsHours,
   tallyKey?`, with `startMinute` bounded 0..1439, `endMinute` 0..1440, `spanDays` minimum 1, and
   `additionalProperties: false`. `Duty` is exactly `{personKey, date, slotKey}`, also closed.

4. **The table is a SUPERSET of the engine `Slot` on both sides.** AR-05's sketch carries `name`,
   `days[]` and `active`, none of which is an engine member; the engine requires `spanDays` and
   `crossesMidnight`, neither of which appears in AR-05 (`spanDays` is P2's own owner-decision-E
   addition, which AR-05 predates). So P3a owns a projector, and three SL-01 facts never reach the
   engine at all.

5. **`assertSlot()` already refuses two shapes**, so `cadence` and `spanDays` are not independent
   columns: `cadence === 'daily' && spanDays !== 1` throws, and `cadence === 'weekly' &&
   crossesMidnight` throws. A form that lets an administrator set both freely produces rows the
   engine refuses at evaluation time rather than at save time.

6. **`Slot.kind` has zero code branches.** Its only reader anywhere is
   `kindMatches(kind, kinds) { return kinds.length === 0 || kinds.includes(kind); }`
   (`conditions/support.ts`), called from `min_gap`, `max_gap`, `consecutive_max`, `count` and
   `post_duty_exclusion`. `consecutive_max`'s `'nights'` unit is `slot.crossesMidnight`, not a kind
   named night.

7. **A mistyped `kinds` entry is silent.** The package emits exactly twelve `coverage()` skip
   reasons — `carryInSkip`, `fairnessNoDenominatorSkip`, `fairnessNoQuantitySkip`,
   `historyShortOfWindowSkip`, `holidayLookbackSkip`, `holidayNotInHorizonSkip`, `midWindowJoinSkip`,
   `openGapSkip`, `partialWindowSkip`, `unknownDayTypeSkip`, `unknownJoinDateSkip`,
   `wePairingNoOccurrenceSkip` — and none of them concerns an unmatched kind or an unresolvable slot
   key in params. `fairness_distribution` is the one type that reports vocabulary drift, and its
   docblock says why: *"a mistyped quantity [is] the likeliest way this rule ever goes quiet, and a
   quiet rule is indistinguishable from an even schedule."*

8. **`eligibility` — a HARD type — keys its params map on SLOT KEY, and a slot absent from the map is
   unrestricted** (its own schema description says so). Nothing validates a params slot key against
   `context.slots`. Renaming a slot therefore turns a hard rule off with no error anywhere. This is
   the concrete driver behind Decision C.

9. **`units.code` and `levels.code` are editable in place today**, with no cascade and no
   immutability guard (`UnitRequest`, `LevelRequest`, both `Rule::unique(...)->ignore($id)` on an
   update path), while owner decision G fixes condition params on those same codes.

10. **`Slot.unitKey` is read by nothing** under `packages/engine/src/`. Every `unitKey` a condition
    reads is `unitKeyAt(person, date)` — the person's rotation unit — or CG-01's `scope.unitKeys`.

11. **`EvaluationRequest::forPeriod($period, $document)` takes `slots` from a caller-supplied
    document.** That is the seam P3a plugs into: `$document['slots']` becomes the projection of the
    `slots` table. Nothing in `App\Support\Engine` changes.

12. **`DepartmentSetup` already carries P3a's acceptance signal.** Its `later` array holds
    `['key' => 'slots', 'title' => 'Duty slots and coverage templates', 'stage' => 'Stage 2',
    'summary' => '… The tables behind this do not exist yet; when they arrive, the launch presets
    ST-01 names arrive with them.']`, and `DepartmentSetupTest::test_no_step_names_a_slot_a_coverage_template_or_a_condition`
    asserts no rendered step names one. That guard is what P3a retires, and retiring it is the proof
    that ST-03 landed.

13. **`DepartmentSetup` also carries a wrong stage label.** Its `conditions` entry says
    `'stage' => 'Stage 3'`; §35 puts the gate in Stage 2. The `slots` entry beside it is correct.

14. **MR-04 is asserted absent by a whole-`app/` scan.**
    `RotaAccessTest::test_nothing_in_the_rota_infers_on_call_eligibility` walks
    `File::allFiles(app_path())` for `off_roster`, `offRoster`, `callEligib`, `call_eligib`; a
    narrower twin over `app/Support/Rota/*.php` plus the rota's controllers, form requests and Vue
    screens adds `eligib`, `on_call`, `onCall`, `callRoster`. `units` shipped `training_rotation`,
    `call_target`, `clinic_owner`, `aliases`, `name2` and **no** `off_roster`.

15. **The NF-01 benchmark's condition rows are not valid contract instances.**
    `packages/engine/bin/corpus.mjs` defaults `klass = 'soft-top'`; the schema enum is
    `['hard','soft']`. Confirmed against the shipped validator:
    `validate('Condition', {…, class:'soft-top', …})` returns
    `[{"path":"#/Condition/class","message":"\"soft-top\" is not one of [\"hard\",\"soft\"]"}]`.
    It escapes because harness Phase 1 validates through `bin/evaluate.mjs` while Phase 2 imports the
    bundle and calls `engine.evaluate()` directly. `CLASS_ORDER['soft-top']` is `undefined`, so
    `comparePrecedence()` would return `NaN` on those violations.

16. **`days[].periodKey` has zero readers.** `grep -rn periodKey packages/engine/src/` returns three
    lines: two in `contract/schema.ts`, one in `contract/types.ts`. All declaration.

17. **Today's NF-01 reading on this machine**, `npm run build:engine && node packages/engine/bin/corpus.mjs`:
    *93 duties (20 people × 3 slots × 31 days), 22 types active, 299 violations from 14 conditions;
    evaluate() median 24.3 ms (best 21.8, worst 26.3) against a 100 ms budget — MET; evaluate() +
    coverage() median 44.1 ms.* `docs/INVARIANTS.md` records ~56 ms for the same case.

18. **The structure CRUD precedent is uniform.** `UnitController` and `LevelController` each expose
    `index / store / update / setActive` and no `destroy`, routed under `admin/structure` behind
    `cap:structure.manage`, with `Admin/Units.vue` and `Admin/Levels.vue` as their screens.

---

## Decisions

### Decision A — the `slots` table, column by column

```
slots
  id
  institution_id      nullable, constrained, nullOnDelete   PROVENANCE ONLY (D11)
  code                string, UNIQUE                        -> Slot.key ; frozen once referenced
  name                string                                screen only; never leaves the server
  slot_kind_id        constrained -> slot_kinds, restrict    -> Slot.kind (projects the kind's code)
  unit_id             nullable, constrained, restrict        -> Slot.unitKey (projects units.code)
  cadence             string(6)  'daily' | 'weekly'         -> Slot.cadence
  span_days           unsignedSmallInteger, default 1        -> Slot.spanDays
  start_minute        unsignedSmallInteger 0..1439           -> Slot.startMinute
  end_minute          unsignedSmallInteger 0..1440           -> Slot.endMinute
  crosses_midnight    boolean, default false                 -> Slot.crossesMidnight
  counts_hours        boolean, default true                  -> Slot.countsHours
  tally_key           string, nullable                       -> Slot.tallyKey
  runs_on_weekdays    string(7), default '1234567'           SERVER ONLY (Decision B)
  runs_on_day_types   json, nullable                         SERVER ONLY (Decision B)
  active              boolean, default true                  SERVER ONLY (UN-04)
  display_order       unsignedSmallInteger                   SERVER ONLY
  timestamps
```

Eight columns project into the engine's eight required members plus its two optional ones; six never
leave the server. `institution_id` is provenance and **no index leads with it** — the `clinics`
migration states the rule and `InstitutionProvenanceTest` enforces it with no allow-list. Indexes:
`(active, cadence)` for the projection query, `(unit_id)` for MC/WO grouping in P3e.

`code` is separate from `name` because that is what makes the engine key stable under a rename —
`units` and `levels` already have that split and P3a extends its guarantee (Decision C).

### Decision B — SL-01's "days it runs with day-type overrides"

Per **D-P3-02**: a weekday set plus an optional day-TYPE override map, where an override REPLACES the
weekday answer and `Calendar::dayType()`'s existing precedence (HOL beats WE beats WD) decides which
override applies.

`runs_on_weekdays` is a seven-character string of ISO weekday digits (Monday = 1 … Sunday = 7),
matching `Calendar`'s vocabulary exactly — no second weekday encoding anywhere. `runs_on_day_types`
is a nullable map from `'WD'|'WE'|'HOL'` to a boolean; an absent key means *"the weekday set decides"*
and a present key overrides it. **The one shape this must express and the naïve readings cannot** is
*"runs Sun–Thu but not on holidays"*, which is what a call roster actually looks like.

Neither column reaches the engine. The skeleton is DERIVED from them in P3b, never materialised
(D-P3-07), so there is no second definition of *which slots run on this date*.

### Decision C — `code` is frozen once referenced, across all four vocabularies

Per **D-P3-04**. A `code` on `slots`, `slot_kinds`, `units` or `levels` becomes immutable the moment
anything references it — a stored condition's params, a coverage requirement, or (from P3b) an
assignment. `name` stays freely editable. This is a change to two SHIPPED screens, and it is in this
slice rather than deferred because P3a is the slice that turns the hazard from theoretical into a
hard rule silently switching itself off (finding 8).

The refusal is flashed under a key the receiving screen renders, and the two halves are asserted
TOGETHER — rulings 41 and 49, three prior instances in two slices.

### Decision D — SL-01's kind is a table

Per **D-P3-01**. `slot_kinds(id, institution_id, code UNIQUE, name, display_order, active,
timestamps)`, seeded with SL-01's five (`night_call`, `day_call`, `full_24h_call`, `weekly_duty`,
`backup`), administrator-editable exactly as the level ladder is, with `display_order` gapped by ten
so a sixth can be inserted without renumbering — the `levels` precedent.

`shift` is NOT seeded. SL-01 marks it `[Stage 5]`, `forbidden_transition` is registered
`implemented: false` for the same reason, and seeding a kind whose slots nothing can evaluate is a
control that appears to do something.

### Decision E — the projection is a class, and its output is validated against the shipped schema

`App\Support\Schedule\SlotProjection::forDepartment()` returns the `slots` array
`EvaluationRequest::forPeriod()` consumes. It is the ONLY expression in `app/` that names an engine
`Slot` member. It is a reader — it writes nothing — and it lives in `App\Support\Schedule\`, which
becomes P3's namespace and is scanned by the same glob-shaped guards `App\Support\Engine\` has.

Its test does not assert the array's keys by hand. It runs the projection's output through
`packages/engine`'s own `validate('Slot', …)` via the Node entrypoint, so a contract change breaks the
PHP test rather than the deployment. That is the only way to keep a projection honest against a shape
frozen in another language.

### Decision F — SL-03's "optional composition" is a CONDITION, not a template column

SL-03's example splits into two grammars. *"NICU night = one senior (PGY-3/4) + one junior
(PGY-1/2)"* is two requirement rows, each naming a level set with `minimum: 1`. *"Ward night = three
across PGY-1–3, at least one PGY-2+"* is one requirement row (levels R1–R3, `minimum: 3`) **plus** a
composition constraint, and `composition` is a shipped CG-07 type with `implemented: true`.

So the template stores min and target per level set, and composition is expressed as a `conditions`
row in P3c. P3a asserts the absence of a composition column rather than leaving the question open.
Recorded because it is a decision, not an omission: a template column would be `composition`'s
second implementation, in PHP, compared against nothing — §4.1's exact prohibition.

### Decision G — two coverage tables, not three, and the word "template" is UI vocabulary

```
coverage_requirements
  id, institution_id (provenance), slot_id (constrained, restrict),
  day_type string(3) 'WD'|'WE'|'HOL', display_order, minimum, target nullable,
  active, timestamps
  index (slot_id, day_type)                    <- what the skeleton query filters on
  no unique key: two requirement rows per (slot, day_type) is the "senior + junior" shape

coverage_requirement_levels
  id, coverage_requirement_id (constrained, cascade), level_id (constrained, restrict)
  unique (coverage_requirement_id, level_id)
  NO institution_id — a pure child does not repeat its parent's provenance
  (the person_levels / unit_field_definitions / clinic_attendees precedent)
```

A *template* is the derived grouping of requirement rows sharing a `(slot_id, day_type)`. Giving it
its own table would buy an id nothing needs and an `active` flag duplicating the child's.

**`ON DELETE cascade` on the child is deliberate and is the one place this slice hard-deletes.** A
requirement's level list is not history — it is the requirement's own body — and a level that is
retired is `active = false` with `restrictOnDelete` from this table, so no clinical or historical row
is reachable through the cascade.

### Decision H — three different things are called "coverage", and this slice fixes the vocabulary

| Term | Meaning | Where |
|---|---|---|
| `coverage()` | which condition WINDOWS were evaluated or skipped, and why | `packages/engine/src/coverage.ts` — shipped |
| **coverage requirement / template** | SL-03's per-slot per-day-type level minimum | `App\Support\Schedule\CoverageTemplate` — P3a |
| **morning presence** | §19's who is physically on the unit this morning | `App\Support\Schedule\MorningPresence` — P3e |

A P3 class named `Coverage*` for the third meaning would collide with the first in review and in
conversation. The third is named `MorningPresence` from the start. Stated here because P3a is where
the vocabulary is set and P3e is where it would otherwise be got wrong.

### Decision I — MR-04's columns are named for what they are, and the guard is REPOINTED

Per **D-P3-31**. Two columns:

- `units.on_call_roster` — boolean, default true. A unit whose rotators are on the call roster.
  Positive rather than `off_roster`: a positive default reads correctly on a screen and does not
  require an administrator to reason about a double negative.
- `people.call_participation` — string, `'auto' | 'always' | 'never'`, default `'auto'`. MR-04's
  per-person include/exclude override. `auto` derives from the rota.

Neither name contains any of `RotaAccessTest`'s four needles, and **that is not why they were
chosen** — they are better names either way, and Task 8 repoints the guard anyway, because a guard
whose rule has become false is worse than no guard.

The derivation lives in `App\Support\Schedule\CallEligibility` and it is the ONLY file in `app/`
allowed to read either column. That is a single-READER guard, a new shape here, and it is the honest
statement of MR-04's rule: *the rota* must not infer eligibility, so the inference lives outside the
rota's namespace and only there. It follows the pattern P2 established for `App\Support\Engine\` —
*"both are satisfied by the crossing living in the engine's own namespace and reading those modules'
data"* — and it adds no allow-list entry to any rota guard.

### Decision J — ST-03's presets are STRUCTURE-ONLY

Per **D-P3-13** in the scope document. Both presets ship the SHAPE — how many slots, of which kinds,
crossing midnight or not, which day types get a template row — with every numeric parameter awaited
and stated as awaited. `preset:scfhs`'s precedent is exactly this: P2 shipped it empty and said so,
rather than inventing numbers.

Installing a preset is an explicit administrator action that CREATES rows through the one writer and
never re-asserts. A rename survives `db:seed --force` — the `levels` ladder's guarantee, applied here.

---

## Inherited invariants — stated as things a task must not break

Read `docs/INVARIANTS.md` §Engine, §Rota, §Calendar and dates, and §Provisioning and institutions
before starting. These are the ones this slice can plausibly break.

1. **The duty shape is P2's and is not re-authored.** No column below changes an engine member's
   meaning. The two `assertSlot()` refusals (finding 5) are enforced at the FormRequest so an
   administrator gets a 422 with a sentence rather than an engine `RangeError` at evaluation time —
   which is a validation of the SAME rule, not a second definition of it, because the projection test
   (Task 5) round-trips through the engine's own validator.

2. **`App\Support\Calendar` is the ONLY date converter**, and `resources/js` and `packages/` compute
   no dates. `CalendarIsTheOnlyConverterTest` scans `app/`, `routes/` and `database/`, and P2 extended
   the date guard to `packages/` with an empty allow-list in both directions. `runs_on_weekdays` uses
   `Calendar`'s ISO vocabulary and no screen derives a weekday name.

3. **One writer per table, guarded at source level, house style.** Whole match set (all six writer
   shapes: static `create`/`insert`/`updateOrCreate`/`firstOrCreate`/`upsert`, `DB::table(` in both
   quote styles, property-assignment-then-`save()` with the load-bearing trailing space, relation
   writes, `Model::query()->create(`, and `$model->update([`), an allow-list with a reason per entry,
   a staleness twin, and every needle **proved by planting a file of exactly its shape**. Ruling 66's
   sweep is the standing instruction: `PersonActiveHasOneWriterTest` had the tidiest list in the suite
   and named 4 probes of 22.

4. **`institution_id` is provenance, never a query filter, and no index leads with it** (D11). The
   mistake has been made twice by plan text and caught twice empirically. All four new tables carry
   the `clinics` shape.

5. **No PHI and no names in `audit_log.detail`** — the column is `detail`, singular; ids, field names
   and counts only. A slot audit row carries `slot=<id>` and never the slot's name.

6. **LIGHT THEME ONLY.** Any `dark:` utility is a bug (`CompiledCssIsLightOnlyTest`). Semantic
   classes only — `.readout`, `.channel-tag`, `.channel-bar*` — no raw Tailwind palette classes and no
   hex in markup. `TextContrastMeetsAaTest` still applies.

7. **Slot names, kind names and tally keys are PLAIN TEXT and are never purified server-side** — the
   `clinics.name` / `handovers.extra_fields` contract, not the four `SanitizedHtml` clinical fields.
   Every consumer escapes on render: `{{ }}` interpolation and `:value` binding, **never `v-html`**.
   A slot legitimately called `"NICU <> SCBU cover"` must survive.

8. **Adding a `unit_id` column obliges you to answer `App\Support\UnitMerge`.**
   `UnitMergeCoversEveryUnitReferenceTest` derives the obligation from the LIVE schema in both
   directions, so `slots.unit_id` fails the build until `REFERENCES` gains an entry saying what a
   merge does with it. An entry is a decision, not documentation — a table whose answer is *"a merge
   deliberately leaves this"* still belongs in the map, spelled out.

9. **A refusal is flashed under a key the receiving screen renders, and the two halves are asserted
   together** (rulings 41 and 49) — the PHPUnit half for the key, the Vitest half for the render
   site. There is deliberately no source-level guard for this and the measurement is why; do not
   rebuild one without re-measuring.

10. **A validation rule is asked only of the input the chosen MODE reads** (ruling 48), and a form
    seeds from the intersection of what is STORED and what the pickers OFFER. An id with no checkbox
    is an id nobody can untick — relevant to Task 7's level pickers on a requirement row.

11. **Additive, nullable migrations; soft deletes; never retype a column holding real data; rows
    deactivated, never deleted.** P1e used `2026_08_16_*`; P2 added none; **P3 continues after that.**
    This slice's migrations are `2026_08_17_*`.

12. **`tests/fixtures/` is synthetic, permanently.** No real staff list, no real QCH slot names, no
    real numbers, anywhere in this repository at any time.

13. **A passing test must be shown capable of failing.** P2's review found ~31 deliberately-broken
    versions of correct code passing an 800-test suite. The species is always the same: **a claim
    asserted only where it MATCHES and never where it must not.** Every task below names the plant
    that proves its assertion, and the plant must be of the NARROWING, not of the happy path.

14. **Run tests via Bash, not PowerShell** (PowerShell's PATH lacks `openssl`, so backup tests
    self-skip — a false green). Capture the exit code:
    `php artisan test > /tmp/t.log 2>&1; echo "rc=$?"`. Filter every run.

---

## Standing rules for every task

- Failing test first. Watch it go red. Then implement. Then plant the narrowing and watch the
  assertion catch it. Then revert the plant.
- `php artisan test` **and** `npm run build` **and** `npm test` green before every commit, verified by
  exit code, never by a piped tail.
- The tree is deployable after every commit. A half-built control does not merge.
- Every route behind `auth` + a `cap:` capability. Writes are POST/PATCH/DELETE + CSRF.
- Never concatenate SQL. Eloquent and bindings only.
- Batch independent tool calls. Never dump a failing suite into context; re-run the single filter.

---

# Tasks

### Task 1 — the record: correct the stage label, name the vocabulary, open the §14 items

**Files touched.** `app/Support/Setup/DepartmentSetup.php`,
`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md` (§14 items),
`docs/INVARIANTS.md` (new §Schedule section, stub).

**Failing test first.** In `tests/Feature/Setup/DepartmentSetupTest.php`, a new
`test_the_stated_later_items_carry_the_stage_the_spec_assigns_them`: derive each `later` entry's
stage from `docs/munawib/SPEC.md` §35's own deliverable lines rather than hard-coding *"Stage 2"* —
the `catalog-parity.test.ts` discipline, located by header text and never by line number. It goes red
on `conditions` today (`'Stage 3'` against §35's Stage 2 line naming *"the gate with full catalog and
drag ranking"*).

**Implementation.** Change the `conditions` entry to `'stage' => 'Stage 2'`. Add to §14 the four items
this slice opens: Decision F's composition split, Decision H's three-way "coverage" collision,
Decision I's guard repoint, and the `days[].periodKey` binding (Task 2). Open a §Schedule section in
`docs/INVARIANTS.md` holding nothing yet but the namespace statement — later tasks fill it.

**Verify.** `php artisan test --filter DepartmentSetupTest` red before, green after. Plant: change the
`slots` entry to `'Stage 3'` and confirm the new test names it too — a test that only checks the entry
you just fixed is asserted where it matches and nowhere else.

---

### Task 2 — engine housekeeping P3 depends on: the invalid class, the benchmark scale, and `periodKey`

**Files touched.** `packages/engine/bin/corpus.mjs`, `packages/engine/test/contract.test.ts`,
`docs/INVARIANTS.md` (§Engine).

**No application code. No migration.** This is the last change `packages/engine` gets before P3c, and
it is here because P3a's projection (Task 5) is validated against the contract this task pins.

**Failing test first, three of them.**

1. In `contract.test.ts`, assert that **every condition row the NF-01 harness builds validates**. Feed
   the harness's own row builder through `validate('Condition', …)` and expect `[]`. Red today:
   `class: 'soft-top'` returns
   `[{"path":"#/Condition/class","message":"\"soft-top\" is not one of [\"hard\",\"soft\"]"}]`.
2. In `contract.test.ts`, the **`periodKey` binding** (D-P3-14): for any `EvaluationContext`,
   `days[i].periodKey` is non-null exactly when `days[i].date` falls inside some `periods[j]`, and
   equals that period's `key`. Assert it over the corpus fixtures and over `ContextBuilder`'s output
   (via the entrypoint), not over a hand-built context.
3. In the harness, assert the NF-01 case's own **scale**: at least 60 people and at least 8 slots, so
   the benchmark cannot silently shrink back.

**Implementation.** Change `klass = 'soft-top'` to `klass = 'soft'` in `corpus.mjs:495`. Re-point the
NF-01 world at NF-02's figures — `corpus.mjs` already parameterises everything except the slot list
(`SLOTS`) and `length: 20`; only those two constants and the `eligibility.allowed` map change, and the
two vacuity gates (findings from more than one condition; no active condition reporting
`evaluatedWindows: 0`) are scale-independent and carry over unchanged. Record the new headline
numbers, and **record that they are machine-dependent**: the harness's own docblock already notes 76
and 103 ms minutes apart, `docs/INVARIANTS.md` says ~56 ms, and this machine reads 24.3 ms for the old
case. Correct the INVARIANTS figure to a range with the case named, not a single number.

**Verify.** `npm run build:engine && node packages/engine/bin/corpus.mjs`, rc=0, and the printed
NF-01 line names the new scale. `npm run test:engine` green.

**Plant.** For (1): set one row back to `'soft-top'` and confirm the new test names it — asserting only
the fixed default would be green on the very defect. For (2): shuffle one `days[i].periodKey` to a
neighbouring block's key and confirm red; then set every `periodKey` to `null` and confirm red. Both
plants matter: the field is currently inert, so an invariant asserted only on the happy path proves
nothing. For (3): drop the slot list back to three and confirm red.

---

### Task 3 — `slot_kinds`: the table, the seed, the one writer, the guard

**Files touched.** `database/migrations/2026_08_17_120001_create_slot_kinds_table.php`,
`app/Models/SlotKind.php`, `app/Support/Schedule/SlotVocabulary.php` (the one writer),
`database/seeders/ReferenceSeeder.php`, `database/factories/SlotKindFactory.php`,
`tests/Feature/Build/SlotWritersAreSingularTest.php`, `tests/Feature/Schedule/SlotVocabularyTest.php`.

**Failing test first.** `SlotVocabularyTest::test_the_five_launch_kinds_seed_and_survive_a_reseed`:
seed, rename `night_call`'s `name` to something else, `db:seed --force` again, assert the rename
survives and no sixth row appears — the `levels` ladder's guarantee. Then
`test_shift_is_not_seeded`, asserting `SlotKind::where('code','shift')->exists()` is false, with the
reason in the docblock (SL-01 marks it `[Stage 5]`).

**Implementation.** Table per Decision D, `display_order` 10/20/30/40/50. `SlotVocabulary` is the one
writer of `slot_kinds`; `ReferenceSeeder` calls it. `restrictOnDelete` from `slots.slot_kind_id`, so a
kind in use cannot be removed — retiring is `active = false` (UN-04).

**The guard.** `SlotWritersAreSingularTest` covers `slot_kinds` **and** `slots` (Task 4) in one file —
they share a writer namespace and a reviewer. All six writer shapes plus `find()`-then-`delete()`,
`destroy(` and `truncate(`. Column needles are property-only or `update(`-qualified, per
`ClinicWritersAreSingularTest`'s recorded narrowing: a bare `'code' =>` matches every Inertia
`present()` map and would force the controller onto the allow-list, blinding the guard exactly where
the second writer is born. Allow-list: the writer, plus the two factories with the reason
`ClinicFactory` already carries (a factory writes through `Factory::create()`, which appears nowhere
in its own source, so a needle-match twin would force a fake needle into it).

**Verify.** `php artisan test --filter "SlotVocabularyTest|SlotWritersAreSingularTest"`, rc=0.

**Plant, per needle.** Six throwaway files under `app/`, one per writer shape, each writing
`slot_kinds`; watch the guard name each; revert. Then the two known blind spots explicitly:
`SlotKind::query()->create([...])` (ruling 66 — found by planting exactly that against a green guard)
and `$kind->update(['code' => 'x', 'name' => 'y'])` (ruling 50 — this codebase's house idiom, which
`ClinicWritersAreSingularTest` shipped blind to and which measured green against a plant rewriting six
columns). **Add the staleness twin**: every allow-listed file still exists.

---

### Task 4 — `slots`: the table, the one writer, and the `UnitMerge` answer

**Files touched.** `database/migrations/2026_08_17_120002_create_slots_table.php`,
`app/Models/Slot.php`, `app/Support/Schedule/SlotWriter.php`, `app/Support/UnitMerge.php`,
`database/factories/SlotFactory.php`, `tests/Feature/Build/SlotWritersAreSingularTest.php` (extend),
`tests/Feature/Schedule/SlotWriterTest.php`.

**Failing test first, in this order.**

1. `UnitMergeCoversEveryUnitReferenceTest` goes red **the moment the migration lands**, before any
   writer exists — it derives the obligation from the live schema. That red is the first proof the
   task is real.
2. `SlotWriterTest::test_a_daily_slot_may_not_span_more_than_one_date` and
   `test_a_weekly_slot_may_not_declare_crossing_midnight` — the two `assertSlot()` refusals,
   enforced at the writer and the FormRequest so an administrator gets a 422 rather than a `RangeError`
   at evaluation time.
3. `test_a_window_that_ends_exactly_when_it_starts_is_a_24_hour_call_only_with_the_crossing_flag` —
   `startMinute == endMinute` is legal iff `crossesMidnight`, which is SL-02's single 24-hour call.
4. `test_an_abutting_split_pair_does_not_overlap` — the day slot ending at 20:00 and the night slot
   starting at 20:00 are two slots, and nothing refuses the pair. This is the case that would flag
   *every legal split-call department on every day* if half-open intervals were misread; it belongs in
   the writer's test even though the interval logic is P2's, because the writer is what admits the pair.

**Implementation.** Table per Decision A. `SlotWriter` is the one writer of `slots`; it owns the two
refusals and the code freeze's slot half (Task 6 wires the rest). Add to `UnitMerge::REFERENCES`:

```php
'slots.unit_id' => 'Re-pointed whole. The column is presentation and grouping only (D-P3-05) — '
    .'nothing derives eligibility from it — so there is no unique key to violate and no second '
    .'reading of what a moved slot means.',
```

**Verify.** `php artisan test --filter "SlotWriter|UnitMergeCoversEveryUnitReference|SlotWritersAreSingular"`, rc=0.

**Plant.** Add a `slots.unit_id` entry to `REFERENCES` for a column that does not exist and confirm the
schema-derived guard names it in the OTHER direction too — a map checked one way is half a guard.
Then plant each writer shape against `slots` as in Task 3; the needles differ (`'code' =>` is now a
`slots` column too), so **measure again rather than assuming Task 3's set carries over** — ruling 42.

---

### Task 5 — `SlotProjection`: serialising into the shape P2 froze

**Files touched.** `app/Support/Schedule/SlotProjection.php`,
`tests/Feature/Schedule/SlotProjectionTest.php`,
`tests/Feature/Build/ScheduleNamespaceIsAReaderTest.php`.

This is the load-bearing task of the slice.

**Failing test first.**
`SlotProjectionTest::test_every_projected_slot_validates_against_the_engines_own_schema`: build a
department with one 24-hour call, one split pair, one weekly home-call and one backup; project;
hand each element to `packages/engine`'s `validate('Slot', …)` through the Node entrypoint; assert
`[]` for every one. **Do not hand-write the expected array.** A hand-written expectation is asserted
against the author's memory of the contract, and the contract is frozen in another language.

Second: `test_the_projection_carries_no_column_the_contract_does_not_name` — assert the key set of each
projected element is a SUBSET of the ten engine members. `additionalProperties: false` already refuses
an extra key, so this is the twin that catches the case where the validator is not reached.

Third: `test_a_withheld_optional_member_is_ABSENT_and_never_null` — `unitKey` and `tallyKey` are
optional; a slot with no unit emits no `unitKey` key at all. This is `PersonPresenter`'s discipline
(*"a withheld contact field is ABSENT from the props array, never `null` — the two look identical on
screen and a future consumer would eventually render one as the other"*), and here it is stricter than
taste: `{ "unitKey": null }` fails the schema.

**Implementation.** `SlotProjection::forDepartment()` reads active slots, joins `slot_kinds.code` and
`units.code`, and emits the ten-member array. It converts no dates and holds no rule. It is a READER
and `ScheduleNamespaceIsAReaderTest` — the glob-shaped guard `EngineIsAReaderTest` established for
`App\Support\Engine\*.php`, pointed at `App\Support\Schedule\*.php` — must pass with an allow-list
containing exactly the writers this slice creates (`SlotWriter`, `SlotVocabulary`,
`CoverageTemplateWriter`, `CallEligibility`).

**Verify.** `php artisan test --filter "SlotProjection|ScheduleNamespaceIsAReader"`, rc=0.

**Plant, and this is the important one.** Add an eleventh key (`'name' => $slot->name`) to the
projection and confirm the validator test goes red. Then delete `crossesMidnight` and confirm red.
Then change `startMinute` to 1440 and confirm red. Three plants because the schema refuses in three
different ways — extra key, missing required, out of range — and a test that only ever sees valid
input proves that the validator was called, not that it bites.

---

### Task 6 — `code` is frozen once referenced, on four vocabularies

**Files touched.** `app/Support/Schedule/SlotWriter.php`, `app/Support/Schedule/SlotVocabulary.php`,
`app/Http/Requests/Admin/UnitRequest.php`, `app/Http/Requests/Admin/LevelRequest.php`,
`app/Http/Controllers/Admin/UnitController.php`, `app/Http/Controllers/Admin/LevelController.php`,
the matching Vue screens, `tests/Feature/Schedule/CodeFreezeTest.php`, and a Vitest twin.

**Failing test first.** `CodeFreezeTest`, as a MATRIX over all four vocabularies × (referenced, not
referenced) — `PickerParityTest`'s shape. A spot check on one vocabulary passes for a rule that never
looked at the others, which is finding 9's exact history.

- unreferenced code may be renamed;
- referenced code may not, and the refusal names what references it (a count, never a name);
- `name` may always be renamed;
- a rename of `name` does not touch `code`.

**Implementation.** One predicate, `App\Support\Schedule\CodeFreeze::isReferenced($model)`, per
vocabulary, applied to **both** the FormRequest rule and the writer — because `Rule::exists` runs on
the raw query builder and never sees Eloquent's SoftDeletes global scope, so a predicate written once
as Eloquent and once as raw SQL is two predicates that drift. This is `SignoffPickers`' recorded
discipline, applied to a different question.

**Verify.** `php artisan test --filter CodeFreezeTest`, rc=0. `npm test` green.

**Plant, both halves together (rulings 41 and 49).** The PHPUnit half asserts the refusal is flashed
under a specific key; the Vitest half asserts the receiving screen RENDERS that key. Plant by changing
the flashed key to `code_frozen` in PHP while leaving the screen rendering `errors.code`, and confirm
the Vitest half goes red while the PHPUnit half stays green. That divergence is the whole reason the
pair exists — three prior instances in two slices each looked correct in review and each was a control
that appeared to do nothing.

---

### Task 7 — `coverage_requirements` and their levels

**Files touched.** `database/migrations/2026_08_17_120003_create_coverage_requirement_tables.php`,
`app/Models/CoverageRequirement.php`, `app/Models/CoverageRequirementLevel.php`,
`app/Support/Schedule/CoverageTemplateWriter.php`, `app/Support/Schedule/CoverageTemplate.php` (the
reader), factories, `tests/Feature/Build/CoverageWritersAreSingularTest.php`,
`tests/Feature/Schedule/CoverageTemplateTest.php`.

**Failing test first.**

1. `test_a_requirement_names_at_least_one_level` — a requirement with an empty level set is a
   requirement about nobody. Refused at the writer, not only in the form.
2. `test_the_same_level_cannot_appear_twice_in_one_requirement` — the unique index, asserted
   behaviourally as well, because the writer is what the screen calls.
3. `test_target_may_be_null_and_is_never_below_minimum` — SL-03 gives both, and `target < minimum` is
   a state with no reading.
4. `test_two_requirements_may_share_a_slot_and_day_type` — the "one senior + one junior" shape. This
   is the assertion that stops somebody adding a unique key later.
5. `test_a_requirement_carries_no_composition_column` — Decision F, asserted rather than merely absent.
   Derive the column list from the live schema and assert `composition` is not among it.

**Implementation.** Per Decision G. `CoverageTemplateWriter` is the one writer of both tables;
`CoverageTemplate` is the reader that groups requirements into templates and (from P3b) counts
shortfall — arithmetic only, per D-P3-09.

**The level picker must obey ruling 48 and its client twin.** The form seeds from the intersection of
what is STORED and what the picker OFFERS — an id with no checkbox is an id nobody can untick — and
the write-side rule refuses exactly what the picker never offered, per field, one predicate.

**Verify.** `php artisan test --filter "CoverageTemplate|CoverageWritersAreSingular"`, rc=0.

**Plant.** All six writer shapes against both tables, measured fresh. Then plant the narrowing on (4):
add a unique index on `(slot_id, day_type)` in the migration and confirm the test goes red — a test
that only ever creates one requirement per pair is green on the schema that would break SL-03.

---

### Task 8 — MR-04: the columns, the derivation, and the guard repoint

**Files touched.** `database/migrations/2026_08_17_120004_add_call_roster_flags.php`,
`app/Models/Unit.php`, `app/Models/Person.php`, `app/Support/Schedule/CallEligibility.php`,
`app/Http/Requests/Admin/UnitRequest.php`, `app/Http/Controllers/Admin/PersonController.php`,
`tests/Feature/Rota/RotaAccessTest.php`, `tests/Feature/Build/CallEligibilityHasOneReaderTest.php`,
`tests/Feature/Schedule/CallEligibilityTest.php`.

**This is the most delicate task in the slice.** It rewrites a shipped guard, and a rewrite that
loses a real invariant is indistinguishable from a rewrite that does not.

**Failing test first — the guard's replacement, before the columns exist.**

1. Write `CallEligibilityHasOneReaderTest`: `App\Support\Schedule\CallEligibility` is the ONLY file
   under `app/` that names `on_call_roster`, `onCallRoster`, `call_participation` or
   `callParticipation`. Green today (nothing names them), which is the honest starting point — ruling
   42's *"measured before being bought"*, with an empty allow-list because nothing had to be excused.
2. Strengthen `RotaAccessTest`'s NARROW twin: keep its scope
   (`app/Support/Rota/*.php` + the rota's controllers, form requests and Vue screens) and add the two
   new column names and `CallEligibility` to its needle set, allow-list still empty.
3. `CallEligibilityTest::test_a_person_on_an_off_roster_unit_is_not_eligible_that_day`,
   `test_call_participation_always_overrides_an_off_roster_unit`,
   `test_call_participation_never_overrides_an_on_roster_unit`,
   `test_eligibility_is_DATED_and_follows_the_rota_span` — MR-04 is derived from the master rota, so a
   person mid-rotation changes answer on the span boundary and not on a calendar month.

**Implementation.** Add `units.on_call_roster` (boolean, default true) and `people.call_participation`
(string, default `'auto'`), both additive. `CallEligibility` is the one reader AND the one writer of
both columns; `UnitController` and `PersonController` call it. It reads `master_rota_assignments`
through the rota's existing read surface and writes nothing there.

**Then, and only then, retire the whole-`app/` scan.** Replace
`RotaAccessTest::test_nothing_in_the_rota_infers_on_call_eligibility` with a docblock stating what
changed and why: MR-04 now exists, so *"nothing anywhere infers on-call eligibility"* has become a
FALSE statement and a guard asserting a false rule is worse than none. The rule it becomes is the pair
above — the rota's own namespace stays clean, and the concept has exactly one reader.

**Verify.** `php artisan test --filter "CallEligibility|RotaAccess"`, rc=0.

**Plant — three, and none of them optional.**

1. Plant an eligibility inference of exactly the shape the original guard existed to catch inside
   `app/Support/Rota/RotaGrid.php` — a method reading `on_call_roster` to decide who may be named —
   and confirm the STRENGTHENED narrow twin goes red. If it does not, the repoint has lost the
   invariant and must not merge. This is ruling 42's discipline run in reverse: measure before
   REMOVING a needle.
2. Plant a second reader of `people.call_participation` in `app/Http/Controllers/EndorsementController.php`
   and confirm `CallEligibilityHasOneReaderTest` names it.
3. Plant the staleness case: add a non-existent file to that guard's allow-list and confirm the twin
   refuses it.

---

### Task 9 — the screens: Admin → Structure → Duty slots

**Files touched.** `routes/web.php`, `app/Http/Controllers/Admin/SlotController.php`,
`app/Http/Requests/Admin/SlotRequest.php`, `app/Http/Requests/Admin/SlotKindRequest.php`,
`app/Http/Requests/Admin/CoverageRequirementRequest.php`,
`resources/js/Pages/Admin/Slots.vue`, `resources/js/Pages/Admin/CoverageTemplates.vue`,
`tests/Feature/Schedule/SlotScreenTest.php`, Vitest twins.

**Failing test first.**

1. `test_every_slot_route_is_behind_structure_manage` — derive the route set from the router and
   assert the capability, both directions, so a route added later without one fails.
2. `test_there_is_no_destroy_route` — UN-04, matching `UnitController`, `LevelController`,
   `HolidayController` and `ClinicController`, none of which has one.
3. `test_a_slot_name_containing_angle_brackets_survives_a_round_trip` — plain text, escaped on render,
   never purified. The Vitest twin asserts the render site uses `{{ }}` and not `v-html`.
4. `test_the_query_count_is_bounded` — the index screen's query count does not grow with the slot
   count. P1d asserted this for the rota grid; the same discipline applies.

**Implementation.** `index / store / update / setActive` and no `destroy`, under `admin/structure`,
`cap:structure.manage` (D-P3-30 — DEFINING is structure). The kinds list is edited inline on the same
screen; the coverage template grid is its own screen because it is a matrix (slot × day type) and
does not fit beside a form.

The window input is **minutes**, presented as a time control and stored as `start_minute` /
`end_minute`; `crosses_midnight` is derived from the pair and offered as a read-back rather than a
checkbox the administrator can contradict. `resources/js` computes no dates — the day-type labels and
weekday names come from the existing `Calendar` vocabulary props.

**Light theme only, semantic classes only.** `CompiledCssIsLightOnlyTest` and
`TextContrastMeetsAaTest` must stay green.

**Verify.** `php artisan test --filter SlotScreen`, `npm test`, `npm run build`, all rc=0.

**Plant.** Remove `cap:structure.manage` from one route and confirm (1) names it — a capability test
that lists the routes it expects is green on a route it has never heard of, so the derivation must run
from the router in BOTH directions. For (3): change the render site to `v-html` and confirm the Vitest
twin goes red.

---

### Task 10 — ST-03's two launch presets, structure-only

**Files touched.** `app/Support/Schedule/SlotPresets.php`,
`app/Http/Controllers/Admin/SlotController.php` (an install action),
`tests/Feature/Schedule/SlotPresetsTest.php`.

**Failing test first.**

1. `test_installing_a_preset_creates_rows_through_the_one_writer` — assert via the writer guard's own
   allow-list that `SlotPresets` is NOT a second writer; it calls `SlotWriter`.
2. `test_every_preset_parameter_the_owner_has_not_supplied_is_STATED_as_awaited` — a preset that
   silently ships an invented night-call window is worse than one that ships none. Assert each awaited
   parameter appears in the install summary the screen renders.
3. `test_installing_twice_does_not_duplicate` — install is explicit and idempotent by code.
4. `test_the_split_preset_produces_an_abutting_pair_and_the_24_hour_preset_produces_one_crossing_slot`
   — the two presets differ in exactly the way SL-02 says they do, and this is the assertion that
   catches a preset that produces two structurally identical slot sets.

**Implementation.** Per Decision J. Structure only: kinds, cadences, crossing flags, which day types
get a requirement row, and `minimum` placeholders **stated as awaited** rather than defaulted to a
number. `preset:scfhs`'s precedent — P2 shipped an empty preset that says it is empty rather than
inventing values.

**Verify.** `php artisan test --filter SlotPresets`, rc=0.

**Plant.** Give one awaited parameter a plausible default (say a 20:00 night-call start) and confirm
(2) goes red. A preset test that only checks the parameters that WERE supplied is asserted where it
matches and never where it must not — the species P2's review found 31 times.

---

### Task 11 — the setup checklist: `slots` becomes a step

**Files touched.** `app/Support/Setup/DepartmentSetup.php`,
`tests/Feature/Setup/DepartmentSetupTest.php`.

**Failing test first.** `test_the_slots_step_is_rendered_and_reachable`: the `slots` entry moves out of
`later` and into `steps` with a real `route`, a derived `done`, and a summary counting active slots.
Red today.

**Implementation.** Move the entry. `done` derives — the checklist holds no state anywhere, which is
its whole design. **Retire
`test_no_step_names_a_slot_a_coverage_template_or_a_condition`**, and replace it with the narrower
truth: no step names a CONDITION (that table is P3c). Its docblock records why the slot and coverage
halves were removed and when.

**Verify.** `php artisan test --filter DepartmentSetupTest`, rc=0. This is P3a's acceptance signal:
the guard that said *"ST-03 cannot ship in any subset"* no longer applies to the slot half.

**Plant.** Add a step whose summary names *"condition"* and confirm the replacement guard names it. A
guard narrowed from three needles to one must be proved to still bite on the one it kept.

---

### Task 12 — assert the ABSENCE of the next slice

**Files touched.** `tests/Feature/Build/ScheduleSubstrateIsAbsentTest.php`.

**Failing test first.** It cannot fail today, which is the point — it is written to be planted against.

Three assertions:

1. **No schedule table exists.** Derive the table list from the LIVE schema and assert
   `schedules`, `assignments`, `schedule_moves`, `conditions`, `ignored_warnings`,
   `coverage_overrides` and `feeds` are absent. Schema-derived in both directions, the
   `UnitMergeCoversEveryUnitReferenceTest` shape.
2. **Nothing in `app/` places a duty.** Needle `personKey`, `slotKey`, `'duties'` and `dutyInterval`
   over `app/`, `routes/` and `database/` with comments stripped through
   `Tests\Support\SourceScanner::withoutComments()` — the roots `CalendarIsTheOnlyConverterTest` and
   `RulesLiveOnlyInTheEngineTest` both scan, for their recorded reason. Allow-list:
   `App\Support\Schedule\SlotProjection` (it names `slotKey` in a docblock only — hence the
   comment-stripping) and `App\Support\Engine\EvaluationRequest` (P2's, and it reads duties from a
   caller-supplied document rather than placing one).
3. **No `@engine` import in the application bundle.** `vite.config.js:32` wires the alias and
   `tests/js/EngineAlias.test.js` asserts it resolves with nothing using it. P3a keeps that true;
   NF-03 makes the first import a deliberate, reviewable moment that must be a dynamic import inside
   the workbench route, never a top-level one — `packages/engine/dist/engine.mjs` is 276 KB
   unminified and ~94 KB / ~29 KB gzipped minified, in front of readers who never open a workbench.

**Verify.** `php artisan test --filter ScheduleSubstrateIsAbsent`, rc=0.

**Plant, all three.** Create a throwaway migration for `schedules`; a throwaway
`app/Support/FakePlacement.php` writing `['personKey' => …, 'slotKey' => …]`; and a top-level
`import { evaluate } from '@engine'` in `resources/js/app.js`. Confirm each is named. Revert all three.
An absence assertion nobody has watched fail is a comment.

---

### Task 13 — the demoable artifact: the engine evaluates the department's REAL slots

**Files touched.** `app/Console/Commands/EngineEvaluate.php`,
`tests/Feature/Console/EngineEvaluateTest.php`.

**Failing test first.** `test_the_command_reads_slots_from_the_database_when_the_document_omits_them`:
today `EvaluationRequest::forPeriod($period, $document)` reads `$document['slots']`, so every demo
requires a hand-written slot list — which is a second definition of the department's call structure,
in a file, beside the table that now holds it. Assert the command evaluates with a document carrying
duties and conditions but no `slots` key, and that the slot set it used matches
`SlotProjection::forDepartment()` exactly.

Second: `test_a_document_that_supplies_slots_is_still_honoured_and_says_so` — the fixture path stays
open for corpus work, and the command PRINTS which source it used. A command that silently prefers one
source over the other is the *"control that appears to do something"* shape.

**Implementation.** Default `$document['slots']` to `SlotProjection::forDepartment()` when the key is
absent. Nothing else changes: the command still refuses to run in production, still naming
`services/engine` as the server runtime (owner decision Y, P3d).

**Verify.** Configure a department with a split day/night pair and a weekly home call, then:
`php artisan engine:evaluate <period> <document.json>; echo "rc=$?"`. The printed summary names the
real slots. **This is the slice's demo** — the department's own call structure reaching the engine
end-to-end, with no schedule, no rule and no hand-written slot list anywhere.

**Plant.** Supply a document whose `slots` key contradicts the database and confirm the command reports
which one it used. Then delete a slot from the database and confirm the evaluation changes — a demo
that produces the same output whether or not it read the table has proved nothing.

---

## Definition of done — P3a

**Green, by exit code, not by a piped tail.**

```
php artisan test > /tmp/t.log 2>&1; echo "rc=$?"     # rc=0
npm run build   > /tmp/b.log 2>&1; echo "rc=$?"      # rc=0
npm test        > /tmp/v.log 2>&1; echo "rc=$?"      # rc=0
npm run test:engine; echo "rc=$?"                    # rc=0
node packages/engine/bin/corpus.mjs; echo "rc=$?"    # rc=0, NF-01 at NF-02's scale
```

**Behavioural.**

1. An administrator configures a real call structure from Admin → Structure → Duty slots: kinds, slots
   with windows in minutes, a split day/night pair, a 24-hour call, a weekly home call, and coverage
   requirements per slot per day type.
2. Both ST-03 launch presets install, structure-only, with every awaited parameter stated as awaited.
3. `/admin/setup` renders the `slots` step, derived, tickable, with no stored state.
4. `php artisan engine:evaluate` evaluates against the department's real slots with no hand-written
   slot list.

**Structural.**

5. Four new tables, all `2026_08_17_*`, all additive, all with `institution_id` as nullable provenance
   and **no index leading with it**; `InstitutionProvenanceTest` green with no new allow-list entry.
6. One writer per table, each guard proved by planting every needle it names, each with a staleness
   twin, each with the two known blind spots (`Model::query()->create(` and `$model->update([`)
   explicitly covered and explicitly measured.
7. `UnitMerge::REFERENCES` answers `slots.unit_id`;
   `UnitMergeCoversEveryUnitReferenceTest` green in both directions.
8. `SlotProjection` validates against `packages/engine`'s own JSON Schema, proved by three plants
   (extra key, missing required member, out-of-range value).
9. `CallEligibility` is the one reader and one writer of MR-04's two columns; the repointed rota guard
   proved red on a plant inside `app/Support/Rota/`.
10. `days[].periodKey` bound by a contract invariant, proved red on a wrong key and on a null.
11. `corpus.mjs` produces valid contract instances and measures at NF-02's stated scale.
12. `ScheduleSubstrateIsAbsentTest` green, and each of its three assertions watched red on a plant.

**Not done, and stated:** no schedule, no assignment, no condition, no evaluation in the browser, no
publish. SL-05's other half — a slot edit never reaching published history — waits for the publish
snapshot in P3d (D-P3-21), and is recorded here as owed rather than met.

---

## Amendments

*(Appended as the slice runs. Every correction to this plan lands here rather than being edited into
the text above, following the P1e and P2 convention — a plan quietly rewritten to match what was built
records nothing.)*
