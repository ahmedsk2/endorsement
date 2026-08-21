# P3 — Munawib Stage 2: scope and decomposition

**This document is scope, not execution.** It names every requirement P3 owns, the dependency
graph behind them, the five slices and the seam that produces them, what each slice can be
demonstrated doing, what is scoped OUT and why, and every owner decision the phase needs with a
recommended default. The FIRST slice is planned in executable detail in a separate document,
`2026-08-21-p3a-slots-and-coverage-templates.md`, following the P0a–P1e–P2 convention: each
sub-plan is written when its predecessor merges.

P3 is the largest phase in the programme. Planning it as one executable document would produce a
plan nobody finishes reading and a task list stale by its third task. What this document is for is
the decisions that are cheap now and structural later — the schema keys, the seam, the capability
set, and the eleven questions that must reach the owner before a screen commits to an answer.

---

## What this plan is, and is not

**It IS** the phase-level record: requirement ownership, the slicing argument, the scoped-out list
with each item's blocking phase named, and the owner-decision table.

**It is NOT** a task list. No file paths, no test names, no acceptance criteria — those live in the
per-slice plans.

**It is NOT** a re-authoring of the duty shape. P2 fixed that in `packages/engine/src/duty/` and
`packages/engine/src/contract/schema.ts`, and P3's tables serialise INTO it. Every schema proposal
below is checked against the frozen shape, and where P3's table needs a column the contract does not
carry, that is stated as a projection rather than smuggled in as a contract change.

**It corrects four claims** made in the reconnaissance that preceded it, and one made by the brief
that commissioned it. Those are collected under *Corrections* at the end, with the tree evidence,
because a plan that inherits a wrong fact ships it.

---

## The phase table's P3 row, and the four things it does not name

The design doc's P3 row reads:

> Slots, call windows, coverage templates, conditions gate with drag ranking, draft workbench with
> live hints, trackers, undo ≥30, unfilled lens, publish + archive, morning coverage, who's-on-call
> board, personal pages, tallies, exports. **L1 and the §9.1 share-token feed land here.**

`docs/munawib/SPEC.md` §35's Stage 2 line is longer, and the difference is not decorative:

> **Stage 2 — On-call & morning coverage** (slots incl. 24-h calls, templates, the gate with full
> catalog and drag ranking, workbench with hints/pickers/trackers/undo/unfilled lens,
> publish+archive+hard-block, morning coverage with overrides and pull pool, who's-on-call board,
> personal pages, basic tallies, exports, **UX-07 prototype gate, QA-05**).

Four items belong to P3 and are absent from the phase table's row:

1. **UX-07's clickable prototype gate.** §32: *"A clickable prototype of workbench + hints + picker
   flows is a Stage-2 gate before full build-out."* It precedes the hint work, it does not follow it.
2. **QA-05's security review.** §33: threat model, rules audit, PII scan of built output, token
   handling — *"before any real names enter the system."* For this deployment the binding moment is
   the share-token feed, which is P3's first content route outside the login wall.
3. **ST-03's two launch presets.** §1.2 of the design doc parks them *"in P2/P3"* because both are
   slot and coverage-template presets and neither table existed. P2 shipped CONDITION bundles only
   (`preset:acgme`, `preset:residency`, `preset:scfhs`, as package data). The slot half is P3's.
4. **MR-04's eligibility derivation.** Design §14 item 18: *"MR-04's eligibility derivation is
   unbuilt… *The master rota drives on-call eligibility automatically* is Stage 2."* It is asserted
   ABSENT today by a guard that scans every file under `app/`, so building it is not merely adding
   code — it requires rewriting a shipped guard, which is the single most delicate act in P3a.

A gate omitted from a phase table is a gate nobody runs. All four are placed in the slicing below.

---

## Binding requirement IDs

Everything in this list is P3's. Requirements NOT in it that a reader might expect are in *Scoped
out* further down, each with the phase that owns it.

### §14 Slots — SL-01, SL-02, SL-03, SL-04, SL-05
Slot definition (name, kind, window, cadence, days-it-runs, covered unit, counts-hours, tally key);
call structure as configuration; coverage templates per slot per day type; weekly-cadence slots;
and the rule that slot/template edits reach future drafts and never published history.

### §15 Conditions gate — CG-01, CG-02, CG-03, CG-04 (screen), CG-05, CG-06, CG-08 (install)
The gate LIST with class/scope/active/source columns; drag ranking; the never-retroactive rule; the
plain-language preview rendered on screen (P2 built the generator); hard blocking with the per-department
warn-only relaxation; the ignored-warnings ledger; installing a CG-08 preset bundle into rows.
**CG-07's catalog and CG-09's builder are not P3's** — the first shipped in P2, the second is Stage 4.

### §16 Workbench — WB-01 … WB-08
Skeleton from period + templates; Day / Full grid / Unfilled lens plus the weekly panel; live hints;
fitness-ordered pickers; per-day and per-person trackers; the verb set with undo/redo ≥ 30 and the
move-history strip; targeted ignore of a warning; draft coverage preview.

### §17 Automation — nothing. AU-* is P4.
Named here only because §35's Stage 2 acceptance (*"one full real month scheduled manually at
prototype parity"*) is what P3 is for, and it is a MANUAL month by construction.

### §18 Publishing — PU-01, PU-03
Archive-then-promote, and the publish dialog summarising outstanding warnings and ignored items.
**PU-02 is not P3's** (see *Scoped out*).

### §19 Morning coverage — MC-01, MC-02, MC-03, MC-04
Presence derived per unit per morning; the pull pool; manual overrides; the coverage board.

### §20 Who's-on-call — WO-01, WO-02
The board of current slots and people; the viewer-access policy question WO-02 raises.

### §21 Personal pages — PS-01
My Schedule, including CL-04's clinic rows. **PS-02's ICS feed is P4.**

### §22 Tallies — TL-01
Per-person counters per period and YTD; schedulers see all, residents see own.

### §27 Exports — EX-01 (partial), EX-02
Print/PDF of the grid; the login-gating of contact-bearing exports. EX-01's xlsx, Word and
standalone-HTML clauses are owner decisions, not developer ones — see the decision table.

### §13 Clinics — CL-03 (row + screen half), CL-04
CL-03's PREDICATE shipped in P2 as `clinic_conflict` with `implemented: true`. What P3 owes is the
`conditions` row defaulted ON and the gate rendering it. CL-04 (clinic sessions on personal pages
and the workbench) is wholly P3's.

