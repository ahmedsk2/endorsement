> ## OWNER DECISIONS, 2026-08-09 — READ BEFORE ANY TASK
>
> **A. There is NO terminal level. Do not build `levels.terminal`.**
>
> Task 6 must drop that column and Task 7 must not mark `R4` (or anything else) terminal.
> Nobody is auto-promoted: LV-03's annual promotion stays a **one-action, previewed,
> single-transaction, audited** operation per Munawib LV-03, but the operator **chooses the
> target level explicitly** rather than the system inferring "one step up" and stopping at a
> marker.
>
> The reasoning is worth keeping: a wrong terminal marker fails in two directions that are both
> silent — an unmarked top level advances a cohort into a level that does not exist, and a
> wrongly-marked middle level graduates a cohort a year early. Removing the inference removes
> the whole failure class. `Level::nextAfter()` is therefore **not** the one definition of
> "advance one level"; it should not be built as such. Whatever P1c's promotion screen needs, it
> takes the target level as input.
>
> `EXT` remains outside the ladder and is never promoted.
>
> **B. WARD is the only clinic owner.** Seed `clinic_owner = true` on WARD alone; PICU, NICU and
> SCBU stay false. Affects nothing before P1e, but settles CL-01's first screen.

# P1b — Structure Administration

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Munawib Stage 1's structure layer — units administration (UN-01…05), the level ladder
(LV-01), and the settings surfaces that make both revisitable (ST-02). This is the **first admin
UI in the Rota module**: P1a built the calendar with no screens at all, and P1b is where a
department's shape stops being a seeder and becomes something an administrator edits.

**Binding requirements:** UN-01, UN-02, UN-03, UN-04, UN-05, LV-01, ST-02 — plus ST-06 held
(every day-boundary computation still goes through `App\Support\Calendar`).

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 3 + Vue 3, PHPUnit 12 (SQLite in-memory,
`APP_TIMEZONE=Asia/Riyadh`), Vitest, Playwright, Tailwind 4 via `@theme`, MySQL 8.4 in
production.

**Baseline this plan was written against:** branch `feat/p1-master-rota` at `9598aa5`,
`php artisan test` = **746 tests, 3366 assertions, 0 failures, 0 skipped** (run via Bash),
`npm test` = 109. Every task below states the count it expects to leave behind.

**Scope of P1b specifically:** two additive migrations, one new capability, six new admin
screens, and the first production caller `App\Support\Calendar::flush()` and
`App\Support\PeriodGenerator` have ever had. No people, no rota grid, no clinics, no wizard —
those are P1c, P1d and P1e.

**What P1b is NOT.** It does not touch `people`, `person_levels` or any promotion workflow
(P1c owns LV-02…04 and the `promotion_batch_id`/`reason`/`created_by` columns finding 9 of the
P1 plan requires *before* the first promotion). It seeds the ladder and gives it CRUD; it does
not move anybody up it. It does not add `MissedDays` day-type awareness (owner decision 6). It
adds no unauthenticated route (D7).

---

## Owner decisions carried in from P1

All six of the P1 plan's owner decisions remain binding. Three of them land as code here:

- **Decision 1 — the ladder is `R1, R2, R3, R4, EXT`, seeded and then editable.** Task 6 adds
  the `external` and `terminal` columns LV-01 needs; Task 7 seeds the five levels with explicit
  `display_order` values, `EXT` flagged external and ordered last.
- **Decision 2 — both period systems ship, configurable per department.** Task 10's settings
  screen is where a department chooses, and Task 11 is where the choice becomes `periods` rows.
- **Decision 3 — `hijri_offset_days` is per-department configuration, timezone is per-instance.**
  Task 10 is the first screen that can change the offset. It must not grow a timezone field
  "for symmetry" (finding 5 of the P1 plan; CLAUDE.md).
- **Decision 4 — the academic year resets to a fixed start date; block 13 absorbs the
  remainder.** Task 11 passes the *next* year's start into `PeriodGenerator::weekBlocks()`
  rather than trusting the last entry of `block_weeks`.

---

## Findings

Read these before any task. Each was verified against the tree at `9598aa5` by running or
grepping, not inferred from a document.

1. **`Calendar::flush()` has no production caller, and P1b is the screen that needs one.**
   `grep -rn "Calendar::flush"` returns thirteen hits, **every one of them a test**
   (`tests/TestCase.php:28,44,51`, `tests/Unit/CalendarTest.php`,
   `tests/Feature/Calendar/HolidayTest.php`, `tests/Feature/Calendar/GoldenFixtureTest.php`).
   `Calendar::settings()` and `Calendar::activeHolidays()` are memoised in `static` properties
   for the life of the process (`Calendar.php:51-54`). Harmless while nothing edits calendar
   configuration at runtime — but the moment Task 10 saves a Hijri offset or Task 12 saves a
   holiday, **the redirect response that follows renders from the pre-save memo**, so the admin
   presses Save, the row changes, and the page shows the old value. Under `php artisan serve`
   or a long-lived FrankenPHP worker the stale value can outlive the request entirely. Every
   write path touching `institutions`' calendar columns or `holidays` calls `Calendar::flush()`
   inside the same transaction commit path, and
   `tests/Feature/Build/CalendarWritersFlushTest.php` (Task 10) asserts it at source level so a
   seventh writer added later cannot forget.

2. **`Institution::HIJRI_OFFSET_BOUNDS` is enforced in exactly one place, and it is not a
   request.** `grep -rn "HIJRI_OFFSET_BOUNDS"` returns two hits: the constant
   (`Institution.php:15`) and `ReferenceSeeder.php:134`. Nothing validates the column on write.
   P1b adds the edit surface, so P1b must enforce `[-2, 2]` server-side in the FormRequest —
   an out-of-range offset silently shifts every Hijri date **and every Hijri-ruled holiday** in
   the system, because `Holiday::anchoredOn()` resolves through `Calendar::hijri()` by
   construction (`Holiday.php:69-77`).

3. **`PeriodGenerator::months()` mislabels when the academic year does not start on the 1st —
   confirmed by running it.** Executed against the live class:

   ```
   1 | January 2026  | 2026-01-31 .. 2026-02-27
   2 | February 2026 | 2026-02-28 .. 2026-03-27
   3 | March 2026    | 2026-03-28 .. 2026-04-27
   ```

   Twenty-seven of "January 2026"'s twenty-eight days are in February. A start of `2026-07-15`
   produces `July 2026 = 2026-07-15 .. 2026-08-14` — a rolling 30-day window wearing a calendar
   month's name. Nothing validates the start date today. **Decision C** below resolves it.

4. **`PeriodGenerator` has ZERO production callers — the `periods` table can never be
   populated by the application.** `grep -rn "PeriodGenerator::" app database routes` returns
   only *docblock mentions* (`Period.php:22`, `Calendar.php:291`, the periods migration's
   comment). The only executing callers are `PeriodGenerationTest` and `GoldenFixtureTest`.
   P1a built the arithmetic and the table; **nothing writes a row**. The P1 plan's P1b task
   list (item 8) asks only for "the period-run preview and its gap/overlap warning" — a preview
   with no commit leaves P1d with an empty `periods` table and no grid columns. **Task 11
   therefore ships generate-*and*-commit, which is scope the P1 plan's one-line item did not
   name.**

5. **`levels` has no `external` column.** LV-01 specifies "(name, code, order, external flag)"
   and design §6.1 (as corrected by P1a Task 9) states "code/name/order/`external` all
   administrator-owned data after the seed". `2026_08_10_120002_create_levels_and_person_levels.php`
   creates `levels` with `institution_id, code, name, display_order, active` and nothing else.
   `Level::$fillable` (`Level.php:28-34`) matches. The flag has to be added before `EXT` can be
   seeded as anything other than a level whose meaning lives in its name.

6. **`AppLayout.vue` hardcodes the four units and `app.css` hardcodes four hues — and P1b is
   the plan that makes that reachable.** `AppLayout.vue:62-67` is a literal array of
   `{code, label, bar}`; `resources/css/app.css:71-74` defines `--color-unit-picu/nicu/scbu/ward`
   and `:118-121` the four matching `.channel-bar-*` rules. CLAUDE.md already names both as
   "two known exceptions, pending: a fifth department gets no nav entry or hue until those move
   to configuration." Until now nothing could create a fifth department. **Task 3 must land the
   nav fix before Task 4 lands unit creation**, or the first unit an administrator creates is
   invisible in the sidebar and colourless — a defect shipped by this plan, not inherited.

7. **The capability catalog in `docs/spec/08-foundation.md` is currently CORRECT.** Line 36
   lists all nine live keys including `settings.manage` and `users.manage_residents`; line 38
   lists the role defaults. The staleness the P1 plan warns about was fixed. P1b must keep it
   that way: `structure.manage` is added to **both** lines in the same commit that seeds it
   (Task 2), and `AccessControlSeederRespectsRevocationsTest` /
   `AccessControlParityTest` are the tests that will notice if the catalog and the seeder drift.

8. **`Unit::booted()` already documents the exact thing point 5 of the brief asks for.**
   `Unit.php:93-95`: *"There is no unit-creation UI yet (P1), so this model guard is the whole
   enforcement today. When one lands, it must surface this as a validation message
   (`Rule::notIn(self::RESERVED_CODES)`) rather than let this exception reach the user raw."*
   The guard throws `InvalidArgumentException` from a `saving` listener — an uncaught 500. Task
   4's FormRequest carries `Rule::notIn(Unit::RESERVED_CODES)` **and** the model guard stays as
   defence in depth; the test asserts a 422 with a named field, not a 500.

9. **`units.code` is UNIQUE and institution-blind** (`2026_07_24_120001_create_reference_tables.php:43`,
   `$table->string('code')->unique()`), consistent with D11. A create form validates
   `Rule::unique('units', 'code')` against the **normalised** code, because `Unit`'s `code`
   mutator uppercases and trims on write (`Unit.php:71-74`) but does *not* touch a query's WHERE
   value — the same trap `Unit::findByCode()` exists for. Validating the raw input would let
   `picu` pass uniqueness and then collide on insert.

10. **Three existing tests assert exactly four units render.**
    `tests/Feature/Endorsement/MissedDaysTest.php:138` and
    `tests/Feature/Endorsement/UnitScopeTest.php:174` both assert `->has('units', 4)`;
    `UnitScopeTest.php:137` and `ReferenceSeederTest.php:55` create an ad-hoc `XX` unit
    specifically to prove an inactive unit is unreachable. Every migration in this plan is
    additive **and defaulted**, so none of them moves those counts. Any task that makes one go
    red has changed behaviour, not schema.

11. **There is no `UnitFactory` and no `HandoverFactory`.** `database/factories/` holds exactly
    `HolidayFactory`, `LevelFactory`, `PeriodFactory`, `PersonFactory`, `UserFactory`. Units are
    built with `Unit::create([...])` and handovers with
    `Handover::create(['unit_id' => …, 'handover_date' => …, 'mrn' => …])`
    (`MissedDaysTest.php:39` is the canonical shape). Keep it that way — a `UnitFactory` would
    need `RESERVED_CODES`-aware code generation and buys nothing this plan uses, and
    `Handover::factory()` does not exist and will fatal.

12. **`TextContrastMeetsAaTest` covers text tokens only** — `--color-muted` on three surfaces,
    and the `ok`/`caution` foreground-on-soft-tint pairs (`TextContrastMeetsAaTest.php:23-58`).
    The unit hues are `border-left-color` values and are outside its token set, so Task 3's new
    palette entries do not need to clear 4.5:1 as text. They must still clear
    `CompiledCssIsLightOnlyTest` (no `dark:`), and `npm run build` must run **before**
    `php artisan test` or that guard and the print-CSS check skip rather than pass (P1 finding
    15).

13. **`SettingsController` is the write-side precedent and it is a good one.** Validate, apply,
    audit **by key name only** (`SettingsController.php:86-91`: `'keys='.implode(',', $changed)`),
    then make the change live for the current process (`AppSettings::applyOverrides()`).
    P1b's calendar and holiday writes follow the same three beats, with
    `Calendar::flush()` in the place `applyOverrides()` occupies. `AccessControlController` is
    the precedent for the *set-wise* shape: validate the whole submission, run every guard
    across the whole set before any write, one transaction, delta computed inside it
    (`AccessControlController.php:108-209`).

14. **`Period::booted()`'s overlap guard throws a `RuntimeException`, not a
    `ValidationException`** (`Period.php:80-86`). Task 11 commits a whole year's periods inside
    one transaction; an overlap against an adjacent year would surface as a 500. The commit path
    must pre-check with `PeriodGenerator::warningsAgainstNeighbours()` and convert a real clash
    into a 422 before any `Period::create()` runs — the model guard stays as the last line.

15. **`academic_year` is a free-text string** (`periods.academic_year`, `string(20)`; the
    factory uses `'2026-2027'`, one test uses `'Testing sandbox'`). It is the overlap-scope key
    and the unique-index partner (`periods_year_position_unique` on `(academic_year, position)`).
    Task 11 derives it deterministically from the start date (`2026-2027` from `2026-07-01`) and
    validates the format, or two spellings of one year become two non-overlapping year-sets that
    both claim the same days.

---

## Where the design doc, the P1 plan and the Munawib spec are wrong about this codebase

Every plan in this project so far has found at least one. These are P1b's.

| Claim | Reality |
|---|---|
| P1 plan, P1b task 1: units gain *"an explicit `color` distinct from `bar_class`"* | **Rejected — see [Decision B](#decision-b-the-palette-is-bar_class-widened-not-a-second-colour-column).** `bar_class` already *is* the unit's colour; a second column would be two definitions of one fact, the failure `AuditChain::canonical()` and `Person::levelAt()` both carry docblocks about. P1b widens the palette and constrains `bar_class` to it. |
| P1 plan, P1b task 8: *"academic year start with the period-run **preview** and its gap/overlap warning"* | A preview alone leaves `periods` permanently empty (finding 4). P1b ships preview **and commit**. |
| Design §6.1: *"Units also gain Munawib UN-02's three independent capability flags … and UN-03 import aliases"* — corrected by P1a Task 9 to say P1b | Correct as amended. P1b builds them (Task 1). The section still needs its "not shipped" wording flipped once they are — Task 13. |
| Munawib UN-03: aliases *"normalize imports (typo tolerance)"* | There is no import to normalise yet — ST-04's roster import is P1c task 13 and the rota import is P1d. P1b ships the **column, the cast and the matcher** (`Unit::findByCodeOrAlias()`), with no consumer, and says so rather than pretending typo tolerance is live. |
| Munawib UN-05: *"Optional secondary display name stored for future translations; unused at launch"* | The spec itself says unused. P1b stores `name2` and renders it **nowhere**. Do not wire it into `UnitProfile::toArray()` — an unused prop on the client contract is a future consumer's trap. |
| Munawib LV-01: *"seed PGY-1…PGY-4 + External"* | Overridden by owner decision 1: the department says `R1…R4` and `EXT`. The names are cosmetic and editable, which is exactly what LV-01 also says. |
| Munawib ST-01/ST-02 place *timezone* in per-department settings | Overridden by owner decision 3 and P1 finding 5: timezone is `APP_TIMEZONE`, per instance. The calendar settings screen has no timezone field and must display the instance timezone read-only, with a line saying it is set at deployment. |
| Munawib UN-01: admins *"merge units"* | Supported, but `handover_signoffs` carries `UNIQUE(unit_id, handover_date)` (`2026_07_24_130002:77`). Two units merged across a day both signed is a **collision the merge must resolve explicitly**, not discover at insert time. Task 5. |

---

## Decision A: one new capability, `structure.manage`

Units, levels, the calendar, periods and holidays are one thing — the department's *shape* —
and they are edited by the same person in the same sitting. `settings.manage` stays what its
own catalog entry says it is: *"Edit runtime settings (mail server, push keys, reminder
times)"* — infrastructure. Mistyping an SMTP host bounces a mail; mistyping `hijri_offset_days`
silently redates every Hijri label and every Hijri-ruled holiday in the system. Different blast
radius, different key.

`structure.manage` defaults to **Administrator only**, grantable per role or per named user
like every other key. It is added to `AccessControlSeeder::CATALOG`, `::DESCRIPTIONS` and
`::ROLE_DEFAULTS[0]`, to `docs/spec/08-foundation.md`'s catalog **and** role-defaults lines, and
to `AppLayout.vue`'s `canAdmin` computed — all in Task 2's single commit. Omitting the last of
those is the recon frontend risk the P1 plan names: a user holding only the new capability would
see no Administration section at all.

## Decision B: the palette is `bar_class` widened, not a second colour column

The P1 plan's P1b item 1 asks for "an explicit `color` distinct from `bar_class`". Don't build
it. `bar_class` is a string column already holding `channel-bar-picu` … `channel-bar-ward`, it
already drives the sidebar edge and the chooser card, and it already travels to the client via
`UnitProfile::toArray()`. A `color` column beside it would need a rule for which one wins.

Instead:

- `resources/css/app.css` gains **four more** `--color-unit-*` tokens and their
  `.channel-bar-*` rules, taking the palette to eight. The existing four are untouched, so no
  stored value migrates and no rendered pixel moves.
- `Unit::BAR_CLASSES` becomes the allow-list — an ordered map of class ⇒ human label — and the
  units screen offers it as a `<select>` with a swatch. `Rule::in(array_keys(Unit::BAR_CLASSES))`
  is the write-side gate, so the same list offers and validates (the 2026-07-26
  `SignoffPickers` discipline, applied to a much smaller thing).
- No hex reaches markup. The swatch is a `<span>` carrying the `channel-bar-*` class itself.

This satisfies UN-01's "color" with one column, one allow-list and zero data migration.

## Decision C: the academic-year start is VALIDATED, `months()` is not relabelled

Finding 3's mislabelling is real. There are two ways out and only one of them is honest.

**Relabelling** — rendering `2026-01-31 .. 2026-02-27` as "Jan–Feb 2026" — would mean MR-01's
"months" period system produces periods that are not calendar months. That is a *third* period
system nobody asked for, it breaks the department's own vocabulary (staff say "August", not
"15 Aug – 14 Sep"), and it silently changes what P1a's `PeriodGenerationTest` fixtures and
`golden.json`'s `months` run mean — a file CLAUDE.md calls a contract with P2, not a
convenience.

