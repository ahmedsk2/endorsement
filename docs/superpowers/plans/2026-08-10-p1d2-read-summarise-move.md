> ## OWNER DECISIONS, 2026-08-10 — BINDING, AND ONE OF THEM REVERSES SHIPPED CODE
>
> **Every decision below is already folded into the task text it governs.** This block is an
> index, not a patch applied on top of tasks that contradict it. **Four times** in this programme
> a plan carried decisions in a prepended block and left the task text below unchanged, and four
> times an implementer was instructed by task text to build the thing the decision had forbidden
> (P1b Task 1's `clinic_owner` seed; P1b Tasks 6/7/8's `terminal` column; P1c Task 12's
> `clean.csv` "8 creates"; **P1d-1 Task 1's `rota.manage` default, where the plan's own binding
> block and its own supplied test snippet said opposite things**). **If any task text below
> appears to disagree with this index, the task text is the bug — but it should not, because it
> was written after these decisions, not before.**
>
> **1. NO PUBLISH GATE.** The read view always shows the current rota. No status column, no draft
> state, no publish action, no "visible from" date. If any task text implies a draft/published
> distinction, it is wrong. This confirms Decision D (P1d-1) rather than changing it, and it
> closes the open owner decision the P1d-1 plan left standing. → **Tasks 2, 3, 4, 6 and 13;
> asserted by `RotaReadViewTest::test_there_is_no_publish_state_on_the_read_view` (Task 3).**
>
> **2. `rota.manage` IS ADMINISTRATOR-ONLY BY DEFAULT — WHICH REVERSES WHAT P1d-1 SHIPPED.**
> Not in Chief Resident's `ROLE_DEFAULTS`. An administrator grants it per department from the
> Access Control screen. **This is not a no-op.** P1d-1's own Task 1 amendment records that the
> opposite was seeded: `AccessControlSeeder::ROLE_DEFAULTS[5]` contains `'rota.manage'` today,
> `RotaAccessTest::test_only_an_administrator_and_chief_resident_hold_rota_manage_by_default`
> asserts position 5 holds it, `AccessControlParityTest::expectedByPosition()` expects it, and
> `docs/spec/08-foundation.md` says "Administrator and Chief Resident" in prose. Four sites must
> change, and **Task 1 is where they change, before any other task**, because every access
> assertion in this plan is written against the corrected default. `rota.view` is unchanged:
> seeded for every authenticated member (P1d-1 owner decision 2) — which is exactly why the read
> view must not leak contact data. → **Task 1; consumed by every access assertion after it —
> Tasks 3, 6, 8, 10 and 12.**
>
> **3. THE IMPORTER SNAPS `week`-GRANULARITY VACATIONS TO WHOLE WEEKS, EXACTLY AS THE BOOKING
> SCREEN DOES.** The file's `granularity` column is authoritative. Snapping is **the same code
> path** as the screen — `App\Support\Rota\VacationBooking`, which calls `Calendar::weekOf()` —
> never a parallel rule re-typed in the importer. The preview **reports the adjustment** so a snap
> is never silent (P1d-1 owner decision 3, restated verbatim because this is the plan it was
> written for). → **Task 11; the shared `VacationBooking::snap()` in Task 11 Step 1 is what makes
> "same code path" literally true rather than aspirational.**

---

# P1d-2 — Read, summarise, move

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** the three things P1d-1 deliberately left out. A resident-facing **read view** of the
master rota (MR-05); **per-period availability summaries** by level and unit, including who is on
vacation each week (MR-07); and the **bulk moves** that make a year plannable in an afternoon
rather than a week — fill-down, fill-across, copy-period, CSV export and CSV import with dry-run
preview (MR-06).

**Binding requirements:** MR-05, MR-06, MR-07 — quoted verbatim from `docs/munawib/SPEC.md`:

> MR-05 — Publishable to residents independently of any call schedule (search, level filter,
> per-person period strip, per-period availability summaries). MR-06 — Fill-down/across, copy
> period, import/export. MR-07 — Per-period availability summary per level and unit, including
> who is on vacation each week.

Plus **MR-04 restated and not built** (Stage 2), **D7** held (no unauthenticated route), **D9**
held (offer/write parity, per field), **D11** held (`institution_id` is provenance, never a
filter, never part of a key), **ST-06** held (`App\Support\Calendar` is the only converter, and
the client does no date arithmetic at all), and **§9.2** held (no rota class references a PHI
model or column).

**This is where Stage 1's acceptance criterion lands.** Munawib §35: *"Accepted: the pilot's real
master rota and clinics live; residents claimed accounts; **availability summaries match
reality**."* The third clause is MR-07, and MR-07 is Task 2 of this plan. "Clinics live" is P1e.
"Residents claimed accounts" is P1c-2.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 3 + Vue 3, PHPUnit 12 (SQLite in-memory,
`APP_TIMEZONE=Asia/Riyadh`), Vitest, Playwright, Tailwind 4 via `@theme`, MySQL 8.4 in
production.