### §8 Cross-module link — L1
`OnCallDirectory::forUnitAt(unit, instant)`, and endorsement's Endorsed-By/To pickers defaulting and
scoping to it within D9's per-field rules.

### §9.1 — the share-token feed
The `feeds` table and its department/period token scope. PS-02's per-person ICS token and IN-01's
JSON feed reuse the table in P4 and P5 and are not built here.

### The four unnamed items
ST-03's two launch presets (slot/coverage half); MR-04's eligibility derivation; UX-07's prototype
gate; QA-05's security review.

### Non-functional
NF-01 (hint latency), NF-02 (scale), NF-03 (code-splitting mandatory, FMP < 2.5 s p75 on 4G),
UX-03, UX-05, D7, D9, D10, D11, AR-07.

---

## The dependency graph

Nine of design §6.3's unbuilt tables land in P3. **None of them exists**: `ls database/migrations/`
ends at `2026_08_16_120002_create_demo_rows_table.php`, and there is no migration for `slots`,
`coverage_templates`, `conditions`, `schedules`, `assignments`, `ignored_warnings`,
`coverage_overrides`, the publish snapshot, or `feeds`. Nor does SL-01's kind vocabulary exist:
`grep -rn "night_call\|day_call\|full_24h_call\|weekly_duty\|counts_hours\|tally_key\|coverage_template"
app/ database/ resources/js/ routes/` returns nothing.

There are exactly **two roots**, and they are genuinely parallel:

```
        SLOTS                                   CONDITIONS
  (SL-01..05, kinds, coverage_templates)   (CG-01..06, CG-08 install)
          │                                        │
          │  every duty names a slot               │  the gate screen renders
          │  every template groups by slot         │  CG-04 previews, which
          │  post-duty follows slot windows        │  need no schedule at all
          ▼                                        │
      SCHEDULES + ASSIGNMENTS ◄─────────────────────┤
      (WB-01/02/05a/06, D10 version)                │
          │                                         │
          ├──────────────► HINTS + PICKERS ◄────────┘
          │                (WB-03/04/05b/07 — needs BOTH roots)
          ▼
      PUBLISH + ARCHIVE (PU-01/03, CG-05 gate, services/engine)
          │
          ├──► PUBLISHED READ (TL-01, PS-01, CL-04, EX-01/02, feeds)
          │
          └──► COVERAGE SURFACES (MC-01..04, WB-08, WO-01/02)
                   │
                   └──► L1 (OnCallDirectory → endorsement's sign-off pickers)
```

**Why slots is the deeper root.** `packages/engine/src/duty/interval.ts` declares
`Duty { personKey, date, slotKey }` — three members, `additionalProperties: false` in the JSON
Schema — so a duty cannot exist without a slot key. WB-01's skeleton is *"empty slots from
templates"*. MC-01's own term is *"post-duty exclusions"*, and `postDutyWindow()` is derived from
`dutyInterval()`, which needs the slot's minutes. WO-01 is literally *"current slots and people"*.
Nothing downstream is reachable without it.

**Why conditions is nonetheless a separate root and not a child of slots.** The gate screen renders
CG-01's list and CG-04's preview sentence, and `packages/engine/src/preview.ts` generates that
sentence from PARAMETERS alone — no schedule, no duty, no context. CG-08's presets are already
package data. So a department can install, scope, rank and preview a rule set with zero slots
defined. This is what makes a gate-and-hints slice separable from a substrate slice, and it is the
one place the two roots could have been serialised and should not be.

**Why L1 is last.** `OnCallDirectory::forUnitAt(unit, instant)` needs published assignments, slot
windows able to cover an instant, and the `users.person_id` join into D9's pickers. It is also the
only P3 work that touches the module holding PHI, so it should land when every other risk in the
phase is retired rather than while the workbench is still moving.

---

## The seam: five slices, argued

P3 splits **where the duty changes state**: what must exist before a duty can be stored; what stores
and edits one; what judges one; what publishes one; what reads a published one.

Two properties make a seam good in this codebase, both learned the expensive way. First, the table
sets must be **disjoint per slice**, so no slice half-builds another's writer and no single-writer
guard needs an allow-list entry it will never remove. Second, each boundary must be a point where
**every previously-shipped screen is whole** — items 18, 19 and 22 established that *"we have not
built it"* and *"we have decided not to build it"* are different states and only the second is safe
to build on top of, enforced by comment-stripping source scans through `Tests\Support\SourceScanner`.
The duty-state seam satisfies both. Two alternatives do not.

**Rejected: the P1d write-boundary seam** (plan / read / move). P1d split cleanly because the rota
has three genuinely different write shapes. P3 has exactly ONE write of consequence — a duty
placement — and it is inseparable from its own editor. The seam yields one enormous slice and four
trivial ones.

**Rejected: the P1e leaf seam** (*"the wizard needs clinics; clinics need neither"*). P3 has no leaf.
MC, WO, PS, TL, EX and L1 all read a duty. Every candidate leaf turns out to be a consumer.

**Rejected: slicing by requirement family** (SL, then CG, then WB, then PU, then EX). It is the
tidiest list and the worst plan: the CG slice would ship a gate with no schedule to judge, which is
a screen that appears to do nothing — rulings 41 and 49's exact failure shape, one layer out.

### The five slices

| | Slice | Seam — the duty-state boundary it draws | Tables |
|---|---|---|---|
| **P3a** | Slot vocabulary and coverage templates | **Before a duty can exist.** Department configuration only: no schedule, no placement, no rule. | `slot_kinds`, `slots`, `coverage_templates`, `coverage_template_levels` |
| **P3b** | Schedule substrate and the manual workbench | **A duty is stored and edited.** Placement, movement, projection — and no condition is read anywhere. | `schedules`, `assignments`, `schedule_moves` |
| **P3c** | The conditions gate and the live intelligence | **A duty is judged.** First `@engine` import in the app, first browser evaluation, first NF-01 measurement at department scale. | `conditions`, `ignored_warnings` |
| **P3d** | Publish, archive, and the department-wide read | **A duty is published and read.** First `services/engine` caller; first content route outside the login wall. | `schedule_snapshots`, `feeds` |
| **P3e** | Coverage surfaces and the cross-module link | **A published duty is read ACROSS a module boundary.** The only P3 work that touches PHI. | `coverage_overrides` |

Each slice's absence-of-the-next is **assertable**, not merely unimplemented — this programme's own
convention for a partial build:

- P3a asserts no schedule table exists and nothing in `app/` places a duty.
- P3b asserts the workbench reads no condition (`RulesLiveOnlyInTheEngineTest`'s needle set already
  covers `app/`; the browser half needs its own assertion, since `resources/js` is that guard's
  stated residual).
- P3c asserts no publish action exists.
- P3d asserts a published schedule's assignments take no write except a republish.

---

## What is demoable at the end of each slice

This is where P3 is materially better placed than P2 was. §1.3's objection to D13 was that building
all 21 types up front makes a *"long phase with nothing demoable at its end."* P3 has something at
the end of its FIRST slice and the owner's own stated goal at the end of its SECOND.

**P3a.** An administrator opens Admin → Structure → Duty slots and configures a real call structure:
*"NICU night, 20:00–08:00, crosses midnight, counts toward hours, tally key `nights`; weekday
template = 1 senior (R3/R4) + 1 junior (R1/R2)."* Installs a launch preset. The setup checklist at
`/admin/setup` ticks a box that P1e had to leave stated-but-unclickable — `DepartmentSetup`'s `later`
array carries the entry today, keyed `slots`, saying *"The tables behind this do not exist yet."*
That entry becoming a step is the acceptance signal.

**P3b.** §2's and §39's stated goal, in a browser: *"The chief describes month-end scheduling as an
hour of work rather than a week of dread."* A period's skeleton generates from slots and templates;
the chief fills a month by hand across Day, Full grid and Unfilled lens views; undo works thirty
deep; two schedulers work on it at once and see each other. **No engine, no solver, no conditions,
no owner policy data required.** Every item on the *blocked* lists below is irrelevant to this demo.

**P3c.** Dragging a resident onto a weekend surfaces, before the drop lands, every rule that
placement would break, graded by rank, with CG-04's plain sentence. The gate screen ranks them by
drag. This is the demo that sells the product and it is also the slice carrying every unknown.

**P3d.** A month is published. The outgoing version is archived first. Hard violations block, or the
department has relaxed to warn-only and the dialog says exactly what it is publishing over. A share
link opens the published month on a phone with no login.

**P3e.** The morning coverage board for tomorrow, with the post-duty exclusions actually subtracted;
the who's-on-call board; and, in endorsement, the Endorsed-To picker defaulting to the person the
rota says is on.

---

## Scoped OUT of P3

Naming these is the point. An unbuilt thing nobody has decided not to build gets reinvented as an
unanswered gap, and the reinvention is usually a second store beside the one the spec already names.

### Blocked on P4 (Stage 3)

| Item | Why it cannot ship in P3 |
|---|---|
| **PU-02's versioned change log and version browser** | §35 puts *"change notifications + versioned log"* in Stage 3 and *"audit/version browser"* in Stage 4; the design doc's P4 and P5 rows agree. P3 owes the **identity an edit can be a version OF** — `schedules.id` with a version integer — and nothing more. |
| **The unwanted-day store** | Design §14 item 30: `unwanted_day_block` *"has no store anywhere in the tree, and the spec's own answer is an RQ-01 request — which is Stage 3."* So WB-04's *"unwanted day"* reason chip cannot fire in P3, and `context.people[].unwantedDays` is empty by construction. |
| **MC-01's "approved leave" term** | Requests are RQ-01, Stage 3. MC-01's other five terms all exist. The term is stated as absent on the board rather than silently omitted. |
| **Every AU-\* capability** | The solver, ranked-sacrifice report, partial modes, infeasibility reporting, AU-06's replay. P3's month is filled by hand. |
| **PS-02's ICS feed** | Stage 3. The `feeds` table P3 builds is shaped so the per-person token scope drops in. |
| **Publish notifications (NT-02's "New period published")** | L4 — one notification stream — is P4. There is no queue worker: `QUEUE_CONNECTION=database` is set in `.env.example` and `docker-compose.production.yml`, but `docker/supervisord.conf` runs only php-fpm, nginx and the scheduler, nothing schedules `queue:work`, and no class in `app/` implements `ShouldQueue`. **P3 publishes silently, and that is a decision** (see the table) rather than an omission. |
| **§4.3's TS↔solver cross-validation** | Moved to P4 with the CP-SAT mapping it compares against (P2's row says so). |

### Blocked on owner input

| Item | The input needed |
|---|---|
| ST-03's preset parameter values | QCH's real night-call window, day/night boundary, per-unit nightly counts. §37 lists these as outstanding human inputs. Default below ships structure-only presets. |
| The SCFHS/local numeric duty-hour policy | §37. `preset:scfhs` ships empty and says so; nothing in P3 changes that. |
| `same_unit_conflict`'s reading | Design §14 item 31: *"three-way ambiguous… should be routed to the owner before the type is implemented."* P2 implemented reading (a) as its weakest default. P3c's gate screen is the first place a department SEES the preview sentence, so the deadline is P3c, not the solver. |
| `people.joined_at` | Item 34: *"exists and is empty on every instance."* `onboarding_grace` covers nobody until an administrator types dates. Not a blocker for P3; a stated no-op. |
| The xlsx / PDF / Word dependency | `composer.json` requires php, ext-intl, htmlpurifier, inertia-laravel, laravel/framework, tinker, web-push, google2fa — no spreadsheet and no PDF package. Open decision F is still open. |
| §38's paper walk-through | *"Walk one internal-medicine and one surgical rule set through [the catalog] on paper before Stage 2 build-out."* A pre-build owner gate the phase table does not record. Due before P3c. |

---

## Owner decisions, with a recommended default for each

Twenty-two decisions. Each has a default that can be built if no answer arrives, and each default is
the option that fails loudest rather than the option that feels safest.

### Schema and vocabulary

**D-P3-01 — SL-01's `kind` is a small seeded table, not an enum and not free text.**
*Default: a `slot_kinds` reference table (`code`, `name`, `display_order`, `active`), seeded with
SL-01's five names, administrator-editable exactly as the level ladder is.*
This codebase already has a discriminator, visible in the tree rather than asserted: code holds a
vocabulary when code BRANCHES on it (`Clinic::SESSIONS`, `Clinic::ATTENDEE_MODES`, `Unit::BAR_CLASSES`
— the last because the class must exist in the compiled stylesheet), and a table holds it when only
the department's vocabulary depends on it (`units`, `levels`). **Nothing branches on `Slot.kind`.**
Its only reader in the whole engine is one set-membership helper,
`kindMatches(kind, kinds) { return kinds.length === 0 || kinds.includes(kind); }`, called from five
condition modules; nothing anywhere tests it against a literal. `consecutive_max`'s `'nights'` unit is
`slot.crossesMidnight` — a structural fact — precisely because the vocabulary is stored nowhere.
Against free text: `kindMatches(kind, [])` means *every* kind, so a mistyped `kinds` entry silently
narrows a rule to the empty set, and there is no report for it — the package emits exactly twelve
`coverage()` skip reasons and none concerns an unmatched kind. Against `SELECT DISTINCT`: the gate
must be able to offer `weekly_duty` before any weekly slot exists.