**Validating** is the fix. A calendar-month period system requires the academic year to begin on
the **first of a month**. Week-blocks are unaffected: a block is measured in weeks from an
arbitrary date, so any start is legitimate there, and `2026-07-01` (QCH's) happens to satisfy
both.

Enforced in **two places from one definition**, because a rule written once as a validation
string and once as a generator guard is two rules that drift:

- `PeriodGenerator::assertMonthAligned(CarbonImmutable $start): void` — public, throws
  `InvalidArgumentException`. `months()` calls it first, so a seeder, a console command or a
  P1d caller cannot produce a mislabelled run either.
- The calendar-settings FormRequest calls the same method inside a `Rule` closure and converts
  the throw into a field-level 422 on `academic_year_start`.

Every existing `months()` fixture starts on the 1st (`2026-07-01`, `2027-07-01` in
`PeriodGenerationTest`; `2026-07-01` and `2027-07-01` in `golden.json`), verified before
writing this, so the guard lands green.

## Decision D: `period_type` and `academic_year_start` hard-lock once periods exist

P1 finding 6 records the general shape: a configurable calendar makes an unrecoverable change
reachable from a UI. The day boundary is safe (finding 5 keeps the timezone out of the
database), but two of Task 10's fields have the same character:

- changing `period_type` after periods are generated relabels nothing (each `periods` row stores
  its own `kind`, deliberately — `Period.php:15-18`) but leaves the department with a year of
  blocks and a settings page claiming months;
- changing `academic_year_start` after periods exist orphans every generated period against a
  year that no longer starts where they do.

So: both fields are **read-only in the form once any `periods` row exists**, server-side as well
as visually, with the refusal naming the unlock path (delete that year's periods first, Task 11).
Weekend days, the Hijri toggle and the Hijri offset stay editable — they are display and
day-type facts, not period identity, and a department that discovers its offset is wrong on day
three must be able to fix it.

---

## Is P1b too large for one plan?

It is at the top of the range and it is executable. Thirteen tasks, two additive migrations, one
capability, six screens. For calibration: P0c was 150k of plan text and P0d 102k; P1a was nine
tasks with no UI at all.

**It is not split, but it has a declared seam.** Tasks 1–9 (structure data, units, levels) and
Tasks 10–13 (the ST-02 settings surfaces) are independent: the tree is deployable and the suite
green at the end of Task 9, **P1c depends only on Tasks 1–9**, and nothing in Tasks 10–13 is a
prerequisite for the People screen or the level ladder P1c reads. If execution stalls, merge
after Task 9 and pick Tasks 10–13 up as their own branch. Do **not** split anywhere else — in
particular Tasks 2/3/4 must land in that order and none of them is independently useful.

---

## Migration ordering

P1a used `2026_08_12_*`. P1b uses `2026_08_13_*` so it sorts strictly after:

```
2026_08_13_120001_add_munawib_configuration_to_units    (Task 1 — additive, defaulted)
2026_08_13_120002_add_external_and_terminal_to_levels   (Task 8 — additive, defaulted)
```

Both are additive with column-level defaults, on tables holding reference data. Nothing is
retyped, nothing is dropped, no clinical table is touched. The owner runs production migrations
(CLAUDE.md); Task 13 supplies the verification queries for `docs/RUNBOOK-DEPLOY.md`.

Later sub-plans continue: P1c `2026_08_14_*`, P1d `2026_08_15_*`, P1e `2026_08_16_*`.

---

## Amendments made during execution

*(Empty at plan time. Follow the P0c/P0d/P1a convention: when a task turns up something this
plan's enumeration missed — a site not listed, a test that goes red for a reason the plan did
not predict, a behaviour that differs between SQLite and MySQL — record it here, dated, with
what was found and how it was resolved. Findings caught empirically rather than by inspection
are the ones worth writing down. P1a recorded nine such amendments across nine tasks; assume
this plan is wrong somewhere too.)*

**2026-08-09, Task 1 — the task's own Step 1 test text contradicts the plan's binding OWNER
DECISIONS block, and the owner decision wins.** `test_the_four_seeded_units_are_rotations_and_
call_targets` (Task 1's Step 1 draft) asserted `assertFalse($unit->clinic_owner, $code)` for
all four seeded units, including WARD, with the comment "No clinics exist until P1e; claiming
otherwise would be a clinical guess." That reasoning predates Owner Decision B, added to the
plan's own OWNER DECISIONS block the same day: *"WARD is the only clinic owner. Seed
`clinic_owner = true` on WARD alone; PICU, NICU and SCBU stay false. Affects nothing before
P1e, but settles CL-01's first screen."* The task text was written (or left unedited) before
that decision was folded in. Resolved in favour of the binding decision: `ReferenceSeeder`
seeds `WARD` with `clinic_owner => true` and the other three with `false`; the draft assertion
was split into `test_the_four_seeded_units_are_rotations_and_call_targets` (rotation/call-target
only now) plus a new `test_ward_alone_is_seeded_as_a_clinic_owner` pinning the owner-decision
shape. `php artisan test`: 746 → 759 (13 new, one more than the plan's stated 758 — the split
test adds one case). `npm run build` and the full suite green.

**2026-08-09, Task 2 — `AccessControlParityTest`'s hardcoded Administrator capability list is
not mentioned anywhere in the plan's Task 2 text, and went red the moment `structure.manage`
was seeded.** `test_each_role_effective_set_matches_the_documented_server_gates` and
`test_seeder_is_idempotent` both build their expected position-0 set from a private
`expectedByPosition()` array that enumerates every Administrator-default key by name (finding
7's own "drift" tests, but for the *effective set*, not the catalog/seeder pair finding 7
describes). Adding `structure.manage` to `AccessControlSeeder::ROLE_DEFAULTS[0]` is exactly the
intended change, so this is the expected/legitimate kind of red, not drift — added
`'structure.manage'` to the `$adminOnly` array in `expectedByPosition()`, with a comment. No
other hardcoded capability list in the file needed touching
(`test_a_chief_resident_holds_the_scoped_power_but_no_admin_console`,
`test_only_admin_has_users_and_access_manage`, etc. assert against individual keys, not the
full set). `php artisan test`: 759 → 766 (7 new, matching the plan's stated count exactly this
time — Task 1's own +1 offset carries forward unchanged). `npm test`: 110 (109 + 1,
`AppLayout.test.js`'s new structure.manage-alone case). `npm run build` and the full suite
green.

**2026-08-09, Task 3 — the plan's own CSS snippet for Step 3 breaks the guard test it is
paired with, by double-spacing two of the four alignment columns.** The plan's Step 3 text
lines up `.channel-bar-amber`/`.channel-bar-moss`/`.channel-bar-clay`/`.channel-bar-slate` with
extra spaces before `{` so the `border-left-color:` values visually align — but
`test_the_palette_and_the_stylesheet_agree`'s regex (`/\.(channel-bar-[a-z0-9]+) \{/`) matches
exactly ONE space before the brace. `.channel-bar-moss  {` and `.channel-bar-clay  {` (two
spaces, copied verbatim from the plan) silently fell out of the matched set — proved by running
the extraction regex directly against the file with `php -r`, which returned only 9 of the 11
expected classes (picu/nicu/scbu/ward/amber/slate/ok/critical/caution — moss and clay missing).
Not a chosen exception: the test's own docblock says every declared class must round-trip, and
a silently-missing declaration is exactly the drift class of bug this guard exists to catch.
Fixed by writing all four new rules with a single space before `{`, matching the test's
contract rather than the plan's cosmetic alignment; the `@theme` token declarations above them
(which the regex does not scan) were left double-space-aligned since nothing depends on their
formatting. `php artisan test`: 766 → 772 (6 new, matching the plan's stated count). `npm test`:
111 (110 + 2 new AppLayout.test.js cases — one updated fixture for the four-unit render, one new
case for a fifth unit appearing with no frontend change — one more than the plan's implied
"fix the existing fixture" scope). `npm run build`, `CompiledCssIsLightOnlyTest` and
`TextContrastMeetsAaTest` green.

**2026-08-09, Task 4 — one test added beyond the plan's list, no schema or contract surprises.**
The FormRequest, controller writes and routes matched the plan's code verbatim. Added
`test_a_reserved_code_is_refused_with_an_http_422_not_a_500`, which repeats the reserved-code
case with an `X-Inertia`/JSON `Accept` header and asserts the response status is literally
**422** (`assertJsonValidationErrors`), rather than only the redirect-plus-session-errors shape
`assertSessionHasErrors` exercises. Finding 8 and `Unit::booted()`'s own docblock are both about
the wire-level status code (a 500 vs a validation response), and `assertSessionHasErrors` alone
would pass equally for a 302 *or* a 200 — it does not pin the status. The Task 4 plan text names
"a 422 with a named field" as the assertion goal; this test is what actually pins the 422.
`Units.vue`'s create form and per-row inline edit form both follow `Settings.vue`'s
`useForm`/`inputClass`/`recentlySuccessful` shape; aliases are a comma-separated text input
split/joined at the transform step, per the plan's Step 5 note. `php artisan test`: 772 → 785
(13 new, one more than the plan's stated 783 total — the extra 422 test, same pattern as Task
1's own +1). `npm test`: 111 (unchanged — Task 4's Files list names no JS test, and none was
needed). `npm run build` and the full suite green.

**2026-08-09, Task 5 — two real plan errors, both verified against the tree before writing
code, neither one a clinical-evidence risk.**

1. **`users.preferred_unit` is not a code string.** The plan's Task 5 text says twice that it
   is ("a code string, not an FK … `User::where('preferred_unit', $source->code)->update(...)`")
   and cites migration `2026_07_24_140001`. Reading that migration shows the column is actually
   `preferred_unit_id`, a real `foreignId('preferred_unit_id')->nullable()->constrained('units')
   ->nullOnDelete()` — added for Phase 7.1 "one-tap access", added to `$fillable` in `User.php`,
   and read at `EndorsementController.php:259,287-288`. `UnitMerge::commit()` therefore updates
   `preferred_unit_id` by **id** (`User::where('preferred_unit_id', $source->id)->update(['preferred_unit_id' => $target->id])`),
   which is simpler than the plan's described code-string rewrite, not harder — id-to-id is
   exactly what an explicit UPDATE on a real FK should look like. Still an explicit statement
   inside the transaction, still counted in the plan and the audit entry, exactly as the plan
   required; only the column name and type were wrong in the plan text.
2. **A second unique-index collision exists that the plan's own prose never names.**
   `unit_field_definitions` carries `UNIQUE(unit_id, key)` (`2026_08_09_120001:61`) — the same
   shape of hazard as `handover_signoffs`' `UNIQUE(unit_id, handover_date)`, on a table the
   plan's own findings list as one of the four `unit_id`-bearing tables a merge touches. Two
   units each defining a custom field under the same key would collide on re-point exactly like
   a signoff date does. No unit has ever defined a custom field (design §6.2's admin UI does not
   exist yet), so this cannot fire against real data today, but it is a live schema hazard for
   the moment it can. Unlike the signoff case there is no `keep_target`-style resolution
   available (nothing to "keep on the source" — a field definition is a shape, not evidence), so
   `UnitMerge::conflictingFieldDefinitionKeys()` refuses the merge outright, checked (both in the
   controller and again inside `commit()`'s transaction) BEFORE any write — finding 14's
   pre-check-and-refuse discipline, applied to a collision the plan didn't name.

Neither finding is clinical-evidence risk: the FK correction only changes *how* a housekeeping
column is rewritten, and the field-definition guard *prevents* a hazard from ever reaching a
write, exactly the "surfaced before insert" standard the plan set for the signoff collision.
Work proceeded rather than stopping, per the instruction that only a collision-handling design
that would LOSE or REWRITE clinical evidence should halt execution.

Design choices made where the plan intentionally left the wire contract to the implementer:
`UnitMerge::plan()`'s `signoffs` count is signoffs that will actually MOVE (total minus
collisions), matching `handovers`/`field_definitions`' "rows this merge changes" meaning: a
colliding date's row does not move, so it is not counted as moving, and the collision itself is
reported separately by date. The commit endpoint accepts an explicit `resolution` of
`keep_target` or `abort` (`UnitMerge::KEEP_TARGET`/`::ABORT`) plus `accepted_collisions` (the
exact date list the operator confirmed, re-checked against a FRESH `plan()` computed inside the
transaction — a signoff created between preview and submit cannot slip past a stale list).
`abort` is honoured unconditionally, before any other check, so it always means "make no
changes" regardless of what else is true about the pair. The merge screen (`UnitMerge.vue`)
requires one checkbox per colliding date, not a blanket acknowledgement, per the plan's Step 4
text.

`php artisan test`: 785 → 798 (13 new). `npm test`: 111 (unchanged — Task 5's Files list names
no JS test). `npm run build`, `CompiledCssIsLightOnlyTest` and the client-side-date-math guards
green.

**2026-08-09, Task 6 — built narrower than the plan's own Task 6 text, per OWNER DECISION A at
the top of this plan.** The plan's Task 6 text (written before Decision A was folded in) asks
for a `terminal` column, `Level::nextAfter()` as "the one definition of advance one level", and
a `LevelLadderTest` that pins both. Decision A rejects the inference outright: a wrong
terminal marker fails silently in two directions — an unmarked top level lets a cohort advance
into a level that does not exist, and a wrongly-marked middle level graduates a cohort a year
early — and removing the inference removes the whole failure class. Built instead: `levels`
gains `external` only (migration renamed from the plan's
`2026_08_13_120002_add_external_and_terminal_to_levels` to
`2026_08_13_120002_add_external_to_levels`, same slot in the sequence — `2026_08_13_120001` was
already taken by Task 1), `Level::scopeInternal()` (the levels a P1c promotion picker offers),
and no `nextAfter()` method at all. `LevelLadderTest` replaces the plan's tie-break/terminal
cases with `test_there_is_no_terminal_column_and_no_next_after_inference`, a guard that pins
Decision A itself so it cannot silently regress if a later plan reaches for the same inference.
`php artisan test`: 798 → 803 (5 new). `npm test`: unchanged (Task 6 touches no JS). `npm run
build` and the full suite green.

**2026-08-09, Task 7 — no `terminal` assertion, per the same Owner Decision A.** The plan's own
Step 1 text (written before Decision A landed) asks for `R4` seeded `terminal = true` and
`nextAfter(R4)` asserted null. Neither is built: `R1…R4` are seeded `external => false` only,
with no `terminal` key at all (the column does not exist — Task 6), and `EXT` is seeded
`external => true`. `display_order` is 10/20/30/40/90 exactly as the plan specifies — that part
of the plan's reasoning (gaps of ten so an `R5` or `R2.5` can be inserted without renumbering)
is unaffected by Decision A and was kept verbatim. `php artisan test`: 803 → 807 (4 new). `npm
test`: unchanged. `npm run build` and the full suite green.

**2026-08-09, Task 8 — narrower than the plan's own text in two respects, both traced to Owner
Decision A.** First, the screen offers no `terminal` toggle (the column does not exist — Task
6). Second, and not explicitly named by Decision A but a direct consequence of it: the plan's
own bullet list asks for "the last active non-terminal, non-external level cannot be
deactivated," justified in the plan's own words as protection against "`Level::nextAfter()`
returning null for everyone." Neither `terminal` nor `nextAfter()` exists, so that justification
is void, and the guard was not built. This was a judgement call rather than something Decision A
states outright, made on two grounds: (1) `UnitCrudTest` has no equivalent "last active unit"
guard, so omitting it here keeps Level's CRUD consistent with Unit's own precedent rather than
inventing a rule Units doesn't have to follow; (2) Decision A's stated intent is "the operator
chooses the target level explicitly" — a floor that blocks deactivating the last internal level
would be exactly the kind of system-side inference the decision rejects, applied one level up
the stack. If a future plan (P1c) finds it needs a non-empty-picker guard, that is P1c's call to
make with full knowledge of what the promotion screen actually requires, not a guess made here
against a promotion feature that does not exist yet. Everything else matches the plan's Steps
2-5 exactly: FormRequest → controller (`index`/`store`/`update`/`setActive`, no `destroy`) →
routes in the `admin/structure` group → `Levels.vue` mirroring `Units.vue`'s
cards-plus-table layout → a `Levels` nav link beside `Units` behind `structure.manage` →
`tests/js/AppLayout.test.js`'s existing structure.manage-alone case extended to assert both
links (not a new case — the plan's phrase "beside Units" read most naturally as one enlarged
assertion rather than a duplicate test with the same setup). `php artisan test`: 807 → 819 (12
new, matching the plan's own list of cases with `terminal`/`nextAfter` cases removed and
`test_an_out_of_range_display_order_is_refused` / `test_there_is_no_delete_endpoint` /
`test_a_retired_level_can_be_brought_back` added — mirroring `UnitCrudTest`'s coverage
one-for-one). `npm test`: 111 (unchanged — one existing case widened, not a new one added).
`npm run build`, `CompiledCssIsLightOnlyTest` and the full suite green.

---

## Conventions every task follows

Stated once, not repeated per task.

**Verification runs under Bash, never PowerShell.** PowerShell's PATH on this machine lacks
`openssl`, so the backup tests self-skip there and the suite reports green without exercising
them — a false green indistinguishable from a real one. Every command block in this plan opens
with:

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
```

**`npm run build` runs before `php artisan test`**, or `CompiledCssIsLightOnlyTest`'s artifact
layer and the print-CSS check skip rather than pass.

**Frontend.** Every page is `AppLayout` + `useCan()`, `useForm()` for writes, `preserveScroll`
(and `preserveState` where an indicator must survive), `form.errors.*` rendered as
`text-critical`, `form.recentlySuccessful` as the "Saved." affordance. Semantic classes only:
`.readout`, `.channel-tag`, `.channel-bar*`, `bg-panel`, `bg-ground`, `bg-ground-deep`,
`border-line`, `text-ink`, `text-body`, `text-muted`, `text-critical`, `text-ok`. **No `dark:`
utility, no raw Tailwind palette class, no hex in markup.** Reuse `Settings.vue`'s `inputClass`
string verbatim. Note `bg-panel-soft` compiles to nothing (P1 finding 13) — never use it; use
`bg-ground-deep` for table headers and inset surfaces.

**Dates.** Any date a screen shows is formatted server-side through `Calendar::label()` or
`Calendar::ymd()` and arrives as an Inertia prop. `resources/js` performs no date arithmetic —
`CalendarIsTheOnlyConverterTest` fails the build otherwise, and its JS needle list already
covers `new Date(`, `toISOString(`, `toLocaleDateString(`, `Date.parse(`, `Intl.DateTimeFormat`
and six more.

**Audit.** Every write in this plan calls `AuditLog::record($action, $detail, $userId, $ip)`
with `$detail` naming **keys and ids only** — never a value, never a name. Configuration is not
PHI, but the trail's job is "what changed", and `SettingsController.php:86-91` is the shape.

**Routes.** Every route in this plan sits behind `['auth', 'throttle:clinical',
'cap:structure.manage']`. Writes are POST/PATCH/DELETE + CSRF.

---

# P1b — tasks

---

### Task 1: Units gain UN-02's flags, UN-03's aliases and UN-05's secondary name

**Files:**
- Create: `database/migrations/2026_08_13_120001_add_munawib_configuration_to_units.php`
- Create: `app/Casts/UnitAliases.php`
- Modify: `app/Models/Unit.php`
- Modify: `database/seeders/ReferenceSeeder.php`
- Test: `tests/Feature/Units/UnitCapabilityFlagsTest.php`

Design §6.1 claimed these shipped with P0a. They did not — P0a added nine *presentation*
columns and nothing else (P1a Task 9 corrected the section). This task is the schema half; the
screen that edits it is Task 4.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Units/UnitCapabilityFlagsTest.php`:

```php
<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib UN-02 (three independent capability flags), UN-03 (import aliases) and UN-05 (an
 * optional secondary display name). Design §6.1 claimed P0a shipped these; it did not.
 *
 * The three flags are INDEPENDENT on purpose: a subspecialty clinic that owns clinics but is
 * not a rotation and is not an on-call target is a real shape, and any two-of-three
 * combination must be storable without the third.
 */
class UnitCapabilityFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_unit_defaults_every_capability_flag_off(): void
    {
        $unit = Unit::create(['code' => 'RGH1', 'name' => 'Riyadh General Ward 1']);

        $this->assertFalse($unit->fresh()->training_rotation);
        $this->assertFalse($unit->fresh()->call_target);
        $this->assertFalse($unit->fresh()->clinic_owner);
    }

    /**
     * The same reasoning P0a applied to `active` (amendment 2): a half-configured department
     * must be INERT, not live. A flag that defaulted true would enrol a freshly created unit
     * into the training rotation and the call roster before anyone confirmed it belongs there.
     */
    public function test_the_three_flags_are_independent(): void
    {
        $unit = Unit::create([
            'code' => 'CLIN',
            'name' => 'Subspecialty Clinics',
            'training_rotation' => false,
            'call_target' => false,
            'clinic_owner' => true,
        ]);

        $fresh = $unit->fresh();
        $this->assertFalse($fresh->training_rotation);
        $this->assertFalse($fresh->call_target);
        $this->assertTrue($fresh->clinic_owner);
    }

    public function test_the_flags_cast_to_booleans_not_strings(): void
    {
        $unit = Unit::create([
            'code' => 'RGH2', 'name' => 'Ward 2', 'training_rotation' => 1, 'call_target' => 0,
        ]);

        $fresh = $unit->fresh();
        $this->assertIsBool($fresh->training_rotation);
        $this->assertIsBool($fresh->call_target);
        $this->assertIsBool($fresh->clinic_owner);
    }

    public function test_aliases_round_trip_as_a_list_preserving_source_spelling(): void
    {
        $unit = Unit::create([
            'code' => 'PICU2',
            'name' => 'Second PICU',
            // UN-03: source data is PRESERVED. "Paeds ICU" comes back exactly as typed.
            'aliases' => ['Paeds ICU', ' picu-2 ', 'PICU 2'],
        ]);

        $this->assertSame(['Paeds ICU', 'picu-2', 'PICU 2'], $unit->fresh()->aliases);
    }

    public function test_aliases_default_to_an_empty_list_never_null(): void
    {
        $unit = Unit::create(['code' => 'RGH3', 'name' => 'Ward 3']);

        $this->assertSame([], $unit->fresh()->aliases);
    }

    public function test_aliases_drop_blanks_and_duplicates_and_non_strings(): void
    {
        $unit = Unit::create([
            'code' => 'RGH4',
            'name' => 'Ward 4',
            'aliases' => ['Ward Four', '', '   ', 'Ward Four', 42, null, 'ward four'],
        ]);

        // De-duplication is CASE-INSENSITIVE (that is the whole point of typo tolerance), and
        // the FIRST spelling wins, because that is the one the administrator typed on purpose.
        $this->assertSame(['Ward Four'], $unit->fresh()->aliases);
    }

    public function test_a_unit_resolves_by_alias_case_and_whitespace_insensitively(): void
    {
        $unit = Unit::create(['code' => 'RGH5', 'name' => 'Ward 5', 'aliases' => ['Ward Five']]);

        $this->assertSame($unit->id, Unit::findByCodeOrAlias('  ward five ')?->id);
        $this->assertSame($unit->id, Unit::findByCodeOrAlias('RGH5')?->id);
        $this->assertSame($unit->id, Unit::findByCodeOrAlias('rgh5')?->id);
        $this->assertNull(Unit::findByCodeOrAlias('Ward Six'));
    }

    /** Code wins over another unit's alias — an exact identity beats a typo-tolerance hint. */
    public function test_code_takes_precedence_over_another_units_alias(): void
    {
        $byCode = Unit::create(['code' => 'RGH6', 'name' => 'Ward 6']);
        Unit::create(['code' => 'RGH7', 'name' => 'Ward 7', 'aliases' => ['RGH6']]);

        $this->assertSame($byCode->id, Unit::findByCodeOrAlias('RGH6')?->id);
    }

    /** UN-05: stored, and rendered NOWHERE at launch. */
    public function test_name2_is_optional_and_stored_verbatim(): void
    {
        $unit = Unit::create(['code' => 'RGH8', 'name' => 'Ward 8', 'name2' => 'العنبر ٨']);

        $this->assertSame('العنبر ٨', $unit->fresh()->name2);
        $this->assertNull(Unit::create(['code' => 'RGH9', 'name' => 'Ward 9'])->fresh()->name2);
    }

    /**
     * UN-05 is "stored for future translations; unused at launch". Leaking it into the client
     * contract now would give a future consumer a prop with no rendering rules.
     */
    public function test_name2_does_not_reach_the_client_contract(): void
    {
        $unit = Unit::create(['code' => 'RGHA', 'name' => 'Ward A', 'name2' => 'Secondary']);

        $this->assertArrayNotHasKey('name2', $unit->profile()->toArray());
    }

    public function test_the_four_seeded_units_are_rotations_and_call_targets(): void
    {
        $this->seed(ReferenceSeeder::class);

        foreach (['PICU', 'NICU', 'SCBU', 'WARD'] as $code) {
            $unit = Unit::findByCode($code);

            $this->assertTrue($unit->training_rotation, $code);
            $this->assertTrue($unit->call_target, $code);
            // No clinics exist until P1e; claiming otherwise would be a clinical guess.
            $this->assertFalse($unit->clinic_owner, $code);
            $this->assertSame([], $unit->aliases, $code);
        }
    }

    /** A re-seed refreshes `name` only — an administrator's flags are theirs (P0a precedent). */
    public function test_reseeding_preserves_administrator_flag_changes(): void
    {
        $this->seed(ReferenceSeeder::class);
        Unit::findByCode('WARD')->update(['call_target' => false, 'clinic_owner' => true]);

        $this->seed(ReferenceSeeder::class);

        $ward = Unit::findByCode('WARD');
        $this->assertFalse($ward->call_target);
        $this->assertTrue($ward->clinic_owner);
    }
}
```

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter UnitCapabilityFlagsTest 2>&1 | tail -15
```

Expected: FAIL — `SQLSTATE[HY000]: General error: 1 table units has no column named training_rotation`.

- [x] **Step 3: The migration**

Create `database/migrations/2026_08_13_120001_add_munawib_configuration_to_units.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib UN-02 (three independent capability flags), UN-03 (import aliases), UN-05 (optional
 * secondary display name). Design §6.1 asserted P0a shipped these; P0a's
 * 2026_08_08_120001_add_configuration_to_units.php added nine PRESENTATION columns and nothing
 * else. P1a Task 9 corrected the section; this migration is what makes it true.
 *
 * Additive and defaulted, per the project rule. Per-column Schema::hasColumn guards follow
 * P0a's own hardening (its amendment 7): the Blueprint emits one ALTER TABLE per column, so
 * guarding only the first leaves a partial failure unrecoverable.
 *
 * The three flags default FALSE, matching P0a's `active` decision (amendment 2): a
 * half-configured department must be INERT. A flag defaulting true would enrol a freshly
 * created unit into the training rotation and the on-call roster before anyone confirmed it.
 *
 * `aliases` carries NO index. It is read by `Unit::findByCodeOrAlias()`, which loads the (very
 * small) unit set and matches in PHP — a JSON containment index would be MySQL-only and this
 * schema runs on SQLite under test. Units number in the tens, not the thousands.
 */
return new class extends Migration
{
    /** The four QCH units, backfilled so an existing production database needs no seeder run. */
    private const SEEDED_CODES = ['PICU', 'NICU', 'SCBU', 'WARD'];

    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // UN-02. Three INDEPENDENT flags — never collapsed into one enum: a subspecialty
            // that owns clinics but is neither a rotation nor an on-call target is a real shape.
            if (! Schema::hasColumn('units', 'training_rotation')) {
                $table->boolean('training_rotation')->default(false)->after('active');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            if (! Schema::hasColumn('units', 'call_target')) {
                $table->boolean('call_target')->default(false)->after('training_rotation');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            if (! Schema::hasColumn('units', 'clinic_owner')) {
                $table->boolean('clinic_owner')->default(false)->after('call_target');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            // UN-03. Nullable rather than defaulted: MySQL cannot carry a literal DEFAULT on a
            // JSON column (the same constraint Institution's calendar JSON columns hit, see
            // App\Models\Institution's $attributes docblock). The App\Casts\UnitAliases cast
            // resolves null to [] on read, so no caller ever sees one.
            if (! Schema::hasColumn('units', 'aliases')) {
                $table->json('aliases')->nullable()->after('clinic_owner');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            // UN-05. Stored for future translations; the spec itself says "unused at launch",
            // and UnitProfile deliberately does not carry it to the client.
            if (! Schema::hasColumn('units', 'name2')) {
                $table->string('name2')->nullable()->after('name');
            }
        });

        // Backfill the four paediatric units so an EXISTING production database is correct
        // without waiting for `db:seed --force`. They are where residents rotate and where
        // on-call is counted; no clinics exist anywhere until P1e, so clinic_owner stays false.
        // DB::table, not Eloquent: a migration must not depend on a model's current shape.
        DB::table('units')
            ->whereIn('code', self::SEEDED_CODES)
            ->update(['training_rotation' => true, 'call_target' => true]);
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['training_rotation', 'call_target', 'clinic_owner', 'aliases', 'name2']);
        });
    }
};
```

- [x] **Step 4: The aliases cast**

Create `app/Casts/UnitAliases.php`:

```php
<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Munawib UN-03: alias names normalise imports (typo tolerance) **while preserving source
 * data**. Both halves of that sentence are load-bearing here.
 *
 *  - PRESERVED: the stored value is exactly what the administrator typed, minus surrounding
 *    whitespace. "Paeds ICU" comes back "Paeds ICU", not "PAEDS ICU" — the alias list is read
 *    by humans on the units screen and a normalised store would make it unreadable.
 *  - NORMALISED: matching is case- and whitespace-insensitive, done at COMPARE time by
 *    Unit::findByCodeOrAlias(), never by mangling the stored string.
 *
 * De-duplication is therefore case-insensitive with FIRST-SPELLING-WINS: "Ward Four" and
 * "ward four" are one alias, and the one kept is the one typed first, on purpose.
 *
 * Unlike App\Casts\ExtraRowFields there is no key allow-list, and there must not be one: an
 * alias is free text an administrator authors, not a field name that reaches a mass-assignment
 * boundary. Unlike App\Casts\EncryptedJson there is nothing secret here — aliases are
 * configuration, never PHI.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
class UnitAliases implements CastsAttributes
{
    /** A generous ceiling, so a paste accident cannot store a novel. */
    private const MAX_ALIASES = 50;

    private const MAX_LENGTH = 100;

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? self::normalize($decoded) : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $list = is_array($value) ? self::normalize($value) : [];

        return [$key => json_encode($list)];
    }

    /**
     * Case-folded key for MATCHING only. Collapses internal whitespace runs too, so
     * "Ward   Four" and "Ward Four" are the same alias.
     */
    public static function fold(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function normalize(array $values): array
    {
        $out = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_LENGTH) {
                continue;
            }

            $folded = self::fold($trimmed);

            if (isset($seen[$folded])) {
                continue;
            }

            $seen[$folded] = true;
            $out[] = $trimmed;

            if (count($out) >= self::MAX_ALIASES) {
                break;
            }
        }

        return $out;
    }
}
```

- [x] **Step 5: Teach the model its new shape**

In `app/Models/Unit.php`, add `use App\Casts\UnitAliases;` beside the existing
`use App\Casts\ExtraRowFields;`, then extend `$fillable` and `casts()`:

```php
    protected $fillable = [
        'code',
        'name',
        'name2',
        'display_order',
        'active',
        'training_rotation',
        'call_target',
        'clinic_owner',
        'aliases',
        'extra_row_fields',
        'bed_label',
        'consultant_pair',
        'consultant_by_label',
        'bar_class',
        'print_plan_label',
        'print_narrative_label',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'active' => 'boolean',
            'training_rotation' => 'boolean',
            'call_target' => 'boolean',
            'clinic_owner' => 'boolean',
            'aliases' => UnitAliases::class,
            'consultant_pair' => 'boolean',
            'extra_row_fields' => ExtraRowFields::class,
        ];
    }
```

and add the matcher below `findByCode()`:

```php
    /**
     * Munawib UN-03's typo-tolerant resolver: exact code first, then any unit whose alias list
     * matches case- and whitespace-insensitively.
     *
     * CODE WINS. An exact identity beats another unit's typo-tolerance hint, or an alias could
     * shadow a real unit and silently redirect an import.
     *
     * Loads the whole unit set and matches in PHP rather than querying JSON: units number in
     * the tens, JSON containment predicates are MySQL-only, and this schema runs on SQLite
     * under test. `findByCode()` remains the resolver for anything ROUTING — a URL segment must
     * never resolve through a fuzzy alias.
     *
     * No production consumer yet: ST-04's roster import is P1c and the rota import is P1d.
     * Shipped with the column so the two arrive together rather than the import inventing its
     * own matcher.
     */
    public static function findByCodeOrAlias(string $value): ?self
    {
        if (($exact = static::findByCode($value)) !== null) {
            return $exact;
        }

        $needle = UnitAliases::fold($value);

        if ($needle === '') {
            return null;
        }

        return static::query()->get()->first(
            fn (self $unit): bool => collect($unit->aliases)
                ->contains(fn (string $alias): bool => UnitAliases::fold($alias) === $needle)
        );
    }
```

- [x] **Step 6: Seed the four units' flags**

In `database/seeders/ReferenceSeeder.php`, add three keys to each of the four unit arrays. PICU,
NICU, SCBU and WARD are all training rotations and all on-call targets; nothing owns a clinic
until P1e creates the concept.

```php
            'PICU' => [
                'name' => 'Pediatric Intensive Care Unit',
                'display_order' => 1,
                'active' => true,
                'training_rotation' => true,
                'call_target' => true,
                'clinic_owner' => false,
                'extra_row_fields' => [],
                // ... the remaining keys unchanged
```

Repeat identically for `NICU`, `SCBU` and `WARD`. The existing `firstOrNew` + `if (! $unit->exists)`
guard already means these are written on CREATE only, so a re-seed cannot revert an
administrator's change — that is what `test_reseeding_preserves_administrator_flag_changes`
asserts.

- [x] **Step 7: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter UnitCapabilityFlagsTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

Expected: `UnitCapabilityFlagsTest` 12 passed; full suite **758 passed** (746 + 12), 0 failures.
`UnitConfigurationTest`, `ReservedUnitCodesTest`, `UnitScopeTest`, `MissedDaysTest` and
`ReferenceSeederTest` must all stay green — finding 10 is why.

```bash
git add app/Casts/UnitAliases.php app/Models/Unit.php database/ tests/
git commit -m "feat: a unit says what it is for, and answers to more than one name"
```

---

### Task 2: `structure.manage`, and the screen it opens

**Files:**
- Modify: `database/seeders/AccessControlSeeder.php`
- Modify: `docs/spec/08-foundation.md`
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/Admin/UnitController.php`
- Create: `resources/js/Pages/Admin/Units.vue`
- Modify: `resources/js/Layouts/AppLayout.vue`
- Test: `tests/Feature/Admin/StructureAccessTest.php`
- Test: modify `tests/js/AppLayout.test.js`

**These land together and cannot be split.** A capability seeded with no route is dead weight; a
route with no nav entry is unreachable; a nav entry whose capability is missing from `canAdmin`
renders nothing at all (the recon frontend risk the P1 plan names at item 2 of P1b's scope). The
P0a plan's amendment 1 records what splitting a mutually-dependent pair costs.

This task ships the units screen **read-only**. Writes are Task 4, after Task 3 has made the nav
capable of showing a fifth unit.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Admin/StructureAccessTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\RoleCapability;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P1b's new capability, `structure.manage` — the department's SHAPE (units, levels, calendar,
 * periods, holidays), as opposed to `settings.manage`'s infrastructure (SMTP, VAPID, reminder
 * times). Administrator-only by default, grantable per role or per named user like every other
 * key.
 */
class StructureAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_the_capability_is_in_the_catalog(): void
    {
        $this->assertDatabaseHas('capabilities', ['key' => 'structure.manage']);
    }

    public function test_it_defaults_to_administrator_only(): void
    {
        $id = (int) Capability::where('key', 'structure.manage')->value('id');

        $this->assertSame(
            [0],
            RoleCapability::where('capability_id', $id)->pluck('position')->map(intval(...))->all()
        );
    }

    public function test_an_administrator_can_open_the_units_screen(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/admin/structure/units')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Units')
                ->has('units', 4)
                ->has('palette')
                ->where('units.0.code', 'PICU')
                ->where('units.0.training_rotation', true)
                ->where('units.0.clinic_owner', false)
                ->where('reserved_codes', ['TODAY', 'COMPLIANCE', 'ROWS'])
            );
    }

    public function test_a_resident_is_refused(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/units')->assertForbidden();
    }

    /** A refusal is audited by the cap: middleware, as every other capability's is. */
    public function test_a_refusal_is_audited(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/units')->assertForbidden();

        $this->assertDatabaseHas('audit_log', ['action' => 'access_denied']);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/structure/units')->assertRedirect('/login');
    }

    /**
     * The screen lists INACTIVE units too — UN-04 deactivation "hides forward", and an
     * administrator who cannot see a retired unit cannot bring it back.
     */
    public function test_inactive_units_are_listed(): void
    {
        \App\Models\Unit::create(['code' => 'RETIRED', 'name' => 'Old Ward']);
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/admin/structure/units')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('units', 5));
    }
}
```

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter StructureAccessTest 2>&1 | tail -15
```

Expected: FAIL — `Failed asserting that a row in the table [capabilities] matches the attributes
{"key":"structure.manage"}`.

- [x] **Step 3: Seed the capability**

In `database/seeders/AccessControlSeeder.php`, add to `DESCRIPTIONS`:

```php
        'structure.manage' => 'Define the department\'s STRUCTURE: units (create, rename, colour, '
            .'order, capability flags, aliases, deactivate, merge), the training-level ladder, the '
            .'calendar (weekend days, Hijri display and its calibration), rota periods, and the '
            .'holiday list. Distinct from “settings.manage”, which covers infrastructure (mail '
            .'server, push keys, reminder times) — mistyping an SMTP host bounces a message, '
            .'whereas mistyping the Hijri offset silently redates every Hijri label and every '
            .'Hijri-ruled holiday in the system. Default: Administrator only; grantable per role '
            .'or per named user like any capability.',
```

to `CATALOG`, after the `// User & access administration.` block:

```php
        // Departmental structure (Munawib UN-*, LV-01, ST-02, ST-06).
        'structure.manage' => 'Manage units, training levels, the calendar, periods and holidays',
```

and to `ROLE_DEFAULTS[0]`:

```php
        0 => [
            'profile.manage',
            'endorsement.view', 'endorsement.edit', 'endorsement.reopen', 'endorsement.compliance',
            'users.manage', 'users.manage_residents', 'access.manage', 'settings.manage',
            'structure.manage',
        ],
```

No other role gains it. The `applied_role_defaults` marker is per `(position, capability_id)`
pair, so a brand-new key has never been marked and lands on the next `db:seed --force` even on
an existing deployment (the P1 plan's correction to the reconnaissance).

- [x] **Step 4: The spec catalog**

`docs/spec/08-foundation.md` line 36 — append the key:

> **Capability catalog (complete):** `endorsement.view`, `endorsement.edit`,
> `endorsement.reopen`, `endorsement.compliance`, `profile.manage`, `users.manage`,
> `users.manage_residents`, `access.manage`, `settings.manage`, `structure.manage`.

and line 38 — append to the role-defaults sentence, immediately after the
`endorsement.compliance` clause:

> `structure.manage` (units, levels, calendar, periods, holidays — Munawib UN-\*, LV-01, ST-02)
> also defaults **Administrator-only**, added P1b 2026-08-09.

This catalog has been found stale twice. It is correct as of `9598aa5`; this step is what keeps
it that way.

- [x] **Step 5: The route group and the controller**

In `routes/web.php`, after the Settings group, add:

```php
/*
 * Admin → Structure: the department's SHAPE — units, training levels, the calendar, rota
 * periods and holidays (Munawib UN-01…05, LV-01, ST-02, ST-06). One capability covers all of
 * them: they are edited by the same person in the same sitting, and they are a different kind
 * of thing from `settings.manage`'s infrastructure.
 *
 * `/admin/structure/*` is deliberately NOT under `/endorsement`, so Unit::RESERVED_CODES —
 * which ReservedUnitCodesTest derives from the literal segments under /endorsement alone — is
 * unaffected by anything added here.
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:structure.manage'])
    ->prefix('admin/structure')
    ->name('admin.structure.')
    ->group(function () {
        Route::get('/units', [UnitController::class, 'index'])->name('units');
    });
```

with `use App\Http\Controllers\Admin\UnitController;` added to the imports.

Create `app/Http/Controllers/Admin/UnitController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Structure → Units (cap:structure.manage). Munawib UN-01…05.
 *
 * The surface `Unit::RESERVED_CODES` was written for (Unit.php's own docblock says so): a code
 * that would be route-shadowed under /endorsement is refused here as a VALIDATION message, not
 * as the raw InvalidArgumentException the model guard throws.
 *
 * INACTIVE units are listed. UN-04 deactivation "hides forward, never deletes" — an
 * administrator who cannot see a retired unit cannot bring it back.
 */
class UnitController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Units', [
            'units' => Unit::query()->ordered()->get()->map(self::present(...))->values()->all(),
            // Offered and validated from ONE list (Unit::BAR_CLASSES) — the SignoffPickers
            // discipline applied to a much smaller thing.
            'palette' => Unit::BAR_CLASSES,
            // Surfaced so the form can warn BEFORE submit as well as refuse on it.
            'reserved_codes' => Unit::RESERVED_CODES,
        ]);
    }

    /**
     * The unit shape the screen edits. Deliberately NOT UnitProfile::toArray() — that is the
     * clinical sheet's contract and carries no administrative fields; this one carries no
     * print labels. Two audiences, two projections.
     *
     * @return array<string, mixed>
     */
    private static function present(Unit $unit): array
    {
        return [
            'id' => (int) $unit->getKey(),
            'code' => (string) $unit->code,
            'name' => (string) $unit->name,
            'name2' => $unit->name2,
            'display_order' => (int) $unit->display_order,
            'active' => (bool) $unit->active,
            'training_rotation' => (bool) $unit->training_rotation,
            'call_target' => (bool) $unit->call_target,
            'clinic_owner' => (bool) $unit->clinic_owner,
            'aliases' => $unit->aliases,
            'bar_class' => $unit->bar_class ?? 'channel-bar-slate',
        ];
    }
}
```

`Unit::BAR_CLASSES` does not exist yet — Task 3 adds it. Add the constant now, with the four
existing entries only, and let Task 3 widen it:

```php
    /**
     * The unit colour palette: class => human label. `bar_class` IS the unit's colour; there
     * is deliberately no second `color` column (P1b Decision B — two definitions of one fact).
     * This map both OFFERS the choice and validates it, so the two cannot drift.
     *
     * Widened by P1b Task 3 with four hue-named entries, so a fifth department has a colour.
     */
    public const BAR_CLASSES = [
        'channel-bar-picu' => 'Teal',
        'channel-bar-nicu' => 'Indigo',
        'channel-bar-scbu' => 'Violet',
        'channel-bar-ward' => 'Plum',
    ];