**Baseline this plan was written against:** branch `main` at `9c8c1cf` (*"feat: the department's
year of duty, on one screen"* — the P1d-1 merge). Measured, not assumed, by running the commands
below via **Bash**:

```bash
npm run build     # ✓ built
php artisan test  # {"tool":"phpunit","result":"passed","tests":1159,"passed":1159,"assertions":5014,"duration_ms":140520}
npm test          # Test Files 14 passed (14) / Tests 131 passed (131)
```

`npm run test:e2e` was **not** re-measured for this document; P1d-1's own amendments record it at
**18 specs** after Task 11. **Measure it yourself before Task 6 and trust the measurement**, not
this sentence — four of P1c's thirteen amendments and one of P1d-1's four were the plan's own
expected-count arithmetic being stale before the task began.

**`php artisan test` takes ~2 minutes 20 seconds.** Budget for it. Filter verbose output
(`| tail -5`), and on a failure re-run only the failing filter
(`php artisan test --filter <TestName> | head -30`).

---

## What this plan is, and is not

**It is** one read surface, one pure summary computation used by two screens, three bulk write
operations sharing one preview/confirm engine, one two-file CSV export, one CSV importer built to
`RosterImport`'s discipline, and the document corrections all of that invalidates.

**It adds NO migration.** Not one. Every table it needs exists: `master_rota_assignments`,
`vacations`, `periods`, `people`, `person_levels`, `units`, `levels`, `audit_log`. Nothing is
retyped, nothing is dropped, no column is added. That is a deliberate property and a reviewer
should check it holds — a "small additive column" appearing in this slice would mean something in
it was designed wrong. (P1e's `2026_08_16_*` migration allocation stays untouched and unclaimed.)

**It adds NO new writer.** `App\Support\Rota\RotaAssignment` and `App\Support\Rota\VacationBooking`
remain the only writers of their tables, and `RotaWritersAreSingularTest` fails the build for a
second one. Every bulk operation in Task 8 and every row the importer applies in Task 11 writes
**through** them. If a task's implementation reaches for `MasterRotaAssignment::create()` or
`DB::table('vacations')`, the task is being implemented wrong, not the guard being inconvenient.

**It builds NO publish gate** (owner decision 1). No `status` column, no `published_at`, no
"publish" button, no draft. The read view shows the current rota, always.

**It builds NO on-call eligibility of any kind.** MR-04 is Stage 2. An availability summary is
*precisely* the shape a future implementer would reach for to derive eligibility ("who is
available for call in Block 11?"), which is why Task 12 extends the existing guard to cover the
new files rather than leaving MR-04's absence asserted only over P1d-1's.

**It touches NO PHI.** No `handovers`, `handover_revisions` or `handover_signoffs` row is read or
written. Design §9.2's rule holds and Task 12 asserts it over the new namespace members.

**It is TWO branches.** See [The split](#the-split-p1d-2a-and-p1d-2b).

---

## Findings

Read these before any task. Each was verified against the tree at `9c8c1cf` by running or
grepping, not inferred from a document.

**Finding 1 — the shipped `rota.manage` default contradicts owner decision 2, at four sites.**
`grep -n "rota.manage" database/seeders/AccessControlSeeder.php` shows it inside `ROLE_DEFAULTS[5]`
with a comment citing "Owner decision 1 (P1d, 2026-08-09)". `tests/Feature/Rota/RotaAccessTest.php`
has `test_only_an_administrator_and_chief_resident_hold_rota_manage_by_default` asserting position
5 holds it, plus a case asserting a Chief Resident gets `200` on `/admin/rota`.
`AccessControlParityTest::expectedByPosition()` line 56 merges `'rota.manage'` into position 5's
expected set. `docs/spec/08-foundation.md` line 38 says *"defaults to **Administrator and Chief
Resident**"*. All four are wrong under the answered decision. **Task 1 corrects all four.**

**Finding 2 — `AccessControlSeeder` will NOT revoke the grant on an instance that already has it.**
The seeder's own comment: *"Role defaults — applied ONCE per (position, capability), then never
re-asserted. An already-marked pair is skipped outright: whatever `role_capabilities` says about it
now is the administrator's decision, revocations included."* It keys that on an
`applied_role_defaults` ledger row. So on a **fresh** database Task 1's change means Chief Resident
never receives `rota.manage`; on an instance where P1d-1 already seeded it, the `role_capabilities`
row survives. **Do not write a data migration to revoke it** — revoking a capability an
administrator may have deliberately kept is exactly what this seeder's design refuses to do. Task 1
documents the operator step (un-tick it on Admin → Access Control) in `docs/RUNBOOK-DEPLOY.md`
instead.

**Finding 3 — `PersonPolicy::viewContact()` returns true for EVERY signed-in account when the
department opts in**, and the rota grid asks it. `PersonPolicy::viewContact()` is
`AccessControl::allows($user, 'people.manage') || ContactVisibility::membersMaySeeContact()`.
`RotaGrid::forYear()` calls `PersonPresenter::one($person, $viewer)`. So on a department that has
set `institutions.contact_visibility` to `members`, **today's editor grid already emits every
colleague's email and phone in its Inertia props**, and a read view written the same way would emit
them to every resident. Nothing renders them (`grep -c "email\|phone"
resources/js/Pages/Admin/MasterRota.vue` returns `0`), so this is a props-payload disclosure, not a
screen one — which is worse, not better: it is invisible in review. **Task 3 fixes it at the
source** by making the rota surfaces ask for a contact-free projection rather than passing a viewer
whose answer depends on a department toggle. See [Decision C](#decision-c-the-rota-asks-for-a-contact-free-projection-rather-than-a-viewer-dependent-one).

**Finding 4 — `RotaGrid` already builds everything MR-07 needs, and computes none of it.** Each
period prop carries `weeks` (`Calendar::weeksIn()`, itself query-free, with `clipped_starts_on`/
`clipped_ends_on`); each cell carries `spans` (with `unit_id`), `uncovered_days`, `level_id` (the
level held at **that period's** start, not the row group's) and `vacations` (with bounds and
granularity); each row carries `stale`. Every number MR-07 asks for is a fold over that array.
This is what makes [Decision B](#decision-b-availabilitysummary-is-a-pure-fold-over-the-grid-array-not-a-second-set-of-queries)
possible at zero query cost.

**Finding 5 — `people.short_name` is nullable and UNIQUE.**
`database/migrations/2026_08_10_120001_create_people_and_link_users.php:51` —
`$table->string('short_name', 50)->nullable()->unique();`. It is the only app-wide unique human
handle that is not a contact field, which is why the export identifies a person by it (Task 10) and
the importer matches on it (Task 11). **It is also nullable**, so a person with no short name
exports with a blank handle and cannot be re-imported. Task 10 makes that visible before the file
is generated rather than after.

**Finding 6 — `periods` is keyed `(academic_year, position)`, not by label.**
`periods_year_position_unique`. Labels are administrator-editable text. The importer resolves a
period by that pair and never by `label` (Task 11).

**Finding 7 — `Vacation::SOURCE_IMPORT` already exists.** P1d-1 shipped `'manual'` and `'import'`
in `app/Models/Vacation.php` for exactly this slice. The importer sets `SOURCE_IMPORT`; nothing new
is needed on the model.

**Finding 8 — `AuditLog::record()` opens its own transaction and locks the chain tail.**
`RosterImport::commit()` learned this the hard way (its review finding 6): auditing per row from
inside the import transaction serialises the whole chain for the import's duration, for no benefit,
because a failed import rolls back regardless. **Every bulk audit row in this plan is written AFTER
its transaction commits**, never inside it — Tasks 8 and 11.

**Finding 9 — `RotaAssignment::set()`/`split()` each open their own `DB::transaction()`.** Nesting
them inside a fill's outer transaction is correct and safe (Laravel opens a savepoint), and the
fill's all-or-nothing property rests on the **outer** transaction. Do not "avoid the nesting" by
reimplementing the writer inline; that is precisely the second writer
`RotaWritersAreSingularTest` exists to refuse.

**Finding 10 — `RotaCellRequest` applies the strict active-person / active-unit predicate on the
two WRITE routes and a bare exists on DELETE**, deliberately (its own docblock; P1d-1 pre-merge
finding 1). Every bulk path in this plan that **creates** an assignment inherits the strict half:
a target person who is off the active roster, or a source unit that has been retired, is **skipped
with the reason named**, never written. That is D9's offer/write parity applied to a bulk surface,
where it is easier to get wrong precisely because there is no picker in front of it.

**Finding 11 — `CsvRosterReader` is already format-generic and already un-neutralises.** It sniffs
`,`/`\t`/`;`, refuses non-UTF-8 with a message naming the fix, caps at `MAX_ROWS = 2000`, returns
headers verbatim and strips exactly one leading apostrophe ahead of a dangerous prefix. The rota
importer needs **no reader work at all** — it constructs a `CsvRosterReader` and consumes
`headers()`/`rows()`. `CsvIsTheOnlyReaderWriterTest` fails the build for a second reader.

**Finding 12 — `RosterImport` emits one outcome per LINE. The rota importer cannot.** A cell's
assignment is a *set* of spans, and `RotaAssignment::split()` replaces the whole set. So two lines
describing two halves of one split period are **one** outcome, not two. The unit of outcome in
`RotaImport` is the **(person, period) cell**, with its contributing line numbers listed. This is
the single largest shape difference from `RosterImport` and Task 11 states it in the class's own
docblock, because a reader coming from `RosterImport` will assume otherwise.

**Finding 13 — the `AuditAnomalies` single-occurrence watch list fires once per row in the
window.** `foreach (... as $action => $meaning) { $n = AuditLog::where('action', $action)... }` then
one `OpsAlert::critical` per run summarising all findings. Adding `rota_fill` there means **one
bulk fill produces one alert**, which is the intent — and it is exactly why per-cell auditing was
forbidden in Decision H: 780 `rota_assign` rows on the watch list would be 780 findings in one
alert body. Task 8's audit row is per OPERATION.

**Finding 14 — `CalendarIsTheOnlyConverterTest`'s ten-needle client scan has no allow-list, by
design, and it matches docblock prose.** P1d-1 tripped it **twice** on comments *describing*
`strtotime()` and `new Date(...)` rather than calling them. Any new `resources/js` docblock in this
plan that quotes one of those needles as code-being-described will trip it. Write around the
parenthesised call shape in prose; never suppress the guard.

**Finding 15 — the desktop table row and the mobile card carry identical `data-testid`s.** Only
one is visible per viewport (CSS `hidden`), but both are in the DOM, so an unscoped
`page.getByTestId()` hits Playwright strict mode's "resolved to 2 elements". Every per-row lookup in
Task 6's spec is scoped through a row helper built from the row's own `data-row-id`, never an
unscoped `getByTestId`.

---

## Where the design doc and the Munawib spec are wrong or thin about this slice

| Claim | Reality |
|---|---|
| Design §13's P1d-2 sentence: *"MR-06's fill-down/fill-across/copy-period plus CSV export/import"* | Correct in content, silent on the thing that makes it dangerous: these are **bulk writes over hundreds of cells behind one confirmation**, and this codebase's bulk discipline (P1 finding 12, `AccessControlController::updateRoles()`) is not optional for them. Task 13 adds that clause. |
| Design §9.1's P1d-1 paragraph: *"An explicit 'not visible until I say so' gate remains a real, if unbuilt, product option (§14 open item)"* | **Answered, 2026-08-10: there is no gate, and the option is closed, not open.** Task 13 moves it out of §14's open items and records the answer in §9.1. |
| Munawib MR-06: *"Fill-down/across, copy period, import/export"* | Six words for the most destructive surface in the whole rota. It says nothing about what happens to a cell that already carries a deliberate split, which is the exact case where a blanket fill silently destroys work. This plan resolves it: **a target cell carrying a split is SKIPPED unless explicitly confirmed**, per target cell, defaulting to false — the same shape `RosterImport`'s `$confirmations` array already has for a no-email create. |
| Munawib MR-06: *"fill-across"* | Says nothing about direction. This plan fills **forwards only** — a backwards fill overwrites periods the department has already worked, and no requirement asks for it. Task 7. |
| Munawib MR-07: *"per-period availability summary per level and unit"* | Says "availability" and defines nothing. A summary that counts only assignments is not an availability summary — it cannot tell a fully-planned period from a half-planned one. This plan counts **uncovered days and the number of people carrying a gap**, alongside assignments and vacations, because owner decision 3 (P1d-1) makes a gap a legal state and §35's acceptance is that the summaries *match reality*. Decision B. |
| Munawib MR-05: *"Publishable to residents"* | Settled twice over: by D7/design §9.1 (no unauthenticated route; token links are P3) and by Munawib's own data model (`masterRota/{periodId}` carries no `status` field, unlike `schedules/{periodId}`). Now settled a third time by owner decision 1. It means a `cap:rota.view` screen and nothing else. |
| Munawib §35 Stage 1 acceptance: *"the pilot's real master rota ... live"* | Nothing in this repository ever contains the pilot's real rota, and no fixture in this slice ever resembles a real department (P1c owner decision 3). "Live" means the owner loads it into a running instance and observes it there. Task 11's fixtures exercise failure *shapes*, not a staff list. |

---

## Decision A: the read view is its own controller, on its own route group, with no write route in it

`App\Http\Controllers\RotaController` — **not** under `Admin\`, and **not** a method added to
`MasterRotaController`.

Three reasons, in order of weight.

1. **`MasterRotaController`'s entire class sits inside a `cap:rota.manage` route group.** Adding a
   `cap:rota.view` method to it puts one class behind two capabilities, and makes its own docblock
   (*"Admin → Master Rota (cap:rota.manage)"*) false. This codebase has already paid for the
   analogous mistake one level down: `RotaCellRequest` is one FormRequest behind three routes and
   needs an explicit `routeIs()` branch plus a thirty-line docblock to stay honest about which
   predicate applies where. Doing the same thing with a **capability** rather than a payload is
   strictly worse, because the failure mode is authorization rather than validation, and an
   authorization mistake does not show up as a 422 in a test someone happened to write.
2. **The URL space was already decided.** P1d-1 Decision A: `/admin/rota` is the editor,
   `/rota` is the read view, and *"a resident reading the rota is not doing management"*. A
   controller under `App\Http\Controllers\Admin\` serving `/rota` would contradict the directory it
   lives in. `EndorsementController` is the precedent: the non-admin surface every account uses
   lives outside `Admin\`.
3. **It makes the read group provably read-only.** The new group is:

   ```php
   Route::middleware(['auth', 'throttle:clinical', 'cap:rota.view'])->group(function () {
       Route::get('/rota', [RotaController::class, 'index'])->name('rota');
   });
   ```

   One route. GET. And Task 3 asserts the property over the **router**, not over a hand-written
   403 case: *every registered route whose middleware stack contains `cap:rota.view` uses the GET
   method.* That fails the build the day a future PR adds a write endpoint to the read group —
   which a "a resident gets 403 on PATCH /admin/rota/cell" test does not, because it only covers
   the routes someone remembered to enumerate. **Both tests ship**, because they fail for different
   reasons.

`Unit::RESERVED_CODES` is untouched: `/rota` does not sit under `endorsement/`, and
`ReservedUnitCodesTest` derives its expected set from routes that do, bidirectionally. **Do not add
`ROTA` to it.**

**Navigation.** `AppLayout.vue` currently renders a "Master Rota" link under the admin section for
`can('rota.manage')`. Task 4 adds a **top-level "Rota"** entry for `can('rota.view')`, beside the
unit channels rather than inside the admin block. An administrator therefore sees **two** rota
links, and that is correct rather than a bug to tidy: reading the department's rota and editing it
are two different acts on two different screens, and hiding the read view from the person most
likely to want to check what residents actually see would be a strange kindness. Task 4's own
comment says so, so it does not come back as a review question.

---

## Decision B: `AvailabilitySummary` is a pure fold over the grid array, not a second set of queries

**It sits beside `RotaGrid`, and takes `RotaGrid`'s output as its input.** It does not extend
`RotaGrid`, is not called from inside it, touches no model, and issues **no query at all**.

```php
final class AvailabilitySummary
{
    /** @param array{periods:list<array<string,mixed>>, levels:..., units:..., rows:...} $grid */
    public static function forGrid(array $grid): array
}
```

**Why not fold it into `RotaGrid::forYear()`.** Every per-cell save on the editor ends in `back()`,
which re-renders the whole grid. Folding the summary in would recompute every period's per-level,
per-unit and per-week figures on every single cell save, for a panel the editor shows once. It
would also make the summary impossible to test without a database, and impossible to prove
identical between the two surfaces — because there would be nothing to call twice.

**Why not give it its own queries.** It would re-fetch the assignments, vacations, level spans and
units `RotaGrid` has already fetched — doubling the read view's query count for data already in
memory — and, far worse, it would create a **second** definition of "how many R2s are on PICU in
Block 11". Two definitions of one fact is the failure class `AuditChain::canonical()` and
`Person::levelAt()` already carry docblocks against, and the whole point of MR-07 being one
computation is that the editor's summary and the resident's summary cannot disagree.

**What it costs in queries: nothing.** Zero. Both surfaces keep the query budget of the grid they
already build. `RotaGridTest::test_the_whole_grid_is_a_bounded_number_of_queries` stays pinned at
`assertLessThan(20)` (measured 16 on a populated year), and **the read view carries its own budget
test at its own bound** — measured the same way, on a **populated** year. P1d-1's pre-merge finding
3 is the reason this is spelled out: the grid's budget was originally measured on an empty year, so
it only ever proved the empty case, while every N+1 the class warns about is per-span or
per-vacation. Task 4's budget test seeds 1170 spans, 120 vacations, 30 mid-year promotions and ten
stale people before measuring, exactly as `RotaGridTest` now does, and takes its bound from a first
run against a deliberately unreachable number.

**Being pure also buys the parity proof.** Task 5 calls `AvailabilitySummary::forGrid()` once with
the editor's grid and once with the read view's grid for the same year and asserts the two results
are identical — which is only possible because the function has no hidden inputs.

### What it counts, exactly

Per **period** (the outer key is `period_id`):

| Key | Meaning | Why it is not optional |
|---|---|---|
| `by_level_unit` | `[level_id][unit_id] => {people: n, days: n}` | MR-07's literal ask. `level_id` is the cell's own `level_id` — the level held at **that period's** start — never the row's `group_level_id`. A mid-year promotion means a person is R2 in Block 4 and R3 in Block 9, and a summary keyed on the row group would report both under R2. |
| `assigned_days` | total assigned days across the period | the denominator everything else reads against |
| `uncovered_days` | `sum(cell.uncovered_days)` over non-stale rows | owner decision 3 makes a gap a **legal** state; a summary that omits it cannot distinguish a finished period from a half-planned one |
| `people_with_a_gap` | count of people whose cell has `uncovered_days > 0` | 26 uncovered days is one person's whole block **or** 26 people missing a day each. Those are different facts and reporting only the sum rounds the difference away — which is precisely what §35's *"match reality"* forbids. |
| `unassigned_people` | count of people with **no** span in the period at all | distinct from a gap: nothing was planned, rather than something was planned incompletely |
| `weeks` | `[{starts_on, ends_on, clipped_starts_on, clipped_ends_on, on_vacation: n, person_ids: [...]}]` | MR-07's *"including who is on vacation each week"*. Read straight from the period's `weeks` prop. |
| `stale_people` | count of period-cells held by a person who is off the active roster — one per PERSON, however many spans they hold there | see Decision D. Named `stale_assignments` until the adversarial review: it counted cells and was rendered as "N assignment(s)", and "assignment" already means a `master_rota_assignments` row elsewhere in this codebase. |

**How a week is decided, without any date arithmetic.** A person is on vacation in a week when
their vacation's `[starts_on, ends_on]` intersects the week's `[clipped_starts_on,
clipped_ends_on]`. All four are `Y-m-d` strings already in the props, and `Y-m-d` compares
correctly as a string — so the test is `$vac['starts_on'] <= $week['clipped_ends_on'] &&
$vac['ends_on'] >= $week['clipped_starts_on']`. **No `Calendar` call, no `DateTime`, no client
math.** The weeks themselves came from `Calendar::weeksIn()` when `RotaGrid` built the props, which
is the one converter (ST-06). This is the same string-comparison idiom `RotaGrid::cellFor()`
already uses to decide which vacations touch a cell.

**A split contributes days to two units and counts as one person in each.** That is the honest
reading: a person split PICU/NICU is genuinely on both that period.

---

## Decision C: the rota asks for a contact-free projection rather than a viewer-dependent one

Finding 3 is the problem: `PersonPolicy::viewContact()` is true for **any** signed-in account once
a department sets `contact_visibility` to `members`, so `PersonPresenter::one($person, $viewer)` on
a rota surface emits `email` and `phone` whenever that toggle is on — to a `rota.manage` holder
today, and to every resident the moment the read view exists.

Three options were considered.

- **Pass `null` as the viewer.** Works (the presenter's gated blocks both check `$viewer !== null`)
  but lies at the call site: the rota *has* a viewer. A future reader "fixing" the null by passing
  the real user would silently reopen the hole, with no test failing.
- **Filter the contact keys out after `PersonPresenter::one()` returns.** A second projection in all
  but name, and the exact thing `ContactFieldsAreProjectedOnceTest` exists to refuse.
- **Name the intent on the presenter itself.** Chosen.

```php
/**
 * A projection for a surface that has NO business showing contact detail, whatever the viewer
 * holds and whatever `institutions.contact_visibility` says. Delegates to one() — this is not a
 * second projection, it is one() with the contact question answered "no" at the call site instead
 * of by a department toggle.
 *
 * The master rota is the whole reason it exists: `rota.view` is seeded for every authenticated
 * member, so a rota screen is read by the entire department, and no rota screen renders an email
 * address or a phone number in any state.
 */
public static function contactFree(Person $person, array $extra = []): array
{
    return self::one($person, null, $extra);
}
```

`RotaGrid::forYear()` switches to it, which **also closes the editor's existing exposure** — so the
fix lands on both surfaces from one edit. `RotaGrid::forYear()`'s `?User $viewer` parameter becomes
unused and is **removed**, so that no future caller can pass a viewer and expect it to mean
something. There is exactly **one** production call site today (`MasterRotaController::index():54`
— verified by `grep -rn "RotaGrid::" app/`); Task 3 adds the second, in `RotaController`, and it
must be written against the new one-argument signature rather than the old one.

The property Task 3 asserts is deliberately stronger than "a resident sees no contact": **no rota
surface emits a contact field for any viewer**, asserted for an administrator holding
`people.manage` on a department with `contact_visibility = members` — the single most permissive
combination the system can produce. A test written against a resident would pass with the bug still
present for an administrator, and the props are the disclosure surface regardless of who reads
them.

`PersonPresenter` remains the only path from a `Person` to Inertia props;
`ContactFieldsAreProjectedOnceTest`'s allow-list is unchanged, because the new method lives in the
one file that list already permits.

---

## Decision D: a stale person is excluded from the read view and from the coverage numbers, and counted separately

P1d-1's pre-merge review added **stale rows** to the editor grid: people are deactivated, never
deleted, so "assigned in March, left in April" is a state the system reaches on its own, and
without the row the operator had no control to clear the cell — which then blocked
`PeriodController::destroy()` and, with it, **P1b's** Decision D unlock of
`period_type`/`academic_year_start`, forever. (Two different Decision Ds are in play across these
plans; the one referenced here is P1b Task 10's hard-lock, not the one you are reading.)
`RotaGrid` unions them
in and flags the row `stale`; `MasterRota.vue` renders them read-only except for Clear.

The read view's needs are different, and so is the summary's.

**On the read view: hidden.** MR-05 exists so a resident can see which unit they rotate through
next and search for a colleague. A person who has left the department appearing in that list, with
a unit beside their name, reads as current staffing and is wrong. They are not reachable by search
either — the filter runs server-side over the already-filtered row set, so there is no query string
that surfaces them.

**In the summary: excluded from coverage, surfaced as their own number.** This is the part that
matters for §35. Counting a departed person's PICU block as coverage **overstates availability**,
which is the exact failure *"availability summaries match reality"* names. Zeroing them silently
would be equally dishonest in the other direction — those cells really are occupied, and something
has to be done about them. So `stale_people` is its own per-period count, and a non-zero value
is an administrator's to-do: open the editor, clear those cells.

**The ordering trap, stated because it is easy to get backwards.**
`AvailabilitySummary::forGrid()` must be handed the **full** grid, including stale rows — it is
what computes `stale_people`. The read controller filters `rows` for display **after** calling
it. Filtering first loses the number entirely, silently, and the summary would still look
plausible. Task 4's test asserts a non-zero `stale_people` on a year that has one, from the
read view's own props.

The editor's summary (Task 5) uses the identical computation and so reports the identical
`stale_people`, which is what makes Task 5's parity assertion meaningful rather than tautological.

---

## Decision E: fill copies a span set within a period, and only a whole-period assignment across periods

The trap: an assignment is a **date-bounded span**, and every span must lie inside its own period
(`MasterRotaAssignment::booted()` refuses otherwise, and `RotaAssignment::split()` refuses before
it). Periods are not the same length — MR-01 allows block lengths to vary within a year, and a
calendar-month system has 28-to-31-day periods by construction. So "copy this cell to that cell"
has two very different meanings.

- **Fill-down (same period, different person).** The target period **is** the source period, so the
  span dates are already inside it by construction. The span set copies **verbatim, splits
  included**. This is the operation an operator actually wants: "every R1 is on PICU for Block 11,
  and the four of them who join late start on the 9th".
- **Fill-across (same person, later periods) and copy-period (whole column to whole column).** The
  target period has different bounds. There is no correct way to map "PICU for the first 9 days,
  NICU for the rest" onto a period of a different length: proportional rescaling invents dates
  nobody chose, and clamping silently truncates one of the two units. So a source cell carrying a
  **split** is not fillable across periods at all — the preview reports it as `SKIP_SPLIT_SOURCE`
  with the reason named, and the operator splits the target cell by hand. Only a **whole-period**
  source assignment fills across, and it lands as a whole-period assignment on the target's own
  bounds via `RotaAssignment::set()`.

This is a real limitation and it is deliberate. Naming it in the preview is what makes it a
*decision* the operator sees rather than a *behaviour* they discover when Block 12 looks wrong.

**Fill-across is forwards only.** From the source period's `position` to the end of the academic
year. Backwards would overwrite periods the department has already worked, and MR-06 does not ask
for it.

**Two fill-down actions, not one that guesses.** *"Fill this level group"* and *"Fill this whole
column"*. A single control that infers which one the operator meant is a control that is wrong half
the time on the most destructive surface in the rota.

---

## Decision F: one preview/confirm engine, one transaction, one audit row, and `rota_fill` joins the watch list

All three bulk operations share `App\Support\Rota\RotaFill`, and it copies
`AccessControlController::updateRoles()`'s discipline (P1 finding 12) and `RosterImport`'s
(P1c) in equal measure:

1. **`plan()` and `apply()` share ONE `analyse()`.** `plan()` is `analyse()` with no writes;
   `apply()` calls the same `analyse()` **inside its own transaction** and never trusts what an
   earlier `plan()` said. Same reason `RosterImport::commit()` re-derives rather than trusting a
   client round trip: the rota can change between the preview and the confirm.
2. **The whole set is validated and authorized before any mutation.** Every target cell is resolved,
   every target person checked against the strict active predicate (finding 10), every source unit
   checked active, every span checked for containment — for the **entire** operation — before the
   first write. A refusal refuses the whole operation, never "412 of 780 applied".
3. **One transaction**, with the delta computed inside it. Nested writer transactions are expected
   and correct (finding 9).
4. **One `rota_fill` audit row per operation**, written **after** the transaction commits (finding
   8), carrying ids and counts only:
   `op=fill_down_level;source_person=<id>;source_period=<id>;targets=<n>;assigned=<n>;replaced=<n>;skipped=<n>`.
   Never one row per cell — 780 chain appends serialize the audit tail (P1 finding 11), and would
   put 780 findings in one `OpsAlert` body.
5. **`rota_fill` joins `AuditAnomalies`' single-occurrence watch list.** Decision H recorded that
   P1d-1 deliberately added **none** of its five actions there, because per-cell editing is
   ordinary work — and named this exact action as the one that belongs. It is the first rota entry
   on that list. One fill produces one finding, which is the intent: a single confirmation that
   rewrote several hundred cells always deserves a human look.

**Outcomes, per target cell** (the preview renders every one of them with its reason):

| Outcome | When |
|---|---|
| `ASSIGN` | the target cell was empty |
| `REPLACE` | the target held a different whole-period assignment |
| `UNCHANGED` | the target already holds exactly this — not written, not counted as a change |
| `SKIP_SPLIT_TARGET` | the target carries a split and the operator has not confirmed this cell |
| `SKIP_SPLIT_SOURCE` | fill-across/copy-period from a split source (Decision E) |
| `SKIP_STALE_PERSON` | the target person is off the active roster (finding 10 — offer/write parity) |
| `SKIP_RETIRED_UNIT` | the source unit is no longer active (same rule) |

**`SKIP_SPLIT_TARGET` is the silent-data-loss guard, and it defaults to skip.** A blanket fill
destroying deliberate split work is the exact class this codebase keeps guarding against, and the
confirmation is **per target cell**, shaped like `RosterImport`'s `$confirmations` array (position
=> bool, absent means false). An "overwrite all splits" master tick is offered on the preview, but
it sets the individual boxes rather than replacing them, so the confirmed set is always explicit in
the request body and always visible in the preview the operator is looking at.

---

## Decision G: the export is two files on two routes, behind `cap:rota.manage`, carrying no contact field

**Two files, not one.** `rota.csv` is one row per span; `vacations.csv` is one row per vacation. A
single file mixing two row shapes is either sparse (half the columns blank on every row) or
ambiguous (the importer has to guess which shape it is reading), and the second is how an importer
silently misreads a file.

**Two routes, not one route with a `?file=` parameter and not a zip.** `GET
/admin/rota/export/assignments` and `GET /admin/rota/export/vacations`, each streaming through
`App\Support\Csv::stream()`. No archive: a zip would add a packaging path and an `ext-zip` question
for no benefit when the screen can simply offer two buttons. Each URL is independently
bookmarkable and independently audited.

**Behind `cap:rota.manage`, not `cap:rota.view`.** The read view answers "which unit am I on";
a whole-year CSV extraction is an administrative act, it is the input to the importer, and it earns
its own audit row (`rota_export`, counts only). It also keeps the `cap:rota.view` group at exactly
one GET route, which is what Decision A's router-level assertion pins.

**Columns.** A person is identified by `short_name` (finding 5 — the app-wide unique handle) plus
`full_name` for humans. **No email. No phone. No `person_id`** — ids are instance-local and
meaningless in another deployment, and D11 makes cross-instance identity a non-question anyway.
Because no contact field appears, EX-02's contact-bearing-export question does not arise at all.

- `rota.csv`: `academic_year, period_position, period_label, period_starts_on, period_ends_on,
  short_name, full_name, unit_code, starts_on, ends_on`
- `vacations.csv`: `short_name, full_name, starts_on, ends_on, granularity, source`

**A person with no `short_name` exports with a blank handle and cannot be re-imported** (finding
5). That is surfaced **before** the file is generated — the export screen names how many people in
the year lack a short name and offers to go fix them — rather than discovered when the re-import
reports `SKIP_UNKNOWN_PERSON` on a third of the file.

Every cell goes through `Csv::neutralise()` on the way out (it is inside `Csv::stream()`), BOM
first, and Task 11's round-trip test proves the pairing at the feature level, not just at the
primitive's.

---

## Decision H: the importer never invents anything, and its unit of outcome is the cell

`App\Support\Rota\RotaImport` copies `RosterImport`'s discipline **exactly** where the shapes match,
and states each deliberate difference in its own docblock.

**Same:** `preview()` and `commit()` share ONE `analyse()`; the whole file is validated before any
write; the commit is pinned to the SHA-256 digest of the exact bytes the preview ran against and
422s naming the mismatch otherwise; a file-level error refuses the **whole** import, never "7 of 8
imported"; the reader is `App\Support\Roster\CsvRosterReader` behind the `RosterReader` port
(finding 11), so xlsx remains one class plus one composer line away; audit rows carry counts only
and are written after the transaction commits.

**Different, deliberately:**

1. **No column-mapping UI.** `RosterImport` takes an operator `$mapping` because a hospital HR
   spreadsheet has arbitrary headers. A rota file round-trips from **this system's own export**, so
   headers are fixed names, matched case-insensitively after trimming, and a missing required
   header is a file error naming the header. A mapping screen here would be ceremony with a drift
   surface.
2. **The unit of outcome is the (person, period) CELL, not the line** (finding 12), with the
   contributing line numbers listed on the outcome. Two lines describing two halves of one split
   period are one `REPLACE`, because `RotaAssignment::split()` replaces the whole set.
3. **It never rediscovers a retired person.** `RosterImport::analyseRow()` matches
   `withTrashed()` on purpose — rediscovering a soft-deleted person instead of duplicating them is
   its whole job. Here the roster is not the importer's business: a `short_name` that resolves to
   an inactive or soft-deleted person is `SKIP_UNKNOWN_PERSON` with *"no longer on the active
   roster"* as the reason. That is finding 10's offer/write parity again — `RotaCellRequest`
   refuses to name such a person on a write, so the importer must too.
4. **It never invents a person, a unit or a period.** Unknown `short_name` →
   `SKIP_UNKNOWN_PERSON`. Unknown or retired `unit_code` → `SKIP_UNKNOWN_UNIT`. An
   `(academic_year, period_position)` pair with no row → `SKIP_UNKNOWN_PERIOD`. **No create path
   exists for any of the three**, and `RosterNeverMintsCredentialsTest` gains this file so it
   cannot grow one for `users` either.
5. **One extra outcome on the vacations file: `SKIP_DUPLICATE`.** Assignments are idempotent by
   construction — they are keyed on (person, period) and REPLACE — so importing the same file twice
   changes nothing. A vacation has **no** natural key, so the same import run twice would double
   every leave row. A row whose person and **snapped** bounds already exist is
   `SKIP_DUPLICATE`. The asymmetry is explainable rather than arbitrary, and this outcome is
   named in the plan because the P1d-1 scoped text's outcome list did not anticipate it.

**Period resolution is by `(academic_year, position)`** (finding 6), never by `label` — labels are
administrator-editable text and two years can share one.

**`week`-granularity vacations snap through the same code path as the screen** (owner decision 3).
Task 11 Step 1 extracts `VacationBooking::snap()` from `book()` so that "same code path" is
literally true: `book()` calls it, and the preview calls it to *display* the adjustment. It writes
nothing, so `RotaWritersAreSingularTest` is unaffected.

---

## The split: P1d-2a and P1d-2b

P1d-1 was twelve tasks and took a full branch. P1d-2 as scoped is a read view, a summary
computation, three bulk write operations with a shared preview engine, a two-file export, a CSV
importer with its own preview/commit/digest machinery and its own fixture corpus, plus the document
corrections. **That is two branches**, on the same evidence that split P1c and P1d.

| | Scope | Requirements | Tasks |
|---|---|---|---|
| **P1d-2a — Read and summarise** | The `rota.manage` default correction; `AvailabilitySummary` as a pure fold; the contact-free projection and the read view (`/rota`, search, level filter, per-person period strip); the summaries on **both** surfaces; the read-view e2e journey. | MR-05, MR-07 | 1–6 |
| **P1d-2b — Move** | `RotaFill`'s preview/confirm engine; the fill commit path with its single `rota_fill` audit row and its `AuditAnomalies` watch entry; the fill UI; the two-file export; `RotaImport` and its synthetic fixture corpus; the import screen and its e2e journey; the MR-04 restatement and the documents. | MR-06, MR-04 (restated) | 7–13 |

**The seam is read versus bulk-write.** P1d-2a adds **zero write paths** — not one new route that
mutates anything, not one new call into either writer. It is entirely read-side, which means it
ships behind `rota.view` with no new risk to a single row of data, and it can be reviewed as a
disclosure-and-correctness question rather than a data-integrity one. P1d-2b is **all** write:
three bulk operations and an importer, every one of them going through `RotaAssignment` /
`VacationBooking`, every one of them subject to the same bulk discipline — validate the whole set
first, one transaction, one summary audit row. Reviewing that property once, over one branch, is
worth more than reviewing it twice across a boundary drawn somewhere else.

**Two further reasons the seam is here and not elsewhere.**

- **MR-07 is the Stage 1 acceptance criterion.** Putting it in 2a means *"availability summaries
  match reality"* becomes demonstrable a branch earlier, without waiting for an importer that has
  nothing to do with it.
- **The read view is what makes the contact fix urgent** (finding 3). Landing Decision C in 2a,
  before any bulk surface exists, means the disclosure is closed at the earliest point rather than
  riding along behind a larger change.

**Nothing in 2b changes anything 2a builds**, and both sides leave the tree deployable. The one
ordering dependency runs the other way and is small: 2b's fill preview renders the same cell shape
2a's read view renders, so 2a's presenter conventions are settled first.

---

## Migration ordering

**None.** P1d-2 adds no migration in either half — see [What this plan is, and is
not](#what-this-plan-is-and-is-not). P1e's `2026_08_16_*` allocation stays free.

---

## Amendments made during execution

*(Empty at plan time. Follow the P0c/P0d/P1a/P1b/P1c/P1d-1 convention: when a task turns up
something this plan's enumeration missed — a site not listed, a test that goes red for a reason the
plan did not predict, a behaviour that differs between SQLite and MySQL or between UTC and
Asia/Riyadh — record it here, dated, with what was found and how it was resolved. Findings caught
empirically rather than by inspection are the ones worth writing down.*

*The base rate is not low: P1a recorded nine amendments across nine tasks, P1b eight across
thirteen including two real plan errors, P1c thirteen across twelve including three cases of the
plan contradicting its own tests and four cases of stale expected-test-count arithmetic, and
**P1d-1 recorded roughly a dozen across twelve tasks — whose single most repeated class was task
text contradicting the plan's own decisions block**. That is why every decision above is folded
into the task text below rather than left in the block, and why finding 1 exists at all. **Assume
this plan is wrong somewhere too**, and in particular: run `php artisan test` before touching any
file at the start of each task and trust the measured baseline over this document's arithmetic.)*

**Task 1 (2026-08-10) — finding 1 undercounts. `rota.manage` said "Administrator and Chief
Resident" at SIX sites, not four.** The four the plan enumerates are all real and all corrected.
Two more were found by grepping the tree rather than by reading the plan, and both would have
survived the task as written:

1. **`AccessControlSeeder::DESCRIPTIONS['rota.manage']`** — the plan's "Files touched" names
   `ROLE_DEFAULTS[5]` and the comment above it, but the same file carries the capability's long
   description, ending *"Default: Administrator and Chief Resident — Chief Resident is Munawib's
   Scheduler persona and owns the master rota"*. That string is not a comment: it is what an
   administrator **reads on the Access Control screen** when deciding who to grant. Leaving it
   would have had the screen assert the reversed default at the exact moment an administrator used
   that screen to perform owner decision 2's own documented remedy. Rewritten to *"Default:
   Administrator only (owner decision, 2026-08-10)"*, keeping the Scheduler-persona note as the
   reason a department might choose to grant it. Blast radius is fresh instances only: the seeder
   uses `firstOrCreate` and backfills a description only when it is `null`, so an already-seeded
   instance keeps the old wording — the same never-re-assert discipline as finding 2, and out of
   scope for the same reason.
2. **`docs/RUNBOOK-DEPLOY.md` already contained the claim.** The plan says the runbook "gains a
   short note", implying an addition to a document that was silent on it. It was not — the P1d-1
   post-deploy checklist bullet read *"`rota.view` lands on EVERY seeded role automatically,
   `rota.manage` on Administrator and Chief Resident"*. That bullet was corrected to
   Administrator-only **and** finding 2's operator note added beside it, so the runbook does not
   contradict itself two bullets apart.

**Task 1 (2026-08-10) — the task's arithmetic (1159 → 1160) is correct; the number on screen is
not, and the reason matters more than the number.** The committed-tree baseline measured 1159,
exactly as the plan states. After Task 1 the full suite reports **1170**. The extra ten are not
Task 1's: another worker's in-progress **Task 2** files (`app/Support/Rota/AvailabilitySummary.php`
plus its nine-test spec, and `RotaGrid.php`'s per-span `days` count with
`RotaGridTest::test_every_span_carries_its_own_day_count`) were present in the same working tree.
Established by diffing `phpunit --list-tests` across the change rather than by subtracting
expected counts: Task 1 renames two tests, adds one, and nets **+1**. **Consequence for anyone
executing a task in this tree: `git commit -am` is unsafe** — the command this plan gives at the
end of Task 1 would have swept a neighbouring task's unfinished work into Task 1's commit. Task 1
was committed by explicit path (five files) instead. Prefer `git commit <paths>` for every task
here, and check `git status` before committing rather than after.

**Task 2 (2026-08-10) — Decision B's `by_level_unit` example is out by the headcount.** The task
text specifies *"two people at R1 on PICU for a whole period … `by_level_unit[r1][picu] ===
['people' => 2, 'days' => 28]`"*. Two people covering a 28-day period is **56** person-days; `28`
would be indistinguishable from one person covering the period alone, and it contradicts
`assigned_days` (*"total assigned days across the period"*, which is 84 for that fixture). Shipped
as person-days, and `test_it_counts_people_and_days_per_level_and_unit` now also asserts the
invariant the plan's figure would have broken: the buckets sum to `assigned_days`.

**Task 2 (2026-08-10) — `people_with_a_gap` and `unassigned_people` ship DISJOINT.** Decision B's
table defines `people_with_a_gap` as *"count of people whose cell has `uncovered_days > 0`"*, which
would also count everybody with no span at all, because `RotaGrid` reports their whole period as
uncovered. The task's own test name — `test_a_person_with_no_span_at_all_is_unassigned_not_a_gap` —
says the opposite, and it is the better answer: disjoint counts add up, so an operator reading
"five with a gap, three unassigned" knows eight people need attention rather than somewhere between
five and eight. A person with no span is counted in `unassigned_people` only; their days still land
in `uncovered_days`.

**Task 2 (2026-08-10) — a cell whose `level_id` is null needed a bucket the plan does not mention.**
`by_level_unit` is keyed on the cell's own level id, which `RotaGrid` leaves null for a person with
no level span covering that period. Dropping them would break the buckets-sum-to-`assigned_days`
invariant above and hide them from the one screen that exists to show where everybody is, so they
bucket under `AvailabilitySummary::NO_LEVEL` (`0` — never a real `levels.id`).

**Task 2 (2026-08-10) — `uncovered_days` was a FLOAT, and nothing had noticed.** Carbon 3's
`diffInDays()` returns a float, so `RotaGrid::cellFor()` was emitting `uncovered_days` as `8.0`, and
the new per-span `days` would have shipped the same way. Both are cast to int at source now, and
`RotaGridTest::test_every_span_carries_its_own_day_count` asserts with `assertSame` so the type is
pinned rather than incidentally right. On the plan's *"check, do not assume"* instruction about the
span-prop shape: no existing `RotaGridTest` case pins the exact key set of a span, so the new `days`
key needed no assertion updated — only the float that the new case's `assertSame` exposed.

**Task 3 (2026-08-10) — finding 3's disclosure claim HELD, and it is wider than the finding
states.** Verified empirically before implementing, by watching
`test_the_editor_grid_is_contact_free_too` fail against the committed tree. The failure named four
leaked paths on a two-row grid:

```
grid.rows.0.person.phone   grid.rows.0.person.email
grid.rows.1.person.phone   grid.rows.1.person.email
```

The finding describes the leak as conditional on `contact_visibility = members`. It is not, for an
administrator: `PersonPolicy::viewContact()` is `people.manage OR membersMaySeeContact()`, so the
FIRST branch alone already released both fields to any `people.manage` holder on the DEFAULT
setting — and `people.manage` is exactly what the `/admin/rota` viewer holds. (That half is
already pinned by a green test in the tree,
`ContactProjectionNarrowsTest::test_a_roster_manager_still_sees_the_email`, which sets no
institution setting at all.) So the editor grid was disclosing contact detail for its typical
viewer on a stock department, not only for an opted-in one. The fix is unchanged in shape —
`RotaGrid` asks for the contact-free projection and takes no viewer — but the finding understates
what it closes.

**Task 3 (2026-08-10) — the router-level GET assertion is VACUOUSLY GREEN before the route
exists, and a second case was added to say so.** The plan's snippet passed on the first run against
the committed tree: it iterates the routes carrying `cap:rota.view`, and before this task there
were none, so `$offenders` was empty and the guard proved nothing. That is the standing rules'
"a test that passes on first run has proved nothing" in its most literal form, and here it is a
permanent property rather than a one-off — deleting or renaming the group at any future date
silently re-empties the set. `test_the_read_view_route_is_actually_registered_behind_cap_rota_view`
ships beside it and asserts the set is non-empty; it went red as expected. The two fail for
different reasons and neither subsumes the other, which is the same argument the plan already makes
for shipping the router assertion alongside the per-route 403 cases.

**Task 3 (2026-08-10) — the publish-state scan cannot walk the WHOLE props tree; `flash.status`
is a shared Inertia prop.** `HandleInertiaRequests::share()` emits `flash.status` (the layout's
one-shot banner channel) on every page in the app. A scan for a `status` key over the full props
tree therefore fires on every request regardless of the rota, so
`test_there_is_no_publish_state_on_the_read_view` excludes the five shared keys (`auth`, `nav`,
`shift`, `flash`, `errors`) and scans the controller's own props. The CONTACT scan deliberately
does **not** exclude them — no shared prop carries a contact field today, and if one ever does,
that test should be the thing that says so.

**Task 3 (2026-08-10) — measured query cost of `/rota`: 16 on a populated year, and
`AvailabilitySummary` adds none of them.** Measured with the same fixture `RotaGridTest`'s budget
case uses (60 people, 13 periods, 1170 spans, 120 vacations, 30 mid-year promotions, ten stale
people) via a throwaway test, deleted after reading the figure. In one process: `/rota` as a
resident **16**; `/admin/rota` as an administrator **14**; a SECOND `/rota` request in the same
process **11**. The 16→11 drop between the first and second request by the same actor is
first-request session/capability-catalogue warmup, not grid work — which is also why the resident's
16 and the administrator's 14 are not comparable as a difference between the two screens. The
number for Task 4 to pin is **16**, the same figure `RotaGridTest` measures for the editor against
its `assertLessThan(20)` bound; Decision B's "zero query cost" claim for the summary holds
(`AvailabilitySummaryTest::test_it_issues_no_query` proves the other half compositionally).

**Task 3 (2026-08-10) — a minimal `resources/js/Pages/Rota.vue` shipped in Task 3, not Task 4.**
The plan lists that file under Task 4 only, which would leave a committed tree where `GET /rota`
resolves server-side and then fails in the browser because the Inertia page component does not
exist. `npm run build` stays green either way (the page glob resolves at runtime), so nothing would
have caught it — but "tree deployable after every commit" is a CLAUDE.md non-negotiable, and an
unreachable-but-registered route that 500s on the client is not deployable. What shipped is
deliberately the smallest honest screen: the year picker, the two empty states, and a read-only
table of person × period carrying unit codes. **No search input, no level filter control, no
summary panel, no mobile cards** — Task 4 replaces this file wholesale and should treat it as a
placeholder, not a starting point. Note that the search and level filter are already applied
SERVER-side by `RotaController` (Task 3 implements them; Task 4 only tests them and adds the
inputs), so `filters` is populated and correct before any control exists to set it.

**Task 4 (2026-08-10) — the task text contradicts itself about `<select>`, and the contradiction
is worth resolving in writing rather than silently.** Its opening says *"Read-only means read-only:
**no `<select>`**, no Split…, no On leave…, no Clear, no form of any kind"*, and its own Vitest
case 6 repeats it (*"no `<select>` … appears anywhere"*) — while its implementation section, four
paragraphs later, specifies *"a search input and a level `<select>`"* plus the year picker, which
MR-05 requires by name. Both cannot hold. The property that actually matters is **no WRITE**: the
three controls on this screen navigate (`router.get`, query string, GET route), and the whole
`cap:rota.view` group is GET-only at the router. So the shipped assertion is the honest form of the
same idea, and it is stronger than the literal one in two ways: the **strip itself** carries no
`<select>`, `<button>` or `<input>` at all, there is no `<form>` anywhere, and **every button in the
page's own `main` landmark is clicked** with `router.patch`/`post`/`delete` asserted never called.
That last part had to be scoped to `main#main-content` after it went red on
`AppLayout`'s own "Sign out", which is a `router.post` on every screen in the app — a real find,
but a fact about the layout, not about the rota.

**Task 4 (2026-08-10) — three of the four PHP cases were green on first run; the two that were not
are the ones this task actually decided.** `test_search_and_filter_narrow_the_rows_but_not_the_
summary`, `test_a_deactivated_person_..._is_not_on_the_read_view_but_is_counted` and the query
budget all passed against the committed tree, because Task 3 shipped the server-side filtering and
Decision D's summary-then-filter ordering together (its own amendment says so). Checked rather than
assumed that each would have failed before Task 3: `/rota` did not exist as a route, so all three
404'd. The two that went red — `test_it_lands_on_the_academic_year_that_contains_today` and
`test_it_falls_back_to_the_most_recent_year_when_today_falls_in_none` — failed with
*"Failed asserting that null is identical to '2050-2051'"*, i.e. the screen rendering its
choose-a-year empty state, which is exactly the behaviour they were written to change.

**Task 4 (2026-08-10) — THE LANDING-YEAR DECISION: `/rota` with no `?year=` resolves the year
CONTAINING TODAY, falling back to the most recent generated.** Task 3 flagged this as a judgment
call. The editor's "choose an academic year" is right for `/admin/rota` — picking the year to plan
is the first decision an administrator makes — but a reader has no such decision, and an empty
screen with a picker asks them a question they did not have. The obvious one-liner (`$years->last()`)
was rejected as the *wrong* default rather than merely a lazier one: P1b's Periods screen exists so
a department can generate next year ahead of time, and the day an administrator does that, every
resident's landing page would silently move onto a mostly-empty future grid while the year the
department is actually working still had months to run. `RotaController::landingYear()` costs **one
query**, and only on a request that named no year — a `?year=` request (every link, bookmark and
filter submission the screen produces) never reaches it, so the measured grid budget is unchanged.
An unrecognised `?year=` lands the same way; the picker always shows which year is on screen, so
nothing is silent. Three cases pin it, including
`test_a_department_with_no_periods_lands_on_no_year_at_all` — the empty-department state still
renders its empty screen rather than a 500.

**Task 4 (2026-08-10) — measured query budget for `/rota`: 16, bound pinned at 20.** Read from a
first run against a deliberately unreachable `assertLessThan(1, …)` on the populated fixture (60
people, 13 periods, 1170 spans, 120 vacations, 30 mid-year promotions, ten stale people), then
restored. Identical to Task 3's measurement and to `RotaGridTest`'s own figure for the editor, which
is Decision B's "the summary costs nothing" claim holding at the request level rather than only in
`AvailabilitySummaryTest::test_it_issues_no_query`.

**Task 4 (2026-08-10) — an EMPTY cell renders "Unassigned", not the plan's day count.** The plan's
Vitest case 3 says *"a cell with uncovered days renders the count"*, which taken literally would
print "28 day(s) not yet assigned" on a person with no span at all — a reading that makes nothing
planned look like a planning error, and one the summary already reports better as
`unassigned_people`. The count renders on a **partly** covered cell (the split-with-a-gap case it
was written for); an empty cell says "Unassigned" in words. This is the same disjoint-counts
argument Task 2's own amendment settled server-side, applied to the screen.

**Task 4 (2026-08-10) — `tests/js/AppLayout.test.js` gained two cases the plan's file list does not
name.** The task adds a nav entry, and the layout's nav is the one file in this codebase with a
test per capability-gated link (P1b's and P1c's recon risk: a capability whose only screen is
unreachable). Both went red first: a resident holding only `rota.view` sees a top-level **Rota**
entry and **no Administration section**, and somebody holding both `rota.view` and `rota.manage`
sees two links to two different URLs — Decision A's deliberate duplication, now asserted rather
than only commented.

**Task 4 (2026-08-10) — counts.** `php artisan test` 1177 → **1183** (six new cases in
`RotaReadViewTest`). `npm test` 131 → **144** (eleven in the new `tests/js/Rota.test.js`, two in
`AppLayout.test.js`). `npm run build` green. `npm run test:e2e` not re-measured — Task 6 owns it,
and no existing spec navigates by nav-link text, so the new entry cannot make one ambiguous
(checked, not assumed).

**Task 5 (2026-08-10) — the parity test's FIRST red was the test's own fixture, and the mechanism
is worth writing down because it would fool the next reader too.** `AvailabilitySummaryParityTest`
went red as designed on the missing prop, and then went red a second time after the controller was
fixed — with `unassigned_people` and `uncovered_days` off by exactly one person in every period.
Nothing was wrong with the code: the helper created its viewer inside the request wrapper, and
`User::factory()` mints a **`Person`** alongside the account (P0c: identity is two tables). So the
administrator account created for the first request put one more unassigned person on the roster
that the second request then legitimately counted. Both viewers are now created before either
request, with the reason in a comment at the call site — an availability summary counts the roster,
so anything that touches the roster between two reads changes the answer, correctly.

**Task 5 (2026-08-10) — the plan's Vitest instruction is too weak, and was strengthened rather
than followed.** The task says *"Add a Vitest case asserting `AvailabilityPanel.vue` renders the
same markup from the same props on both pages — one component, two mounts."* Mounting the
COMPONENT twice with the same props asserts that Vue is deterministic and nothing else: it stays
green through a page that feeds the component a different slice of the grid, through a page that
never mounts it at all, and through a page that re-inlines its own copy. What shipped mounts the
two **pages** whole and compares the `[data-testid="availability-panel"]` subtree pulled out of
each. Verified to fail, twice, by deliberate divergence and restore: (1) the editor passing
`:levels="[]"` instead of `grid.levels` → *"expected '&lt;section class=…' to be '&lt;section
class=…'"* plus the non-vacuity case going red on a missing `R1`; (2) the editor's
`<AvailabilityPanel/>` replaced by a hand-inlined summary section carrying the same testid → same
two cases red. Both restored and re-run green. The one divergence this shape does **not** catch is
a second, hand-inlined panel added BESIDE the shared one without the testid; the count case
(`exactly once`) catches it only if the duplicate uses the component.

**Task 5 (2026-08-10) — the panel takes `periods`/`levels`/`units` and NEVER `rows`, and that is
load-bearing rather than tidy.** The read view filters `grid.rows` for display and the editor does
not (Decision D), so any panel that read the row list would render differently on the two screens
for a reason that has nothing to do with the numbers — and the parity test would be red for a
correct implementation. Passing the whole `grid` was rejected for the same reason: it hands the
component the one input it must not use. `summary: null` renders nothing at all, so both pages
mount it unconditionally inside their existing "a grid exists" branch.

**Task 5 (2026-08-10) — `Rota.vue`'s `codeFromSpans` fallback (Task 4) is UNREACHABLE, and its
docblock says the opposite.** Task 4 shipped a fallback resolving a unit code from
`span.unit_code`, commented *"a unit RETIRED since the rota was planned is not in `grid.units` …
but its spans still carry their own `unit_code`"*. They do not: `RotaGrid::cellFor()` fills that
field from `$unitsById`, which is built from `Unit::query()->active()` — so `unit_code` is null in
exactly the retired case the fallback existed to cover, and non-null only where the `grid.units`
lookup has already succeeded. Confirmed at source (`RotaGrid.php:151` and `:280`), not inferred.
Removed rather than left as dead code with a rationale that reads as true, and the extracted panel
resolves a unit code the same way, so the strip and the summary beneath it cannot label one unit
two ways. `levelsById` in `Rota.vue` became genuinely dead when `summaryLevelRows` moved out and
went with it.

**Task 5 (2026-08-10) — both query budgets re-measured, both still 16.** Read from a run against a
deliberately unreachable `assertLessThan(1, …)` on each budget test's own populated fixture, then
restored: `/admin/rota` **16** (`RotaGridTest`), `/rota` **16** (`RotaReadViewTest`), identical to
Task 3's and Task 4's figures. Decision B's "the summary costs nothing" claim therefore holds at
the request level on the EDITOR too, not only on the read view — adding `AvailabilitySummary` to
`MasterRotaController::index()` moved neither bound.

**Task 5 (2026-08-10) — `tests/js/Rota.test.js` is in the task's file list and needed no edit.**
Its five summary assertions (`summary-cell-*`, `summary-week-*`, `summary-stale-*`) pass unchanged
through the extracted component, which is the cheapest available evidence that the extraction is
behaviour-preserving rather than a rewrite that happens to be self-consistent. `MasterRota.vue`'s
three existing spec files needed no edit either: `summary` defaults to null and a null summary
renders nothing.

**Task 5 (2026-08-10) — counts.** `php artisan test` 1183 → **1187** (four in the new
`AvailabilitySummaryParityTest`). `npm test` 144 → **150** (six in the new
`tests/js/AvailabilityPanel.test.js`). `npm run build` green. `npm run test:e2e` not re-measured —
Task 6 owns it.

**Task 6 (2026-08-10) — THE JOURNEY FOUND A REAL DEFECT, AND IT WAS NEVER ROTA-SPECIFIC: the
sidebar loses its place the moment any screen carries a query string.** `AppLayout.vue`'s two nav
helpers compared Inertia's `page.url` — which carries the full path **and** query — against a bare
href. So `/rota?year=2026-2027` was not `/rota`, the entry stopped being highlighted, and
`aria-current="page"` — what a screen reader announces — disappeared. MR-05's read view pushes a
query string from all three of its controls, so a resident lost the highlight on the first click
they made. Six screens were affected, and the rota was only the newest: `/endorsement/{unit}` with
its date filter (`Endorsement/Index.vue`, the screen the department actually lives on),
`/endorsement/compliance`, `/admin/rota`, `/admin/structure/periods` and `/admin/access-control`.
Fixed with TDD rather than worked around in the spec, per this task's own instruction: one
`currentPath` computed that splits on `?`/`#`, consumed by both helpers, so a seventh filterable
screen cannot arrive with the same bug. Red first at BOTH levels and for the same reason — a new
`tests/js/AppLayout.test.js` case (*"expected undefined to be 'page'"*) and the e2e assertion in
`rota-read.spec.js` (*"Expected: 'page' / Received: ''"*, run against a build with the fix reverted).
**Why nothing caught it before:** every existing mount in `AppLayout.test.js` stubbed a bare path
(`/dashboard`), and PHPUnit never renders the nav at all. This is the same shape as P1d-1's
missing Clear control — a screen affordance that no layer below the browser was looking at.

**Task 6 (2026-08-10) — the seeder needed a second ACCOUNT, not merely a second person, and the
read fixture is deliberately disjoint from `master-rota.spec.js`'s.** The plan's file list says "a
second person and a vacation, if the existing fixture is thin — check what P1d-1's Task 11 already
added". Checked: it added one `Person` ("E2E Rota Resident"), two periods, and nothing else — no
account, no level history, no assignment, no vacation. Every other spec in the browser suite signs
in as `admin`, who holds every capability in the catalogue, so an administrator reaching `/rota`
would have proved nothing about MR-05; `E2eSeeder` now mints a position-4 `resident` account
(`rota.view`, never `rota.manage`) and `fixtures.js` exports `RESIDENT` beside `ADMIN`. The two new
people are **not** "E2E Rota Resident" and their names do not contain that string: that person is
addressed by name in `master-rota.spec.js` (`hasText`) and has their cell edited through the
editor's controls, so seeding them an assignment would change what that spec finds. For the same
reason the seeded leave is 2026-08-16..22 (Block 2's *second* week) rather than the 08-09..15 week
`master-rota.spec.js` books — so the count this spec asserts is identical whether the suite runs
whole or one file at a time. Everything is written through `RotaAssignment`, `VacationBooking` and
`LevelAssignment`; a fixture built by a different route than the app uses can be valid while the
app's own write path is broken.

**Task 6 (2026-08-10) — every assertion that could pass for the wrong reason was made to fail on
purpose first.** Four probes, each restored afterwards. (1) `RotaController` stopped echoing
`filters` → the row set stayed narrowed and *only* `toHaveValue('Colleague')` went red, which is
exactly the client-only-filter bug the task's "assert the control's value, not just the row set"
instruction is about. (2) The seeder gave the reader the whole of Block 1 instead of seven days →
`People with a gap` went `1` → `0`, i.e. the rounded-away zero MR-07 exists to prevent, and
`Assigned days` `21` → `28`. (3) A `<button @click="router.delete(...)">` planted inside
`data-testid="rota-strip"` → the `main` button count went red. (4) The fix in the amendment above,
reverted. Only the login timeout was a natural red (no `resident` account existed yet), and it
proves the seeder, not the screen.

**Task 6 (2026-08-10) — "nothing writes" is asserted at the NETWORK, and its own non-vacuity is the
login POST.** A component-level "no `<form>`, no `<button>`" (Task 4's Vitest case) cannot see a
control that writes through something other than a form, and an empty list of observed writes looks
identical whether nothing wrote or the recorder never fired. So the request listener is attached
BEFORE `login()`: signing in is itself a POST, `signInAndWatch()` asserts the recorder caught it,
and each spec then asserts the list has not grown **since** that mark. Three assertions in total,
because each misses what the others catch: the landmarks carry no form and no button (asserted
`toBeVisible()` first, so a zero count cannot come from a locator that matched nothing), the strip
carries no control at all, and `/admin/rota` answers **403** to this actor in a real browser.

**Task 6 (2026-08-10) — the plan's steps 3–5 were implemented stronger than written, deliberately.**
Step 3/4 say "assert the narrowed set is the same" after a reload; the shipped spec also asserts the
**control's own value**, which is what proves the `filters` prop round-tripped rather than the URL
merely surviving — and it is the assertion that caught probe (1) above. Step 5 says "a non-zero
figure for the seeded period"; the shipped spec asserts all four headline figures against written-out
arithmetic, the per-level/per-unit cell for two levels, and the per-week leave count, then re-reads
every one of them through a `?q=` that narrows the rows to one person — Decision D's ordering trap
proved in a browser rather than only in `RotaReadViewTest`. The summary figures are located by the
LABEL a reader sees (`Days not assigned`), not by a testid, because the ids `summary-cell-*` is keyed
on are not visible anywhere on the screen and pinning what the screen actually says is the point of
asserting it here at all.

**Task 6 (2026-08-10) — counts, all four suites.** `npm run test:e2e` **18 → 21** (three cases in
the new `tests/e2e/rota-read.spec.js`). The 18 was re-measured against the committed tree before the
task began, per the plan's own instruction to trust the measurement over the document — and this
time the document was right. `npm test` 150 → **151** (the nav case above). `php artisan test`
**1187**, unchanged: this task adds no PHP case, and the whole suite was re-run green after the
`AppLayout.vue` fix. `npm run build` green.

**Adversarial slice review (2026-08-10) — six findings, and the two most useful were about TESTS
rather than about code.** Recorded here because four of the six were pre-existing on `main` and
would otherwise have no home.

1. **`/rota?q[]=x` was a 500, and so were four sibling sites.** Every query value is a string OR AN
   ARRAY, chosen by whoever types the URL; `(string) $request->query('q', '')` on an array raises
   `Array to string conversion`, which `HandleExceptions` promotes to an `ErrorException` and
   renders as a 500. `$request->string('q')` is not the fix — it throws on array input too. The two
   parameters read either side of it (`year` behind `is_string()`, `level` behind `is_numeric()`)
   already guarded, which is exactly what made the third easy to miss, and is why this was treated
   as a class rather than an instance: `Admin/PeriodController`'s `next_year_start` (same cast),
   `Admin/AccessControlController`'s `user_id` (different failure — `User::find(['1'])` returns a
   *Collection*, which is not null, so the null-check below it passed and `$user->getKey()` came out
   as a `BadMethodCallException`), and both `member_email` normalisers, where a pre-validation
   `?string` sink turned a would-be 422 into a `TypeError`. `tests/Feature/Security/
   ArrayShapedQueryTest.php` is named after the shape, asserts a NEGOTIATED answer at each site
   (rendered page with the parameter ignored, or a 422) and asserts the echoed filter's TYPE as
   well as the status code — a guard that swallowed `q` into `null` where the screen expects `''`
   is the same bug one layer along. All four cases were watched red, each for its predicted reason.
2. **`/rota/` and `/endorsement/` lost the highlight too.** Task 6's fix split `page.url` on
   `[?#]` and stopped, so a trailing slash — which browsers, proxies and typed URLs all produce —
   failed every `isExactly` comparison exactly as a query string had. Normalised at the one helper,
   with `/` kept whole.
3. **The twelve Administration links bound no `aria-current` at all** (pre-existing on `main`).
   They carried the visual `channel-bar` highlight and announced nothing, so a screen reader was
   told where it was on four entries and silent across the whole admin surface. All sixteen links
   now route through one `ariaCurrent()` helper, and `AppLayout.test.js` sweeps the Administration
   section PER LINK — the defect was twelve independent omissions, so one sampled representative
   would have proved one of them.
4. **`AppLayout.vue`'s docblock overstated finding 2's fix**: it named six filterable screens, but
   three of the six are Administration entries that bound nothing, so the query-string fix reached
   the highlight on six and the announced state on three. Corrected to what is true after 3 landed.
5. **`stale_assignments` counted CELLS and was rendered as "N assignment(s)".** One departed person
   holding a three-way split in one block was one cell and three assignments, so the number was
   wrong for its own label — and "assignment" already means a `master_rota_assignments` ROW here
   (`PeriodController::destroy()` refuses a year while N "master rota assignment(s)" reference it,
   and that N is rows). The COUNT is the figure worth keeping — a headcount beside two other
   headcounts, and the number of Clear controls an administrator has to press, since `MasterRota`'s
   Clear empties a whole cell splits and all — so it became `stale_people` and the sentence moved to
   meet it. Pinned by one departed person, one period, three spans.
6. **Two tests could not detect what they named, and this is the entry worth re-reading.**
   - `AvailabilitySummaryParityTest`'s mid-year-promotion line
     (`assertNotSame(array_keys($first['by_level_unit']), array_keys($last['by_level_unit']))`)
     named the bug a summary keyed on the ROW's group level would produce and could not see it:
     block 1 IS the academic year's start, so both keyings agree there by construction, and block 3
     holds one person either way — the key lists differ under the correct implementation
     (`[0, XP1, XP2]` vs `[XP2]`) and just as happily under the broken one (`[0, XP1, XP2]` vs
     `[XP1]`). **Proven** by keying `AvailabilitySummary` on `row['group_level_id']` and watching
     the line stay green. (`AvailabilitySummaryTest`'s own unit case DID catch that break — the
     parity file's line was decorative, not the only cover.) Replaced by the specific claim, plus
     the non-vacuity half that lets it fail at all, and both halves watched red in turn.
   - `AvailabilityPanel.test.js`'s "renders nothing when there is no summary" mounted
     `{ grid: null, summary: null }` — and both pages wrap the panel in a `v-else` on `grid`, so
     with a null grid the component is never mounted and the case was measuring the PAGES' guard
     under the COMPONENT's name. **Proven** by deleting the panel's `v-if="summary"` outright and
     watching the whole file stay green. Split into a null summary with a LIVE grid (the only state
     the component alone answers for, with a non-vacuity half beside it) and a separately-named case
     for the pages' grid guard.

   The two guards added in finding 2 that were green on arrival were made falsifiable rather than
   left as decoration: the segment-prefix case goes red against a bare `startsWith` (both
   `/endorsement/pic` and `/endorsement/picu` light for one screen — unit codes are
   administrator-created, so that pair is a real configuration), and the root-path case goes red
   against an inverted prefix test (six entries current at once). The root case's docblock says
   plainly that `/` → `''` is unobservable with today's nav and is pinned before something makes it
   observable — an honest weak guard, named as one, rather than a strong-sounding unfalsifiable one.

   **Counts.** `php artisan test` 1187 → **1193**, `npm test` 151 → **157**, `npm run test:e2e`
   **21** unchanged, `npm run build` green.

**Task 7 (2026-08-10) — "three operations" is four action keys, and the task text says both.** The
task's own opening names *"fill-down, fill-across and copy-period"* while Decision E says *"two
fill-down actions, not one that guesses"* and Task 8 validates *"`op` in the **four** action keys"*.
Four is the reconciled reading and what shipped: `RotaFill::FILL_DOWN_LEVEL`, `FILL_DOWN_COLUMN`,
`FILL_ACROSS`, `COPY_PERIOD`, collected in `RotaFill::OPERATIONS` so Task 8's `RotaFillRequest`
validates against the one list rather than restating it. Three *shapes*, four *actions*.

**Task 7 (2026-08-10) — THE PLAN NEVER DEFINES "a cell carrying a split", and the narrow reading is
a live data-loss bug.** Counting spans (`count > 1`) would leave a cell holding ONE span that starts
on the 9th unprotected — which is Decision E's own worked example of deliberate work ("the four of
them who join late start on the 9th"). What shipped: a cell carries a split when its span set is
anything other than empty or exactly one span covering the period end to end (the degenerate split
`MasterRotaAssignment`'s docblock names). `RotaFill::isSplit()`/`isWholePeriod()` are the one
definition, used by both the target guard and Decision E's cross-period source test.

**Task 7 (2026-08-10) — `UNCHANGED` has to be decided BEFORE the split guard, and the plan's outcome
table does not say which wins.** A target already holding exactly the source's split loses nothing,
so demanding a per-cell confirmation for it would be a tick for a no-op — and an operator taught to
tick meaningless boxes is an operator who ticks the meaningful one.
`test_an_identical_split_target_is_unchanged_and_needs_no_confirmation` pins it. The full precedence
now lives in `outcomeFor()`'s docblock: source-level refusal (retired unit, split source) → stale
target person → unchanged → split target → replace/assign, with source-level first because it kills
every target and a preview where one row blames the target and the rest blame the source reads as a
partial failure when it is a total one.

**Task 7 (2026-08-10) — `SKIP_SPLIT_TARGET` keeps its proposal; every other skip drops it.** The
plan says each target carries "the current span set and the proposed one". For the three skips
nothing legitimate can be proposed, so `proposed` is `[]` — but `SKIP_SPLIT_TARGET` is the one skip
the operator is asked to overrule, and they cannot choose between two span sets they can only see
one of. Asserted in `test_a_target_carrying_a_split_is_skipped_unless_confirmed`.

**Task 7 (2026-08-10) — the stale-person candidate set had to be scoped to the ACADEMIC YEAR, not
the source period, and only the test found it.** The first shape unioned the active roster with
people holding a span *in the source period*, which reads correct and is not:
`RotaGrid`'s row set unions anybody holding a span anywhere in the **year**, so a person who left in
April still has a row in every column — including the ones where their cell is empty.
`test_an_inactive_target_person_is_skipped` went red with *"No target planned for person 2 in period
1"*: the departed person simply was not in the plan, so a fill-down-column preview was one row
shorter than the column with nothing to say why. Fixed by widening the union's subquery to the year,
at **zero** extra queries — both halves are model query builders passed to `whereIn` (`Period::forYear()`
and `MasterRotaAssignment::query()`), deliberately not `->from('master_rota_assignments')`, because a
raw table name would be the first mention of that table outside its one writer and
`RotaWritersAreSingularTest`'s needles do not look for it.

**Task 7 (2026-08-10) — an empty source cell, an unknown op and an empty target set are ERRORS, not
empty plans.** The plan's return shape has an `errors` key and never says what fills it. A preview
that silently renders "0 cells" is indistinguishable from one whose operator picked the wrong cell.
Five errors ship: unknown operation, source period gone, empty source cell, copy-period with no or
same or out-of-year target, and a catch-all "nothing for this fill to do" when the target set comes
out empty for any other reason. `errors` non-empty always means `targets` empty.

**Task 7 (2026-08-10) — `plan()` strips a `context` key that `analyse()` returns.** `analyse()`
resolves the `Person`/`Period` MODELS it validated against, which Task 8's `apply()` dispatches to
`RotaAssignment::set()`/`split()` without a second round of queries; `plan()` unsets them, because
Eloquent models in a props payload is how a contact field reaches a page nobody meant to put it on
(Decision C, finding 3). Task 8 needs no change to `analyse()` for this.

**Task 7 (2026-08-10) — the red was watched twice, and the second time was the point.** The class was
created as a stub returning an empty plan FIRST, so the 14 behavioural cases failed on wrong
outcomes and missing targets rather than on "class not found" — the standing rules name a missing
class as explicitly not the reason a test should be red. The two remaining cases passed against that
stub **vacuously** (two empty plans are identical; a plan that resolves nothing issues no query), so
each gained a non-vacuity assertion — five targets, and a non-empty target list — and both were then
watched red. Finally, `test_plan_writes_nothing` was proved falsifiable by planting the exact defect
it exists to catch (a `RotaAssignment::set()` inside `analyse()`'s target loop, i.e. Task 8's
`apply()` leaking into `plan()`) and watching it go red before reverting.

**Task 7 (2026-08-10) — that probe also showed the guard's own failure message was 126 KB.** The
first version compared the whole serialised assignment row set with `assertSame`, so a real failure
dumped 390 rows into the runner — the "never dump a failing suite into context" rule broken by the
test meant to help. Now: row count first (the readable half), then `md5()` of the row set (which
still catches a same-count swap that a count alone would pass).

**Task 7 (2026-08-10) — measured query budgets, and they are constant in the number of targets.**
Read from a deliberately unreachable `assertLessThan(1, …)` on a 40-person, 13-period fixture, then
restored: `fill_down_level` **6**, `fill_down_column` **4**, `fill_across` **5**, `copy_period` **5**.
`fill_down_level`'s extra two are `Person::levelSpansBetween()` and its eager `level` load; the two
cross-period ops' extra one is the academic year's period list, which fill-down does not need.

**Task 7 (2026-08-10) — counts.** `php artisan test` 1193 → **1209** (sixteen in the new
`RotaFillPlanTest`). `npm test` **157** and `npm run test:e2e` **21** unchanged — this task adds no
client file and no route. `npm run build` green.

**Task 8 (2026-08-10) — the task text never says what pins the commit to the previewed plan, and
without one, "re-derives the plan" is only half of `RosterImport`'s discipline.** Decision F says
`apply()` re-runs `analyse()` inside its transaction and never trusts the client — which it does,
and which is necessary. But `RosterImport` does a second thing the task text does not transpose: its
commit refuses outright unless the sha256 of the bytes it is about to import equals the digest its
own preview returned. Re-derivation alone gives an operator who confirmed *"replace 40 cells"* an
apply of whatever the grid says by the time their click lands — silently, and with a `back()` that
looks identical to the one they expected. That is the same defect class the digest exists to close.

What shipped: **`RotaFill::plan()` returns a `digest`, `RotaFillRequest` requires it on the confirm
route, and `apply()` takes it as a fourth argument.** A fill has no file, so the digest is taken
over the plan's own STATE PROJECTION — the operation, the source cell, and per target the cell's
identity, what it CURRENTLY holds and what would be WRITTEN over it — computed by one
`RotaFill::digest()` used by both entry points, never re-typed. `outcome`/`reason` and the
confirmations are deliberately OUT of it, because they are a pure function of that state *and* the
operator's own answers: including them would invalidate the pin on every tick of a confirm box,
force a re-preview round trip per tick, and make Task 9's master "overwrite all splits" control
impossible to build. The pin catches the WORLD moving, not the operator deciding.
`test_confirming_a_cell_does_not_invalidate_the_digest` asserts exactly that pair (the confirmation
changes the summary; the digest does not). **Task 9's request body therefore carries a fifth field:
`{op, source_person_id, source_period_id, target_period_id?, confirmations, digest}`** — the task
text's list omits it.

**Task 8 (2026-08-10) — consequently, the plan's own test 1 describes the wrong behaviour.** It asks
for *"a request whose claimed plan says 'replace 40 cells' while the database has since changed;
assert the applied set matches a fresh analysis, not the claim"*. Taken literally that is the
silent-divergence defect above: the fill would apply a set the operator never saw. Under the pin a
changed grid is a **refusal** — 422 on `digest`, zero rows written
(`test_a_fill_is_refused_when_the_grid_changed_under_the_operator`). The "does not trust the
request" half is kept as its own test
(`test_the_spans_come_from_the_source_cell_and_never_from_the_request`, which posts a forged
`unit_id`, a forged `spans` array and a forged `targets` array and asserts the written row carries
the SOURCE cell's unit).

**Task 8 (2026-08-10) — the refusal is a typed exception, not a string in `errors`.** The controller
has to tell "the rota moved" apart from every other refusal to put the message on the right field,
and picking an HTTP field by matching an error message's text is the drift this codebase keeps
removing. `App\Support\Rota\StaleFillPlanException` (new file, not in the task's "Files touched") is
thrown from INSIDE `apply()`'s transaction, so the refusal rolls back rather than resting on a
`return` that is only safe while nothing above it writes.

**Task 8 (2026-08-10) — the audit detail gains two keys the plan's format omits, and one of them is
a real gap.** Decision F specifies
`op=…;source_person=…;source_period=…;targets=…;assigned=…;replaced=…;skipped=…`. Two problems.
(1) **`target_period` is missing**, so a `copy_period` row records that a column was overwritten but
not *onto which* — the single most important fact about that operation, and unrecoverable
afterwards. (2) `unchanged` is missing, so `targets` does not equal the sum of the outcome counts
and a reader has no way to tell a short row from a lost one. Shipped as
`op=<op>;source_person=<id|none>;source_period=<id>;target_period=<id|none>;targets=<n>;assigned=<n>;replaced=<n>;unchanged=<n>;skipped=<n>`
— always the same keys, `none` where the operation has no such id, still ids and counts only.

**Task 8 (2026-08-10) — "assert no `rota_fill` row after a forced failure" cannot prove finding 8,
and the test that replaced it found the harness in the way.** An audit row written INSIDE the
transaction would roll back too, so the plan's test 5 passes either way. What distinguishes them is
the transaction DEPTH at the moment the row is appended, asserted in
`test_the_audit_row_is_appended_outside_the_fills_transaction` via an `AuditLog::created` listener.
First written as `assertSame([1], …)` and red at `2` — **`RefreshDatabase` wraps every test in a
transaction of its own**, so the ambient depth is 1 and `AuditLog::record()`'s own makes 2. Now
asserted as `ambient + 1`, measured in the test rather than hard-coded, and proved falsifiable by
wrapping the controller's `AuditLog::record()` call in a `DB::transaction()` and watching it read
`3`.

**Task 8 (2026-08-10) — three guards were proved falsifiable by planting the defect, not by
reading them.**
1. `RotaWritersAreSingularTest` — a `MasterRotaAssignment::create(` planted in `RotaFill::apply()`'s
   dispatch loop went red naming the file and the needle. It would catch a second writer here.
2. `test_a_failure_part_way_through_rolls_the_whole_fill_back` — replacing `apply()`'s
   `DB::transaction(fn …)` with a plain immediately-invoked closure went red with **7 rows where
   there had been 6**, i.e. a genuine partial apply. The atomicity claim is measured, not asserted.
3. `AuditAnomaliesTest::test_a_rota_fill_is_reported_as_a_single_occurrence` was written and watched
   red (*"the expected OpsAlertMail was not sent"*) BEFORE `rota_fill` joined the watch list — so
   the list is proved to fire on it, not merely to contain the string.

**Task 8 (2026-08-10) — the acting administrator is a row in their own fill, and two tests had to
say so.** `User::factory()` links every account to a `people` row (P0c), and that person is active,
so a `fill_down_column` run by an administrator targets their own roster row as well. Correct
behaviour — a fill-down fills the column it is looking at — but it made a 40-peer fixture produce
**41** targets and a 2-peer one produce 3. Both counts are now stated in the tests with the reason,
rather than computed from the plan, because a test that derives its expected count from the thing
under test asserts nothing about the count.

**Task 8 (2026-08-10) — `AuditAnomaliesTest::test_per_cell_rota_editing_is_not_watched` passed on
its first run,** and is recorded as such per the standing rules. It is a regression guard on
Decision H (fifty `rota_assign` rows plus one each of `rota_split`/`rota_clear`/`vacation_book`/
`vacation_cancel` raise no alert), not a red-first test: those five actions were already absent from
the watch list. It would go red the moment anybody adds one of them.

**Task 8 (2026-08-10) — `RotaFill`'s class docblock opened with "THIS CLASS WRITES NOTHING", which
this task makes false.** Rewritten to "TWO ENTRY POINTS, ONE ANALYSIS", keeping the writes-nothing
claim where it is still true and testable (`plan()`, `test_plan_writes_nothing`). Left as-is it
would have been the most confidently wrong sentence in the file.

**Task 8 (2026-08-10) — counts.** `php artisan test` 1209 → **1226** (fifteen in the new
`RotaFillCommitTest`, two in `AuditAnomaliesTest`). `npm test` **157** and `npm run test:e2e` **21**
unchanged — this task adds no client file. `npm run build` green.

**Task 9 (2026-08-10) — THE PLAN COULD NOT REACH A SCREEN AT ALL, and the task's file list is why
it would have shipped that way.** Task 8's controller flashes the plan with
`back()->with('rota_fill_preview', …)`; Inertia does not forward session flash to a page on its own,
and every other one-shot channel in this app is enumerated in `HandleInertiaRequests::share()`'s
`flash` array (`roster_preview`, `promotion_preview`, `bulk_report`, …) precisely because a session
key no `share()` names is invisible to every screen. Neither Task 8's "Files touched" nor Task 9's
mentions that file, so as written this task builds a panel bound to a prop that is permanently
`undefined` — and `npm run build`, `npm test` and `php artisan test` would all have stayed green,
because a Vitest mount supplies its own `usePage()` props and no PHP test renders Vue. Caught by
writing the test first: `RotaFillCommitTest::test_the_preview_and_the_result_reach_the_screen_as_
shared_flash_props` went red with *"Property [flash.rota_fill_preview.targets] does not exist"*.
Asserted through a REAL second request rather than by reading `session()`, because "the controller
flashed it" and "a page can see it" are different claims and only the second one is the feature.
`app/Http/Middleware/HandleInertiaRequests.php` therefore joins this task's file list.

**Task 9 (2026-08-10) — the task lists only Vitest, and two of this task's claims are server-side
facts.** Both new PHP cases live in `tests/Feature/Rota/RotaFillCommitTest.php` (the preview route's
own test file) rather than a new one: the flash-plumbing case above, and the preview request's query
budget. A budget measured on `RotaFill::plan()` alone (Task 7: 4–6) does not bound what an
operator's click costs, and the growth it exists to catch — a per-target lookup added to the
CONTROLLER or the REQUEST rather than to `RotaFill` — is invisible to Task 7's bound by
construction.

**Task 9 (2026-08-10) — measured preview budget: 7 warm, 10 cold; pinned at 12 — and the BOUND is
the weaker half.** Read from a deliberately unreachable `assertLessThan(1, …)` and restored. The
three-query gap between the first request in a process and the second is session and
capability-catalogue warmup, the same effect Task 3's amendment measured as a 16→11 drop on `/rota`,
so the test discards a warm-up request before measuring. The assertion that actually guards the N+1
is the **equality**: the same operation is measured against a 4-target column and a 44-target one
and the two counts must be identical. Proved falsifiable by planting a per-target
`Person::query()->find()` in `MasterRotaController::fillPreview()` — **11 vs 51**, where the bound
of 12 alone would have passed the small fixture and only failed the large one for a reason the
message would have mis-stated.

**Task 9 (2026-08-10) — `withHeaders()` persists for the REST OF THE TEST, and that made an Inertia
GET a 409.** `MakesHttpRequests::withHeaders()` sets the case's default headers, not one request's.
So the `X-Inertia: true` needed on the preview POST was still attached to the `GET /admin/rota` that
follows it, and Laravel answered a version mismatch (409) rather than rendering the page — a failure
that looks exactly like a broken route. `flushHeaders()` before each GET, with the reason at the
call site. Worth recording because the two rota test files that came before this one never mix the
two request shapes in one test, so nothing in the tree demonstrated the trap.

**Task 9 (2026-08-10) — Vue Test Utils SILENTLY SKIPS a click on a disabled element, and the
four-actions case measured two posts for four clicks because of it.** The panel disables its
controls for the duration of a visit (`fillProcessing`), which is correct — a double-click on
"Apply this fill" must not post twice. But `trigger()` returns early when `element.disabled` is
true, and the mocked router never calls `onFinish`, so the flag stayed set. Worse, the count was
**2**, not 1: `trigger()` awaits a tick on its way out, so every other click landed on a
freshly-re-enabled button. The fix is in the test — play `onFinish` back, as Inertia always does,
and let the tick through — not in the component: a screen that stopped disabling itself to make a
test pass would be a screen that can double-apply a fill.

**Task 9 (2026-08-10) — the master-tick case COULD NOT DETECT the defect it is named after, in its
first shape.** It asserted the two boxes became checked, then unticked one and asserted the request
body carried `{'11:101': true}`. A master control that replaced the per-cell set with a single flag
would pass all of that, because with one box unticked it is no longer "all" and any implementation
falls back to the explicit set. **Proved** by planting
`confirmations: fillAllSplitsConfirmed.value ? { all: true } : fillConfirmedSet()` and watching the
file stay green. Now the body is asserted while ALL boxes are ticked — the state a flag would
collapse — and the untick-one half is kept beside it as the thing a flag cannot do at all. Red
against the planted flag (*"expected { all: true } to deeply equal { '9:101': true, '11:101':
true }"*), green after restore.

**Task 9 (2026-08-10) — the preview is gated on the SERVER's own echo, not on a client-side key.**
`RosterImport.vue` invalidates its preview with a `previewedKey`/`currentKey` pair over the file and
mapping, because a file is client-side state the server never sees again. A fill has no such state:
`RotaFill::plan()` echoes the `op` and the `source` cell it planned against, so `fillPlan` renders
only when that echo matches the action and cell currently selected. That closes a case a client key
would not: a plan flashed by a DIFFERENT screen action, or one surviving into a render where the
operator has since picked another operation, is simply not this plan and is never drawn under this
one's heading.

**Task 9 (2026-08-10) — ticking a box does NOT re-preview, and the screen says so rather than
pretending the table is current.** Task 8 kept the confirmations out of the digest exactly so a
per-tick round trip is unnecessary and a master control is buildable; the consequence the task text
does not draw is that the OUTCOMES on screen were computed against the ticks the preview ran with,
so a ticked `SKIP_SPLIT_TARGET` row still reads "skipped" until the next preview. Three things
follow, all shipped: the four counts stay the server's (the plan's instruction — the component
computes none of them), a separate line names how many boxes the OPERATOR has ticked since, and a
"Preview again" control sits beside "Apply this fill". Confirming without re-previewing remains
correct — `apply()` re-derives with the same confirmations — and the notice says that too, rather
than blocking a safe action.

**Task 9 (2026-08-10) — the task says "a panel below the grid" and never says how it OPENS.** A
"Fill…" affordance now sits in every non-stale cell's action row beside Split…/On leave…/Clear, on
both the mobile cards and the desktop table, and it is off a stale row for the same reason Split is:
`RotaFillRequest` applies the strict active predicate to `source_person_id`, so a fill from a
departed person's cell could only ever 422. Copy-period also needed a control the task text does not
mention — it copies onto ANOTHER block, so the panel offers a `<select>` of the year's other
periods, and the button is disabled until one is chosen. The three cell-sourced actions are disabled
on an empty source cell, which the server would otherwise answer with *"that cell is empty"* after
a round trip.

**Task 9 (2026-08-10) — every claim worth making was proved falsifiable by planting its defect.**
Four probes, each reverted: (1) the destroyed-span list replaced by `{{ current.length }} span(s)` →
*"expected '2 span(s)' to contain 'PICU'"*, which is the legibility requirement failing exactly as
intended; (2) the stale-digest handler calling `submitFill()` again instead of re-previewing → the
"never retries" case red; (3) the master-tick flag above; (4) `fill_across` pointed at
`/admin/rota/fill` → *"expected '/admin/rota/fill' to be '/admin/rota/fill/preview'"*. All eleven
cases were watched red first against the un-implemented screen.

**Task 9 (2026-08-10) — counts.** `php artisan test` 1226 → **1228** (two in `RotaFillCommitTest`).
`npm test` 157 → **168** (eleven in the new `tests/js/MasterRotaFill.test.js`). `npm run test:e2e`
**21** unchanged — re-measured rather than assumed, because this task adds a fourth button to every
cell of `/admin/rota` and `master-rota.spec.js` locates its row by `hasText`; nothing collided.
`npm run build` green.

**Task 10 (2026-08-10) — the export is a support class, not two controller methods, and the reason
is Task 11 rather than tidiness.** The task's "Files touched" lists only
`MasterRotaController.php`. What shipped is `app/Support/Rota/RotaExport.php` holding
`ASSIGNMENT_HEADERS`/`VACATION_HEADERS` and the two row builders, with the controller reduced to
one private `export()` that both routes call. Three reasons, in order of weight: (1) the importer
matches those header names case-insensitively (Decision H point 1), so the export and the import
need ONE definition of the column list or they drift silently — a header renamed on the writing
side and not the reading side produces a file that imports as "missing required header"; (2) every
other rota computation in this codebase lives in `App\Support\Rota` (`RotaGrid`, `RotaFill`,
`AvailabilitySummary`), so a hundred lines of query-and-row-building in a controller would be the
one exception; (3) `peopleWithoutAShortName()` is a pure fold that belongs beside the thing it
warns about.

**Task 10 (2026-08-10) — the rows are an ARRAY, not the lazy generator the task text asks for, and
the audit row is why.** *"`Csv::stream()` takes an iterable, so build rows lazily"* cannot be
reconciled with test 6: a `StreamedResponse` does not run its callback until after the controller
has returned, so a generator's row count is unknown at the moment `rota_export` is written. The
alternatives were a second `COUNT(*)` — which can disagree with the file it claims to describe —
or an audit row with no count, which is the one number the task specifies. A year's spans are
bounded (sixty people x thirteen periods x a span or two), so the array is affordable and the count
is the file's own.

**Task 10 (2026-08-10) — THE STALE-PERSON DECISION: they are IN the file.** Decision D hides a
departed person from the resident read view; this export deliberately does not. Their spans are
exactly what blocks `PeriodController::destroy()`, so an administrator exporting the year to find
out why it will not clear must see them — and the export sits behind `rota.manage`, the same
capability as the EDITOR grid, which shows them for that same reason. Task 11 copes by answering
such a row `SKIP_UNKNOWN_PERSON` with *"no longer on the active roster"* (Decision H point 3): the
information is named on the way back in, never silently dropped on the way out. Both queries eager-
load the person `withTrashed()`; proved necessary by removing it and watching
`test_a_stale_person_who_still_holds_a_span_is_in_the_file` go red on the soft-deleted half —
without it SoftDeletes' global scope nulls the relation rather than omitting the row, so the file
would have carried the span with two BLANK name columns and no error anywhere.

**Task 10 (2026-08-10) — the short-name warning counts people who would APPEAR IN THE FILE, not
everybody in the year.** Decision G says the screen "names how many people in the year lack a short
name". Counted literally over a `RotaGrid` row set that also includes every active person with
nothing planned, the number does not match the number of broken lines in the file it is warning
about — and a warning that fires on somebody who exports no rows at all is one an administrator
learns to ignore. `RotaExport::peopleWithoutAShortName()` therefore requires at least one span or
one vacation in the year. The export still RUNS with the blank handle, per the task text: dropping
the person would lose rows from a file whose whole job is to describe what the rota holds.

**Task 10 (2026-08-10) — a `?year=` with no periods is a 404, which the task text does not
specify.** The alternative — a header-only file — makes a typo indistinguishable from a year whose
rota was cleared, the same argument Task 7's amendment settled for a fill preview that silently
renders "0 cells". `test_an_unknown_year_is_a_404` was the ONE case in this file green on its first
run, and vacuously: before the routes existed every URL in it answered 404. It now asserts the
known year is a 200 first, so deleting the routes fails it — the same non-vacuity fix Task 3's
amendment records for the router assertion.

**Task 10 (2026-08-10) — `ContactFieldsAreProjectedOnceTest` fired on this task's own DOCBLOCK.**
`RotaExport`'s class comment explained that a future hand-built row carrying the phone attribute
would fail the byte-level assertion — and quoted the arrow-and-field accessor form to say so. That
guard's needle list is a plain substring scan with no allow-list, so the sentence describing the
defect WAS the defect as far as the scan could tell: *"app/Support/Rota/RotaExport.php contains
->phone"*, on an otherwise-green full run. This is finding 14's trap (docblock prose matching a
needle) in the PHP contact scan rather than the client's date scan, and finding 14 only warns about
the latter. Rewritten around the accessor shape; the guard was not touched.

**Task 10 (2026-08-10) — every claim was proved falsifiable by planting its defect, and all five
probes were reverted.** (1) The unit eager-load constrained to `active()` — i.e. reading codes the
way `RotaGrid` does — → `test_a_retired_units_span_keeps_its_code` red with *"expected 'XCU', got
''"*, which is the whole reason this class exists rather than a fold over the grid. (2) `withTrashed()`
dropped, above. (3) An `email` column planted in `ASSIGNMENT_HEADERS` and its row → BOTH
`test_the_export_carries_no_contact_column` and `test_a_person_is_identified_by_short_name_and_full_name`
red, the first printing the leaked address in its own failure message. (4) Both export buttons
pointed at the same file → the href case red (a screen offering two buttons to one file looks
entirely correct otherwise). (5) The short-name warning's `v-if` forced false → the count case red.

**Task 10 (2026-08-10) — the round trip is asserted at the FEATURE level, through
`CsvRosterReader`, and it is what every content assertion in the file goes through.** Test 4 in the
task text asserts the leading apostrophe in the raw bytes; that alone proves neutralisation but not
the PAIRING, which is what `CsvInjectionTest` exists to assert at the primitive and what makes
export → re-import lossless. `test_the_export_round_trips_through_the_reader_unchanged` writes the
streamed bytes to a temp file, reads them back with the reader Task 11 will use, and asserts a
formula-injection name (`=HYPERLINK("http://evil/?"&A1)`) and an Arabic name (`محمد العتيبي`) come
back byte-identical from BOTH files. A short name beginning `=` is in the same fixture, so the
handle the importer matches on is proved to survive the trip too.

**Task 10 (2026-08-10) — `rota_export` is deliberately NOT on `AuditAnomalies`' watch list.** That
list fires once per matching row in the window (finding 13), and an export is a routine
administrative act; adding it would train an operator to ignore the channel that exists for
`rota_fill`. The audit row is still written on every export, ids and counts only — and
`test_a_resident_cannot_export` asserts a REFUSED export writes none.

**Task 10 (2026-08-10) — counts.** `php artisan test` 1228 → **1241** (thirteen in the new
`RotaExportTest`). `npm test` 168 → **173** (five in the new `tests/js/MasterRotaExport.test.js`;
the plan lists no Vitest file for this task, but a prop nothing renders is exactly the defect
Task 9's amendment records — the PHP side proves the count, only a mount proves an operator sees
it). `npm run test:e2e` **21** unchanged, re-measured. `npm run build` green.

**Task 11 (2026-08-10) — THE PIN LIVES IN `RotaImport`, NOT IN THE CONTROLLER, and the plan's own
test list is what forces that.** `RosterImport` has no digest logic at all: `RosterImportController`
computes the sha256 and throws the `ValidationException`. Transposed literally, Task 11's own test 4
(*"a changed file 422s naming the mismatch"*) could not be written until Task 12 existed, so the
task's central safety property would ship a task later than the code it protects. What shipped
instead follows **Task 8's** precedent in this same slice: `RotaImport::digest()` is one definition
used by both entry points, `preview()` returns it, and `commit()` takes the operator's claim as a
fourth argument and refuses inside its own transaction. The refusal is `StaleImportFileException`
(new file, in neither task's "Files touched") — the file sibling of `StaleFillPlanException`, for
the reason Task 8 already wrote down: the controller has to tell this refusal apart from every other
one to put the 422 on the right field, and choosing an HTTP field by matching an error message's
text is the drift this codebase keeps removing. **Consequence for Task 12:** its controller is
thinner than `RosterImportController`, not a mirror of it — it maps the exception to a 422 on `file`
and writes no audit row of its own, because `commit()` writes `rota_import` itself, after its
transaction (finding 8). A pin that a controller can forget to apply is not a pin.

**Task 11 (2026-08-10) — `commit()` needs the BYTES, and that is a real seam the port does not
cover.** `RosterReader` exposes `headers()` and `rows()` and nothing about the underlying file, by
design (xlsx is one class away). The digest is over bytes, so `preview()`/`commit()` take
`string $bytes` beside the reader, exactly as `RosterImportController::readerFromRequest()` already
returns both from one `file_get_contents()`. Adding a `digest()` method to the PORT was rejected: it
would put a second definition of the pin in every future reader implementation, and the one thing
`RosterImport`'s controller proves about this is that a single definition is what makes it safe.

**Task 11 (2026-08-10) — `UNCHANGED` is not in the plan's outcome list and the round trip cannot be
written without it.** Decision H enumerates `CREATE`, `REPLACE`, `SKIP_UNKNOWN_*`, `SKIP_DUPLICATE`
and `ERROR` — then the same task's test 9 asks that an exported year re-import with *"every outcome
`UNCHANGED`/`SKIP_DUPLICATE`"*. Shipped with `UNCHANGED` as a first-class outcome, and it earns its
place twice over: it is what makes a re-import cost **zero writes** rather than rewriting every row
to its own value (a REPLACE of identical spans deletes and re-inserts, which is a different audit
trail and a different set of row ids for the same rota), and it is the only outcome that can
distinguish "this file matches the rota" from "this file overwrote the rota with itself".

**Task 11 (2026-08-10) — the round trip's outcome set is FOUR answers, not two, and Task 10 is why.**
Test 9 as written expects `UNCHANGED`/`SKIP_DUPLICATE`. A real export of a real year also contains a
stale person's spans and a retired unit's code — Task 10 put both there deliberately, and its own
amendment says so. So a faithful round trip answers `SKIP_UNKNOWN_PERSON` and `SKIP_UNKNOWN_UNIT`
too. The assertion that actually carries the meaning is therefore not the outcome vocabulary but
`create + replace + error === 0`, `applied === 0`, and an md5 fingerprint of the whole assignment and
vacation row sets taken before and after the commit. All four are asserted;
`test_an_exported_year_re_imports_as_a_no_op` names the seven expected outcomes exactly, sorted on
both sides (the export's row order is a legibility property its own docblock disclaims, and pinning
it would fail the day somebody improves it, for no defect).

**Task 11 (2026-08-10) — a cell holding one good span and one on a retired unit is SKIPPED WHOLE,
and that had to be decided rather than fallen into.** The cell is the unit of outcome and
`split()` replaces the whole set, so applying the half that resolved would silently DELETE the half
that did not — a data-loss shape identical to Task 7's "what is a split" finding. The full
precedence now lives in `finaliseAssignmentCell()`'s docblock: person → period → file errors →
unit → compare. Identity first because without it there is no cell to say anything about; the
file's own errors before the unit because they name something to fix in the file rather than
something about the department's configuration.

**Task 11 (2026-08-10) — `Vacation::booted()` refuses OVERLAPPING leave, and the plan's outcome list
has nowhere to put it.** `SKIP_DUPLICATE` covers an exact match; leave that overlaps existing leave
without matching it would reach the model guard from inside the import transaction and abort the
WHOLE file over a row the preview called clean. It is an `ERROR` on that row alone, detected against
the same per-person list the duplicate check uses — so a duplicate/overlap **within the file** and
one **against the database** are caught by one comparison rather than two rules that can disagree.
`vacations-overlapping.csv` is the fixture; `test_leave_overlapping_other_leave_is_an_error_not_an_
aborted_import` asserts the good row still lands.

**Task 11 (2026-08-10) — three guard probes and three behaviour probes, every one reverted.**
1. `MasterRotaAssignment::create(` planted in `commit()`'s dispatch loop → `RotaWritersAreSingularTest`
   red naming the file and the needle. 2. `->save()` planted in the same place → the newly-extended
   `RosterNeverMintsCredentialsTest` red on the bare persistence needle. 3. `User::create(` planted
   there → the same guard red on the literal account-minting claim. 4. The `UNCHANGED` comparison
   forced false → the round trip and the idempotence case both red with `replace` where `unchanged`
   belonged, i.e. a re-import silently rewriting the year. 5. `CsvRosterReader::unNeutralise()`
   short-circuited → `'=formula` came back as the handle and the round trip answered
   **`skip_unknown_person`** for that person: the neutralise/un-neutralise mismatch failing in a
   REAL column, silently, which is the exact defect the plan says only this test can catch.
   6. `VacationBooking::snap()` made a no-op for `week` → the vacation case red on its own
   NON-VACUITY half (*"the fixture week row is already aligned — this test would prove nothing"*).
   **Probe 6 is the one worth reading:** the round trip stayed GREEN under a broken snap, because an
   exported week booking is already week-aligned, so a no-op snap returns the same bounds. The round
   trip is not the guard for the snap and never could be — only the not-aligned fixture is.

**Task 11 (2026-08-10) — the red was watched behaviourally, per the standing rules.** The class was
created as a stub returning an empty analysis FIRST, so 23 of 25 cases failed on wrong outcomes,
missing cells and un-thrown refusals rather than on "class not found". The two that passed against
the stub passed **vacuously** (an importer that writes nothing leaves an unmentioned cell alone; an
empty analysis carries no Eloquent model either) and each gained a non-vacuity assertion — `applied
=== 3`, and a three-outcome count — before being watched red.

**Task 11 (2026-08-10) — two fixtures the plan's table does not list, both real failure shapes.**
`assignments-blank-short-name.csv` (`people.short_name` is nullable — finding 5 — so an export can
legitimately carry a blank handle, and `RotaExport`'s own test docblock already says Task 11 answers
it `SKIP_UNKNOWN_PERSON`) and `assignments-bad-headers.csv` (the file-error path; the plan asks for
`test_a_file_error_refuses_the_whole_import` and names no fixture that produces one).
`assignments-retired-unit.csv` carries **two** rows rather than one, because a retired code and a
non-existent code are two different operator problems and ship two different messages —
`Unit::findByCode()` carrying no `active` scope is what makes telling them apart possible.

**Task 11 (2026-08-10) — counts.** `php artisan test` 1241 → **1266** (twenty-five in the new
`RotaImportTest`). `npm test` **173** and `npm run test:e2e` **21** unchanged — this task adds no
client file and no route; e2e was re-measured rather than assumed. `npm run build` green.

**Task 12 (2026-08-10) — the plan's own needle list would have failed the build on the three
docblocks that state MR-04's absence, and the fix is a deliberate departure from finding 14.** The
task text says to add `'eligib'`, `'on_call'`, `'onCall'` and `'callRoster'` to the existing scan.
`grep -rniE "eligib|on_call|onCall|callRoster" app/Support/Rota/` returns three hits before a line
of this task is written — `AvailabilitySummary`'s *"THIS IS NOT, AND MUST NEVER BECOME, AN ON-CALL
ELIGIBILITY COMPUTATION"*, the same file's *"the rota had quietly begun inferring eligibility"*, and
`RotaFill`'s *"THIS IS NOT AN ON-CALL ELIGIBILITY COMPUTATION"*. `CalendarIsTheOnlyConverterTest`'s
remedy for the same collision (finding 14: write around the call shape in prose) is unavailable
here, because the prose IS the rule and rewording it to dodge a substring would be absurd. So the
new scan **strips comments before matching** — PHP through `token_get_all()`, `.vue` through three
conservative patterns — and a guard that punished the documentation of the rule it enforces became
a guard that reads only code. Two consequences worth stating: the match is now **case-insensitive**
(safe only because comments are gone, and it is the difference between catching `off_roster` alone
and also catching `isEligibleForCall()`), and the stripper itself needs a test, so
`test_the_scan_strips_comments_and_still_sees_the_code` pins both ends against real files — the
prose is gone, the code is not. A stripper that over-reached would disable the whole guard and look
identical to a clean tree. The original four-needle scan over the whole of `app/` is UNCHANGED and
kept beside the new one: the two fail for different reasons and neither subsumes the other.

**Task 12 (2026-08-10) — three MR-04 probes, and the third is the one that justifies the
departure.** (1) `private static function isEligibleForCall()` planted in `RotaImport` →
*"app/Support/Rota/RotaImport.php: eligib"*, which is also the case-insensitivity earning its
place, since `eligib` as a fixed-case needle would have missed `isEligibleForCall` entirely.
(2) `const onCallRoster = []` planted in `RotaImport.vue` → two needles red on the screen file.
(3) The **same words in a comment** (`/** … never infers on_call eligibility. */`) → still GREEN,
which is the property the stripper exists for. All three reverted.

**Task 12 (2026-08-10) — the stale digest cannot be reached from the screen, and that is the
screen working rather than a gap in the journey.** The task's "failing test to write first" asks
the e2e to *"upload a changed file against the previous digest and assert the 422 message"*. The
screen invalidates its analysis client-side the moment the file or the kind changes (`previewedKey`
/`currentKey`, `RosterImport.vue`'s discipline), so the commit control is simply not offered
against a file the server has not answered for — there is no browser gesture that posts file B
under file A's digest. Re-writing the file on disk between the choice and the commit was rejected
as a route to it: Chromium snapshots a `File` by path+size+mtime and a modified file fails the read
with a network-level error rather than a 422, which would assert nothing about this feature.
The refusal is therefore proved where it is reachable: server-side in
`RotaImportScreenTest::test_a_file_changed_since_the_preview_is_refused_on_the_file_field` (a
different file's bytes carrying the previous digest → errors on `file`, zero rows, zero audit
rows), and client-side in `RotaImport.test.js` (given that 422, the screen shows the server's
sentence, DROPS the analysis, and re-runs the PREVIEW — never the commit). The Vitest case was
proved falsifiable by pointing the stale handler back at `commit()`: *"expected
'/admin/rota/import/commit' to be '/admin/rota/import/preview'"*.

**Task 12 (2026-08-10) — THE JOURNEY'S OWN FINAL ASSERTION COULD NOT DETECT WHAT IT NAMED, and
planting the defect is what showed it.** The destructive half previews a file that would overwrite
`ereader`'s deliberate Block 1 gap, deliberately does NOT confirm it, and then re-reads the rota
from the server. That last step originally read `await expect(untouched).not.toContainText('NICU')`.
It is worthless: an overwritten cell stops being a split and renders a plain unit `<select>` whose
options list every unit in the department, so "NICU is not in this cell" is true of a split cell and
false of an empty one, for reasons that have nothing to do with the import. Caught by planting
`RotaImportController::preview()` calling `RotaImport::commit()` — the whole point of the e2e half,
a dry run that writes — and reading which assertion fired. It now asserts the cell is still a split:
`li.channel-bar` count 1, PICU, `2026-08-07`, `7d unassigned`, and the whole cell's `innerText`
unchanged. Re-probed after the change: **red at `toHaveCount(1)`, received 0**, green on revert.

**Task 12 (2026-08-10) — the destructive half is previewed and never committed, and that is a
FIXTURE CONTRACT as well as the requirement.** `rota-read.spec.js` runs after this file against the
same sqlite database and asserts E2eSeeder's exact coverage arithmetic (21 assigned days, 35 not
assigned, one person with a gap, two unassigned). Committing the overwrite here would fail that
spec for a reason nobody reading it could diagnose. The no-op round trip in the first half is safe
for exactly the reason it is worth asserting: `applied === 0`, so it writes nothing at all. Both
properties are stated in the spec's own docblock so a later edit cannot quietly commit the second
half.

**Task 12 (2026-08-10) — a handle-only preview-row locator is a strict-mode violation by
construction.** The first shape of the spec's `previewRow()` filtered on the short name alone and
resolved to TWO elements: the unit of outcome is the (person, period) CELL, so anybody planned
across a year has one preview row per block. It now filters on the handle AND the block label, with
`toHaveCount(1)` asserted before anything is read off it. Worth recording because the same trap is
NOT finding 15's (mobile card versus desktop row) — it is the importer's own cell shape, and no
existing rota spec demonstrates it.

**Task 12 (2026-08-10) — the screen needed an affordance the task's file list does not mention,
and P1d-1's journey is why it was added rather than assumed.** `routes/web.php` gaining
`/admin/rota/import` makes the screen exist; nothing made it REACHABLE. An "Import a file…" control
now sits in `MasterRota.vue`'s export section, beside the two files it reads back — an Inertia
`<Link>`, not a plain `<a>` like the two exports beside it, because those are downloads and this is
an ordinary page. `MasterRota.vue` gains `Link` to its `@inertiajs/vue3` import; all seven Vitest
files that mount it already stub `Link`, checked before the edit rather than after.

**Task 12 (2026-08-10) — `HandleInertiaRequests::share()` joins this task's file list too, for the
third time in this slice.** Task 9's amendment records the mechanism (a session key no `share()`
names is invisible to every page, with all four suites green); the lesson was applied here BEFORE
the screen was written rather than discovered again, and
`RotaImportScreenTest::test_the_preview_writes_nothing_and_reaches_the_screen_as_a_shared_prop`
asserts it through a real second request, `->missing('flash.rota_import_preview.context')` included.

**Task 12 (2026-08-10) — finding 9's `post_max_size` detection became a trait rather than a second
copy.** `RotaImportRequest` needs the same "empty `$_POST` past the upload limit" detection
`RosterImportRequest` carries, and that detection has an ordering constraint found EMPIRICALLY
(detect before the request's own `merge()`, or the guard is silently disabled) which is invisible
from the call site. A hand-written second copy would have been a second chance to get it backwards,
so it is now `App\Http\Requests\Concerns\DetectsOversizedUpload`, with the ordering rule in the
trait's own docblock and `RosterImportRequest` re-pointed at it.
`RosterImportTest::test_an_oversized_upload_reports_the_size_not_a_missing_field` is what proves the
extraction did not change the behaviour it moved.

**Task 12 (2026-08-10) — counts, MEASURED after this task's own arithmetic was wrong by one.**
`php artisan test` 1266 → **1281** (thirteen in the new `RotaImportScreenTest`, **two** in
`RotaAccessTest` — the namespace scan and its stripper calibration; this paragraph first said three,
which is the fifth instance of stale expected-test-count arithmetic across P1c/P1d and the reason
the standing rules say to trust the measurement). `npm test` 173 → **185** (twelve in the new
`tests/js/RotaImport.test.js`). `npm run test:e2e` 21 → **22** (one journey in the new
`tests/e2e/rota-import.spec.js`). `npm run build` green.

---

## Standing rules for every task

Verified against the tree; these are not preferences.

- **TDD, strictly.** Write the test, run it, **watch it fail for the reason you expect** (not a
  typo, not a missing class), then implement. A test that passes on first run has proved nothing —
  though note that P1d-1 recorded three legitimate zero-red tasks where a prior task's scope had
  already covered a later task's behaviour. When that happens, say so in the amendments and check
  each case would have failed before the earlier task landed.
- **Build before test, every time.** `npm run build && php artisan test`.
- **Verify with Bash, not PowerShell.** PowerShell's PATH on this machine lacks `openssl` and the
  backup tests silently self-skip there — a false green indistinguishable from a real one. If PHP
  is not on PATH in a fresh shell:
  `export PATH="$LOCALAPPDATA/php84:$LOCALAPPDATA/composer-bin:$PATH"`.
- **Filter output.** `| tail -5` for a full run; `php artisan test --filter <TestName> | head -30`
  on a failure. Never dump a failing suite into context.
- **Assert over the whole set, never inside a `foreach`.** Every source-scanning guard collects
  `$offenders[]` and ends with `assertSame([], $offenders, ...)`.
- **Every route behind `auth` + a `cap:`.** Writes are POST/PATCH/DELETE + CSRF.
- **Eloquent/bindings only.** Never concatenate SQL.
- **Light theme only, semantic classes only.** No `dark:` utility, no raw Tailwind palette class,
  no hex in markup. There is no `bg-panel-soft` token — it compiles to nothing.
- **New screens follow `Units.vue` / `Levels.vue` / `People.vue` / `MasterRota.vue`**: mobile cards
  plus desktop table, `useForm`, `preserveScroll`, live regions, a computed column count.
- **The client performs no date arithmetic** — ten needles, no allow-list, and it matches docblock
  prose (finding 14).
- **`institution_id` is provenance.** Never a `where`, never inside an `index([...])`/`unique([...])`
  array.
- **Audit by ids, field names and counts only.** Never a person's name, a unit's name, a period's
  label or a filename.
- **`RotaAssignment` and `VacationBooking` are the only writers.** Every bulk path and every
  imported row goes through them.
- Commit at the end of each task with the message given, only after `npm run build` and
  `php artisan test` are both green.

---

# P1d-2a — tasks

### Task 1: `rota.manage` goes back to Administrator-only, and the grant path is proved

**Owner decision 2 answered: `rota.manage` is Administrator-only by default. It is NOT in Chief
Resident's `ROLE_DEFAULTS`. An administrator grants it per department from the Access Control
screen.** P1d-1 shipped the opposite (finding 1), on the strength of its own top-of-document
binding block, and four sites say so today. This task reverses all four. It is first because every
access assertion in Tasks 3–13 is written against the corrected default, and a later task that
seeds a Chief Resident and expects a 403 would otherwise fail for a reason that has nothing to do
with it.

`rota.view` is **unchanged** — every seeded position holds it (P1d-1 owner decision 2). Do not
touch it.

**Files touched**

- `database/seeders/AccessControlSeeder.php` (remove `'rota.manage'` from `ROLE_DEFAULTS[5]` and
  rewrite the comment above it)
- `tests/Feature/Rota/RotaAccessTest.php` (class docblock, the Chief Resident route case, the
  default-holders case)
- the `AccessControlParityTest` (position 5's expected set — `find tests -name
  AccessControlParityTest.php` for its path; it merges `'rota.manage'` into position 5 today)
- `docs/spec/08-foundation.md` (the role-defaults sentence)
- `docs/RUNBOOK-DEPLOY.md` (the operator step for an instance that already granted it)

**The failing test to write first**

In `tests/Feature/Rota/RotaAccessTest.php`, replace
`test_only_an_administrator_and_chief_resident_hold_rota_manage_by_default` with two tests. The
first is the rename-and-flip; the second is the half that matters and does not exist today.

```php
public function test_only_an_administrator_holds_rota_manage_by_default(): void
{
    foreach ([2, 3, 4, 5] as $position) {
        $this->assertFalse(
            \App\Support\AccessControl::allows(User::factory()->create(['position' => $position]), 'rota.manage'),
            "position {$position} must not hold rota.manage by default (owner decision 2, 2026-08-10)"
        );
    }

    $this->assertTrue(
        \App\Support\AccessControl::allows(User::factory()->create(['position' => 0]), 'rota.manage')
    );
}

/**
 * The other half of owner decision 2: "an administrator grants it per department from the Access
 * Control screen". A test that only asserts the refusal proves the default and says nothing about
 * whether the documented remedy works — which is the part a department will actually use.
 */
public function test_an_administrator_can_grant_rota_manage_to_chief_resident_from_the_screen(): void
{
    $chief = User::factory()->create(['position' => 5]);
    $this->actingAs($chief)->get('/admin/rota')->assertForbidden();

    $admin = User::factory()->create(['position' => 0]);
    $capability = Capability::where('key', 'rota.manage')->firstOrFail();

    // Through the real endpoint, submitting the whole matrix the screen submits — never by
    // inserting a role_capabilities row directly, which would prove nothing about the screen.
    $roles = [];
    foreach ([0, 2, 3, 4, 5] as $position) {
        $roles[$position] = RoleCapability::where('position', $position)->pluck('capability_id')->all();
    }
    $roles[5][] = $capability->id;

    // PUT, not PATCH — `routes/web.php:94` registers this as
    // `Route::put('/access-control/roles', ...)->name('access-control.roles')`. Verified against
    // the router rather than assumed; a wrong verb here 405s and reads like an authorization bug.
    $this->actingAs($admin)->put('/admin/access-control/roles', ['roles' => $roles])
        ->assertRedirect();

    $this->actingAs($chief)->get('/admin/rota')->assertOk();
}
```

Also flip the existing case that asserts a Chief Resident reaches `/admin/rota` — it must now
assert `assertForbidden()`, and its comment must cite owner decision 2 (2026-08-10) rather than the
2026-08-09 decision it reverses.

Run it. **It must go red on the first test before the seeder changes**, because position 5 holds
the capability today. Confirm the red is the assertion and not a missing import.

**The implementation**

1. `AccessControlSeeder::ROLE_DEFAULTS[5]` drops `'rota.manage'`. Rewrite the comment above it:

   ```php
   // Chief Resident (5): a Resident clinically, plus the scoped admin powers. `rota.manage` is
   // NOT here (owner decision 2, 2026-08-10, reversing the 2026-08-09 decision P1d-1 shipped):
   // editing the master rota defaults Administrator-only and an administrator grants it per
   // department from Admin -> Access Control, the same shape `structure.manage` and
   // `people.manage` already ship in.
   5 => [
       'profile.manage', 'rota.view',
       'endorsement.view', 'endorsement.edit',
       'users.manage_residents',
   ],
   ```

2. The `AccessControlParityTest` — position 5's expected set drops `'rota.manage'` (it stays in the
   `$adminOnly` array, which position 0 picks up).

3. `docs/spec/08-foundation.md`'s role-defaults sentence: *"`rota.manage` (edit assignments and
   vacations — Munawib MR-02/MR-03/MR-06) defaults **Administrator-only**, grantable per role from
   the Access Control screen with no code change. Munawib §5 also grants it to its Scheduler
   persona, which maps to no role here; Chief Resident is the nearest fit and a department that
   wants it there grants it (owner decision, 2026-08-10)."*

4. `RotaAccessTest`'s class docblock line 18 currently reads *"Defaults to Administrator AND Chief
   Resident (owner decision 1, 2026-08-09 ...)"*. Rewrite it to the corrected default **and record
   that it was reversed**, so the next reader does not "restore" it from a stale memory of the
   P1d-1 plan.

5. `docs/RUNBOOK-DEPLOY.md` gains a short note under the P1d section (finding 2): *"P1d-1 seeded
   `rota.manage` to Chief Resident; P1d-2 removed it from the defaults. `AccessControlSeeder`
   applies each default **once** and never re-asserts it (`applied_role_defaults`), so an instance
   that already received the grant keeps it — that is by design, because a capability an
   administrator has since kept is theirs. To remove it: Admin → Access Control → Chief Resident →
   un-tick 'Create and edit master rota assignments and vacations' → Save. There is no migration
   and there must not be one."*

**How to verify**

```bash
npm run build && php artisan test --filter 'RotaAccessTest|AccessControlParityTest' | tail -5
php artisan test | tail -5
```

Expected: `1159 → 1160` (one net new test: two replace one). **Measure it; do not trust this
arithmetic** — stale expected counts are this programme's most common amendment.

```bash
git commit -am "fix: editing the rota is an administrator's by default, and a grant that works"
```

---

### Task 2: `AvailabilitySummary` — one computation, no queries, gaps that show

MR-07: *"Per-period availability summary per level and unit, including who is on vacation each
week."* **This is Stage 1's acceptance criterion** (§35: *"availability summaries match reality"*).

`App\Support\Rota\AvailabilitySummary::forGrid(array $grid): array` is a **pure fold over the array
`RotaGrid::forYear()` already returns** (Decision B, finding 4). It issues **no query**, touches no
model, and is used by **both** the editor and the read view — never two computations. It counts
**uncovered days and the people carrying them** as well as assignments, so owner decision 3's
permitted gaps show up in the number rather than being rounded away. It is handed the **full**
grid, stale rows included (Decision D's ordering trap).

There is **no publish state anywhere in this computation** (owner decision 1) — no `status`, no
"published assignments only" filter. It summarises the current rota, because the current rota is
the only rota there is.

**Files touched**

- `app/Support/Rota/AvailabilitySummary.php` (new)
- `tests/Feature/Rota/AvailabilitySummaryTest.php` (new)

**The failing test to write first**

`AvailabilitySummaryTest` — build the grid array **by hand**, as a literal, with no database at
all. That is the point of a pure function, and it makes every case readable:

1. `test_it_counts_people_and_days_per_level_and_unit` — two people at R1 on PICU for a whole
   period, one at R2 on NICU: `by_level_unit[r1][picu] === ['people' => 2, 'days' => 28]`.
2. `test_a_split_counts_the_person_under_both_units_and_the_days_under_each` — one person, PICU
   days 1–9 and NICU days 10–28: one person under each unit, 9 and 19 days.
3. `test_the_level_is_the_one_held_at_this_periods_start_not_the_row_group` — a row whose
   `group_level_id` is R2 but whose Block 9 cell carries `level_id` R3 is counted under **R3** for
   Block 9. This is the mid-year-promotion case and it is the one a naive implementation gets
   wrong.
4. `test_uncovered_days_and_people_with_a_gap_are_separate_numbers` — one person with a 26-day gap
   and twenty-six people each missing one day both produce `uncovered_days === 26`, but
   `people_with_a_gap` is `1` and `26`. Assert both grids in one test so the difference is on the
   screen.
5. `test_a_person_with_no_span_at_all_is_unassigned_not_a_gap` — `unassigned_people` counts them and
   they contribute their whole period to `uncovered_days`.
6. `test_who_is_on_vacation_is_reported_per_week_from_the_periods_own_weeks` — a vacation
   intersecting weeks 2 and 3 of a five-week period appears in exactly those two, by
   `clipped_*` bounds, with the person's id listed.
7. `test_a_stale_row_is_excluded_from_coverage_and_counted_separately` — Decision D. Their days do
   **not** appear in `by_level_unit` or `assigned_days`; `stale_people` is the count of their
   occupied cells.
8. `test_it_issues_no_query` — wrap the call in `DB::enableQueryLog()` / `assertCount(0,
   DB::getQueryLog())`. This is the test that stops someone "just fetching the unit name" later and
   turning a pure fold into an N+1 on two screens at once.

Run them. All eight must be red before the class exists.

**The implementation**

`forGrid()` walks `$grid['periods']`, and for each period walks `$grid['rows']`, reading each row's
`cells[$periodId]`. Every input is already in the array:

- `cell['spans']` — each with `unit_id`, `starts_on`, `ends_on`. Day count is a
  `Calendar::datesBetween()`-free integer: the spans' day counts are derivable from the bounds, and
  since both are `Y-m-d` strings the honest way to count is **through `App\Support\Calendar`** if
  any arithmetic is needed at all. Prefer avoiding it: `RotaGrid::cellFor()` already computes
  `uncovered_days` from `periodDays - coveredDays`, so **have `RotaGrid` also emit each span's own
  `days` count** in its span prop (a one-line addition inside `cellFor()`, where the Carbon objects
  are already in hand) and read it here. That keeps `AvailabilitySummary` free of date handling
  entirely — the strongest possible form of ST-06 compliance for this class, and one fewer place
  for a converter to appear.
- `cell['uncovered_days']`, `cell['level_id']`, `cell['vacations']` — read directly.
- `period['weeks']` — each with `clipped_starts_on`/`clipped_ends_on`. Vacation intersection is the
  four-way `Y-m-d` **string** comparison stated in Decision B. No `DateTime`. No `Calendar`.
- `row['stale']` — Decision D.

Class docblock must state, in this order: that it is the one computation used by two surfaces; that
it is pure and query-free and why (Decision B); that `level_id` is the cell's, not the row's; that a
gap is a legal state and is counted twice over (days and people); that a stale row is excluded from
coverage and counted separately (Decision D); and — because a future reader will absolutely reach
for this class to answer it — **that this is not, and must never become, an on-call eligibility
computation** (MR-04 is Stage 2; Task 12 asserts it).

**How to verify**

```bash
npm run build && php artisan test --filter AvailabilitySummaryTest | tail -5
php artisan test | tail -5
```

Expected: eight new tests, plus any `RotaGridTest` case that pins the exact span-prop shape (the new
`days` key may need one assertion updated — check, do not assume).

```bash
git commit -am "feat: what the rota adds up to, gaps included"
```

---

### Task 3: the read view — `cap:rota.view`, contact-free, and provably read-only

MR-05: *"Publishable to residents independently of any call schedule."* **Owner decision 1: there
is no publish gate.** `/rota` always shows the current rota. No status column, no draft state, no
publish action, no "visible from" date. If you find yourself adding one, stop — it is the decision
this task exists to hold.

This task is the server half: the route group, the controller, the contact-free projection
(Decision C, closing finding 3 on **both** surfaces), and the two assertions that make read-only a
property rather than a habit. The screen is Task 4.

**Files touched**

- `app/Support/PersonPresenter.php` (add `contactFree()`)
- `app/Support/Rota/RotaGrid.php` (call it; drop the now-unused `?User $viewer` parameter)
- `app/Http/Controllers/Admin/MasterRotaController.php` (drop the argument at its call site)
- `app/Http/Controllers/RotaController.php` (new — **not** under `Admin\`, Decision A)
- `routes/web.php` (one new group, one GET route)
- `tests/Feature/Rota/RotaReadViewTest.php` (new)
- `tests/Feature/Rota/RotaAccessTest.php` (the router-level read-only assertion)

**The failing test to write first**

`RotaReadViewTest`:

1. `test_a_resident_reaches_the_read_view` — position 4, `assertOk()`, with `rota.view` seeded.
2. `test_the_read_view_is_not_under_admin_and_the_editor_still_is` — a resident gets `200` on
   `/rota` and `403` on `/admin/rota`.
3. **`test_no_contact_field_reaches_the_props_for_any_viewer`** — the strong form (Decision C).
   Seed a person with both an email and a phone; set
   `Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS])` — the
   most permissive setting; then assert **for a resident AND for an administrator holding
   `people.manage`** that no row's `person` array has an `email` or `phone` key. Walk the whole
   props tree rather than checking one row, so a future presenter change cannot leak through a row
   the test did not look at.
4. `test_the_editor_grid_is_contact_free_too` — the same assertion against `/admin/rota` as an
   administrator on the same permissive institution. This is the half that fails **today** (finding
   3) and it must be watched failing before the presenter changes.
5. `test_there_is_no_publish_state_on_the_read_view` — the props contain no `status`, `published`,
   `published_at` or `draft` key anywhere. Owner decision 1, asserted rather than assumed.

In `RotaAccessTest`, the router-level assertion (Decision A):

```php
/**
 * `rota.view` is seeded for EVERY authenticated position, so anything reachable with it is
 * reachable by the whole department. Asserted over the ROUTER rather than as a list of 403
 * cases, because a hand-written list only covers the routes somebody remembered to add to it.
 */
public function test_every_route_behind_cap_rota_view_is_a_get(): void
{
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('cap:rota.view', $route->gatherMiddleware(), true)) {
            continue;
        }

        if ($route->methods() !== ['GET', 'HEAD']) {
            $offenders[] = $route->uri().' allows '.implode(',', $route->methods());
        }
    }

    $this->assertSame([], $offenders,
        "A write route behind cap:rota.view would be writable by every member of the department.\n"
        .implode("\n", $offenders));
}
```

Run all six. Case 4 must go red against today's code; case 3 must go red for the administrator half
at minimum. If case 3 passes on first run, the institution fixture is not actually set to
`CONTACT_MEMBERS` — check that before believing it.

**The implementation**

1. `PersonPresenter::contactFree()` exactly as in Decision C, with that docblock.
2. `RotaGrid::forYear(string $academicYear)` — the `?User $viewer` parameter is **removed**, not
   ignored. Its line 199 becomes `PersonPresenter::contactFree($person)`. Update the class docblock:
   the `withExists(['user as has_account'])` note stays (it is still what stops an EXISTS per row);
   add a sentence saying no rota surface projects a contact field for any viewer, and why that is
   stronger than gating on the viewer's capability.
3. `MasterRotaController::index()` drops the `$request->user()` argument.
4. `RotaController::index()` — reads `?year=`, resolves the same distinct-academic-years list
   `MasterRotaController::index()` does, builds the grid, calls
   `AvailabilitySummary::forGrid($grid)` **with the full grid**, then filters `$grid['rows']` for
   display: drop `stale` rows (Decision D), apply the `?q=` name search and `?level=` filter
   server-side. **In that order** — the summary first, the filter after, or `stale_people`
   silently becomes zero and the search narrows the summary along with the list, which would make
   the department's availability figures depend on what the reader typed in a search box.

   Search is a case-insensitive substring match on `full_name` and `short_name` over the already-
   built rows (no query — the roster is tens of people and the grid is already in memory). Level
   filter matches `group_level_id`.

   Renders `Inertia::render('Rota', [...])` with `academic_years`, `year`, `grid`, `summary`,
   `filters` (the echoed `q`/`level`, so the screen never re-derives them).
5. `routes/web.php` — the new group from Decision A, placed **outside** the `/admin` prefix, with a
   comment naming owner decision 1 (no publish gate), Decision A (why its own controller) and the
   `ReservedUnitCodesTest` note (do not add `ROTA`).

**How to verify**

```bash
npm run build && php artisan test --filter 'RotaReadViewTest|RotaAccessTest|RotaGridTest|ContactProjectionNarrowsTest|ContactFieldsAreProjectedOnceTest' | tail -5
php artisan test | tail -5
```

`ContactFieldsAreProjectedOnceTest` must stay green **without an allow-list change** — the new
method is inside the one file that list already permits. If it goes red, something read
`->email`/`->phone` outside the presenter, which is the guard doing its job.

```bash
git commit -am "feat: the rota a resident can read, and no phone number in it"
```

---

### Task 4: the read screen — search, level filter, a per-person period strip, and the summaries under it

MR-05's four named affordances. Follows `MasterRota.vue`'s own conventions — mobile cards plus
desktop table, computed column count, every date arriving server-formatted and dual-dated. It
performs **no** date arithmetic (finding 14 — and mind the docblock-prose trap).

Read-only means read-only: **no `<select>`, no Split…, no On leave…, no Clear, no form of any
kind.** Owner decision 1 again: there is no publish control and no status badge, because there is
no status.

**Files touched**

- `resources/js/Pages/Rota.vue` (new — top level, matching the controller's namespace)
- `resources/js/Layouts/AppLayout.vue` (the top-level "Rota" nav entry)
- `tests/js/Rota.test.js` (new)
- `tests/Feature/Rota/RotaReadViewTest.php` (the query-budget case)

**The failing test to write first**

Vitest (`tests/js/Rota.test.js`), against the component with hand-built props:

1. renders one strip row per person with one chip per period, the unit code in each
2. a cell with a split renders both spans with their server-supplied labels, and no editing control
3. a cell with uncovered days renders the count, and a cell with none does not
4. a vacation renders on the cells it touches
5. the summary panel renders per-level, per-unit figures and the per-week vacation counts
6. **no `<select>`, no `<button>` with a write action, and no `<form>` appears anywhere** — the
   read-only property asserted at the component, not just at the route

PHP, in `RotaReadViewTest`:

7. `test_the_read_view_renders_a_bounded_number_of_queries` — **its own budget, measured, on a
   populated year** (Decision B; P1d-1 pre-merge finding 3). Seed 60 people, 13 periods, 1170
   spans, 120 vacations, 30 mid-year promotions and **ten** stale people. Ten, not one: a union
   written as one query per stale person costs exactly one query when there is one stale person, so
   a single-stale fixture cannot tell a correct implementation from an N+1. Run it once with a
   deliberately unreachable bound to read the real number, then pin `assertLessThan()` comfortably
   above it. Record the measured figure in the amendments.
8. `test_search_and_filter_narrow_the_rows_but_not_the_summary` — Decision D's ordering trap,
   asserted: `?q=` narrows `rows` and leaves every `summary` figure identical.
9. `test_a_deactivated_person_who_still_holds_a_span_is_not_on_the_read_view_but_is_counted` —
   absent from `rows`; `summary[periodId]['stale_assignments'] >= 1`.

**The implementation**

- `Rota.vue`: year picker (same shape as `MasterRota.vue`'s), a search input and a level `<select>`
  that push to `router.get('/rota', {...}, {preserveState: true, replace: true})`, the per-person
  period strip, and the summary panel below it. `channel-tag` / `channel-bar-*` for unit colour —
  the `bar_class` is already in `grid.units`. Semantic classes only.
- The strip is the row; each period is a chip carrying the unit code, the span labels when split,
  the uncovered-day count when non-zero, and a leave marker when a vacation touches it. The chip is
  a `<div>`, never a control.
- `AppLayout.vue`: a top-level "Rota" link for `can('rota.view')`, with the comment from Decision A
  explaining why an administrator legitimately sees two rota links. Do **not** touch the existing
  admin-section "Master Rota" entry.
- **Docblock warning:** the component docblock will want to explain that it does no date maths. Do
  not write the literal parenthesised call shapes the client scan looks for — P1d-1 tripped that
  guard twice on prose (finding 14). Describe the behaviour without the call shape.

**How to verify**

```bash
npm run build && npm test | tail -5
php artisan test --filter RotaReadViewTest | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: a year of rotations, on one screen a resident can read"
```

---

### Task 5: the same summary on the editor, and a test that proves it is the same

MR-07 says one summary. Decision B says one computation. This task is what makes that checkable
rather than merely stated: `MasterRotaController::index()` renders the same
`AvailabilitySummary::forGrid()` output under the editor grid, and a test calls it against both
surfaces for the same year and asserts the two are identical.

**Files touched**

- `app/Http/Controllers/Admin/MasterRotaController.php`
- `resources/js/Pages/Admin/MasterRota.vue`
- `resources/js/Components/AvailabilityPanel.vue` (new — extracted from Task 4's panel so both
  screens render one component, not two copies)
- `resources/js/Pages/Rota.vue` (use the extracted component)
- `tests/Feature/Rota/AvailabilitySummaryParityTest.php` (new)
- `tests/js/Rota.test.js` / `tests/js/AvailabilityPanel.test.js`

**The failing test to write first**

```php
/**
 * MR-07 is ONE summary (Decision B). The editor and the read view compute it from their own grid,
 * and the two grids differ in exactly one respect — the read view filters stale rows for DISPLAY,
 * after the summary is computed. So the summaries must be byte-identical, and this asserts it
 * against a populated year rather than trusting the shared call.
 */
public function test_the_editor_and_the_read_view_report_the_same_summary(): void
```

Seed one populated academic year with splits, vacations, a mid-year promotion and one stale person.
GET `/admin/rota?year=…` as an administrator and `/rota?year=…` as a resident, and
`assertSame()` the two `summary` props. It must go red before the editor renders one at all.

Add a Vitest case asserting `AvailabilityPanel.vue` renders the same markup from the same props on
both pages — one component, two mounts.

**The implementation**

`MasterRotaController::index()` gains
`'summary' => $grid === null ? null : AvailabilitySummary::forGrid($grid)`. Extract Task 4's panel
markup into `resources/js/Components/AvailabilityPanel.vue` and use it from both pages. Note in the
component's docblock that the numbers arrive computed and it renders them — it derives nothing,
sums nothing, and converts no date.

**How to verify**

```bash
npm run build && php artisan test --filter 'AvailabilitySummaryParityTest|RotaGridTest' | tail -5
npm test | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: one set of numbers, on both rota screens"
```

---

### Task 6: the e2e journey — a resident reads the rota, and it is still the rota after a reload

The house rule: an e2e spec asserts persistence **after reload**, never a save indicator. This one
has no save at all, so what it must prove instead is that (a) a resident actually reaches the
screen, (b) search and filter survive a reload with the same rows, and (c) **nothing on the page
can write**.

**Files touched**

- `tests/e2e/rota-read.spec.js` (new)
- `database/seeders/E2eSeeder.php` (a second person and a vacation, if the existing fixture is thin
  — check what P1d-1's Task 11 already added before adding anything)

**The failing test to write first**

The spec itself, TDD-style: write it, watch it fail (the page does not render for a resident, or
the testids do not exist), then make it pass.

1. Sign in as a **resident** (not an administrator) and reach `/rota`.
2. Assert the period strip shows the seeded assignment's unit code.
3. Type a name into search; assert the row set narrows; reload; assert the narrowed set is the same
   (the filter lives in the query string, so it survives — this is what proves it is server-side
   and not a client-only convenience).
4. Filter by level; same reload assertion.
5. Assert the summary panel shows a non-zero figure for the seeded period.
6. Assert there is **no** editing control on the page: no `<select>` for a unit, no button with a
   split/clear/leave testid.
7. Navigate to `/admin/rota` as the same resident and assert it is refused.

**Finding 15 applies:** scope every per-row lookup through a helper built from the row's own
`data-row-id`. An unscoped `getByTestId` hits Playwright strict mode because the mobile card and
the desktop row carry identical attributes.

**How to verify**

```bash
npm run build && npm run test:e2e | tail -20
php artisan test | tail -5
```

Measure the e2e count before and after; record both in the amendments.

```bash
git commit -am "test: a resident really can read the rota, and really cannot change it"
```

---

## Definition of done — P1d-2a

- [x] `php artisan test` green, run via **Bash**, after `npm run build`. `npm test` green.
      `npm run test:e2e` green.
- [x] `rota.manage` is Administrator-only in `ROLE_DEFAULTS`, in the parity test, in `RotaAccessTest`
      and in `docs/spec/08-foundation.md`; the grant-from-the-screen path has a test; the runbook
      says what to do on an instance that already has the grant, and **no migration revokes it**.
- [x] `App\Support\Rota\AvailabilitySummary` exists, is pure, issues **zero** queries (asserted),
      and is the only computation of MR-07's numbers.
- [x] The summary counts uncovered days **and** people carrying a gap, separately, and reports
      per-week vacations from the period's own `weeks`.
- [x] `/rota` exists behind `auth` + `cap:rota.view`; every route behind `cap:rota.view` is a GET,
      asserted over the router; a resident is refused `/admin/rota`.
- [x] **No contact field appears in the props of either rota surface, for any viewer**, asserted
      with `contact_visibility = members` and an administrator — and `RotaGrid` no longer takes a
      viewer at all.
- [x] A deactivated-but-assigned person is absent from the read view, absent from the coverage
      numbers, and present in `stale_people`.
- [x] The read view carries its own **measured** query budget, taken on a populated year with ten
      stale people.
- [x] Search and filter narrow the rows and leave the summary untouched.
- [x] No publish state anywhere: no column, no prop, no control, asserted.
- [x] Every date on both screens is server-formatted and dual-dated;
      `CalendarIsTheOnlyConverterTest` green including its client scan.
- [x] `CompiledCssIsLightOnlyTest` and `TextContrastMeetsAaTest` green; no `dark:`, no raw palette
      class, no hex in markup.
- [x] [Amendments](#amendments-made-during-execution) records what this plan got wrong.

---

# P1d-2b — tasks

### Task 7: `RotaFill` — the plan a bulk move makes, before it makes it

MR-06's fill-down, fill-across and copy-period, as a **planner that writes nothing**. The commit
path is Task 8; separating them is what makes "validate and authorize the whole set before any
mutation" (P1 finding 12) structurally true rather than a comment.

**Every rule below is Decision E and Decision F, restated here because this is the task that
implements them:** fill-down copies the span set **verbatim including splits** (same period, so the
dates are already inside it); fill-across and copy-period accept a **whole-period source only** and
report a split source as `SKIP_SPLIT_SOURCE`; fill-across is **forwards only**; there are **two**
fill-down actions ("this level group", "this whole column"), never one that guesses; **a target
cell carrying a split is skipped unless that cell is explicitly confirmed**; a target person off the
active roster and a retired source unit are both skipped with the reason named (finding 10).

**Files touched**

- `app/Support/Rota/RotaFill.php` (new — `plan()` and the private `analyse()` it shares with Task
  8's `apply()`)
- `tests/Feature/Rota/RotaFillPlanTest.php` (new)

**The failing test to write first**

`RotaFillPlanTest`, one case per outcome and one per operation:

1. `test_fill_down_a_level_group_targets_only_that_group`
2. `test_fill_down_a_column_targets_every_person_in_the_period`
3. `test_fill_down_copies_a_split_verbatim` — the target cells get the same span dates, because it
   is the same period
4. `test_fill_across_goes_forwards_only` — a source in position 5 plans positions 6..13 and never
   1..4
5. `test_fill_across_from_a_split_source_is_refused_per_cell` — `SKIP_SPLIT_SOURCE`, with the
   reason string naming why (Decision E)
6. `test_copy_period_maps_whole_period_assignments_onto_the_targets_own_bounds` — the written span
   bounds equal the **target** period's, never the source's
7. `test_a_target_carrying_a_split_is_skipped_unless_confirmed` — same input twice, once with an
   empty `$confirmations` (`SKIP_SPLIT_TARGET`) and once with that cell confirmed (`REPLACE`)
8. `test_an_inactive_target_person_is_skipped` and
   `test_a_retired_source_unit_is_skipped` — finding 10
9. `test_an_identical_target_is_unchanged_not_replaced` — `UNCHANGED`, and it is not counted as a
   change
10. `test_plan_writes_nothing` — assert the assignment row count is identical before and after a
    `plan()` that would have written hundreds of cells. This is the test that makes the separation
    real.

**The implementation**

```php
/**
 * @param array<int,bool> $confirmations  target-cell key ("<personId>:<periodId>") => the operator
 *                                        confirmed overwriting a SPLIT in that cell. Absent means
 *                                        false. Shaped after RosterImport's $confirmations for the
 *                                        same reason: a destructive default must be opt-in, per
 *                                        item, and visible in the request body.
 */
public static function plan(string $op, array $source, array $confirmations): array
```

Returns `['targets' => [...], 'summary' => ['assign'=>n,'replace'=>n,'unchanged'=>n,'skipped'=>n],
'errors' => [...]]`. Each target carries `person_id`, `period_id`, `outcome`, `reason` (null unless
skipped), the current span set and the proposed one — so the preview can show both without a second
computation.

`analyse()` is private and shared; `plan()` is `analyse()` with the writes not taken. The **queries
are bounded**: resolve the source cell, the target periods and the target people in **one query
each**, then group in PHP — the same discipline `RotaGrid` documents by name. A per-target
`MasterRotaAssignment::where(...)` lookup is a 780-query fill preview and is exactly the N+1 class
this codebase keeps paying for.

Docblock must state that it writes nothing, that Task 8's `apply()` re-runs the same `analyse()`
inside its transaction and never trusts a client-supplied plan, and that the skip-a-split default is
a data-loss guard, not a convenience.

**How to verify**

```bash
npm run build && php artisan test --filter RotaFillPlanTest | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: what a bulk fill would do, before it does it"
```

---

### Task 8: the fill commit — one transaction, one audit row, and `rota_fill` on the watch list

**Files touched**

- `app/Support/Rota/RotaFill.php` (`apply()`)
- `app/Http/Controllers/Admin/MasterRotaController.php` (`fillPreview()`, `fill()`)
- `app/Http/Requests/Admin/RotaFillRequest.php` (new)
- `routes/web.php` (two routes, both inside the existing `cap:rota.manage` group)
- `app/Console/Commands/AuditAnomalies.php` (`rota_fill` on the single-occurrence watch list)
- `tests/Feature/Rota/RotaFillCommitTest.php` (new)
- `tests/Feature/Console/AuditAnomaliesTest.php`

**The failing test to write first**

1. `test_apply_re_derives_the_plan_and_does_not_trust_the_request` — send a request whose claimed
   plan says "replace 40 cells" while the database has since changed; assert the applied set matches
   a fresh analysis, not the claim.
2. `test_a_refusal_refuses_the_whole_operation` — make one target invalid; assert **zero** rows
   changed. Not "all but one".
3. `test_every_write_goes_through_the_one_writer` — covered structurally by
   `RotaWritersAreSingularTest`, but assert behaviourally too: a filled cell that overlaps is
   refused by the model guard and surfaces as a 422, never a 500 (P1b finding 14's lesson, already
   applied in `MasterRotaController`'s other actions).
4. `test_one_audit_row_per_operation_not_one_per_cell` — fill 40 cells, assert exactly **one**
   `rota_fill` row, and assert its detail matches `op=...;source_person=<id>;source_period=<id>;
   targets=40;assigned=..;replaced=..;skipped=..` — ids and counts only, no name, no unit code, no
   period label.
5. `test_the_audit_row_is_written_after_the_transaction_commits` — finding 8. Force a failure inside
   the transaction and assert **no** `rota_fill` row exists.
6. `test_a_resident_cannot_fill` — 403 on both routes.
7. In `AuditAnomaliesTest`: `test_a_rota_fill_is_reported_as_a_single_occurrence` — write one
   `rota_fill` row, run `audit:anomalies`, assert it appears in the findings; and
   `test_a_rota_assign_is_not_watched` — write fifty `rota_assign` rows and assert **none** is
   reported, which is Decision H's reasoning asserted rather than commented.

**The implementation**

`apply()` opens **one** `DB::transaction()`, calls the same `analyse()` inside it, and dispatches
each non-skipped target to `RotaAssignment::set()` (whole-period source) or `RotaAssignment::split()`
(fill-down of a split). Nested writer transactions are expected (finding 9) — **do not** inline the
writer to avoid them.

The `rota_fill` audit row is written **after** the transaction returns (finding 8), from the
controller, exactly as `RosterImport::commit()` does it.

`AuditAnomalies`' single-occurrence array gains:

```php
// P1d-2 Decision F: the first rota action on this list, and deliberately the ONLY one.
// Per-cell editing (rota_assign/rota_split/rota_clear/vacation_book/vacation_cancel) is
// ordinary work and stays off it — P1d-1 Decision H. A fill rewrites hundreds of cells behind
// one confirmation, which is why it is audited as ONE row (P1 finding 11) and why that one row
// always deserves a human look.
'rota_fill' => 'a bulk rota fill rewrote many cells at once',
```

`RotaFillRequest`: `op` in the four action keys; `source_person_id` / `source_period_id` against the
**strict** active predicate (finding 10 — the same predicate `RotaCellRequest` applies to its write
routes, because a fill is a write route); `target_period_id` for copy-period; `confirmations` an
array of `"<int>:<int>" => bool`. Dates never appear in this request — the spans come from the
source cell, server-side.

Routes go **inside the existing `cap:rota.manage` group** — a POST behind `rota.view` would fail
Task 3's router assertion, which is the point of having written it.

**How to verify**

```bash
npm run build && php artisan test --filter 'RotaFillCommitTest|AuditAnomaliesTest|RotaWritersAreSingularTest' | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: fill a year in one act, audited as one act"
```

---

### Task 9: the fill UI — preview, then confirm, and never a silent overwrite

**Files touched**

- `resources/js/Pages/Admin/MasterRota.vue`
- `tests/js/MasterRotaFill.test.js` (new)

**The failing test to write first**

Vitest:

1. the four actions are offered from a cell (fill down: level group / whole column; fill across;
   copy period), and **each opens a preview — none writes on click**
2. the preview lists every target with its outcome and reason
3. a `SKIP_SPLIT_TARGET` row renders a per-cell confirm checkbox, **unchecked by default**
4. the master "overwrite all splits" tick sets the individual boxes rather than replacing them, so
   the request body always carries the explicit set
5. the confirm button posts exactly `{op, source_person_id, source_period_id, target_period_id?,
   confirmations}` — no dates, no span payload
6. a `SKIP_SPLIT_SOURCE` result renders its reason (Decision E) and offers no confirm, because
   there is nothing to confirm

**The implementation**

Follow `MasterRota.vue`'s existing split-editor pattern: a panel below the grid, `useForm`,
`preserveScroll`, a live region for the outcome counts, `SaveStatus` for the commit. The preview
comes back through the same `back()->with(...)` flash shape `RosterImport`'s preview uses, so the
page never holds a stale plan across a navigation.

Counts render from the server's summary; the component computes none of them.

**How to verify**

```bash
npm run build && npm test | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: see the fill before it happens, and confirm every split it would eat"
```

---

### Task 10: export — two files, no contact, and a warning before a blank handle ships

Decision G. Both routes behind `cap:rota.manage`, both through `App\Support\Csv::stream()` (BOM
first, every cell formula-neutralised), one audit row each carrying counts only.

**Files touched**

- `app/Http/Controllers/Admin/MasterRotaController.php` (`exportAssignments()`, `exportVacations()`)
- `routes/web.php` (two GETs inside the `cap:rota.manage` group)
- `resources/js/Pages/Admin/MasterRota.vue` (two buttons, plus the short-name warning)
- `tests/Feature/Rota/RotaExportTest.php` (new)

**The failing test to write first**

1. `test_the_assignment_export_is_one_row_per_span` — a split cell produces two rows
2. `test_the_export_carries_no_contact_column` — assert `email` and `phone` appear nowhere in the
   header row **or** the body, for an administrator on a `contact_visibility = members`
   institution. Same permissive fixture as Task 3, same reason.
3. `test_a_person_is_identified_by_short_name_and_full_name` — and no `person_id` column
4. `test_a_formula_cell_is_neutralised_on_the_way_out` — a unit code or a name beginning `=` leaves
   with a leading apostrophe (`Csv::stream()` does this; assert it at the feature level so a future
   hand-rolled writer would fail here as well as in `CsvIsTheOnlyReaderWriterTest`)
5. `test_the_vacations_export_carries_granularity_and_source`
6. `test_the_export_audits_counts_only` — `rota_export` with `file=assignments;year=...;rows=n`, no
   filename, no name
7. `test_a_person_with_no_short_name_is_reported_before_the_file_is_generated` — the screen prop
   names the count (finding 5); the export still runs, with a blank handle, rather than silently
   dropping the person
8. `test_a_resident_cannot_export` — 403

**The implementation**

Read the same `RotaGrid::forYear()` output the screen already uses, or query the spans directly if
that reads more honestly for a stream — either is fine, but **do not** add a second definition of
"which spans belong to this year". `Csv::stream()` takes an iterable, so build rows lazily.

The short-name warning is a prop on the editor screen
(`people_without_a_short_name: n`), rendered beside the export buttons with a link to Admin →
People. It is computed from the grid already in memory; it costs no query.

**How to verify**

```bash
npm run build && php artisan test --filter 'RotaExportTest|CsvIsTheOnlyReaderWriterTest|CsvInjectionTest' | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: the rota as two files, with nobody's phone number in them"
```

---

### Task 11: `RotaImport` — one analysis, a pinned digest, and nothing invented

Decision H. Copies `RosterImport`'s discipline exactly where the shapes match and states each
deliberate difference in its own docblock. **The unit of outcome is the (person, period) cell, not
the line** (finding 12). **`week`-granularity vacations snap through the same code path as the
screen** (owner decision 3), and the preview reports the adjustment.

**Files touched**

- `app/Support/Rota/VacationBooking.php` (extract `snap()`, called by `book()`)
- `app/Support/Rota/RotaImport.php` (new)
- `tests/Feature/Build/RosterNeverMintsCredentialsTest.php` (`SCANNED_FILES` gains the new file)
- `tests/fixtures/rota/*.csv` (new — synthetic, permanently)
- `tests/Feature/Rota/RotaImportTest.php` (new)

**The failing test to write first**

Fixtures first, because the cases are the fixtures. `tests/fixtures/rota/`, **synthetic and
permanently so** (P1c owner decision 3 — no real staff list may ever enter this repository, in a
fixture or anywhere else). Every file exercises a **failure shape**, not a department:

| Fixture | Exercises |
|---|---|
| `assignments-clean.csv` | the happy path, including one split cell across two lines |
| `assignments-unknown-person.csv` | a `short_name` on no roster → `SKIP_UNKNOWN_PERSON` |
| `assignments-inactive-person.csv` | a `short_name` belonging to a deactivated person → `SKIP_UNKNOWN_PERSON`, reason *"no longer on the active roster"* (Decision H item 3 — **not** a rediscovery) |
| `assignments-retired-unit.csv` | a `unit_code` that exists but is inactive → `SKIP_UNKNOWN_UNIT` |
| `assignments-foreign-period.csv` | an `academic_year` with no rows → `SKIP_UNKNOWN_PERIOD` |
| `assignments-span-outside-period.csv` | dates outside the resolved period → `ERROR` |
| `assignments-overlapping-spans.csv` | two lines for one cell covering the same day → `ERROR`, detected across the whole file before any write |
| `assignments-arabic-names.csv` | Arabic `full_name` values, UTF-8, round-tripping unmangled |
| `assignments-formula-injection.csv` | a cell beginning `=` — must round-trip through `Csv::neutralise()`/`CsvRosterReader::unNeutralise()` |
| `vacations-clean.csv` | one `date` row and one `week` row that is **not** week-aligned |
| `vacations-duplicate.csv` | the same person and snapped bounds twice → `SKIP_DUPLICATE` |

Then `RotaImportTest`:

1. one case per fixture, asserting the outcome **and** that the database is untouched by
   `preview()`
2. `test_preview_and_commit_share_one_analysis` — commit a file and assert the applied set equals
   the previewed set exactly
3. `test_a_file_error_refuses_the_whole_import` — never "7 of 8"
4. `test_the_commit_is_pinned_to_the_previewed_bytes` — a changed file 422s naming the mismatch
5. `test_a_split_cell_is_one_outcome_with_two_line_numbers` — finding 12, asserted
6. `test_re_importing_the_same_assignments_file_changes_nothing` — idempotent by construction
7. `test_a_week_vacation_is_snapped_by_the_same_code_path_as_the_screen` — book the same range
   through `VacationBooking::book()` and through the importer; assert identical stored bounds, and
   assert the **preview reports the adjustment** (the original bounds and the snapped bounds both
   appear on the outcome)
8. `test_re_importing_the_same_vacations_file_creates_nothing` — `SKIP_DUPLICATE`
9. **`test_an_exported_year_re_imports_as_a_no_op`** — the round trip that matters: run Task 10's
   export, capture the streamed bytes, write them to a temp file, read them with
   `CsvRosterReader`, and assert every outcome is `UNCHANGED`/`SKIP_DUPLICATE` and no row changed.
   This is `CsvInjectionTest`'s pairing discipline applied at the feature level, and it is the only
   test that can catch a neutralise/un-neutralise mismatch in a real column.

**The implementation**

`VacationBooking::snap(string $from, string $to, string $granularity): array` — extracted from
`book()`, called by `book()`, and called by the importer's preview to display the adjustment. It
writes nothing, so `RotaWritersAreSingularTest` is unaffected. This is what makes owner decision 3's
*"the same code path as the screen"* literally true.

`RotaImport`: `preview()` / `commit()` / private `analyse()`, mirroring `RosterImport`'s signatures.
Fixed headers matched case-insensitively after trim; a missing required header is a file error
naming it. Person by `short_name` against `Person::query()->active()`. Unit by
`Unit::findByCode()` **then an explicit `active` check** — `findByCode()` deliberately finds
retired units too (`app/Models/Unit.php:114`, no `active` scope), and that is useful here rather
than a nuisance: a code that resolves to a retired unit gets `SKIP_UNKNOWN_UNIT` with *"this unit
has been retired"* as its reason, while a code that resolves to nothing gets *"no such unit"*. Two
different operator problems, two different messages. Period by `(academic_year, position)`. Rows
grouped by
`(person, period)` into cell outcomes carrying `lines`. Application goes through
`RotaAssignment::split()` (which replaces the whole set — never a merge) and
`VacationBooking::book()` with `Vacation::SOURCE_IMPORT` (finding 7). One transaction; the audit
row (`rota_import`, counts only) **after** it commits (finding 8).

**`RosterNeverMintsCredentialsTest::SCANNED_FILES` gains `app/Support/Rota/RotaImport.php`.** Note
in the task and in the guard's own comment: **this brings that guard's bare `'->save()'` needle with
it**, so every persistence call in the file must be `create()`/`update()`. In practice the file
should contain **no** persistence call at all — it persists only through the two writers — which is
the strongest form of satisfying the needle and is worth saying out loud rather than discovering.

**How to verify**

```bash
npm run build && php artisan test --filter 'RotaImportTest|RosterNeverMintsCredentialsTest|RotaWritersAreSingularTest|CsvIsTheOnlyReaderWriterTest' | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: read a rota back in, inventing nobody"
```

---

### Task 12: the import screen, its journey, and MR-04 restated over the new files

**Files touched**

- `app/Http/Controllers/Admin/RotaImportController.php` (new)
- `app/Http/Requests/Admin/RotaImportRequest.php` (new)
- `routes/web.php` (index / preview / commit, all `cap:rota.manage`)
- `resources/js/Pages/Admin/RotaImport.vue` (new)
- `tests/e2e/rota-import.spec.js` (new)
- `tests/Feature/Rota/RotaAccessTest.php` (extend the MR-04 scan)

**The failing test to write first**

The e2e spec: upload `assignments-clean.csv`, see the preview, commit, **reload**, and assert the
assignment is on the grid. Then upload a **changed** file against the previous digest and assert the
422 message. Reload-after-commit is the only proof that counts.

Then the MR-04 restatement. `RotaAccessTest::test_nothing_in_the_rota_infers_on_call_eligibility`
exists and scans `app/` for `off_roster` / `offRoster` / `callEligib` / `call_eligib`. Extend it,
because this slice creates the first surface a future implementer would actually build eligibility
on:

```php
/**
 * MR-04 is Stage 2 (P1d owner decision 1) and P1d-2 records the hook, nothing more. Extended here
 * because an AVAILABILITY SUMMARY is precisely the shape somebody would reach for to answer "who
 * can take call in Block 11" — the scan now covers App\Support\Rota in full, including
 * AvailabilitySummary, RotaFill and RotaImport, so the absence is asserted over the files that
 * make the inference tempting rather than only over the ones that predate it.
 */
```

Add `'eligib'`, `'on_call'`, `'onCall'` and `'callRoster'` to the needle list, and assert over the
whole of `app/Support/Rota/` plus the two rota controllers. Collect `$offenders[]`; end with
`assertSame([], $offenders, ...)`.

**The implementation**

`RotaImportController` mirrors `RosterImportController` exactly: `index()` renders the screen;
`preview()` writes **nothing**, no transaction, no audit row, and flashes the analysis plus
`hash('sha256', $bytes)`; `commit()` re-reads the file, `hash_equals` against the claimed digest, and
422s naming the mismatch otherwise. `readerFromRequest()` catches `RosterFormatException` into a
validation message. Two file kinds (`assignments`, `vacations`) selected by a radio on the screen
and validated as an enum, never sniffed from the headers — a file whose shape is guessed is a file
that gets misread.

`RotaImport.vue` follows `RosterImport.vue`'s structure: choose kind, upload, preview table with one
row per **cell** (finding 12 — show the contributing line numbers), file errors listed above it, the
commit button disabled while errors exist.

**How to verify**

```bash
npm run build && php artisan test --filter 'RotaAccessTest|RotaImportTest' | tail -5
npm run test:e2e | tail -20
php artisan test | tail -5
```

```bash
git commit -am "feat: an import screen that shows you the whole file first"
```

---

### Task 13: correct the documents this invalidates

Every claim written here must be **verified against the tree first**, not copied from this plan's
prose. P1d-1's Task 12 is the model: it ran `grep -n "AppLayout.vue" CLAUDE.md` before touching
anything and found the on-disk file already correct, avoiding a "fix" that would have reverted P1b.
The same trap is live here — **a cached copy of CLAUDE.md handed to an agent may still say
`rota.manage` defaults to Administrator and Chief Resident.** Check the file, not the memory.

**Files touched**

- `CLAUDE.md`
- `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md` (§6.3, §7, §9.1,
  §13, §14)
- `docs/spec/08-foundation.md` (already corrected in Task 1 — verify, do not re-edit)
- `docs/spec/15-rulings.md`
- `docs/RUNBOOK-DEPLOY.md`
- `docs/superpowers/plans/2026-08-08-p1-master-rota.md`

**What to write**

1. **CLAUDE.md** gains, in the non-negotiables:
   - *"`App\Support\Rota\AvailabilitySummary` is the ONE computation behind MR-07, it is a pure
     fold over `RotaGrid`'s output and issues no query, and it is used by both the editor and the
     read view — never two. It counts uncovered days and the number of people carrying a gap
     separately, because a gap is a legal state (P1d owner decision 3) and a summary that rounds it
     away fails §35's 'availability summaries match reality'."*
   - *"No rota surface projects a contact field, for any viewer. `RotaGrid` takes no viewer at all
     and calls `PersonPresenter::contactFree()`. `PersonPolicy::viewContact()` returns true for
     every signed-in account once a department sets `contact_visibility` to `members`, so gating a
     rota screen on the viewer's capability would have leaked email and phone to the whole
     department the moment that toggle was set."*
   - *"There is no publish gate on the master rota (owner decision, 2026-08-10). No status column,
     no draft state, no publish action. The read view shows the current rota."*
   - *"`rota.manage` defaults **Administrator-only** (owner decision, 2026-08-10, reversing the
     2026-08-09 decision P1d-1 shipped). An administrator grants it per department from Access
     Control. `rota.view` remains seeded for every authenticated member."*
   - *"Every bulk rota operation writes through `RotaAssignment`/`VacationBooking`, validates and
     authorizes the whole set before any mutation, runs in one transaction, and audits **one**
     `rota_fill` row per operation — never one per cell. `rota_fill` is on `AuditAnomalies`'
     single-occurrence watch list; the five per-cell rota actions deliberately are not."*
   - *"A bulk fill SKIPS a target cell that carries a split unless that cell is explicitly
     confirmed."*
2. **Design doc §6.3** — note that both rota tables now have a bulk write path and an import path,
   and that neither introduces a second writer.
3. **Design doc §7** — `Calendar::weeksIn()` gained its second consumer (`AvailabilitySummary`), and
   `VacationBooking::snap()` is the one snapping rule shared by the screen and the importer.
4. **Design doc §9.1** — record that the publish-gate question is **answered and closed**: no gate.
   Remove it from §14's open items rather than leaving it listed as open, and say who answered it
   and when.
5. **Design doc §13** — mark P1d-2 **SHIPPED** with its actual content, and correct the sentence
   that describes MR-06 without naming the bulk discipline it requires.
6. **`docs/spec/15-rulings.md`** — a ruling for the publish-gate answer and one for the
   Administrator-only `rota.manage` default, both dated 2026-08-10 and both naming what they
   reverse.
7. **`docs/RUNBOOK-DEPLOY.md`** — the export/import operator steps (which file is which, that the
   import never invents anybody, that a commit is pinned to the previewed bytes), plus Task 1's
   Access Control note if it was not already added there.
8. **`docs/superpowers/plans/2026-08-08-p1-master-rota.md`** — mark P1d-2 done and point at this
   document.

**A verification step, not a formality.** Before writing each claim, run the grep or read the file
that proves it. In particular: confirm `RotaGrid::forYear()` really takes no viewer; confirm
`AuditAnomalies` really lists `rota_fill` and really does not list the five per-cell actions;
confirm no migration was added in either half (`git diff --stat main -- database/migrations`
should be empty). A document citing a test or a behaviour that does not exist is the exact failure
this task exists to prevent.

**How to verify**

```bash
npm run build && php artisan test | tail -5
npm test | tail -5
npm run test:e2e | tail -20
```

Then record in [Amendments](#amendments-made-during-execution) every task's real count, every place
this plan was wrong, and everything found empirically rather than by inspection.

```bash
git commit -am "docs: what reading, summarising and moving the rota changed"
```

---

## Definition of done — P1d-2b

- [ ] `php artisan test` green, run via **Bash**, after `npm run build`. `npm test` green.
      `npm run test:e2e` green.
- [ ] `App\Support\Rota\RotaFill` plans without writing; `plan()` and `apply()` share one
      `analyse()`; `apply()` re-derives inside its own transaction and trusts no client-supplied
      plan.
- [ ] A refusal refuses the **whole** operation. Never "412 of 780 applied".
- [ ] A target cell carrying a split is skipped unless explicitly confirmed, per cell, defaulting
      to skip.
- [ ] Fill-across is forwards only; a split source is refused across periods with the reason named;
      fill-down copies splits verbatim.
- [ ] Two explicit fill-down actions, never one that guesses.
- [ ] **One** `rota_fill` audit row per operation, ids and counts only, written after the
      transaction commits. `rota_fill` is on `AuditAnomalies`' watch list; the five per-cell rota
      actions are not, and a test asserts both halves.
- [x] Export is **two** files, through `App\Support\Csv` only, BOM-first, formula-neutralised, with
      **no email and no phone** — asserted with `contact_visibility = members`. (Task 10. The
      contact assertion is over the file's BYTES, and the neutralisation is asserted as a ROUND
      TRIP through `CsvRosterReader` — a formula name and an Arabic name both come back identical.)
- [x] A person with no `short_name` is reported before the file is generated. (Task 10. Counted
      over the people who would appear in the file, not the whole roster — see Amendments.)
- [ ] `RotaImport::preview()`/`commit()` share one `analyse()`; the whole file is validated before
      any write; the commit is pinned to the previewed digest; outcomes are `CREATE`/`REPLACE`/
      `SKIP_UNKNOWN_PERSON`/`SKIP_UNKNOWN_UNIT`/`SKIP_UNKNOWN_PERIOD`/`ERROR`, plus
      `SKIP_DUPLICATE` on the vacations file, with the reason for that addition recorded.
- [ ] The importer **never** invents a person, a unit or a period, and never rediscovers a retired
      one.
- [ ] `week`-granularity vacations snap through `VacationBooking::snap()` — the same code path as
      the screen — and the preview reports the adjustment.
- [ ] An exported year re-imports as a no-op, asserted end to end through `CsvRosterReader`.
- [ ] `app/Support/Rota/RotaImport.php` is in `RosterNeverMintsCredentialsTest::SCANNED_FILES`, and
      the file contains no persistence call of its own.
- [ ] Every fixture under `tests/fixtures/rota/` is synthetic and exercises a failure shape: a
      person not on the roster, a retired unit, a period from another academic year, a span outside
      its period, two spans that overlap, Arabic names, and a formula-injection cell.
- [ ] `RotaWritersAreSingularTest` green with **no** new allow-list entry.
- [x] MR-04 is unbuilt and its absence is asserted over `app/Support/Rota/` in full, including the
      three new classes. (Task 12. Also over the rota's controllers, its form requests and its four
      Vue screens; case-insensitively, over CODE with comments stripped — see Amendments for why
      the plan's literal needle list would have failed the build on the docblocks that state the
      rule, and for the three probes that prove the scan fires.)
- [ ] No migration was added in either half.
- [ ] The documents in Task 13 corrected, each claim verified against the tree before it was
      written.
- [ ] [Amendments](#amendments-made-during-execution) records what this plan got wrong.

---

## Owner decisions needed

**None.** The three that blocked this plan were answered on 2026-08-10 and are folded into the task
text above (see the binding block at the top). Two of them close open items the P1d-1 plan left
standing:

- The publish gate is **answered: none**. Design §14's open item is removed by Task 13, not left
  listed as open.
- `rota.manage` is **answered: Administrator-only**, reversing what P1d-1 shipped. Task 1 is the
  reversal.

One question is deliberately **deferred and named** rather than being answered by default, because
nothing in this plan depends on it: *whether an approved leave request (Munawib RQ-01) should
become a `vacations` row automatically.* `Vacation::SOURCE_IMPORT` and `SOURCE_MANUAL` exist; a
third source is P3, when a request system exists to feed it. This slice records the hook and builds
nothing.

---

## Stage 1 acceptance (§35), after P1d-2

> *Accepted:* the pilot's real master rota and clinics live; residents claimed accounts;
> **availability summaries match reality.**

**The third clause is satisfied by this plan, and it is the one this plan exists for.** MR-07's
summaries are computed from the same rows the grid renders, by one function used by both surfaces,
counting uncovered days and the people carrying them alongside assignments and vacations — so a
half-planned period reads as half-planned rather than as fully covered, and a departed person's
block reads as an occupied cell needing attention rather than as coverage. That is what "match
reality" means operationally, and Task 2's eight cases are where it is proved.

The first clause becomes **reachable in one afternoon rather than one week**: MR-06's fill,
copy-period and CSV import are what turn "the owner types 780 cells" into "the owner fills a
column, imports last year's file, and corrects the exceptions". It is still the owner who loads the
real rota into a running instance, and it is still observed there rather than asserted by a test —
nothing in this repository ever contains a real staff list.

The second clause, *"residents claimed accounts"*, is **P1c-2**. "Clinics live" is **P1e**.

---

## Next plan

**P1e — clinics, the weekly clinic map, and the setup wizard**, per design §13, written when
P1d-2b merges. It inherits five things from this slice that it must respect:

1. `AvailabilitySummary` is the one computation behind any "who is available" figure. A clinic
   screen needing that number extends it or reads it; it does not compute a second one.
2. No rota or clinic surface projects a contact field. `PersonPresenter::contactFree()` is the
   projection for any screen reachable with a broadly-seeded capability.
3. `RotaAssignment` and `VacationBooking` remain the only writers of their tables, and
   `RotaWritersAreSingularTest` fails the build for a second one — including from a clinic path.
4. Any bulk clinic operation inherits the same discipline: validate and authorize the whole set
   first, one transaction, one summary audit row, and a destructive default that is opt-in per
   item.
5. MR-04 is still Stage 2. A clinic assignment is not an eligibility signal, and the guard now scans
   the whole rota namespace for the inference.