**D-P3-02 — SL-01's "days it runs with day-type overrides".** *Default: a weekday set (ISO 1–7)
plus an optional per-day-TYPE override map (`WD`/`WE`/`HOL`) where an override REPLACES the weekday
answer, and `Calendar::dayType()`'s existing precedence — holiday beats weekend beats weekday —
decides which override applies.*
The spec states the phrase and nothing else, anywhere. The three candidate readings (weekday set;
day-type set; weekdays with a day-type override) differ on exactly the case that matters — a weekday
that is also a holiday — and they disagree on every Eid. The default is the only one of the three
that can express *"runs Sun–Thu, but not on holidays"*, which is the operational shape a call roster
actually has. **This is the one column of the slots table that should not be invented; if the owner
has a different answer, take it.**

**D-P3-03 — the day/night boundary is per-slot minutes, with no department-level boundary column.**
*Default: SL-02's split is two abutting slot rows; ST-03's preset writes both from one boundary
input at setup time and stores no standing boundary.*
The engine supports nothing else: a duty resolves its window from `context.slots` alone. A standing
department boundary would be a second definition that could silently re-cut published history the
day it changed. `dutyInterval()` computes `end = absMinute(date + spanDays - 1, endMinute) +
(crossesMidnight ? 1440 : 0)` and throws when `end <= start`, so a single 24-hour call is
`startMinute == endMinute` with `crossesMidnight: true` and a split pair is two rows whose windows
abut — `intersects()` is strict `<` in both directions, so abutting windows do not overlap.

**D-P3-04 — `code` is frozen once referenced, for slots AND for the four vocabularies that already
have this hazard live.** *Default: `slots.code`, `slot_kinds.code`, `units.code`, `levels.code` and
the tally key become immutable once any stored condition, template or assignment references them;
`name` stays freely editable.*
This is an EXISTING unguarded hazard that P3 multiplies. `units.code` and `levels.code` are both
editable in place today (`UnitRequest`, `LevelRequest` — `Rule::unique(...)->ignore($id)` on an
update path), and owner decision G fixes condition params on those codes because ids are
instance-local. There is no cascade and no immutability guard. Renaming a level code today silently
detaches every `eligibility`, `target_per_period` and `composition` row, and `eligibility` is a HARD
type whose params schema says in as many words: *"A slot absent from the map is unrestricted."* A
hard rule that turns itself off on a rename is the worst failure this schema can produce. Whatever
P3 decides must cover all five vocabularies or the department gets five different rename behaviours.

**D-P3-05 — `slots.unit_id` is presentation and grouping only.** *Default: it feeds MC-01's board,
WO-01's board and SL-03 template grouping, and NOTHING derives eligibility from it.*
`Slot.unitKey` is declared in the contract, schema-optional, and read by nothing under
`packages/engine/src/`. Every `unitKey` a condition module reads is the PERSON's rotation unit via
`unitKeyAt(person, date)`, or CG-01's `scope.unitKeys`. The unit-based restriction the engine
actually performs is `eligibility.allowed[slotKey].unitKeys`, whose own schema description reads
*"units.code values — the rotation the person is on that day."* Confirm this or the column acquires
a second meaning nobody wrote down.

**D-P3-06 — `schedules` is keyed on its own id, and `assignments` point at `schedule_id`.**
*Default: `schedules(id, period_id, status, version, …)` with one schedule per period per
department, and `assignments.schedule_id` as the FK.*
AR-05's `schedules/{periodId}` cannot survive PU-01: *"the draft persists for edits/republish"*
requires a draft and a published schedule to coexist for one period, which one-document-per-period
cannot express. AR-05's `assignments` carries `periodId` and no `scheduleId` and is ambiguous the
moment two schedules share a period. Design §6.3 already says `schedule_id`; the two documents
disagree and this resolves it. No unit dimension: a slot already carries its unit and MC/WO read
across units.

**D-P3-07 — no per-date slot instance override in P3.** *Default: the skeleton is derived from
`slots` and never materialised, and "an extra night on Eid" is not expressible.*
`Duty` is three members; the engine never sees an empty cell; a `cells` table would be a second
definition of *which slots run on this date* beside the slot's own day rule. Asserted absent rather
than merely unbuilt, so P4 opens it as a decision instead of inventing a store.

**D-P3-08 — SL-04's weekly duty anchors on the department week start, enforced by the writer.**
*Default: a `cadence: 'weekly'` assignment's date must be the department's week start.*
`spanDays: 7` occupies seven dates from whatever date the assignment carries; `assertSlot()` already
refuses `cadence: 'weekly'` with `crossesMidnight`, and refuses `cadence: 'daily'` with
`spanDays !== 1`, but nothing pins the anchor. A freely chosen anchor lets two home-call weeks
overlap. `Calendar` already derives the department week start and is the only date authority.

### Rules, evaluation and latency