```

- [x] **Step 6: The screen**

Create `resources/js/Pages/Admin/Units.vue`. Read-only in this task; Task 4 adds the forms.

```vue
<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Structure → Units (Munawib UN-01…05).
 *
 * Read-only in P1b Task 2 — the capability, the route and the nav entry had to land together,
 * and a nav entry pointing at a 404 is worse than no nav entry. Task 4 adds the write forms.
 *
 * Mobile cards + desktop table, matching Users.vue and Sheet.vue.
 */
defineProps({
    units: { type: Array, default: () => [] },
    palette: { type: Object, default: () => ({}) },
    reserved_codes: { type: Array, default: () => [] },
});

const flags = (unit) => [
    unit.training_rotation ? 'Rotation' : null,
    unit.call_target ? 'On-call' : null,
    unit.clinic_owner ? 'Clinics' : null,
].filter(Boolean);
</script>

<template>
    <AppLayout title="Units">
        <div class="mx-auto max-w-5xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Units</h2>
                <p class="text-sm text-muted">
                    A unit is configuration, not code. Its code is its address
                    (<span class="readout">/endorsement/&lt;code&gt;</span>), so
                    <span class="readout">{{ reserved_codes.join(', ') }}</span> can never be used —
                    a unit with one of those codes would be permanently unreachable.
                </p>
            </div>

            <!-- Phone: one card per unit. -->
            <div class="space-y-3 lg:hidden">
                <article v-for="unit in units" :key="unit.id"
                         class="channel-bar rounded-md border border-line bg-panel p-4"
                         :class="unit.bar_class">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="readout text-sm font-semibold text-ink">{{ unit.code }}</span>
                        <span class="channel-tag">{{ unit.active ? 'Active' : 'Retired' }}</span>
                    </div>
                    <p class="text-sm text-body">{{ unit.name }}</p>
                    <p v-if="flags(unit).length" class="channel-tag mt-1">{{ flags(unit).join(' · ') }}</p>
                    <p v-if="unit.aliases.length" class="mt-1 text-xs text-muted">
                        Also known as: {{ unit.aliases.join(', ') }}
                    </p>
                </article>
            </div>

            <!-- Desktop: a table. -->
            <div class="hidden overflow-x-auto rounded-md border border-line bg-panel lg:block">
                <table class="w-full text-left text-sm">
                    <thead class="bg-ground-deep">
                        <tr>
                            <th scope="col" class="channel-tag px-4 py-2">Order</th>
                            <th scope="col" class="channel-tag px-4 py-2">Code</th>
                            <th scope="col" class="channel-tag px-4 py-2">Name</th>
                            <th scope="col" class="channel-tag px-4 py-2">Colour</th>
                            <th scope="col" class="channel-tag px-4 py-2">Used for</th>
                            <th scope="col" class="channel-tag px-4 py-2">Aliases</th>
                            <th scope="col" class="channel-tag px-4 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="unit in units" :key="unit.id" class="border-t border-line">
                            <td class="readout px-4 py-2 text-body">{{ unit.display_order }}</td>
                            <td class="readout px-4 py-2 font-semibold text-ink">{{ unit.code }}</td>
                            <td class="px-4 py-2 text-body">{{ unit.name }}</td>
                            <td class="px-4 py-2">
                                <span class="channel-bar inline-block h-4 w-8 rounded-sm bg-ground"
                                      :class="unit.bar_class" aria-hidden="true"></span>
                                <span class="sr-only">{{ palette[unit.bar_class] || unit.bar_class }}</span>
                            </td>
                            <td class="px-4 py-2 text-body">{{ flags(unit).join(' · ') || '—' }}</td>
                            <td class="px-4 py-2 text-muted">{{ unit.aliases.join(', ') || '—' }}</td>
                            <td class="px-4 py-2">
                                <span class="channel-tag">{{ unit.active ? 'Active' : 'Retired' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
```

- [x] **Step 7: The nav entry**

In `resources/js/Layouts/AppLayout.vue`, extend `canAdmin` (line 72-73) and add the link inside
the `<template v-if="canAdmin">` block, above the Settings entry:

```js
const canAdmin = computed(() => can('access.manage') || can('users.manage')
    || can('users.manage_residents') || can('settings.manage') || can('structure.manage'));
```

```vue
                    <Link v-if="can('structure.manage')" href="/admin/structure/units"
                          :class="navClass(isActive('/admin/structure/units'))">
                        Units
                    </Link>
```

Add to `tests/js/AppLayout.test.js` a case asserting that a user whose `auth.can` contains
**only** `structure.manage` sees the Administration heading and the Units link — the exact
failure mode the recon frontend risk describes. Follow the file's existing mount helper.

- [x] **Step 8: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter StructureAccessTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
npm test 2>&1 | tail -5
php artisan test 2>&1 | tail -3
```

Expected: `StructureAccessTest` 7 passed; `npm test` 110 (109 + 1); full suite **765 passed**.
`AccessControlParityTest` and `AccessControlSeederRespectsRevocationsTest` must stay green — they
are what would notice the catalog and the seeder drifting apart.

```bash
git add app/ database/ docs/ resources/ routes/ tests/
git commit -m "feat: the department's shape gets a key, a route and a way in"
```

---

### Task 3: The sidebar stops hardcoding four units

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/Layouts/AppLayout.vue`
- Modify: `resources/css/app.css`
- Modify: `app/Models/Unit.php`
- Test: `tests/Feature/Units/NavUnitsAreConfigurationTest.php`
- Test: modify `tests/js/AppLayout.test.js`

**This must precede Task 4.** CLAUDE.md records the hardcoded sidebar and the four hue classes
as known pending exceptions — *"a fifth department gets no nav entry or hue until those move to
configuration."* Task 4 is the moment a fifth department becomes creatable. Shipping creation
first would mean the first unit an administrator makes is invisible and colourless: a defect
introduced by this plan rather than inherited from P0a.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Units/NavUnitsAreConfigurationTest.php`:

```php
<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * CLAUDE.md's two pending exceptions, closed: `AppLayout.vue` hardcoded a four-entry unit array
 * and `app.css` defined exactly four unit hues, so a fifth department created through P1b's new
 * units screen would have had no sidebar entry and no colour.
 *
 * The nav's unit list is now a SHARED INERTIA PROP built from `Unit::codes()`' own source of
 * truth, so a unit created, renamed, recoloured, reordered or retired is reflected without a
 * frontend change.
 */
class NavUnitsAreConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_the_shared_prop_carries_the_four_seeded_units_in_display_order(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('nav.units', 4)
                ->where('nav.units.0', [
                    'code' => 'picu',
                    'label' => 'Pediatric Intensive Care Unit',
                    'bar' => 'channel-bar-picu',
                ])
                ->where('nav.units.3.code', 'ward')
            );
    }

    public function test_a_fifth_unit_appears_in_the_nav_without_a_frontend_change(): void
    {
        Unit::create([
            'code' => 'RGH1', 'name' => 'Riyadh General Ward 1', 'active' => true,
            'display_order' => 5, 'bar_class' => 'channel-bar-amber',
        ]);

        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('nav.units', 5)
                ->where('nav.units.4', [
                    'code' => 'rgh1',
                    'label' => 'Riyadh General Ward 1',
                    'bar' => 'channel-bar-amber',
                ])
            );
    }

    /** UN-04: deactivation hides FORWARD. A retired unit leaves the nav; its history stays. */
    public function test_a_retired_unit_leaves_the_nav(): void
    {
        Unit::findByCode('SCBU')->update(['active' => false]);

        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('nav.units', 3)
                ->where('nav.units.2.code', 'ward')
            );
    }

    /** A unit with no stored bar_class still gets a colour, never an empty class attribute. */
    public function test_a_unit_without_a_stored_colour_falls_back_to_a_palette_entry(): void
    {
        Unit::create(['code' => 'RGH2', 'name' => 'Ward 2', 'active' => true, 'display_order' => 6]);

        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('nav.units.4.bar', 'channel-bar-slate')
            );
    }

    public function test_a_guest_gets_an_empty_unit_list_not_an_error(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nav.units', []));
    }

    /** Every palette class the model offers has a rule in the stylesheet, and vice versa. */
    public function test_the_palette_and_the_stylesheet_agree(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (array_keys(Unit::BAR_CLASSES) as $class) {
            $this->assertStringContainsString(
                '.'.$class.' {',
                $css,
                "Unit::BAR_CLASSES offers [{$class}] but resources/css/app.css defines no rule for it"
            );
        }

        preg_match_all('/\.(channel-bar-[a-z0-9]+) \{/', $css, $matches);
        $declared = array_values(array_diff($matches[1], ['channel-bar-ok', 'channel-bar-critical', 'channel-bar-caution']));

        $this->assertEqualsCanonicalizing(array_keys(Unit::BAR_CLASSES), $declared);
    }
}
```

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter NavUnitsAreConfigurationTest 2>&1 | tail -15
```

Expected: FAIL — `Property [nav] does not exist.`

- [x] **Step 3: Widen the palette in `resources/css/app.css`**

After `--color-unit-ward` (line 74) inside the `@theme` block, add four hue-named tokens. These
are border colours only — outside `TextContrastMeetsAaTest`'s token set (finding 12) — but kept
in the same mid-dark saturation band as the existing four so an eight-unit sidebar reads as one
system:

```css
    --color-unit-amber: #94660f;   /* amber */
    --color-unit-moss:  #3f7340;   /* moss */
    --color-unit-clay:  #a2542f;   /* clay */
    --color-unit-slate: #4a5a6b;   /* slate — the fallback for a unit with no colour chosen */
```

and after `.channel-bar-ward` (line 121), the matching rules:

```css
    .channel-bar-amber { border-left-color: var(--color-unit-amber); }
    .channel-bar-moss  { border-left-color: var(--color-unit-moss); }
    .channel-bar-clay  { border-left-color: var(--color-unit-clay); }
    .channel-bar-slate { border-left-color: var(--color-unit-slate); }
```

No `dark:` variant, no second definition. The existing four are untouched, so no stored
`bar_class` value migrates and no rendered pixel moves.

- [x] **Step 4: Widen `Unit::BAR_CLASSES` and add the fallback**

In `app/Models/Unit.php`, replace the constant Task 2 added:

```php
    public const BAR_CLASSES = [
        'channel-bar-picu' => 'Teal',
        'channel-bar-nicu' => 'Indigo',
        'channel-bar-scbu' => 'Violet',
        'channel-bar-ward' => 'Plum',
        'channel-bar-amber' => 'Amber',
        'channel-bar-moss' => 'Moss',
        'channel-bar-clay' => 'Clay',
        'channel-bar-slate' => 'Slate',
    ];

    /** What a unit with no colour chosen renders as. Never an empty class attribute. */
    public const DEFAULT_BAR_CLASS = 'channel-bar-slate';
```

and add the nav projection below `codes()`:

```php
    /**
     * The sidebar's unit list — active units, in display order, as the shape AppLayout renders.
     *
     * CLAUDE.md recorded `AppLayout.vue`'s hardcoded four-entry array as a pending exception:
     * "a fifth department gets no nav entry or hue until those move to configuration". This is
     * that move. `code` is lower-cased because the sidebar builds `/endorsement/{code}` URLs and
     * every existing link in the app is lower-case; routing itself is case-insensitive through
     * `findByCode()`.
     *
     * @return list<array{code:string, label:string, bar:string}>
     */
    public static function navList(): array
    {
        return static::query()->active()->ordered()->get()
            ->map(fn (self $unit): array => [
                'code' => strtolower((string) $unit->code),
                'label' => (string) $unit->name,
                'bar' => $unit->bar_class ?: self::DEFAULT_BAR_CLASS,
            ])
            ->values()
            ->all();
    }
```

`UnitProfile::fromUnit()`'s existing `?? 'channel-bar-'.strtolower($code)` fallback
(`UnitProfile.php:52`) is left alone deliberately: it is the *clinical sheet's* contract, it
predates this palette, and changing it would move a rendered colour on the endorsement surface —
outside this plan's scope. Recorded here so the difference is a decision, not an oversight.

- [x] **Step 5: Share it**

In `app/Http/Middleware/HandleInertiaRequests.php`, add a `nav` key to `share()`, after `auth`:

```php
            // The sidebar's unit list, from the `units` table rather than a hardcoded array in
            // AppLayout.vue (CLAUDE.md's pending exception, closed P1b). Codes and display names
            // only — no clinical data, and nothing a guest may not see, which is why an
            // unauthenticated request gets an empty list rather than the seeded four.
            'nav' => [
                'units' => $user ? \App\Models\Unit::navList() : [],
            ],
```

One extra query per authenticated request against a table of four rows. If that ever matters it
belongs behind the same generation-counter cache `AccessControl` uses — noted, not built, because
premature caching of a four-row table is how a stale sidebar happens.

- [x] **Step 6: Consume it**

In `resources/js/Layouts/AppLayout.vue`, delete the hardcoded array (lines 62-67) and replace it
with a computed over the shared prop:

```js
// The unit list comes from the server (`nav.units`, built by Unit::navList()) rather than a
// literal array here, so creating, renaming, recolouring, reordering or retiring a unit on
// Admin -> Structure -> Units is reflected without a frontend change. Codes are the routing
// identity; the bar class carries each unit's channel hue so the sidebar can be scanned by
// edge colour alone.
const units = computed(() => page.props.nav?.units ?? []);
```

`page` is already in scope (`const page = usePage();`, line 12) and `computed` is already
imported (line 2). The `v-for` in the template needs no change — the shape is identical — except
that `{{ unit.label }} Endorsement` now renders a full unit name; change it to
`{{ unit.label }}` alone, since "Pediatric Intensive Care Unit Endorsement" does not fit a
16rem sidebar. Verify visually with `npm run test:e2e`.

- [x] **Step 7: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter NavUnitsAreConfigurationTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
npm test 2>&1 | tail -5
php artisan test 2>&1 | tail -3
npm run test:e2e 2>&1 | tail -5
```

Expected: `NavUnitsAreConfigurationTest` 6 passed; full suite **771 passed**; `npm test` green
after updating `AppLayout.test.js`'s mount to supply `nav: { units: [...] }` (its existing cases
assert the four links and will go red without it — that is the correct red, and fixing the
fixture is part of this step); `CompiledCssIsLightOnlyTest` and `TextContrastMeetsAaTest` green;
e2e green.

```bash
git add app/ resources/ tests/
git commit -m "feat: the sidebar reads the units table instead of remembering four names"
```

---

### Task 4: Units CRUD — create, rename, recolour, reorder, reflag, retire

**Files:**
- Create: `app/Http/Requests/Admin/UnitRequest.php`
- Modify: `app/Http/Controllers/Admin/UnitController.php`
- Modify: `resources/js/Pages/Admin/Units.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/UnitCrudTest.php`

UN-01 (create, rename, colour, order, deactivate), UN-02 (flags), UN-03 (aliases), UN-04
(deactivation hides forward, never deletes), UN-05 (`name2`). Merge is Task 5.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Admin/UnitCrudTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Handover;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib UN-01…05 write paths.
 *
 * The RESERVED-CODE case is the one this screen was foreseen for: `Unit::booted()`'s own
 * docblock says "when [a UI] lands, it must surface this as a validation message rather than
 * let this exception reach the user raw". A 500 here would be the model guard doing its job
 * badly, not the request being rejected well.
 */
class UnitCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'RGH1',
            'name' => 'Riyadh General Ward 1',
            'name2' => null,
            'display_order' => 5,
            'active' => true,
            'training_rotation' => true,
            'call_target' => false,
            'clinic_owner' => false,
            'aliases' => ['Ward One'],
            'bar_class' => 'channel-bar-amber',
        ], $overrides);
    }

    public function test_an_administrator_creates_a_unit(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/units', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('status');

        $unit = Unit::findByCode('RGH1');

        $this->assertNotNull($unit);
        $this->assertSame('Riyadh General Ward 1', $unit->name);
        $this->assertTrue($unit->training_rotation);
        $this->assertFalse($unit->call_target);
        $this->assertSame(['Ward One'], $unit->aliases);
        $this->assertSame('channel-bar-amber', $unit->bar_class);
    }

    /** The code mutator normalises on write; the screen must not have to know that. */
    public function test_a_lower_case_code_is_stored_normalised(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/units', $this->payload(['code' => '  rgh1 ']))
            ->assertRedirect();

        $this->assertDatabaseHas('units', ['code' => 'RGH1']);
    }

    /**
     * Finding 9: uniqueness is checked against the NORMALISED code. Validating the raw input
     * would let `picu` pass and then collide on insert with a 23000.
     */
    public function test_a_duplicate_code_is_a_validation_error_not_a_database_error(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/units', $this->payload(['code' => 'picu']))
            ->assertSessionHasErrors('code');

        $this->assertSame(4, Unit::count());
    }

    public function test_a_reserved_code_is_refused_as_a_validation_message(): void
    {
        foreach (['TODAY', 'compliance', ' Rows '] as $code) {
            $this->actingAs($this->admin)
                ->post('/admin/structure/units', $this->payload(['code' => $code]))
                ->assertSessionHasErrors('code');
        }

        $this->assertSame(4, Unit::count());
    }

    public function test_an_unknown_palette_class_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/structure/units', $this->payload(['bar_class' => 'channel-bar-neon']))
            ->assertSessionHasErrors('bar_class');
    }

    public function test_an_administrator_renames_recolours_and_reorders(): void
    {
        $scbu = Unit::findByCode('SCBU');

        $this->actingAs($this->admin)
            ->patch("/admin/structure/units/{$scbu->id}", $this->payload([
                'code' => 'SCBU',
                'name' => 'Special Care Nursery',
                'name2' => 'حضانة العناية الخاصة',
                'display_order' => 2,
                'bar_class' => 'channel-bar-moss',
                'training_rotation' => true,
                'call_target' => true,
                'aliases' => ['SCN', 'Special Care Baby Unit'],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $scbu->refresh();
        $this->assertSame('Special Care Nursery', $scbu->name);
        $this->assertSame('حضانة العناية الخاصة', $scbu->name2);
        $this->assertSame(2, $scbu->display_order);
        $this->assertSame('channel-bar-moss', $scbu->bar_class);
        $this->assertSame(['SCN', 'Special Care Baby Unit'], $scbu->aliases);
    }

    /** A unit keeps its own code on update — the unique rule must ignore itself. */
    public function test_updating_a_unit_without_changing_its_code_is_allowed(): void
    {
        $picu = Unit::findByCode('PICU');

        $this->actingAs($this->admin)
            ->patch("/admin/structure/units/{$picu->id}", $this->payload([
                'code' => 'PICU', 'name' => 'PICU (renamed)',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('PICU (renamed)', $picu->fresh()->name);
    }

    /**
     * UN-04. Deactivation hides FORWARD: the unit leaves the nav and its routes 404, and every
     * clinical row it already owns is untouched. There is no delete endpoint at all.
     */
    public function test_deactivation_hides_forward_and_deletes_nothing(): void
    {
        $ward = Unit::findByCode('WARD');
        // There is no HandoverFactory in this codebase — every test builds handovers with
        // Handover::create(), matching MissedDaysTest:39. Do not introduce one for this.
        Handover::create([
            'unit_id' => $ward->id,
            'handover_date' => '2026-08-08',
            'mrn' => 'M-12345',
        ]);
        $before = Handover::where('unit_id', $ward->id)->count();

        $this->actingAs($this->admin)
            ->patch("/admin/structure/units/{$ward->id}/active", ['active' => false])
            ->assertRedirect();

        $this->assertFalse($ward->fresh()->active);
        $this->assertSame($before, Handover::where('unit_id', $ward->id)->count());
        $this->actingAs($this->admin)->get('/endorsement/ward')->assertNotFound();
    }

    public function test_there_is_no_delete_endpoint(): void
    {
        $ward = Unit::findByCode('WARD');

        $this->actingAs($this->admin)
            ->delete("/admin/structure/units/{$ward->id}")
            ->assertStatus(405);
    }

    public function test_a_retired_unit_can_be_brought_back(): void
    {
        $ward = Unit::findByCode('WARD');
        $ward->update(['active' => false]);

        $this->actingAs($this->admin)
            ->patch("/admin/structure/units/{$ward->id}/active", ['active' => true])
            ->assertRedirect();

        $this->assertTrue($ward->fresh()->active);
    }

    public function test_every_write_is_audited_by_id_and_field_never_by_value(): void
    {
        $this->actingAs($this->admin)->post('/admin/structure/units', $this->payload());

        $row = \App\Models\AuditLog::where('action', 'unit_create')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('code=RGH1', $row->detail);
        $this->assertStringNotContainsString('Riyadh General Ward 1', $row->detail);

        $picu = Unit::findByCode('PICU');
        $this->actingAs($this->admin)->patch("/admin/structure/units/{$picu->id}", $this->payload([
            'code' => 'PICU', 'name' => 'Renamed', 'bar_class' => 'channel-bar-picu',
        ]));

        $update = \App\Models\AuditLog::where('action', 'unit_update')->latest('id')->first();

        $this->assertStringContainsString('unit='.$picu->id, $update->detail);
        $this->assertStringContainsString('fields=', $update->detail);
        $this->assertStringNotContainsString('Renamed', $update->detail);
    }

    public function test_a_resident_cannot_write(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)
            ->post('/admin/structure/units', $this->payload())
            ->assertForbidden();
    }
}
```

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter UnitCrudTest 2>&1 | tail -15
```

Expected: FAIL — `Expected response status code [302] but received 405` (no POST route).

- [x] **Step 3: The FormRequest**

Create `app/Http/Requests/Admin/UnitRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib UN-01…05 write validation.
 *
 * Two rules here exist because of the `code` mutator (Unit.php): it normalises what is STORED,
 * not what a query compares. Both the uniqueness check and the reserved-code check therefore
 * run against the NORMALISED value, or `picu` passes uniqueness and collides at insert, and
 * ` today ` passes the reserved check and trips the model's saving guard as a raw 500.
 */
class UnitRequest extends FormRequest
{
    /** The route middleware (`cap:structure.manage`) is the gate; nothing extra here. */
    public function authorize(): bool
    {
        return true;
    }

    /** Normalise BEFORE validation so every rule below sees what will actually be stored. */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unit = $this->route('unit');
        $id = $unit instanceof Unit ? $unit->getKey() : null;

        return [
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/',
                Rule::notIn(Unit::RESERVED_CODES),
                Rule::unique('units', 'code')->ignore($id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'name2' => ['nullable', 'string', 'max:255'],
            'display_order' => ['required', 'integer', 'between:1,9999'],
            'active' => ['required', 'boolean'],
            'training_rotation' => ['required', 'boolean'],
            'call_target' => ['required', 'boolean'],
            'clinic_owner' => ['required', 'boolean'],
            'aliases' => ['present', 'array', 'max:50'],
            'aliases.*' => ['string', 'max:100'],
            // Offered and validated from ONE list, so the select and the gate cannot drift.
            'bar_class' => ['required', 'string', Rule::in(array_keys(Unit::BAR_CLASSES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.not_in' => 'That code is reserved by a route under /endorsement — a unit with it '
                .'would be permanently unreachable. Choose another.',
            'code.regex' => 'A unit code is letters and digits only: it is the address in '
                .'/endorsement/<code>.',
            'code.unique' => 'Another unit already uses that code.',
        ];
    }
}
```

- [x] **Step 4: The write endpoints**

Extend `app/Http/Controllers/Admin/UnitController.php`:

```php
    /** UN-01 create. */
    public function store(UnitRequest $request): RedirectResponse
    {
        $unit = Unit::create($request->validated());

        AuditLog::record(
            'unit_create',
            'unit='.$unit->getKey().';code='.$unit->code,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Unit '.$unit->code.' created.');
    }

    /** UN-01/02/03/05 update. */
    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        $data = $request->validated();

        // The delta is computed BEFORE the write and named by FIELD, never by value — a unit
        // name is not a secret, but the trail's job is "what changed", and a values-in-details
        // habit is how PHI eventually reaches an audit row (AccessControlController:197-199).
        $changed = array_keys(array_filter(
            $data,
            fn ($value, $key): bool => $unit->getAttribute($key) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $unit->update($data);

        AuditLog::record(
            'unit_update',
            'unit='.$unit->getKey().';code='.$unit->code.';fields='.(implode(',', $changed) ?: 'none'),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Unit '.$unit->code.' updated.');
    }

    /**
     * UN-04: deactivation HIDES FORWARD and never deletes. Its own endpoint rather than a field
     * on update(), so retiring a unit is a deliberate single act with its own audit action —
     * and so it cannot ride along inside a rename the administrator thought was cosmetic.
     *
     * There is deliberately no destroy(). Clinical rows are never hard-deleted, and a unit that
     * owns handovers is the row those handovers point at.
     */
    public function setActive(Request $request, Unit $unit): RedirectResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];

        $unit->update(['active' => $active]);

        AuditLog::record(
            $active ? 'unit_activate' : 'unit_deactivate',
            'unit='.$unit->getKey().';code='.$unit->code,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Unit '.$unit->code.($active ? ' is active.' : ' retired.'));
    }
```

with `use App\Http\Requests\Admin\UnitRequest;`, `use App\Models\AuditLog;`,
`use Illuminate\Http\RedirectResponse;` and `use Illuminate\Http\Request;` added.

Routes, inside the existing `admin/structure` group:

```php
        Route::post('/units', [UnitController::class, 'store'])->name('units.store');
        Route::patch('/units/{unit}', [UnitController::class, 'update'])->name('units.update');
        Route::patch('/units/{unit}/active', [UnitController::class, 'setActive'])->name('units.active');
```

No `DELETE`. `test_there_is_no_delete_endpoint` asserts the 405 rather than trusting the absence.

- [x] **Step 5: The forms**

Extend `resources/js/Pages/Admin/Units.vue`: a "New unit" section above the table and an inline
edit row per unit. Follow `Settings.vue` exactly — `useForm`, `inputClass`, `form.errors.*` as
`text-critical`, `form.recentlySuccessful` as "Saved.", `preserveScroll: true` on every submit.
Aliases are a comma-separated text input split on submit and joined on load (a tag widget is a
component this codebase does not have and P1b is not the plan to introduce one). The colour
select renders each option's label with a live swatch:

```vue
<select id="bar_class" v-model="form.bar_class" :class="inputClass">
    <option v-for="(label, cls) in palette" :key="cls" :value="cls">{{ label }}</option>
</select>
<span class="channel-bar mt-1 inline-block h-4 w-8 rounded-sm bg-ground" :class="form.bar_class"
      aria-hidden="true"></span>
```

The reserved codes are shown as help text under the code field, from the `reserved_codes` prop,
so the refusal is visible before submit as well as enforced on it.

- [x] **Step 6: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter UnitCrudTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

Expected: `UnitCrudTest` 12 passed; full suite **783 passed**.

```bash
git add app/ resources/ routes/ tests/
git commit -m "feat: a department can add a ward without a deployment"
```

---

### Task 5: Unit merge (UN-01) — the highest-risk task in P1b

**Files:**
- Create: `app/Support/UnitMerge.php`
- Create: `app/Http/Controllers/Admin/UnitMergeController.php`
- Create: `resources/js/Pages/Admin/UnitMerge.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/UnitMergeTest.php`

A merge re-points every row that names the source unit onto the target and retires the source.
Four tables carry `unit_id` today: `handovers`, `handover_signoffs`, `unit_field_definitions`,
and `users.preferred_unit` (a code string, not an FK — `2026_07_24_140001`). P1d will add rota
rows; the merge must be written so adding a fifth table is one line and a test, not a rewrite.

**The collision is `handover_signoffs`' `UNIQUE(unit_id, handover_date)`**
(`2026_07_24_130002:77`). If both units have a signed-off day on 2026-08-08, re-pointing the
source's row onto the target violates that index. **The merge resolves it explicitly, in a
preview the administrator confirms — it never discovers it at insert time.**

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Admin/UnitMergeTest.php`. Cover, at minimum:

- a dry-run preview reports counts per affected table and lists **colliding dates** by date only
  (no PHI: no patient name, no MRN, no row content — dates and counts, per CLAUDE.md);
- a merge with no collisions re-points `handovers`, `handover_signoffs` and
  `unit_field_definitions`, retires the source (`active = false`), and leaves the source row
  itself **undeleted**;
- `users.preferred_unit` holding the source code is rewritten to the target code — otherwise
  every user who favourited the merged unit lands on a 404 at `/endorsement/today`;
- a merge with a signoff collision is **refused** unless the request names a resolution, and the
  two resolutions are: `keep_target` (the source's signoff row for that date is left on the
  source unit and the source stays retired-but-present, so nothing is lost) and `abort`;
- **`keep_target` never deletes a signoff row.** A sign-off is medico-legal evidence — the
  handover rows for that date move, the source's signoff header stays where it is, and the
  preview says so in those words;
- merging a unit into itself is refused;
- merging into an **inactive** target is refused (the result would be a department with no
  reachable unit for that data);
- the whole merge runs in ONE transaction, and a forced failure mid-merge leaves every table
  exactly as it was (assert counts on all four before and after);
- one summary audit row (`unit_merge`, `source=<id>;target=<id>;handovers=N;signoffs=N;defs=N`)
  plus one row per **collision resolved**, ids and dates only — the
  `AccessControlController:190-208` "one summary plus one per changed item" convention;
- a resident is forbidden;
- after the merge, `/endorsement/<source>` 404s and `/endorsement/<target>` shows the merged
  days.

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter UnitMergeTest 2>&1 | tail -15
```

- [x] **Step 3: `App\Support\UnitMerge`**

A support class, not controller code, because the preview and the commit must share **one**
definition of "what this merge would do" — a preview computed one way and a commit performed
another is the drift `SignoffPickers` and `AuditChain::canonical()` both exist to prevent.

```php
<?php

namespace App\Support;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Munawib UN-01's merge. ONE definition of what a merge does, shared by the preview screen and
 * the committing endpoint.
 *
 * THE COLLISION: `handover_signoffs` carries UNIQUE(unit_id, handover_date). Two units each
 * signed off on the same date cannot both re-point onto the target. That is discovered in
 * `plan()` and CONFIRMED by a human before `commit()` runs — never encountered as a 23000 in
 * the middle of a transaction.
 *
 * WHAT A MERGE NEVER DOES: delete a clinical row, delete a sign-off, or delete the source unit.
 * The source is retired (`active = false`) and stays in the database, because every audit row,
 * every handover revision and every future forensic question refers to it by id.
 */
final class UnitMerge
{
    public const KEEP_TARGET = 'keep_target';

    /** @return array{handovers:int, signoffs:int, field_definitions:int, preferred_unit_users:int, collisions:list<string>} */
    public static function plan(Unit $source, Unit $target): array { /* ... */ }

    /** @param  list<string>  $acceptedCollisions dates the administrator confirmed */
    public static function commit(Unit $source, Unit $target, array $acceptedCollisions, int $actorId, ?string $ip): array
    {
        return DB::transaction(function () use ($source, $target, $acceptedCollisions, $actorId, $ip): array {
            /* ... */
        });
    }
}
```

Write the bodies against the tests. Two rules the implementation must honour:

- **Collision handling re-points the handover ROWS for that date and leaves the source's signoff
  header alone.** The target's own signoff for that date is what stands; the source's stays
  attached to the retired source unit, still readable, still verifiable. Deleting it would
  destroy an attestation a named clinician signed.
- **`users.preferred_unit` is a code string, not an FK.** Update it with an explicit
  `User::where('preferred_unit', $source->code)->update([...])` inside the same transaction, and
  include the count in the plan — an FK cascade will not do it and nothing else will notice.

- [x] **Step 4: Controller, routes, screen**

`GET /admin/structure/units/merge` renders the picker plus the live plan;
`POST /admin/structure/units/merge` commits. The screen shows the plan's counts and every
colliding date, and the submit button is **disabled until each collision is acknowledged** —
a checkbox per date, not one blanket "I understand". The confirmation text names the source and
target codes and states that the source will be retired, not deleted.

- [x] **Step 5: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter UnitMergeTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

Expected: full suite **783 + (the number of merge cases written)**. `ClinicalSchemaTest`,
`UnitScopeTest` and every endorsement test must stay green.

```bash
git add app/ resources/ routes/ tests/
git commit -m "feat: two wards become one, and nothing signed is lost doing it"
```

---

### Task 6: The level ladder gains its LV-01 flags

**Files:**
- Create: `database/migrations/2026_08_13_120002_add_external_and_terminal_to_levels.php`
- Modify: `app/Models/Level.php`
- Modify: `database/factories/LevelFactory.php`
- Test: `tests/Feature/Identity/LevelLadderTest.php`

Finding 5: `levels` has `institution_id, code, name, display_order, active` and nothing else.
LV-01 specifies an **external** flag, and the P1 plan's P1b item 6 adds a **terminal/graduating
marker** — *"without one, LV-03 has no way to know who graduates"*. Both are additive and both
must land **before the first promotion**, which is P1c task 8. This is the last plan that can add
them cheaply.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Identity/LevelLadderTest.php`. Cover:

- a new level defaults `external = false` and `terminal = false`;
- both cast to booleans;
- `Level::scopeInternal()` excludes external levels and `scopeOrdered()` still orders by
  `display_order` then `id`;
- `Level::nextAfter(Level $level): ?Level` returns the next **internal, active, non-terminal**
  level by `display_order`, and **null for a terminal level** — the predicate LV-03's promotion
  will read, defined once, here, rather than invented inside a promotion controller;
- `nextAfter()` on an external level returns null (an external person does not advance a ladder
  they are not on);
- two levels sharing a `display_order` is not a crash — `nextAfter()` breaks the tie by `id`,
  deterministically, and a test pins which one it picks.

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter LevelLadderTest 2>&1 | tail -15
```

Expected: FAIL — `table levels has no column named external`.

- [x] **Step 3: The migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib LV-01's `external` flag, plus the terminal/graduating marker LV-03 needs.
 *
 * P0c's 2026_08_10_120002 created `levels` with code/name/display_order/active only — LV-01
 * names four fields and the fourth was never built (P1b finding 5). Design §6.1 (as corrected
 * by P1a Task 9) already describes `external` as administrator-owned data after the seed.
 *
 * `terminal` is not in the Munawib spec at all. LV-03 says "graduates become alumni/inactive,
 * never deleted" without saying how the system KNOWS who graduates; deriving it from "the
 * highest display_order" would make adding an R5 silently graduate every R4. It is a property
 * of the level, so it lives on the level.
 *
 * Both additive with a false default, before the first promotion runs (P1c). Retrofitting after
 * a promotion has executed is not possible without rewriting history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            if (! Schema::hasColumn('levels', 'external')) {
                $table->boolean('external')->default(false)->after('display_order');
            }
        });

        Schema::table('levels', function (Blueprint $table) {
            if (! Schema::hasColumn('levels', 'terminal')) {
                $table->boolean('terminal')->default(false)->after('external');
            }
        });
    }

    public function down(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            $table->dropColumn(['external', 'terminal']);
        });
    }
};
```

- [x] **Step 4: Model, scopes and the one promotion predicate** — AMENDED, see execution note below: only `external` and `scopeInternal()` were built. `terminal` and `Level::nextAfter()` were dropped per Owner Decision A.

Extend `$fillable` with `'external', 'terminal'`, add both to `casts()` as `'boolean'`, and add:

```php
    /**
     * @param  Builder<Level>  $query
     * @return Builder<Level>
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('external', false);
    }

    /**
     * The ONE definition of "advance one level" (Munawib LV-03). Defined here rather than
     * inside P1c's promotion controller so the cohort PREVIEW and the committing write share
     * it — a preview computed one way and a commit performed another is the drift
     * `SignoffPickers` and `AuditChain::canonical()` both carry docblocks about.
     *
     * Returns null for a terminal level (that person graduates, they do not advance) and null
     * for an external level (an external person is not on this ladder at all). Ties on
     * `display_order` break by `id`, matching `scopeOrdered()`, so the answer is deterministic
     * even in a half-configured ladder.
     */
    public static function nextAfter(self $level): ?self
    {
        if ($level->terminal || $level->external) {
            return null;
        }

        return static::query()->active()->internal()
            ->where('terminal', false)
            ->where(fn ($q) => $q
                ->where('display_order', '>', $level->display_order)
                ->orWhere(fn ($q2) => $q2
                    ->where('display_order', $level->display_order)
                    ->where('id', '>', $level->getKey())))
            ->ordered()
            ->first();
    }
```

Add `external` and `terminal` (both `false`) to `LevelFactory`'s definition.

- [x] **Step 5: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter LevelLadderTest 2>&1 | tail -5
php artisan test --filter LevelHistoryTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

`LevelHistoryTest` (P0c, 9 cases) must stay green untouched.

```bash
git add app/ database/ tests/
git commit -m "test: a level knows external from internal, and stops there"
```

---

### Task 7: Seed `R1, R2, R3, R4, EXT`

**Files:**
- Modify: `database/seeders/ReferenceSeeder.php`
- Test: `tests/Feature/ReferenceSeederTest.php`

Owner decision 1, binding: *"The level ladder is `R1`, `R2`, `R3`, `R4`, `EXT` (External) — seed
it… Do not treat the empty `levels` table as still-undecided."*

- [x] **Step 1: Extend the failing test** — AMENDED per Owner Decision A: no `terminal`/
  `nextAfter()` assertions (see execution note below).

Add to `tests/Feature/ReferenceSeederTest.php`:

- the five levels exist with codes `R1, R2, R3, R4, EXT` in that `display_order`;
- `display_order` values are **explicit and distinct** (10, 20, 30, 40, 90) — **not** the
  migration's `1000` default. The P1 plan's item 5 is explicit about this: *"LV-03's 'advance
  one level' is undefined without them"*, and five levels all at 1000 would make `nextAfter()`'s
  tie-break the whole ordering. Gaps of ten so an `R5` or an `R2.5` can be inserted without
  renumbering;
- `EXT` has `external = true`, `terminal = false` and sorts last;
- `R4` has `terminal = true` — it is where a QCH paediatric resident graduates from;
- `R1…R3` have `terminal = false`, `external = false`;
- every level's `institution_id` is the seeded institution's id. **The P0d backfill deliberately
  skipped `levels`** (P1 plan item 5), so nothing else will set it;
- `nextAfter(R1)` is `R2`, `nextAfter(R3)` is `R4`, `nextAfter(R4)` is **null**,
  `nextAfter(EXT)` is null;
- a re-seed after an administrator renames `R1` to `PGY-1` **preserves the rename** — names are
  cosmetic and editable (LV-01), and `db:seed --force` runs on every deploy. Same `firstOrNew`
  + `if (! $unit->exists)` shape the units and the institution already use.

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter ReferenceSeederTest 2>&1 | tail -15
```