**D-P3-09 — SL-03 coverage shortfall is ARITHMETIC, computed server-side, and it is not a condition.**
*Default: one class, `App\Support\Schedule\CoverageTemplate`, counts placed rows against the
template's minimum per (date, slot, level band); the WB-02 unfilled lens, WB-08's preview and
PU-03's dialog are its only consumers.*
Owner decision K refused a `day` window on `count_max`/`count_min` on the explicit ground that a
per-day cap *"builds SL-03 twice"*, and the CG-10 context deliberately carries no `templates` member
(*"an empty array here would read as 'this department has no coverage requirements', which is a
different and false statement"*). So `evaluate()` will never report an unfilled minimum, and
something server-side must. This is safe under §4.1's *"No PHP implementation of the rules exists
anywhere"* because a shortfall is a COUNT, not a judgement about a placement — but note the guard
would not catch a wrong answer here: `RulesLiveOnlyInTheEngineTest` needles the 23 catalog type keys
plus `violation`, `severity`, `hard_block`, `soft_block`, `rank_order`, and a coverage counter
contains none of them. The narrowness is the argument for keeping the computation to arithmetic.

**D-P3-10 — an unfilled coverage minimum WARNS; it does not block publish.** *Default: only hard
CONDITION violations block (CG-05), relaxable per department to warn-only; PU-03's dialog names
every short cell.*
A department publishing a knowingly-short month is a real operational state, and blocking it means
the month does not exist anywhere the department can see it. AU-07 forbids a SILENT under-fill; a
named one in the dialog is not silent.

**D-P3-11 — WB-03's prospective hint is a person-SCOPED evaluation in the browser, and the three
cohort types are refreshed on commit rather than on hover.**
*Default: on a prospective placement, evaluate every condition rescoped to the person under the
cursor via CG-01's `scope.personKeys`; on commit, re-evaluate the whole draft unscoped.*
Measured, not reasoned. On this machine today `npm run build:engine && node packages/engine/bin/corpus.mjs`
prints **evaluate() median 24.3 ms** and **evaluate() + coverage() 44.1 ms** at 93 duties. That case
is 20 people × 3 slots × 31 days, one person per cell — roughly an eighth of NF-02's own stated scale
(*60 people × 31 days × 8 slots*), and SL-03 specifies multi-person cells. Cost tracks DUTY COUNT and
is flat in roster size. At 248 duties a full `evaluate()` is ~60 ms in Node and **~212 ms in Chrome**;
at 744 duties (three per cell) it is ~139 ms in Node and **~520 ms in Chrome** — five times over
NF-01's *"< 100 ms p95 (laptop)"* before any phone penalty. Person-scoping takes the browser figure to
**14 ms at 248 duties and 54 ms at 744**, and reproduces the full run's answer for that person exactly
across the 11 placement-located and 8 window-located types. It is UNSOUND for the 3 cohort types
(`we_pairing`, `fairness_distribution`, `holiday_equity`), which go silent rather than wrong-loud,
because `cohortFor()` filters the roster through `scope` and a person-scope makes a cohort of one.
Every other narrowing is worse: narrowing `context.people` or `context.slots` throws
(*"No person named…"*, *"No slot named… dropped context, not an empty schedule"*), and narrowing the
duty list silently changes the answer — `same_unit_conflict` drops from 6 violations to 0, a hard
rule falling quiet on a narrowed input.
*The residual to accept explicitly: a scheduler dragging a resident onto a weekend sees every
spacing, cap, target and eligibility violation and does NOT see "this makes the weekend distribution
uneven" until they drop.*

**D-P3-12 — WB-04's picker orders by PRECEDENCE, not by a weight, and never by one full evaluation
per candidate.** *Default: key 1 is the worst violation each candidate produces under
`comparePrecedence()`, from a scoped placement-type evaluation; keys 2 and 3 (load vs target, gap
quality) are arithmetic over `assignments` and touch the engine not at all.*
The literal reading of WB-04 — *"order by fitness (rank-weighted violations, then load vs target,
then gap quality)"* — measures 3.6 s in Node and **12.3 s in Chrome** for 60 candidates. It is not
buildable. More importantly, *"rank-weighted"* is undefined in this repository ON PURPOSE:
`severity.ts` records that AU-02's weight CURVE is the solver's own fact and that `comparePrecedence()`
*"returns only -1, 0 or 1, which is the assertion that keeps a weight from quietly appearing as the
difference of the ranks."* Ordering candidates by their worst violation under `comparePrecedence`,
then by count within precedence level, is rank-SENSITIVE and introduces no weight — so P3 does not
become the place a penalty curve is first defined.
*If a neighbourhood pruning is added on top, its width must be derived from the condition
PARAMETERS and never a constant: a ±3-day window agreed with the full run only because the synthetic
corpus's longest placement reach was 48 h, and CG-07 makes every parameter department-editable.*

**D-P3-13 — `coverage()` is called on draft load and on publish, never per edit.** *Default: the
workbench calls `evaluate()` on a settled prospective placement and `coverage()` twice per session.*
`coverage()` is a second full traversal — the pair is roughly double, ~115 ms in Node and ~254 ms in
Chrome at 248 duties. `runConditions()` is exported and returns both projections from ONE pass
(measured 55 ms where the pair is 115 ms); if both are genuinely needed together, use it.