- [x] **Step 3: Seed**

In `ReferenceSeeder::run()`, after the institution block (so `$institution->id` is available):

```php
        // The training-level ladder (Munawib LV-01; owner decision 1, 2026-08-08). P0c seeded
        // none deliberately — "the QCH level set is departmental data the owner supplies" — and
        // that decision has since been made. Names, codes, order and both flags are all
        // administrator-owned data AFTER this seed: everything below is written on CREATE only,
        // so a rename survives `db:seed --force`.
        //
        // display_order is explicit and gapped by ten. The migration's default is 1000, and five
        // levels sharing it would make Level::nextAfter()'s id tie-break the entire ladder —
        // "advance one level" would then be undefined, which is exactly what LV-03 depends on.
        $levels = [
            'R1' => ['name' => 'Resident 1', 'display_order' => 10, 'external' => false, 'terminal' => false],
            'R2' => ['name' => 'Resident 2', 'display_order' => 20, 'external' => false, 'terminal' => false],
            'R3' => ['name' => 'Resident 3', 'display_order' => 30, 'external' => false, 'terminal' => false],
            // Terminal: an R4 graduates rather than advancing. Not derived from "highest order",
            // which would silently graduate every R4 the day an R5 is added.
            'R4' => ['name' => 'Resident 4', 'display_order' => 40, 'external' => false, 'terminal' => true],
            // External people are named on a rota but are not on this ladder (Munawib PE-03).
            'EXT' => ['name' => 'External', 'display_order' => 90, 'external' => true, 'terminal' => false],
        ];

        foreach ($levels as $code => $attributes) {
            $level = Level::firstOrNew(['code' => $code]);

            if (! $level->exists) {
                // institution_id is set here and only here: the P0d backfill migration
                // deliberately skipped `levels` (it had no rows to backfill).
                $level->fill($attributes + ['active' => true, 'institution_id' => $institution->getKey()]);
                $level->save();
            }
        }
```

with `use App\Models\Level;` added.

- [x] **Step 4: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter ReferenceSeederTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

```bash
git add database/ tests/
git commit -m "feat: the ladder the department already climbs, written down"
```

---

### Task 8: Levels CRUD

**Files:**
- Create: `app/Http/Controllers/Admin/LevelController.php`
- Create: `app/Http/Requests/Admin/LevelRequest.php`
- Create: `resources/js/Pages/Admin/Levels.vue`
- Modify: `routes/web.php`, `resources/js/Layouts/AppLayout.vue`
- Test: `tests/Feature/Admin/LevelCrudTest.php`

LV-01: *"names cosmetic and editable"*. The screen offers create, rename, reorder, toggle
`external`, and **deactivate — never delete**. (The plan's own text also names a `terminal`
toggle here — dropped per Owner Decision A; see the execution note below.)

- [x] **Step 1: Write the failing test** — AMENDED per Owner Decision A: no `terminal` toggle
  and no "last active non-terminal level" guard (see execution note below).

Create `tests/Feature/Admin/LevelCrudTest.php`. Cover:

- the index renders `Admin/Levels` with the five seeded levels in order, behind
  `cap:structure.manage`, and a resident gets 403;
- create, rename, reorder, and both flag toggles work and are audited by id and field name;
- `code` is UNIQUE outright (`levels.code`, institution-blind by design — the migration's own
  docblock explains why), so a duplicate is a **validation error**, not a 23000;
- **a level that has history cannot be deleted, and the screen says so.**
  `person_levels.level_id` is `restrictOnDelete` (`2026_08_10_120002:44-46`); the controller must
  not offer a destroy route at all, and the screen's retire button carries the sentence *"a level
  is never deleted once anyone has held it — past history still resolves through it"*;
- deactivating a level removes it from the pickers P1c will build but leaves `person_levels`
  untouched — assert the count;
- an out-of-range `display_order` is refused.

- [x] **Steps 2-5**

Mirror Task 4's shape exactly: FormRequest → controller (`index`, `store`, `update`,
`setActive`) → routes inside the `admin/structure` group → `Levels.vue` following `Units.vue`'s
mobile-cards-plus-desktop-table layout → a `Levels` nav link beside `Units`, both behind
`can('structure.manage')` → `tests/js/AppLayout.test.js` extended.

Verify:

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter LevelCrudTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
npm test 2>&1 | tail -5
php artisan test 2>&1 | tail -3
```

```bash
git add app/ resources/ routes/ tests/
git commit -m "feat: the ladder is the department's to rename"
```

---

### Task 9: Phase-1 checkpoint

**Files:** none.

The declared seam. At this point:

- units carry UN-02's flags, UN-03's aliases and UN-05's `name2`, and are creatable, renameable,
  recolourable, reorderable, retirable and mergeable from a screen;
- the sidebar is configuration;
- the ladder is seeded, flagged and editable;
- `structure.manage` exists, is in the catalog, the spec, `ROLE_DEFAULTS` and `canAdmin`.

**P1c depends on exactly this and nothing in Tasks 10–13.** Confirm before continuing:

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
npm test 2>&1 | tail -5
npm run test:e2e 2>&1 | tail -5
git status --short
```

Expected: all green, working tree clean. If the plan has to stop, it stops here — not mid-task
and not between Tasks 10 and 11.

**2026-08-09, Task 9 — checkpoint confirmed, one pre-existing doc contradiction found and
closed.** `php artisan test`: 819 passed (0 failures). `npm test`: 111 passed. `npm run
test:e2e`: 17 passed. `npm run build` green, run before the PHP suite. Working tree clean before
this note's own two doc edits.

One coherence problem, not introduced by Tasks 6–8 but live in the tree they landed on:
CLAUDE.md's units paragraph still read *"Two known exceptions, pending: `AppLayout.vue` …
and `app.css` … still hardcode the four units — a fifth department gets no nav entry or hue
until those move to configuration."* Task 3 (before this session) closed that exception weeks
of plan-time ago; CLAUDE.md was never updated to say so, and this plan's own Task 13 has a step
that fixes exactly this sentence — but Task 13 is Tasks 10–13's work, out of scope for this
seam, and CLAUDE.md is project instructions read at the start of every session in the meantime.
Left uncorrected, the checkpoint would hand P1c a CLAUDE.md actively describing units as less
configurable than they now are. Fixed narrowly: the sentence is replaced with what Task 3 built
(`Unit::navList()` via the shared `nav.units` prop, `Unit::BAR_CLASSES`'s eight-entry
offer-and-validate list, `Unit::DEFAULT_BAR_CLASS` as the fallback), and nothing else in
CLAUDE.md was touched — Task 13's other five documents and its other two CLAUDE.md edits (the
`Calendar::flush()` paragraph, the levels vocabulary line) depend on Tasks 10–12 features that
do not exist yet and were correctly left alone.

Also corrected in this plan's own **Definition of done — P1b** section (below): its `levels`
and "level with history" bullets still asserted `terminal` and `Level::nextAfter()` as shipped
requirements, written before Owner Decision A was folded in — the same class of drift Task 1's
own Step 1 test text had (see that amendment). Marked AMENDED in place rather than silently
rewritten, so a reader comparing the plan's original intent against what actually shipped can
still see both.