**D-P3-14 — `days[].periodKey` is BOUND, as an asserted duplicate of a derivation.** *Default: add a
contract invariant in `packages/engine/test/contract.test.ts` — `periodKey` is non-null exactly when
the date falls inside some `periods[i]`, and names that period — and keep the field.*
P2's recommendation is confirmed and **both of the reasons offered for it are wrong.** P2's own
stated reason (*"WB-03 badges a cell and will want a per-date period label"*) does not follow:
`ContextBuilder::periodKey()` returns `academic_year.'-'.sprintf('%02d', position)` — `"2026-2027-11"`,
an identity key. The human label *"Block 11"* is `periods.label`, which the CG-10 `Period { key,
startsOn, endsOn, weeks }` does not carry, so the label comes from Inertia props regardless. The
reconnaissance's replacement reason (*"WB-05's tracker must map a window violation's [from,to] back
to a period"*) does not follow either: `Location`'s window member is `{ personKey, from, to,
contributing }` and `context.periods[]` carries `{ key, startsOn, endsOn }`, so one range comparison
answers it without touching `days[]`. The field has **zero readers** — `grep -rn periodKey
packages/engine/src/` returns three lines, all declaration — and is provably inert. Bind it anyway,
for the only honest reason: keeping it costs nothing, dropping it is a schema amendment, and an
ASSERTED duplicate is a checked cache while an unasserted one is a second definition waiting to
drift. **The state it must not stay in is the current one: mandatory, unread, and justified by
something untrue.**

**D-P3-15 — `holidays.equity_tracked` gets a contract field.** *Default: add `tracked: boolean` to
the `Day.holidays` item (additive, the widening CG-10 explicitly permits) and make `holiday_equity`
count only tracked holidays.*
`docs/INVARIANTS.md` records this as deferred in writing: *"`holidays.equity_tracked` has no field in
the CG-10 `Day.holidays` shape… closing it properly needs a contract field, which is a P3 decision,
not a P2 workaround."* The loader carries every resolved holiday deliberately, because filtering
would be the loader deciding what a type may see. P3c's gate screen is where a department switches
`holiday_equity` on and where the wrong answer becomes visible.

**D-P3-16 — `same_unit_conflict` keeps P2's reading (a) and the gate SAYS which reading it is.**
*Default: no code change; CG-04's preview sentence names the reading in words.*
Item 31 routes this to the owner *"before the type is implemented"* and P2 implemented the weakest of
the three. The cheapest correct action is not to re-litigate but to make the reading visible on the
one screen a department will read it on. If the owner picks a different reading, it is a P3c
parameter change, not a re-plan.

### Undo, concurrency and the client

**D-P3-17 — undo is a WRITE, not a client stack: a server-side per-schedule move log with 30 as a
retention number.** *Default: `schedule_moves` holds the ordered move history; undo POSTs; the browser
holds no schedule state.*
Three constraints collide here and nothing in the tree resolves them. D10 (design §3.3) is explicit:
*"writes go to the database; no view mutates local schedule state. Every mutation is a
POST/PATCH/DELETE to Laravel; views re-read from server state."* WB-06 requires *"undo/redo ≥ 30"*
plus a *"move-history strip with warning flags"*. WB-01 requires concurrent schedulers, and SC-03
forbids whole-doc last-write-wins. A 30-deep client stack is exactly the local schedule state D10
forbids, and it has no answer for undoing a move a second scheduler has since overwritten. A move log
makes undo durable, shared between editors, and auditable, and WB-06's own visible move-history strip
already implies the log exists. The cost is one round trip per undo, which the 3 s draft poll already
budgets for. **This is a proposal: no document in the tree states it.**

**D-P3-18 — a replayed undo carries a precondition and REFUSES when the cell has moved.**
*Default: `App\Support\Rota\StatePin`'s discipline, applied per cell.*
`StatePin` exists for exactly this hazard on bulk rota writes — *"a rota that moved between the
preview and the confirm is applied as whatever it now says rather than as what the operator
approved"* — and it pins, for every cell the operation touches, its identity, what it CURRENTLY
holds, and what would be written over it. §29's SC-03 answer covers two schedulers touching
DIFFERENT cells and says nothing about a replay against a changed one. Refusing with a named reason
is the only option that cannot silently undo somebody else's work.

**D-P3-19 — D10 wins over UX-03: the drag commits on drop and the cell reflects the server's answer;
only the HINT is optimistic.** *Default: no optimistic placement.*
UX-03 asks for *"optimistic UI with rollback"* and D10 forbids local schedule state. They cannot both
hold for a placement. The honest split is that a hint is ADVICE and may be computed locally and
instantly (UX-05: *"Hints never block on network"*), while a placement is STATE and goes to the
server. CLAUDE.md's autosave invariant leans the same way: *"UI reflects the server response."*

**D-P3-20 — the engine runs in the browser, and in P3 it discloses nothing new.** *Default: proceed
with the client-side hint, and record that the disclosure question binds at P4, not P3.*
Design §14 item 30 records this as genuinely the owner's: *"the engine runs in the browser for
WB-03's live hints, so one person's unwanted days enter another person's Inertia props."* In P3 the
unwanted-day store does not exist (D-P3-07's neighbour, item 30 itself), so `unwantedDays` is empty
by construction, and the only date facts in the context are leave dates — which `AvailabilitySummary`
already publishes to every `rota.view` holder. `ContextBuilder` emits `{key: 'p<id>', levelSpans,
unitSpans, leaveDays, unwantedDays, eligibleDays, external, joinedAt}` — no names, no email, no
phone. So the question does not bind in P3 and **must be answered before P4 adds the store.**
Payload is not a constraint: the whole request at 60 people × 8 slots is 108.6 KB raw / 5.3 KB gzipped,
and the minified engine is ~94 KB / ~29 KB gzipped.

### Publishing, exports and disclosure

**D-P3-21 — publish snapshots the whole CG-10 document, and a published schedule is only ever
re-evaluated from its snapshot.** *Default: `schedule_snapshots` stores `{schedule, context,
conditions}` in the shape `EvaluationRequest::forPeriod()` already produces.*
This is what makes SL-05 (*"slot/template edits affect future drafts, never published history"*) and
CG-03 (*"never retroactive on published schedules"*) true BY CONSTRUCTION rather than by discipline,
and it is the only proposal that does. The hazard it closes is concrete: a `Duty` carries no window
and resolves it from the CURRENT `context.slots`, so re-evaluating a published month after a slot's
minutes are edited silently evaluates history under the new window. It also hands AU-06 its
requirement for free — *"regenerate a past pilot month from archived real inputs"* becomes a direct
replay. The cost, stated: the archive format is coupled to a contract that is explicitly allowed to
widen additively, so the snapshot must record the engine `version` it was taken against.

**D-P3-22 — the snapshot table is NOT called `archives`.** *Default: `schedule_snapshots`.*
*"Archive"* is already this repository's word for the nightly encrypted database backup, in code
(`App\Support\Instance`: *"It names the archive, scopes the archive's own retention sweep"*), in ops
(`BackupRun`'s `--keep=14 "How many archives to retain"`, `docker/backup-offhost-sync.sh`'s
*"the local 14-archive retention"*) and in the compliance pack. A PU-01 table named `archives`
collides in every conversation and every grep. The two are not substitutes in either direction: a
backup is `openssl enc -aes-256-cbc` under `BACKUP_PASSPHRASE`, a secret the application deliberately
does not hold, so the application can never read one to serve a version browser.

**D-P3-23 — the poll validator and `schedules.version` are two different numbers.** *Default: an
ETag derived from the draft's last-move id/timestamp for D10's 3 s poll, and a separate `version`
integer that increments only on publish.*
A draft edit must move the first and must not create a second. Conflating them either makes the
workbench stale or inflates PU-02's future history with non-events.

**D-P3-24 — EX-01 ships print/HTML and CSV in P3; xlsx, Word and the standalone-HTML file do not.**
*Default: an `@page`-driven print view following `resources/js/Pages/Endorsement/Print.vue`, plus CSV
through `App\Support\Csv::stream()`; the other three clauses get §1.2 override rows.*
Three separate obstacles, all verified. (1) No dependency: `composer.json` and `package.json` carry no
PDF and no spreadsheet package, and P1c Decision E already refused to add one for ST-04 —
*"adding one to a system holding children's PHI is an owner supply-chain decision, not a
developer's"* — with open decision F still open. (2) `TemplateScanningIsNarrowTest` asserts
`assertSame(['../js', '../views'], $sources[1])` — an EXACT set — so a self-contained export cannot
get a new template root without failing the build. (3) An xlsx writer bypasses both halves of the
`Csv::neutralise()` / `CsvRosterReader::unNeutralise()` pair that `CsvInjectionTest` asserts together,
and would need its own formula-injection answer first.

**D-P3-25 — EX-01's "standalone self-contained HTML for chat-app sharing" is REPLACED by the share
link, and the substitution gets a §1.2 override row.** *Default: send a URL, not a file.*
D7 permits a token specifically because *"A bearer token differs in kind from a public URL: it
expires, it can be revoked, and its use is logged."* A downloaded HTML file forwarded on WhatsApp has
none of those three properties, so it is strictly WEAKER than the public URL D7 refused. This is an
owner decision to surface, not a developer's to make — but the default is the one D7's own reasoning
compels. **EX-01 appears zero times in the design doc** (as do EX-02, PU-03, WO-01, WO-02, TL-01,
PS-01 and SL-05, counted), so this clause has never been adapted, overridden or costed.

**D-P3-26 — WO-02's "link-public or login-only per department policy" gets a §1.2 override row
saying D7 holds.** *Default: no policy switch; a wall display uses a share link or a kiosk account
with `schedule.view`.*
This is the identical shape as the CL-05 clinic-map footnote §1.2 already overrode — Munawib §5 named
*"the published schedule, boards, and clinic map"* as link-public and the override says *"D7 holds, as
it does for every other surface"*, stating the cost plainly (*"a consultant checking clinic times signs
in"*) and recording it *"because a deviation that lives in a plan is one nobody finds."* WO-02 has never
been given that row.

**D-P3-27 — no P3 export carries a contact field, for any viewer.** *Default: `RotaExport`'s
discipline, asserted over the file's own bytes.*
EX-02 (*"contact-bearing exports are login-gated"*) is already satisfied by construction — every
content route is behind `auth` + `cap:` — and it is the weaker of the two controls this platform has.
`RotaExport`'s docblock states the stronger one: *"because no contact field appears in the column list
at all, the 'may this viewer see a phone number' question never arises… `RotaExportTest` asserts the
absence over the FILE'S OWN BYTES, not over this constant."* Nothing in P3 needs a contact field:
WO-01's tap-to-call is a SCREEN under `PersonPresenter`'s policy, not an export.

**D-P3-28 — `services/engine` ships as a third compose service in P3d.** *Default: honour owner
decision Y.*
CG-05's publish gate must evaluate server-side — a client-computed gate is a client-trusted gate. The
proven path exists (`EngineEvaluate` runs `Process::timeout(300)->run(['node', base_path(
'packages/engine/bin/evaluate.mjs')])`) but it aborts in production by design, naming the reason:
*"The server runtime is services/engine (P3, owner decision Y). This command would make `node` in the
app container an undocumented dependency."* The cost is real and should be stated when the decision is
re-confirmed: a third service against `docker-compose.production.yml`'s current two, and 15 pinned
test methods in `DeploymentInvariantsTest`, plus CLAUDE.md's rule that every new variable needs an
explicit `${VAR:-default}` in the `environment:` block or Coolify's screen has no effect.

**D-P3-29 — P3 publishes SILENTLY, and says so on the dialog.** *Default: no mail on publish; no
queue worker in P3.*
L4 is P4, NT-04's *"queued with retries; failures surface in an admin view"* has no runtime, and a
synchronous fan-out inside the publish request would additionally be forced to carry a rate limit by
`MailSendingRoutesAreThrottledTest` — which derives the property from the router and follows one level
of same-class call, so a `publish()` ending in `notifyAffected()` is caught the day it is registered.
A throttle on the publish button is a strange control, and that strangeness is the argument for
waiting for the worker rather than shipping around it.

### Access, gates and guards

**D-P3-30 — two new capability keys, not five.** *Default: `schedule.manage` (workbench, gate,
publish) and `schedule.view` (published month, boards, My Schedule, exports), the latter seeded to
every position. Defining a slot, a coverage template or a condition stays on `structure.manage`.*
`AccessControlSeeder` states the precedent verbatim for clinics: *"ONE new key, not two: DEFINING a
clinic is department structure and stays on `structure.manage`; only the department-wide MAP is read
by everybody and needs a key of its own."* The catalog is twelve keys today; `rota.view` and
`clinics.view` are already seeded to all five positions, which is the shape `schedule.view` copies.

**D-P3-31 — MR-04's guard is REPOINTED, not narrowed.** *Default: `RotaAccessTest`'s whole-`app/`
scan keeps its reach and gains exactly one allow-listed namespace, `App\Support\Schedule\`, so it
becomes "nothing OUTSIDE the schedule namespace infers on-call eligibility."*
This is the most delicate act in P3a. The guard walks `File::allFiles(app_path())` for `off_roster`,
`offRoster`, `callEligib`, `call_eligib`, with a narrower namespace twin over `app/Support/Rota/*.php`
adding `eligib`, `on_call`, `onCall`, `callRoster`. `units` shipped `training_rotation`, `call_target`,
`clinic_owner`, `aliases`, `name2` and NO `off_roster` column, so an `off_roster` cast or fillable
entry on `App\Models\Unit`, or a units FormRequest naming it, turns the whole-app scan red. The guard's
own rule is that *the rota* must not infer eligibility, and P2 established the pattern that satisfies
it: `App\Support\Engine\` is clear of all three needle sets and P2 added no allow-list entry, because
*"both are satisfied by the crossing living in the engine's own namespace and reading those modules'
data."* Ruling 42's discipline applies in reverse: **measure before REMOVING a needle, and prove the
repointed guard still goes red on a plant of exactly the shape it exists to catch, inside
`app/Support/Rota/`.**

**D-P3-32 — build §9.2 item 3's missing guard as part of L1.** *Default: a namespace scan asserting
the schedule and rota namespaces reference no PHI model or column, written in P3e.*
The design doc states as fact, under the heading *"Enforced, not intended"*: *"A guard test asserts
the Rota namespace references no PHI model or column."* **It does not exist.** The only namespace-wide
scan over `App\Support\Rota` is the MR-04 eligibility one, and no test file under `tests/Feature/Rota/`
mentions handovers at all. L1 is the first deliberate rota↔handover crossing, so the absent guard
matters at exactly the moment it is first relied on.

**D-P3-33 — the three §35 gates get calendar positions.** *Default: §38's paper walk-through and the
`same_unit_conflict` answer before P3c starts; UX-07's clickable prototype BETWEEN P3b and P3c;
QA-05's security review before P3d merges.*
UX-07 says *"before full build-out"*, and P3b's shipped workbench is itself the clickable prototype
for placement, verbs, undo and the lens — so the gate reviews P3c's hint, picker and tracker flows on
P3b's real data, which is a better prototype than a mockup. QA-05 says *"before any real names enter
the system"*; for this deployment the binding event is the share-token feed, P3's first content route
outside the login wall.

**D-P3-34 — fix `DepartmentSetup`'s wrong stage label in passing.** *Default: change the `conditions`
entry from `'stage' => 'Stage 3'` to `'Stage 2'`.*
A shipped screen currently tells an administrator the conditions gate is Stage 3. §35 puts *"the gate
with full catalog and drag ranking"* in Stage 2, and the neighbouring `slots` entry is already labelled
correctly.

---

## Two facts the phase must plan around

### NF-01 is met, and it is not the workbench's budget

The brief's *"MET at 58 ms"* and `docs/INVARIANTS.md`'s *"~56 ms"* are both stale for this machine:
running the shipped harness today gives **24.3 ms** (best 21.8, worst 26.3), and the file's own
docblock records 76 ms and 103 ms minutes apart. The number moves 2× run to run and 2.5× between
recordings. **No interaction design should be built on one reading of it.**

More important than the drift: the case is 20 people × 3 slots × 31 days = 93 duties, one person per
cell, against NF-02's stated *60 people × 31 days × 8 slots* and SL-03's explicitly multi-person cells.
And it carries a defect: `packages/engine/bin/corpus.mjs` defaults its condition rows to
`class = 'soft-top'`, which the contract refuses. Verified against the shipped validator —
`validate('Condition', {…, class:'soft-top', …})` returns
`[{"path":"#/Condition/class","message":"\"soft-top\" is not one of [\"hard\",\"soft\"]"}]`. It escapes
because Phase 1 of the harness validates through `bin/evaluate.mjs` while Phase 2 imports the bundle
and calls `engine.evaluate()` directly. Timing is unaffected (`stampViolation` copies `class`
verbatim), but `CLASS_ORDER['soft-top']` is `undefined`, so `comparePrecedence()` would return `NaN`
on those violations and **the benchmark's world is not a valid contract instance**.

**P3a should re-point the benchmark at NF-02's own figures and fix the class value.** It is not a new
harness: `corpus.mjs` already parameterises everything except the slot list and the people count, and
its two vacuity gates (findings from more than one condition; no active condition reporting
`evaluatedWindows: 0`) are scale-independent.

### P1 is built and unshipped, and P3e's risk profile depends on it

The brief states *"P1 is DEPLOYED to production."* The tree does not support it.
`docs/DEPLOY-P1-2026-08-12.md` opens: *"Every command below is the owner's to run. Nothing in this
file has been executed against production."* `docs/OUTSTANDING-2026-08-19.md`, verified against
production on 19 August, records image `8886f8d` built 2026-07-30, **22 of 44 migrations applied**
(last: `2026_07_27_180001_create_trusted_devices_table`), `Person.php`, `Calendar.php`, `RotaFill.php`
and `ClinicWriter.php` absent from the container, and *"2 users, 2 handover rows, 0 sign-offs ever."*
Either the deploy happened and both documents are stale, or P3e's L1 — which changes the sign-off
pickers on the one surface holding PHI — targets a production instance that has never run P1's schema.
**This must be resolved before P3e is planned, not before it is built.**

---

## Corrections to the reconnaissance and the brief

1. **`days[].periodKey`'s binding reason.** The reconnaissance argued *"WB-05's per-person tracker must
   map a window violation's [from,to] back to a period, and periods[] and days[].periodKey are the two
   halves of that one fact."* Wrong: `Location`'s window member carries `[from, to]` and
   `context.periods[]` carries `{key, startsOn, endsOn}`, so one range comparison answers it and
   `days[]` is not involved. Verified: `grep -rn periodKey packages/engine/src/` returns three lines,
   all declaration, zero readers. The binding is still right — for the reason given in D-P3-14.
2. **P2's own stated reason for binding it** — that WB-03 wants a per-date period LABEL — is also
   wrong: `periodKey` is `"2026-2027-11"`, an identity key, and the label is `periods.label`, which the
   contract's `Period` does not carry.
3. **NF-01's meaning.** The Node-only figure (~24 ms) is not the workbench's budget in either
   direction: the browser costs 2.4–3.4× more, and a realistic month misses NF-01's laptop budget by
   ~5×. A plan written against the Node number alone would commit to synchronous hinting that does not
   exist in a browser.
4. **CL-03.** The brief describes CL-03 and CL-04 as *"hooks P1e recorded and left unbuilt."* CL-03's
   predicate shipped in P2 — `registry.ts` carries `typeKey: 'clinic_conflict', implemented: true`. What
   P3 owes for CL-03 is a `conditions` row and the gate rendering it. CL-04 is wholly P3's. The design
   doc's item 22 was right; the brief's paraphrase is what is stale.
5. **PU-01..03.** The brief assigns all three to P3. §35 and the design doc's own P4/P5 rows put
   PU-02's two halves in Stage 3 and Stage 4.

One reconnaissance claim was checked and **stands**, against expectation: the design doc's §9.2 item 3
asserts a guard test that the Rota namespace references no PHI model or column, and no such test
exists. That is now D-P3-32.

---

## Order of work

```
  P3a  slots + kinds + coverage templates + MR-04 + ST-03 presets + setup step
       │
       │   ── gates before P3c ──►  §38 paper walk-through (owner)
       │                            same_unit_conflict reading (owner)
       ▼
  P3b  schedules + assignments + move log + the manual workbench + D10 poll
       │
       │   ── gate ──►  UX-07 clickable prototype, reviewed on P3b's real data
       ▼
  P3c  conditions + gate screen + drag ranking + hints + pickers + trackers + ignore
       │
       │   ── gate ──►  QA-05 security review, before the share token exists
       ▼
  P3d  publish + archive + services/engine + published read + tallies + My Schedule
       │                                                      + exports + feeds
       ▼
  P3e  morning coverage + overrides + pull pool + who's-on-call + WB-08 + L1
```

Each slice's plan is written when its predecessor merges. P3a's is
`docs/superpowers/plans/2026-08-21-p3a-slots-and-coverage-templates.md`.