No other half-finished item was found: `structure.manage` is in the catalog
(`AccessControlSeeder::CATALOG`/`DESCRIPTIONS`/`ROLE_DEFAULTS[0]`), the spec catalog
(`docs/spec/08-foundation.md` lines 36/38, verified present and correct — Task 2's edit held),
and `AppLayout.vue`'s `canAdmin`; both migrations are additive and defaulted; `UnitScopeTest`,
`MissedDaysTest`, `ReferenceSeederTest` and `ReservedUnitCodesTest`'s four/five-unit assertions
are all green untouched. P1c depends on exactly Tasks 1–9 and nothing in Tasks 10–13, and that
holds.

```bash
git add CLAUDE.md docs/superpowers/plans/2026-08-09-p1b-structure-admin.md
git commit -m "docs: the sidebar stopped hardcoding four units two tasks ago"
```

---

**2026-08-09, Task 10 — the Step 6 guard's own needle list, taken literally, both under- and
over-fires against the real tree; widened and allow-listed empirically rather than shipped as
written.** Two real gaps, both found by doing what the plan's own Step 6 text instructs
("prove it empirically… a guard never observed failing is a guard that might not work"), not by
inspection:

1. **Scope.** The plan's skeleton says "walk app/", but its own `ALLOW_LIST` names a
   `database/seeders/` file — which "walk app/" would never reach, so the entry could never be
   exercised either way. Widened the scan to `app/`, `database/`, `routes/`, matching the
   established sibling guards this file's own docblock cites (`CalendarIsTheOnlyConverterTest`,
   `InstitutionProvenanceTest`) — both scan the same three roots for the identical reason
   (`InstitutionProvenanceTest`'s own docblock: "a migration or route closure is a live
   conversion surface too… P1a Task 4 and Task 7 each nearly shipped one").
2. **The needle set.** `'Institution::current()'` is not writer-specific — a plain grep across
   `app/`+`database/`+`routes/` before writing the guard (not guessed) found it also appearing
   as a pure READ in `App\Support\Calendar` itself (`Calendar::settings()`, building the very
   memo the guard protects), and in two unrelated console commands
   (`app/Console/Commands/CreateAdmin.php`, `app/Console/Commands/InstanceShow.php`) that only
   print or attach an id. The same grep showed `ReferenceSeeder.php` writes
   `hijri_offset_days` (Line 189-190) through `Institution::firstOrNew()->save()`, a DIFFERENT
   call shape than `Institution::current()` — so the plan's own three-needle list would never
   have matched the one file its `ALLOW_LIST` names, making that entry inert either way. Added
   `'Institution::firstOrNew('` as a fourth needle so the allow-listed reason ("the seeder runs
   as its own process and exits") is actually exercised, and added
   `app/Support/Calendar.php`, `app/Console/Commands/CreateAdmin.php`,
   `app/Console/Commands/InstanceShow.php` and `app/Http/Requests/Admin/CalendarSettingsRequest.php`
   (which itself reads `Institution::current()` only, to compare against the submitted payload
   for Decision D's lock — it never saves) to `ALLOW_LIST`, each with the reason stated at its
   site, per this project's own convention (`STRTOTIME_ALLOW_LIST`, "each with the reason stated
   at its site"). `test_the_allow_list_is_not_stale` was written to the STRONGER form both
   sibling guards already use (each entry must still match a needle, not merely still exist) —
   the plan's own weaker skeleton text ("assert each path exists") would not have caught the
   `ReferenceSeeder.php` mismatch above.

The empirical-failure step itself (Step 6) also caught a self-inflicted false negative: a
first throwaway probe file's own comment — "// Deliberately NO Calendar::flush() here." —
contained the literal substring `Calendar::flush()`, so the coarse text-scan guard read it as
compliant. Rewritten to describe the omission without naming the method, confirmed the guard
then failed and named the exact file, deleted the probe, `git status` clean. Recorded because it
is the same class of trap `CalendarIsTheOnlyConverterTest`'s own carve-out for `Calendar.php`
already document ("a mention, not a call") — worth remembering for any future guard of this
shape, in this codebase or another.

`php artisan test`: 819 → 848 (29 new: 27 in `CalendarSettingsTest`, 2 in
`CalendarWritersFlushTest`). `npm test`: 111 (unchanged — Task 8's structure.manage-alone case
widened again to assert a "Calendar" link, not a new case). `npm run build` and the full suite
green.

```bash
git add app/Http/Controllers/Admin/CalendarSettingsController.php app/Http/Requests/Admin/CalendarSettingsRequest.php app/Support/PeriodGenerator.php resources/js/Layouts/AppLayout.vue resources/js/Pages/Admin/CalendarSettings.vue routes/web.php tests/Feature/Admin/CalendarSettingsTest.php tests/Feature/Build/CalendarWritersFlushTest.php tests/js/AppLayout.test.js
git commit -m "feat: the calendar is editable, and the module notices when it changes"
```

---

### Task 10: The calendar settings screen (ST-02), and the flush that must follow every save

**Files:**
- Create: `app/Http/Controllers/Admin/CalendarSettingsController.php`
- Create: `app/Http/Requests/Admin/CalendarSettingsRequest.php`
- Create: `resources/js/Pages/Admin/CalendarSettings.vue`
- Create: `tests/Feature/Build/CalendarWritersFlushTest.php`
- Modify: `app/Support/PeriodGenerator.php`
- Modify: `routes/web.php`, `resources/js/Layouts/AppLayout.vue`
- Test: `tests/Feature/Admin/CalendarSettingsTest.php`

ST-02's "every setup step revisitable in Settings", for the calendar step. **Three of this
plan's findings all land in this one task**: the missing `Calendar::flush()` (finding 1), the
unenforced `HIJRI_OFFSET_BOUNDS` (finding 2), and the month-alignment guard (finding 3 /
Decision C). Decision D's hard-lock is here too.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Admin/CalendarSettingsTest.php`. Cover, in this order:

**The flush (finding 1) — the case that would otherwise ship broken:**

```php
    /**
     * Finding 1: `Calendar::settings()` is memoised in a static for the life of the process and
     * `Calendar::flush()` had NO production caller before this screen. Without a flush on save,
     * the redirect that follows the save renders from the pre-save memo — the admin presses
     * Save, the row changes, and the page shows the old value. Under a long-lived worker the
     * stale value outlives the request entirely.
     *
     * Asserted through Calendar's own API, not by reading the column, because the column was
     * never the thing that was wrong.
     */
    public function test_saving_the_offset_takes_effect_within_the_same_process(): void
    {
        $this->seed(ReferenceSeeder::class);

        // Warm the memo the way a real request would.
        $this->assertSame(0, Calendar::hijriOffsetDays());
        $before = Calendar::hijri('2026-07-15');

        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['hijri_offset_days' => -1]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // NO Calendar::flush() here on purpose — the controller must have done it.
        $this->assertSame(-1, Calendar::hijriOffsetDays());
        $this->assertNotEquals($before, Calendar::hijri('2026-07-15'));
    }

    /** The same trap on the holiday memo: weekend days feed dayType(), which is memoised too. */
    public function test_saving_weekend_days_takes_effect_within_the_same_process(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->assertSame([5, 6], Calendar::weekendDays());

        $this->actingAs($this->admin)
            ->put('/admin/structure/calendar', $this->payload(['weekend_days' => [6, 7]]))
            ->assertRedirect();

        $this->assertSame([6, 7], Calendar::weekendDays());
    }
```

**The bounds (finding 2):**

- `hijri_offset_days` of `-3` and `3` are **422 on that field**, and the message names the bound
  and says an offset that large is a wrong timezone or a wrong hospital — the same sentence
  `ReferenceSeeder.php:137-139` already uses, from `Institution::HIJRI_OFFSET_BOUNDS`, never a
  literal;
- `-2, -1, 0, 1, 2` all save;
- a non-integer (`"-1.5"`, `"minus one"`) is refused.

**Month alignment (finding 3 / Decision C):**

- with `period_type = 'months'`, an `academic_year_start` of `2026-01-31` is a **422 on
  `academic_year_start`**, and the message says a calendar-month period system must begin on the
  first of a month;
- with `period_type = 'week_blocks'`, `2026-01-31` **saves** — a block is measured in weeks from
  any date;
- `PeriodGenerator::months(CarbonImmutable::parse('2026-01-31'))` **throws**
  `InvalidArgumentException` directly, so a seeder or console caller is covered too;
- `PeriodGenerator::months(CarbonImmutable::parse('2026-07-01'))` is unchanged — 12 periods,
  365 days, first label "July 2026" (this is `PeriodGenerationTest`'s existing fixture; assert
  it here as well so the guard's blast radius is visible in one file).

**The hard-lock (Decision D):**

- with no `periods` rows, `period_type` and `academic_year_start` both save;
- with any `periods` row present, changing either is **422**, and the message names the unlock
  path ("delete this year's periods first");
- with periods present, `weekend_days`, `hijri_enabled` and `hijri_offset_days` still save —
  they are display and day-type facts, not period identity;
- the index response carries a `locked` boolean so the form can disable the two fields rather
  than let an administrator type into a field that will refuse.

**The rest:**

- `block_weeks` accepts a list of 1–26 integers each 1–8 (mirroring
  `PeriodGenerator::validateBlockWeeks()`'s bounds, referenced not re-typed) and refuses `[]`,
  `[0]`, `[9]`, and 27 entries;
- `weekend_days` accepts a list of distinct ISO weekday numbers 1–7 and refuses `[]`, `[0]`,
  `[8]`, `[5,5]`;
- every save is audited as `calendar_settings_update` with `keys=` naming the changed keys and
  **no values** — `hijri_offset_days=-1` must not appear in the trail's detail;
- a resident is 403; a guest redirects to `/login`;
- **the screen shows no timezone field.** Assert `$page->missing('form.timezone')` and that the
  instance timezone arrives as a read-only `instance_timezone` prop equal to
  `config('app.timezone')`. Owner decision 3 and P1 finding 5: adding one would make it one fact
  in two places.

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter CalendarSettingsTest 2>&1 | tail -15
```

- [x] **Step 3: The month-alignment guard, defined once**

In `app/Support/PeriodGenerator.php`, add above `months()` and call it as the method's first
statement:

```php
    /**
     * A calendar-month period system requires the academic year to begin on the FIRST of a
     * month. Without this, `months()` mislabels: a run starting 2026-01-31 renders its first
     * period as "January 2026" although 27 of its 28 days are in February (P1b finding 3,
     * confirmed by running it).
     *
     * The alternative — relabelling that period "Jan-Feb 2026" — would mean MR-01's "months"
     * system produces periods that are not calendar months, which is a third period system
     * nobody asked for and which breaks the department's own vocabulary ("Block 11", "August").
     * See P1b Decision C.
     *
     * Public and called from BOTH `months()` and the calendar-settings FormRequest, because a
     * rule written once as a validation string and once as a generator guard is two rules that
     * drift — the failure `SignoffPickers` and `AuditChain::canonical()` each carry a docblock
     * about.
     *
     * Week-blocks are deliberately NOT constrained: a block is measured in weeks from an
     * arbitrary date, so any start is legitimate there.
     */
    public static function assertMonthAligned(CarbonImmutable $start): void
    {
        if ((int) $start->format('j') !== 1) {
            throw new InvalidArgumentException(
                'A calendar-month period system must begin on the first of a month; got '
                .$start->format(Calendar::YMD).'. A run starting mid-month produces periods that '
                .'are not calendar months and would be labelled with the wrong one.'
            );
        }
    }
```

- [x] **Step 4: The FormRequest**

`app/Http/Requests/Admin/CalendarSettingsRequest.php`. Points that matter:

```php
    public function rules(): array
    {
        [$min, $max] = Institution::HIJRI_OFFSET_BOUNDS;   // never a literal -2/2 here

        return [
            'hijri_enabled' => ['required', 'boolean'],
            // Finding 2: enforced in exactly one place before P1b, and that place was the
            // seeder. This screen is the other way in.
            'hijri_offset_days' => ['required', 'integer', "between:{$min},{$max}"],
            'weekend_days' => ['required', 'array', 'min:1', 'max:7'],
            'weekend_days.*' => ['integer', 'between:1,7', 'distinct'],
            'period_type' => ['required', Rule::in([Institution::PERIOD_MONTHS, Institution::PERIOD_WEEK_BLOCKS])],
            'block_weeks' => ['required_if:period_type,'.Institution::PERIOD_WEEK_BLOCKS, 'array', 'min:1', 'max:26'],
            'block_weeks.*' => ['integer', 'between:1,8'],
            'academic_year_start' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The two cross-field rules, both of which need more than a rule string:
     *
     *  - Decision C: a months-type year must start on the first of a month, checked through
     *    PeriodGenerator::assertMonthAligned() so the generator and this form share one rule.
     *  - Decision D: period_type and academic_year_start hard-lock once any `periods` row
     *    exists. Changing either after generation orphans every period against a year that no
     *    longer starts where they do — the same species of unrecoverable-from-a-UI change P1
     *    finding 6 records for the day boundary.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();

            if (($data['period_type'] ?? null) === Institution::PERIOD_MONTHS
                && ! empty($data['academic_year_start'])) {
                try {
                    PeriodGenerator::assertMonthAligned(Calendar::parse($data['academic_year_start']));
                } catch (\InvalidArgumentException $e) {
                    $v->errors()->add('academic_year_start', $e->getMessage());
                }
            }

            if (! Period::query()->exists()) {
                return;
            }

            $institution = Institution::current();

            foreach (['period_type', 'academic_year_start'] as $locked) {
                $current = $locked === 'academic_year_start'
                    ? $institution?->academic_year_start?->format(Calendar::YMD)
                    : $institution?->period_type;

                if (array_key_exists($locked, $data) && (string) $data[$locked] !== (string) $current) {
                    $v->errors()->add($locked, 'Periods have already been generated against this '
                        .'setting. Delete this academic year\'s periods first (Structure → Periods), '
                        .'then change it — otherwise every generated period is orphaned against a '
                        .'year that no longer starts where they do.');
                }
            }
        });
    }
```

- [x] **Step 5: The controller**

`index()` renders `Admin/CalendarSettings` with the institution's six calendar values, a
`locked` boolean (`Period::query()->exists()`), the weekday and period-type option lists, and
`'instance_timezone' => config('app.timezone')` as a **read-only display value**.

`update()` follows `SettingsController`'s three beats exactly:

```php
    public function update(CalendarSettingsRequest $request): RedirectResponse
    {
        $institution = Institution::current();

        if ($institution === null) {
            // D11: zero or many institutions means there is no right answer, and guessing would
            // stamp a department's calendar onto a deployment that has not been seeded.
            return back()->with('error', 'This deployment has no single active institution to '
                .'configure. Run `php artisan db:seed --force` first.');
        }

        $data = $request->validated();
        $changed = array_keys(array_filter(
            $data,
            fn ($value, $key): bool => $institution->getAttribute($key) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $institution->fill($data)->save();

        // FINDING 1. Calendar::settings() and ::activeHolidays() are memoised per process and
        // `flush()` had no production caller before this line existed. Without it, the redirect
        // that follows this save renders from the pre-save memo — the admin presses Save, the
        // row changes, and the page shows the old value.
        Calendar::flush();

        AuditLog::record(
            'calendar_settings_update',
            'keys='.(implode(',', $changed) ?: 'none'),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Calendar settings saved.');
    }
```

Route (inside the `admin/structure` group):

```php
        Route::get('/calendar', [CalendarSettingsController::class, 'index'])->name('calendar');
        Route::put('/calendar', [CalendarSettingsController::class, 'update'])->name('calendar.update');
```

- [x] **Step 6: The source-level guard**

Create `tests/Feature/Build/CalendarWritersFlushTest.php`, in the family of
`CalendarIsTheOnlyConverterTest` and `InstitutionProvenanceTest`:

```php
<?php

namespace Tests\Feature\Build;

use Tests\TestCase;

/**
 * Finding 1, made permanent. `App\Support\Calendar` memoises its settings and its holiday list
 * in statics for the life of the process; `flush()` had NO production caller before P1b, which
 * was harmless only while nothing edited calendar configuration at runtime.
 *
 * Any file under app/ that WRITES the institution's calendar columns or a holiday must also
 * call Calendar::flush(). Asserted over the whole match set, never in a foreach that stops
 * guarding once the last offender is fixed.
 */
class CalendarWritersFlushTest extends TestCase
{
    /** Writes that do NOT need a flush, each with the reason stated at its site. */
    private const ALLOW_LIST = [
        // The seeder runs as its own process and exits; nothing renders afterwards.
        'database/seeders/ReferenceSeeder.php',
    ];

    /** Tokens that mean "this file writes calendar configuration". */
    private const WRITE_NEEDLES = [
        'Institution::current()',
        'Holiday::create(',
        'Holiday::query()',
    ];

    public function test_every_calendar_writer_flushes_the_memo(): void { /* walk app/, assert */ }

    /** The allow-list must not outlive its entries. */
    public function test_the_allow_list_is_not_stale(): void { /* assert each path exists */ }
}
```

Prove it empirically, the way P1a's Task 8 amendment records: drop a throwaway controller that
writes `Institution::current()->save()` without a flush into `app/`, watch this test name that
exact file, delete it, confirm `git status` clean. A guard never observed failing is a guard
that might not work.

- [x] **Step 7: The screen**

`resources/js/Pages/Admin/CalendarSettings.vue`, following `Settings.vue` section-by-section:

- **Hijri display** — a checkbox and a number input bounded `-2 … 2` in the markup as well as
  server-side, with the sentence *"Verify against the department's own published calendar across
  a month boundary before trusting a Hijri date on screen"* (`docs/RUNBOOK-DEPLOY.md` already
  carries it) and a live preview of today's Gregorian and Hijri labels **rendered from a
  server-supplied `Calendar::label()` prop**, never computed client-side.
- **Weekend days** — seven checkboxes, Monday-first, labelled by name.
- **Periods** — the period-type radio pair and, for week-blocks, the block-length list; both
  `:disabled="locked"` with the lock reason rendered beside them.
- **Academic year start** — a date input, `:disabled="locked"`, with the month-alignment rule
  stated in help text when months is selected.
- **Instance timezone** — a `<p class="readout">` showing `instance_timezone`, and a sentence
  saying it is set at deployment and is per-instance, not per-department. **No input.**

Nav link "Calendar" beside "Units" and "Levels".

- [x] **Step 8: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter CalendarSettingsTest 2>&1 | tail -5
php artisan test --filter CalendarWritersFlushTest 2>&1 | tail -5
php artisan test --filter PeriodGenerationTest 2>&1 | tail -5
php artisan test --filter GoldenFixtureTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

`GoldenFixtureTest` is the one to watch: `golden.json` is a contract with P2, and
`assertMonthAligned()` runs inside `months()`, which that fixture exercises. Both its month runs
start on the 1st (verified before writing this plan), so it must stay green **without editing
the fixture**. If it goes red, the guard is wrong — not the fixture.

```bash
git add app/ resources/ routes/ tests/
git commit -m "feat: the calendar is editable, and the module notices when it changes"
```

---

### Task 11: Periods — preview, generate, and delete a year

**Files:**
- Create: `app/Http/Controllers/Admin/PeriodController.php`
- Create: `resources/js/Pages/Admin/Periods.vue`
- Modify: `routes/web.php`, `resources/js/Layouts/AppLayout.vue`
- Test: `tests/Feature/Admin/PeriodGenerationScreenTest.php`

**Finding 4: `PeriodGenerator` has zero production callers, so `periods` can never be
populated.** The P1 plan's P1b item 8 asks only for a preview. A preview alone leaves P1d with
no grid columns. This task ships preview **and** commit **and** the delete path Decision D's
hard-lock names as its unlock.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Admin/PeriodGenerationScreenTest.php`. Cover:

**Preview:**

- with `period_type = week_blocks`, `academic_year_start = 2026-07-01`,
  `block_weeks = [4×12, 5]` and a next-year start of `2027-07-01`, the preview returns **13
  periods, 365 days total, block 13 = `2027-06-02 .. 2027-06-30`** — decision 4's arithmetic,
  the same numbers `PeriodGenerationTest::test_week_blocks_final_block_absorbs_the_remainder_before_next_years_start`
  already pins. **Do not copy these from the plan; assert against the generator.**
- with no next-year start supplied, the preview falls back to the nominal 35-day block 13 and
  **says so on screen** — that fallback is a preview convenience, and P1a's Task 8 amendment
  records it as the one place 371 is the correct number;
- with `period_type = months`, 12 periods, 365 days, labels "July 2026" … "June 2027";
- a preview is a **GET-shaped read with no writes**: assert `periods` count unchanged after it;
- `warningsAgainstNeighbours()`' output is surfaced as **warnings, not errors** — a gap or
  overlap against an adjacent persisted year renders and the generate button stays enabled
  (reconnaissance finding 7: the department sets its own start dates).

**Commit:**

- generating writes N `periods` rows with `academic_year`, `kind`, `position`, `label`,
  `starts_on`, `ends_on` and `institution_id` set from `Institution::current()`;
- `academic_year` is **derived deterministically** from the start date (`2026-07-01` →
  `2026-2027`; a January start → `2026`), validated by format, and finding 15 is why: two
  spellings of one year become two non-overlapping year-sets both claiming the same days;
- generating **twice** for the same academic year is refused with a 422 naming the year and the
  delete path, and leaves the existing rows untouched — never a partial re-write;
- the whole commit is one transaction: force a failure on the last period and assert **zero**
  rows landed;
- **finding 14**: an overlap against an adjacent year's persisted periods is caught **before**
  any `Period::create()` and returned as a 422, not as `Period::booted()`'s `RuntimeException`
  reaching the user as a 500. Assert the status is 422 and the message names both labels;
- one summary audit row (`periods_generate`, `year=2026-2027;kind=week_block;count=13`) — ids,
  counts and labels only.

**Delete:**

- deleting an academic year's periods requires the year to be **named in the request** (typing
  the year, not a bare confirm), is refused when P1d assignment rows exist — **the hook**: there
  is no such table yet, so the check is a documented `// P1d: refuse when master_rota_assignments
  references any of these periods` comment plus a test asserting the delete succeeds today and a
  note in the amendments section when P1d lands;
- deletion is audited (`periods_delete`, `year=...;count=N`);
- after deletion the calendar screen's `locked` flag returns false, so Decision D's unlock
  actually unlocks. **Assert that end to end** — a lock whose unlock does not work is a lock with
  no way out.

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter PeriodGenerationScreenTest 2>&1 | tail -15
```

- [x] **Step 3: The controller**

`PeriodController` with `index` (list persisted years + the preview for the configured
settings), `store` (generate) and `destroy` (delete a year). The preview and the commit both go
through **one** private `plan()` method that calls `PeriodGenerator::weekBlocks()` /
`::months()` — never two call sites with two argument sets.

Dates reaching the screen go through `Calendar::label()`, so each period renders Gregorian and
Hijri together (UX-04) and no date arithmetic crosses into `resources/js`.

Routes:

```php
        Route::get('/periods', [PeriodController::class, 'index'])->name('periods');
        Route::post('/periods', [PeriodController::class, 'store'])->name('periods.store');
        Route::delete('/periods/{academicYear}', [PeriodController::class, 'destroy'])
            ->where('academicYear', '[A-Za-z0-9\- ]{1,20}')->name('periods.destroy');
```

- [x] **Step 4: The screen**

`Admin/Periods.vue`: the configured settings echoed read-only with a link to the calendar
screen, a next-year-start input, the preview table (position, label, Gregorian span, Hijri span,
day count), the warnings block (`channel-bar-caution bg-caution-soft text-caution`), the
Generate button, and a per-year list with a type-the-year delete confirmation.

- [x] **Step 5: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter PeriodGenerationScreenTest 2>&1 | tail -5
php artisan test --filter CalendarSettingsTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

```bash
git add app/ resources/ routes/ tests/
git commit -m "feat: the periods table finally has something that writes to it"
```

**2026-08-09, Task 11 — one real plan gap in the commit path's error shape, one interpretive
call the plan states as two bullets that read like a contradiction until reconciled, and two
small deviations from the plan's literal text, all found or made while writing the code.**

1. **`back()->withErrors()` never negotiates 422 JSON — only a thrown `ValidationException`
   does.** Finding 14's own commit bullet says "Assert the status is 422", mirroring Task 4's
   `test_a_reserved_code_is_refused_with_an_http_422_not_a_500` shape (an `X-Inertia`/
   `Accept: application/json` request). That case works there because `Rule::notIn` fails
   inside a `FormRequest`, and Laravel's validator throws `ValidationException` itself, which
   negotiates content type automatically. `PeriodController::store()`'s business-rule refusals
   (duplicate year, real adjacent-year overlap, the transaction's `RuntimeException` backstop)
   are NOT FormRequest rules — they are checks inside the controller body — so the first
   implementation used `Settings`/`CalendarSettingsController`'s own `back()->withErrors()`
   shape and got a 302 HTML redirect even under JSON headers, which the test then discovered
   the hard way: `TestResponse::json()` on that HTML body returned something whose `->all()`
   call fataled, reported by PHPUnit as an *error*, not an assertion *failure* — worth noting
   as its own small lesson, since the wrong diagnosis (test bug) cost more time than the real
   one (controller bug) once traced. Fixed by throwing `Illuminate\Validation\ValidationException::withMessages()`
   at all four of `store()`'s business-rule refusal points and both of `destroy()`'s — the
   *same* class Laravel's own validator throws, so it redirects-with-session-errors for a
   classic Inertia post and returns 422 JSON for an XHR one, without a controller having to
   duplicate that negotiation itself.
2. **The preview bullet ("a gap or overlap against an adjacent persisted year renders and the
   generate button stays enabled") and the commit bullet ("an overlap... is caught... and
   returned as a 422") are not a contradiction once the two are read as covering different
   moments, but the plan does not say so explicitly.** Resolved as: the PREVIEW never disables
   the button for either warning type — the department is always allowed to attempt (finding
   7's "the department sets its own start dates" holds at preview time regardless of what kind
   of warning it is). The COMMIT, when actually attempted, blocks ONLY a genuine day-collision
   (`$firstStart <= $prevEnd` / `$nextStart <= $lastEnd`, the same conditions
   `PeriodGenerator::warningsAgainstNeighbours()` already tests internally, recomputed in
   `PeriodController::overlapAgainstNeighbours()` rather than parsed from its message strings,
   so the two can never drift on what counts as "real") — a pure gap still commits successfully.
   `test_a_gap_against_the_previous_year_does_not_block_generation` and
   `test_a_real_overlap_against_the_previous_years_persisted_periods_is_a_422_not_a_500` pin
   both halves of this reading.
3. **`institution_id` is set from `$request->user()?->institution_id`, not
   `Institution::current()`** as the plan's Step 3 prose literally says. Matches
   `LevelController::store()`'s own established precedent (`app/Http/Controllers/Admin/LevelController.php:44`)
   rather than introducing a second convention for one controller; also avoids adding
   `PeriodController.php` to `CalendarWritersFlushTest::ALLOW_LIST` for a read that carries no
   calendar-configuration meaning at all (periods are not part of `Calendar::settings()`'s
   memoised keys — `Calendar::periodFor()`/`::periodsForYear()` query fresh every call, never
   memoized — so nothing in this controller needed a flush either).
4. **`PeriodGenerator::deriveAcademicYear()` is a new public method the plan's Task 11 file list
   does not name**, needed for finding 15's "derived deterministically from the start date"
   requirement. Derives from the ACTUAL generated run's first start and last end (not a
   month-number heuristic), so it is correct for both period systems without knowing in advance
   which one produced the run: `test_the_derived_academic_year_label_is_two_years_for_a_july_start`
   and `test_the_derived_academic_year_label_is_one_year_for_a_january_start_under_months` pin
   both the two-year and one-year cases the plan's own finding 15 text names.

`php artisan test`: 848 → 867 (19 new). `npm test`: 111 (unchanged — Task 8's structure.manage
nav case widened again to assert a "Periods" link). `npm run build` and the full suite green.

---

### Task 12: Holidays CRUD

**Files:**
- Create: `app/Http/Controllers/Admin/HolidayController.php`
- Create: `app/Http/Requests/Admin/HolidayRequest.php`
- Create: `resources/js/Pages/Admin/Holidays.vue`
- Modify: `routes/web.php`, `resources/js/Layouts/AppLayout.vue`
- Modify: `tests/Feature/Build/CalendarWritersFlushTest.php`
- Test: `tests/Feature/Admin/HolidayCrudTest.php`

P1a built `holidays`, `Holiday::anchoredOn()` and `Calendar::holidaysOn()`/`dayType()`, and its
own docblock says *"The CRUD screen is P1b"*. This is it.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Admin/HolidayCrudTest.php`. Cover:

- index renders `Admin/Holidays` behind `cap:structure.manage`, listing active and inactive
  rules, each with **the concrete Gregorian dates it resolves to this year and next**, computed
  server-side through `Calendar` (a rule stored as "Hijri 10/1" means nothing to an administrator
  until they see it lands on 2027-03-09);
- create a Gregorian rule (National Day, 9/23, recurring) and a Hijri rule (Eid al-Fitr, 10/1,
  `duration_days = 4`) and assert `Calendar::isHoliday()` agrees **without a manual flush** —
  the same finding-1 assertion shape Task 10 uses, extended to `Calendar::$holidays`;
- **a Hijri rule moves when `hijri_offset_days` changes.** `HolidayTest` already proves this at
  the model level (Eid al-Fitr, `2027-03-09` at offset 0 vs `2027-03-10` at offset −1); assert it
  **through the two screens** — save the holiday, save the offset on the calendar screen, and
  confirm the holidays index's resolved dates moved by one day. That is the cross-screen
  interaction neither task's own tests would catch;
- `month`/`day` are validated against the **rule's own calendar**: 1–12 and 1–30 for Hijri,
  1–12 and 1–31 for Gregorian, and a Gregorian 2/30 is refused;
- `duration_days` 1–60, `year` nullable, `calendar` restricted to `Holiday::GREGORIAN` /
  `Holiday::HIJRI` via `Rule::in`;
- deactivating a rule removes it from `Calendar::holidaysOn()` and **does not delete the row** —
  a holiday that was observed last year is history;
- **holiday beats weekend**: a rule landing on a Friday reports `HOL`, not `WE`
  (`Calendar::dayType()`'s documented precedence);
- **`MissedDays` is unaffected.** Owner decision 6 is binding and
  `HolidayTest::test_missed_days_denominator_is_unaffected_by_a_holiday` already pins it — assert
  it again here, through the screen, because this is the first surface from which a user can
  create a holiday and therefore the first place the denominator could visibly move;
- every write audited by id and rule identity (`holiday=<id>;calendar=hijri;md=10-1`), never by
  name — a holiday name is not sensitive, but the by-key habit is the one that keeps values out
  of the trail;
- resident 403, guest redirect.

- [x] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter HolidayCrudTest 2>&1 | tail -15
```

- [x] **Steps 3-5: FormRequest, controller, screen**

Same shape as Tasks 4 and 10. **Every write path calls `Calendar::flush()`** — and
`CalendarWritersFlushTest`'s needle list already names `Holiday::create(` and `Holiday::query()`,
so a path that forgets fails the build rather than shipping a stale day-type.

The screen shows, per rule: name, calendar, month/day (labelled in that calendar's own month
names — `__('calendar.hijri_months')` for Hijri, which `golden.json`'s `hijri_labels` section
already pins), year or "every year", duration, equity-tracked, active, and the resolved
Gregorian dates for this year and next.

Nav link "Holidays".

- [x] **Step 6: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter HolidayCrudTest 2>&1 | tail -5
php artisan test --filter HolidayTest 2>&1 | tail -5
php artisan test --filter CalendarWritersFlushTest 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
npm test 2>&1 | tail -5
npm run test:e2e 2>&1 | tail -5
```

```bash
git add app/ resources/ routes/ tests/
git commit -m "feat: a department writes down its own holidays"
```

**2026-08-09, Task 12 — `CalendarWritersFlushTest` needed no modification (a plan gap already
closed in Task 10), one real test-fixture bug caught by the plan's own cross-screen assertion,
and one design point (how "this year and next" is resolved) the plan left to the implementer.**

1. **The plan's Files list names `tests/Feature/Build/CalendarWritersFlushTest.php` as
   Modified; it was not touched.** `HolidayController.php` contains `Holiday::create(` (in
   `store()`) and calls `Calendar::flush()` in all three write methods — the guard's file-level
   check (finding 1: "somewhere in the same file") is satisfied by construction, and running
   the guard confirms it (`CalendarWritersFlushTest`: 2 passed, unchanged). The plan's own text
   ("CalendarWritersFlushTest's needle list already names `Holiday::create(` and
   `Holiday::query()`, so a path that forgets fails the build") is correct in spirit; the Files
   list simply over-stated what needed editing, likely because it was written before Task 10's
   own amendment widened and corrected that guard's needle set and scan scope.
2. **A test fixture bug, caught by the plan's own cross-screen assertion, not by inspection.**
   `test_a_hijri_rule_moves_across_both_screens_when_the_offset_changes` first used
   `duration_days = 4` (matching `HolidayTest`'s own duration-spanning fixture). At offset 0
   that spans BOTH 2027-03-09 and 2027-03-10, so `assertFalse(Calendar::isHoliday('2027-03-10'))`
   failed immediately — not a defect in the controller, a defect in the test's own borrowed
   fixture, which needed the single-day flip `HolidayTest`'s Eid al-Fitr case (`duration_days =
   1`) actually produces. Fixed by using `duration_days = 1` for this specific test, with a
   comment explaining why, rather than weakening the assertion.
3. **"This year and next"'s resolution is a forward day-by-day scan from today
   (`HolidayController::resolve()`, 750 days, using `Holiday::anchoredOn()` — the SAME method
   `Calendar::holidaysOn()` already walks), not a month-number heuristic.** The plan's Step 4
   text names the requirement ("the resolved Gregorian dates for this year and next") but not
   the mechanism. A scan works identically for BOTH calendars without a special case (Hijri's
   ~354-day year and Gregorian's 365/366-day year both just produce ~1.03 matches per scan
   year), and it reuses `anchoredOn()` rather than adding a second, un-audited resolver — the
   same "one definition of a rule" discipline `PeriodGenerator::assertMonthAligned()` and
   `SignoffPickers` both exist to enforce elsewhere in this codebase.

`php artisan test`: 867 → 886 (19 new). `npm test`: 111 (unchanged — the structure.manage-alone
nav case widened a fifth time, to assert a "Holidays" link). `npm run test:e2e`: 17 passed,
unchanged. `npm run build`, `HolidayTest`, `CalendarWritersFlushTest` and the full suite green.

---

### Task 13: Correct the documents this invalidates

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`
- Modify: `docs/spec/08-foundation.md`
- Modify: `docs/RUNBOOK-DEPLOY.md`
- Modify: `docs/OPEN-DECISIONS.md`
- Modify: `docs/superpowers/plans/2026-08-08-p1-master-rota.md`

Every plan in this project has found the documents wrong. P1b's job is to leave them right.

- [x] **Step 1: `CLAUDE.md`**

Three edits:

- **Domain vocabulary, the units paragraph.** Delete the sentence *"Two known exceptions,
  pending: `resources/js/Layouts/AppLayout.vue` (sidebar nav) and `resources/css/app.css` (hue
  classes) still hardcode the four units — a fifth department gets no nav entry or hue until
  those move to configuration."* Replace with: the nav reads `Unit::navList()` via the shared
  `nav.units` Inertia prop (P1b Task 3), the palette is `Unit::BAR_CLASSES` — eight entries,
  offered and validated from one list, with `channel-bar-slate` the default — and
  `NavUnitsAreConfigurationTest` asserts the model's palette and the stylesheet's rules agree in
  both directions.
- **Non-negotiable rules, the calendar paragraph.** Add: **any write path touching the
  institution's calendar columns or `holidays` must call `Calendar::flush()`** — the module
  memoises settings and holidays per process, and before P1b `flush()` had no production caller
  at all, so a save would have rendered its own redirect from the pre-save memo. Guarded by
  `tests/Feature/Build/CalendarWritersFlushTest.php`. Add too: **a calendar-month period system
  must begin on the first of a month** (`PeriodGenerator::assertMonthAligned()`, called by both
  `months()` and the settings FormRequest from one definition), and **`period_type` /
  `academic_year_start` hard-lock once any `periods` row exists.**
- **Domain vocabulary, a new line for levels.** The ladder is seeded (`R1, R2, R3, R4, EXT`,
  `display_order` 10/20/30/40/90 — explicit and gapped, never the migration's `1000` default),
  `EXT` is `external`, `R4` is `terminal`, and `Level::nextAfter()` is the ONE definition of
  "advance one level" that P1c's LV-03 preview and commit will both read.

- [x] **Step 2: The design doc**

- **§6.1:** flip the "not shipped" wording. UN-02's three flags, UN-03's `aliases` and UN-05's
  `name2` shipped in P1b Task 1 (`2026_08_13_120001`). Record Decision B: there is **no** `color`
  column — `bar_class` is the colour, constrained to `Unit::BAR_CLASSES`. Record that the level
  ladder is now seeded and that `levels` gained `external` and `terminal`.
- **§7:** add that the calendar's memo has a production flush contract now, and name the guard
  test.
- **§9:** no change — P1b adds no anonymous route, and that is worth stating in the P1b row of
  §13 rather than editing §9.
- **§13:** P1b's row gains its real scope and a "shipped 2026-08-09" marker.
- **§14 item 12** (*"`institutions` still has no admin surface"*): **partially closed.** The
  calendar columns are editable at `/admin/structure/calendar`, audited, with the offset bounded
  server-side. `name` and `code` remain env-only and are still uneditable — narrow the item to
  that rather than deleting it.
- **§14, new item:** `Calendar::flush()`'s production contract and the one allow-listed
  non-flushing writer (`ReferenceSeeder`, which exits).

- [x] **Step 3: `docs/spec/08-foundation.md`**

Verify Task 2's two edits are still present and correct after the whole plan has run — the
catalog line and the role-defaults line. This file has been found stale twice; check it, do not
assume it.

- [x] **Step 4: `docs/RUNBOOK-DEPLOY.md`**

Verification queries for the two migrations, and a post-deploy note:

```sql
SELECT code, training_rotation, call_target, clinic_owner, name2 FROM units ORDER BY display_order;
SELECT code, name, display_order, external, terminal, active FROM levels ORDER BY display_order;
```

Expected after `db:seed --force`: four units all `training_rotation = 1`, `call_target = 1`,
`clinic_owner = 0`; five levels `R1 R2 R3 R4 EXT` at 10/20/30/40/90 with `EXT.external = 1` and
`R4.terminal = 1`.

Add to the post-deploy checklist: **`structure.manage` lands on the Administrator role
automatically** (the `applied_role_defaults` marker is per pair and a new key has never been
marked), and **Admin → Structure → Calendar is now the place to verify `HIJRI_OFFSET_DAYS`
reached the container** — the screen shows today's Gregorian and Hijri labels side by side, so
the calibration can be checked against the department's published calendar from the app rather
than from a SQL prompt.

- [x] **Step 5: `docs/OPEN-DECISIONS.md`**

Add the owner items this plan surfaced (below), each with what it blocks and what happens by
default until answered.

- [x] **Step 6: The P1 master plan**

`docs/superpowers/plans/2026-08-08-p1-master-rota.md`'s P1b task list is scoping written before
the tree was read. Add a dated pointer under it: this plan supersedes it, three items changed
(no `color` column — Decision B; periods **generate**, not merely preview — finding 4; the nav
and palette move to configuration in Task 3, which that list did not name at all), and the
`Next plan` section's two P1a outputs P1b must respect were both honoured.

- [x] **Step 7: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

```bash
git add CLAUDE.md docs/
git commit -m "docs: five documents described a department nobody could configure"
```

**2026-08-09, Task 13 — the largest plan-text error found across P1b: Task 13's OWN Step 1 text
asked for a false claim to be written into CLAUDE.md, and it was not written.** Step 1's third
bullet ("Domain vocabulary, a new line for levels") reads, verbatim in the plan as handed to
this session: *"`R4` is terminal, and `Level::nextAfter()` is the ONE definition of 'advance one
level' that P1c's LV-03 preview and commit will both read."* That sentence directly contradicts
this plan's own binding OWNER DECISIONS block (Decision A, at the top of this file) and what
Tasks 6-8 actually shipped: there is no `terminal` column, no `nextAfter()` method, and the
`Owner decisions still needed` section's own item 2 below ("Is `R4` really the terminal level at
QCH?") is likewise a question Decision A already closed outright, not one still open. Writing
either into CLAUDE.md — project instructions read at the start of every session — would have
been a documented falsehood about the tree, the exact "design doc wrong a seventh time" the
task's own framing warned against. Resolved by writing the TRUE state instead (`external` only,
no `terminal`, no `nextAfter()`, P1c's promotion screen takes the target level as explicit
input) into all three touched documents (CLAUDE.md, the design doc, the P1 master plan), and by
**omitting** the stale item 2 from `docs/OPEN-DECISIONS.md` rather than transcribing a question
Decision A had already answered — `docs/OPEN-DECISIONS.md` gained items D and E (which units own
clinics; the next academic year's start date) instead, the two genuinely-still-open items from
this plan's "Owner decisions still needed" section; item 4 (invitation lifetime) was not
re-added there either, since it is already recorded under that file's own
"DECIDED — 2026-08-08 (P1a...)" section and a second copy would be the two-places-one-fact drift
this whole task exists to prevent.

Two further, smaller findings, both from re-reading the plan's own instructions against the
current tree rather than executing them blind:

- **Editing the design doc's §13 sequencing table cell as separate `Edit` calls produced real
  newlines inside a single markdown table cell**, silently breaking the table (a `|`-delimited
  row cannot contain a line break) and leaving a duplicated `**P1c** **P1c**` fragment behind.
  Caught by reading the file back before moving on, not assumed correct because the tool call
  succeeded — fixed by rewriting the whole row as one line. Worth recording because it is a
  trap this specific editing pattern (several sequential edits inside one table row) will
  reproduce identically on any other document with a wide table.
- **`docs/spec/08-foundation.md` needed no edit at all** (Step 3): its `structure.manage`
  catalog line and role-defaults line already correctly named "calendar, periods, holidays" —
  Task 2 apparently wrote description text slightly ahead of what existed at the time, and
  Tasks 10-12 caught up to it rather than the reverse. Verified by grep, not assumed, per the
  task's own "this file has been found stale twice; check it, do not assume it" instruction.

`npm run build` and the full suite green, unchanged at 886 (a documentation-only task).

```bash
git add CLAUDE.md docs/ docs/superpowers/plans/2026-08-09-p1b-structure-admin.md
git commit -m "docs: five documents described a department nobody could configure"
```

---

## Definition of done — P1b

- `php artisan test` passes with **no fewer tests than the 746 this plan started from**, run via
  **Bash** with the PATH export. `npm test`, `npm run build` and `npm run test:e2e` green.
- `npm run build` ran **before** `php artisan test`, or `CompiledCssIsLightOnlyTest`'s artifact
  layer and the print-CSS check skip rather than pass.
- `units` carries `training_rotation`, `call_target`, `clinic_owner` (three independent booleans,
  default false), `aliases` (json, cast to a normalised `list<string>`, source spelling
  preserved) and `name2` — and `name2` reaches **no** client contract.
- `Unit::findByCodeOrAlias()` resolves by code first, then alias, case- and
  whitespace-insensitively; `Unit::findByCode()` is still what routing uses.
- A unit can be created, renamed, recoloured, reordered, reflagged, aliased, retired and
  reactivated from `/admin/structure/units`, every write audited by id and field name and never
  by value; a reserved code is a **422 naming the field**, never the model guard's 500; a
  duplicate code is a 422, never a 23000. There is **no delete endpoint** — asserted, not assumed.
- Two units merge: `handovers`, `handover_signoffs`, `unit_field_definitions` and
  `users.preferred_unit` all re-point; **every signoff collision is named in a preview and
  acknowledged individually before the commit runs**; no clinical row, no signoff and no unit row
  is ever deleted; the whole merge is one transaction proven to roll back whole.
- `resources/js/Layouts/AppLayout.vue` contains **no literal unit array**; `nav.units` is a
  shared Inertia prop built by `Unit::navList()`; a fifth unit appears in the sidebar with its
  own hue and no frontend change; `Unit::BAR_CLASSES` and `resources/css/app.css` are asserted to
  agree **in both directions**.
- `levels` carries `external` only — **AMENDED, Owner Decision A (2026-08-09):** the plan's
  original `terminal` column and `Level::nextAfter()` "advance one level" inference were
  dropped outright (see the Task 6/7/8 amendment notes above). `R1 R2 R3 R4 EXT` are seeded
  with explicit `display_order` 10/20/30/40/90 (never the `1000` default), `institution_id`
  set, `EXT` external and last, and **no level marked terminal — there is no such column.** A
  rename survives `db:seed --force`.
- A level with history cannot be deleted: there is no destroy route to refuse it through, so a
  DELETE attempt is a 405 by construction, never a 500. There is **no** "last active level"
  guard — the plan's original text justified one solely by `Level::nextAfter()` returning null
  for everyone, and that justification is void once Decision A removes `nextAfter()` (see the
  Task 8 amendment note).
- **Saving any calendar setting or any holiday flushes `Calendar`'s memo**, proven by a test that
  calls no `flush()` of its own, and enforced at source level by
  `CalendarWritersFlushTest` — which has been **observed failing** against a deliberately
  non-flushing throwaway file before being trusted.
- `hijri_offset_days` outside `Institution::HIJRI_OFFSET_BOUNDS` is a **422 on that field**, with
  the bound read from the constant and never re-typed as a literal.
- `PeriodGenerator::months()` **throws** on a start date that is not the first of a month, and
  the calendar settings form surfaces the same rule as a field error — one definition
  (`assertMonthAligned()`), two consumers. Week-blocks are unconstrained.
  `tests/fixtures/calendar/golden.json` is **unchanged** — the contract with P2 held.
- `period_type` and `academic_year_start` refuse to change once any `periods` row exists, the
  refusal names the unlock path, and **the unlock is proven to work end to end**.
- `/admin/structure/periods` generates and persists a year of periods: 13 week-blocks from
  `2026-07-01` with `2027-07-01` as the next year's start = **365 days, block 13 =
  `2027-06-02 .. 2027-06-30`**, asserted against the generator rather than copied from this plan.
  Generating twice is refused. An adjacent-year overlap is a **422**, never `Period::booted()`'s
  `RuntimeException` as a 500. Gaps and overlaps against neighbours **warn** and do not block.
- Holidays are creatable in either calendar; a Hijri rule's resolved Gregorian dates move when
  the offset changes, asserted **across the two screens**; a holiday on a Friday reports `HOL`;
  **`MissedDays` counts exactly what it counted before** (owner decision 6), asserted again from
  the new surface.
- Every route added by this plan is behind `auth` + `cap:structure.manage`; every write is
  POST/PATCH/DELETE + CSRF; a refusal is audited by the `cap:` middleware.
- No `dark:` utility, no raw Tailwind palette class and no hex in any markup added by this plan;
  no `bg-panel-soft`; `TextContrastMeetsAaTest` green.
- `resources/js` still contains no date arithmetic — `CalendarIsTheOnlyConverterTest` green with
  its allow-lists unchanged.
- `InstitutionProvenanceTest` green: neither migration adds an index led by `institution_id`, and
  no new query filters on it.
- Both migrations are additive and defaulted; `UnitScopeTest`, `MissedDaysTest`,
  `ReferenceSeederTest` and `UnitConfigurationTest`'s four-unit assertions are all still green
  untouched.

---

## Owner decisions still needed

None blocks P1b. Each blocks a specific later task and has a stated default.

1. **Which units own clinics?** UN-02's third flag is seeded `false` for all four QCH units,
   because clinics do not exist as a concept until P1e and claiming otherwise would be a clinical
   guess. *Blocks:* P1e's CL-01 clinic screen, which needs at least one clinic-owning unit.
   *Default if unanswered:* stays false everywhere, and the P1e plan's first step is an
   administrator ticking the boxes on the units screen — which is exactly what ST-02 is for, so
   this may never need an answer at all.

2. **Is `R4` really the terminal level at QCH?** The ladder is `R1…R4` plus `EXT`, and the
   promotion workflow needs to know where the ladder ends. `terminal` is seeded on `R4`.
   *Blocks:* P1c's LV-03 annual promotion — a wrong terminal marker either graduates a cohort a
   year early or advances one into a level that does not exist. *Default if unanswered:* `R4` is
   terminal, editable on the levels screen at any time before the first promotion runs.

3. **What is the department's next academic year start date?** Decision 4 makes block 13 absorb
   the remainder before the *following* year's fixed start, so generating 2026-2027 correctly
   requires knowing 2027's start. *Blocks:* nothing — Task 11's preview falls back to the nominal
   35-day block 13 and says so on screen. *Default if unanswered:* the fallback, which is the one
   place a 371-day year is the right answer; the year is regenerated once the next start is known.

4. *(Carried forward, unchanged.)* **Invitation lifetime, 7 days or 14?** Settled by round-2
   owner decision 5 — 7 stays the default and becomes admin-configurable. *Blocks:* P1c task 10,
   which builds the configurable setting. Recorded here only so it is not lost between plans.

---

## Stage 1 acceptance (§35), after P1b

> *Accepted:* the pilot's real master rota and clinics live; residents claimed accounts;
> availability summaries match reality.

P1b satisfies none of these and is not meant to. It makes them **configurable**: the rota's rows
are people at levels, and the ladder was an empty table; the rota's columns are periods, and
nothing in the application could write one; a unit was a seed row nobody could add to, rename or
merge; and the calendar the whole thing stands on could only be changed with a SQL prompt.

The three acceptance criteria are met by **P1d** (master rota and availability summaries),
**P1e** (clinics) and **P1c** (claimed accounts). P1c can begin the moment Task 9's checkpoint
is green.

---

## Next plan

**P1c — People, roster and accounts** (PE-01…03, AC-01…04, LV-02…04, ST-04). Four P1b outputs
P1c must respect:

1. **`Level::nextAfter()` is the ONE definition of "advance one level".** LV-03's cohort preview
   and its committing transaction both read it. A second predicate written inside the promotion
   controller is the drift this codebase has three docblocks about.
2. **`levels.terminal` is how the system knows who graduates.** LV-03's *"graduates become
   alumni/inactive, never deleted"* keys off that column, not off "the highest `display_order`".
3. **`structure.manage` is not `people.manage`.** P1c adds its own key; the People screen is
   person-scoped where `Users.vue` is account-scoped, and the two must not be conflated.
4. **`Calendar::flush()` now has a production contract and a source-level guard.** Anything in
   P1c that writes calendar-adjacent configuration — AC-02's configurable invitation lifetime is
   the obvious candidate — inherits it.

And the standing one, unchanged since P1a: `App\Support\Calendar` is the only converter, its
guard test fails the build for any new one, and `tests/fixtures/calendar/golden.json` is a
contract with P2, not a convenience.
