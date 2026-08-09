> ## OWNER DECISIONS — ROUND 2, 2026-08-08 (three more, all binding)
>
> **4. The academic year RESETS to a fixed start date; it does not drift.**
> Blocks run 13 × 4 weeks, but the year always begins on the department's set date, so the
> **final block absorbs whatever days remain** — which is why block 13 is longer. Its length
> therefore **varies year to year** and must not be hardcoded to five weeks. MR-01 explicitly
> supports varying block lengths within a year; the generator must compute the last block from
> the next year's start date rather than from a constant. The plan's 371-day arithmetic and any
> fixture derived from it need revisiting: a year is 365 or 366 days, and block 13 is whatever
> is left.
>
> **5. Invitation expiry is CONFIGURABLE, default 7 days.**
> Munawib AC-02 specifies 14; this codebase uses 7 and keeps 7 as the default, because an
> invitation is a credential — redeeming it creates an account that reaches children's clinical
> records — and a shorter window means a forwarded link is live for less time. A department that
> genuinely needs longer can raise it. Validate the setting (a sane upper bound, an integer, no
> zero-or-negative) so the knob cannot be turned to something absurd. Record the deviation from
> AC-02 in the design doc's override table alongside the others.
>
> **6. The missed-days compliance counter is UNCHANGED.**
> Every day still counts. Making it weekend- and holiday-aware would silently alter every
> historical compliance figure the system has produced — a change in what the number *means*,
> not a refactor, and nothing records which definition produced an earlier figure. P1a must pin
> the current behaviour with a test so the new calendar's day-type knowledge cannot leak into it
> by accident. If this is ever revisited it should be a deliberate, dated change with the old
> figures preserved.

> ## OWNER DECISIONS, 2026-08-08 — READ BEFORE ANY TASK
>
> **1. The level ladder is `R1`, `R2`, `R3`, `R4`, `EXT` (External) — seed it.** This is the
> vocabulary the department already uses: the prototype's rota, its resident database and its
> master rota all key on it. Munawib LV-01 makes level names cosmetic and editable, so these
> are seeded **and stay editable** — code, name, order and the `external` flag are all
> administrator-owned data after the seed.
>
> P0c deliberately seeded **no** levels
> (`2026_08_10_120002_create_levels_and_person_levels.php:16-18`: *"the QCH level set is
> departmental data the owner supplies; inventing one here would be a clinical guess this plan
> has no standing to make"*). That judgement was right at the time and this decision settles
> it. Do not treat the empty `levels` table as still-undecided.
>
> **2. Both period systems ship, configurable per department (MR-01 in full).** Calendar
> months **and** week-measured blocks whose lengths may vary within a year, with
> department-set start dates. **QCH is configured as week-blocks: 13 per academic year, four
> weeks each, block 13 running five weeks** — that is how this department actually schedules
> and how its staff speak ("Block 11").
>
> The owner was told this roughly doubles the calendar and grid work versus months-only and
> chose it anyway. Implement both properly: no "months now, blocks later" shim, and no code
> path that assumes a period is a calendar month.
>
> **3. Dual Gregorian–Hijri dating, yes — and the calendar module lands before any screen
> renders a date.** There is currently **no Hijri conversion anywhere in this repository**;
> this is a build, not a port. Design §7 and Munawib AR-08 require **one** internal calendar
> module applying the instance timezone and a per-department `hijriOffsetDays`, with
> **nothing outside that module converting dates**.
>
> The prototype established the offset as **−1** for this hospital, verified against its
> published calendar across a month boundary. That value is configuration, not a constant:
> `HIJRI_OFFSET_DAYS` defaults to `0` for a new customer and is set to `-1` for QCH.
>
> **Sequencing is part of this decision.** Retrofitting the module after screens exist means
> revisiting every date-rendering site, which is exactly what AR-08's rule exists to prevent.
> Nothing in P1 that renders, filters, groups or arithmetics a date may precede P1a.

---

# P1 — Munawib Stage 1

## Master plan, and **P1a (Calendar, Periods, Holidays)** in full

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Munawib Stage 1 (§35) — the setup wizard, units, levels with bulk operations and
annual promotion, people, invitations, roles, the master rota (both period systems, split
periods, vacations, import/export, publish view), clinics, and holidays.

**The headline from reconnaissance:** the storage layer for people and level history exists
and is tested; **the operational layer above it is entirely empty.** `Level`, `PersonLevel`
and `Person::levelAt()` have zero callers outside their own test file. There are no `people`
or `levels` routes. `app/Policies/` does not exist (recon called it an empty directory — it is
absent altogether). `levels` has never been seeded. P1 builds that layer, and builds the
calendar the layer stands on.

**Recommendation, stated up front: P1 does not fit in one plan. It splits into five.** See
[The split](#the-split-p1a--p1e). This document carries the findings, the decisions and the
split for all of P1, and then **P1a in executable detail**. P1b–P1e get their own plans, each
written when its predecessor merges — the convention design §13 sets and P0a–P0d followed.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 3 + Vue 3, PHPUnit 12 (SQLite in-memory), Vitest,
Playwright, Tailwind 4 via `@theme`, MySQL 8.4 in production. `ext-intl` (ICU 77.1 locally,
installed in the image at `Dockerfile:76` and in CI at `.github/workflows/ci.yml:30`) supplies
Umm al-Qura Hijri conversion with **no new dependency**.

**Scope of P1a specifically:** one calendar module, the per-department calendar configuration
it reads, both period systems, the holiday model and day-type resolver, the absorption of
every existing date conversion into the module, and the first dual-dated screens. No new
route, no new capability, no new admin surface — those begin in P1b.

**What P1a is NOT.** It does not change what `MissedDays` counts. Introducing weekends and
holidays into that denominator changes **every historical compliance figure** the system has
ever produced (recon calendar report, risk 6). That is a data-meaning change requiring an
owner ruling, and it is deferred with the ruling named, not smuggled in behind a refactor.
P1a routes `MissedDays`' arithmetic through the module and leaves every calendar day counting.

---

## Amendments made during execution

*(Empty at plan time. Follow the P0c/P0d convention: when a task turns up something the plan's
enumeration missed — a site not listed, a test that goes red for a reason the plan did not
predict, a behaviour that differs between SQLite and MySQL or between UTC and Asia/Riyadh —
record it here, dated, with what was found and how it was resolved. Findings caught
empirically rather than by inspection are the ones worth writing down.)*

**2026-08-09, guard-hardening follow-up (reviewer findings I1/I2/M2/M3 on Task 4/7/8's own
guards and fixture) — widening a source-level guard's file-scope to a directory it had never
scanned before turns up prose false positives in that directory's own docblocks, not just real
violations.** `InstitutionProvenanceTest::test_no_query_filters_on_institution_id` scanned
`app_path()` only, and its regex missed `orWhere(`/`firstWhere(` (case), the array form
`where([...])`, and every migration `index()`/`unique()` — Task 4 and Task 7's own D11-safe
migrations record that both were caught avoiding the mistake by luck, not by this test. Fixed
by walking `[app_path(), base_path('database'), base_path('routes')]`, making the query-token
match case-insensitive, adding the array form, and adding a second pattern for a composite
`index([...])`/`unique([...])` naming `institution_id`. The one legitimate
`whereNull('institution_id')` site (the backfill migration, 2026-08-11) is named on an
allow-list with a staleness test mirroring `CalendarIsTheOnlyConverterTest`'s existing pattern,
not silently skipped. Running the widened scan for the first time immediately tripped on
`database/migrations/2026_08_12_120002_create_periods_table.php`'s own docblock (Task 7's
amendment above already explains the schema decision using the literal example text
`` `where('institution_id', ...)` `` as prose, in backticks, illustrating exactly the call NOT
to write) — not a real violation, the code itself is clean, but the comment's example syntax
matched the same regex the code would. Reworded the docblock to describe the forbidden call
without reproducing its literal syntax (`a future query filtered on institution_id`), the same
fix shape `CalendarIsTheOnlyConverterTest`'s own strtotime allow-list already uses for
Calendar.php's self-referential docblock — confirmed via `git diff` that the migration's
behaviour (nothing outside `up()`/`down()` changed) is untouched.

For `CalendarIsTheOnlyConverterTest`, added a fourth PHP-side check (`Carbon::parse`,
`CarbonImmutable::parse`, `new DateTime`, `DateTime::createFromFormat` over `app/` and
`routes/`) with its own three-entry allow-list (`EncryptedDateTime.php`, `AuditChain.php`,
`routes/console.php` — the same three Calendar.php's own docblock already names) and staleness
test, and widened `JS_DATE_NEEDLES` with `toLocaleDateString(`/`toLocaleTimeString(` (which
`toLocaleString(` does not substring-match — confirmed by inspecting the two strings
character-by-character), `Date.now(`, `Date.parse(`, `Date.UTC(`, `Intl.DateTimeFormat`,
`getTimezoneOffset(`. Also widened the two PRE-EXISTING PHP checks (ICU symbols, `strtotime()`)
from `app/` alone to `[app_path(), base_path('database'), base_path('routes')]` — not asked for
directly, but the same scope-narrowness I1 proved was live in the sibling guard, and a
`grep -rln` before touching the test files confirmed zero hits for either needle set in
`database/`/`routes/` today, so widening carried no risk of an unrelated red build. Every new
and widened check was proven empirically, not just written: a throwaway probe file for each of
`orWhere`/`firstWhere`/`where([...])`, a migration-style `unique(['institution_id', ...])`, a
`Carbon::parse()` call outside the allow-list, and a `toLocaleDateString(` call was dropped into
the scanned tree, the corresponding test observed to fail listing that exact file, then removed
— confirmed `git status` clean afterwards.

`tests/fixtures/calendar/golden.json`'s `hijri_month_boundary._description` (M2) had the offset
0/-1 reading backwards — 2026-07-15 is the FIRST day of Hijri month 2 (Safar) at offset 0, not
"the last day of Hijri month 1 ... at offset 0" as written, and an unedited authoring aside
("(Muharram... actually Safar)") had shipped in the prose; the `offset_0`/`offset_-1` VALUES two
lines below were already correct, only the sentence explaining them was inverted. Corrected the
prose; no value changed.

M3's fixture gaps: added an explicit `version` field (1) plus a `test_fixture_declares_a_version`
assertion; made `duration_days`, `year`, and each holiday `expect[].settings.weekend_days` entry
in `golden.json` explicit rather than relying on `GoldenFixtureTest.php`'s `?? 1`/`?? null`/
`?? [5, 6]` fallbacks, and removed those fallbacks from the PHP consumer so a future edit that
drops a key now fails loudly (undefined array key) instead of silently reverting to a default a
TypeScript mirror could never see; added a `parse_rejects` section (`"+5 years"`, `"2026-02-30"`)
asserted against both `Calendar::parse()` (throws) and `::tryParse()` (returns null); added a
`hijri_labels` section (the `lang/en/calendar.php` month-name vocabulary) asserted equal to
`__('calendar.hijri_months')`; and added a second `week_block` `period_runs` entry crossing a
leap-year boundary (`starts_on 2027-07-01`, `next_year_starts_on 2028-07-01`) — the case a
mirror hardcoding the 365-day run's 29-day final block could pass without ever exercising
decision 4's actually-varying length. Verified by RUNNING `PeriodGenerator::weekBlocks()` via
`php artisan tinker` rather than computed by hand, matching Task 8's own established discipline
above: block 13 = `2028-06-01..2028-06-30` (30 days, not 29 or 35), total 366 days (2028 is a
leap year; blocks 1-12 stay fixed 28-day spans regardless of where the leap day falls inside
them, so the whole 366-vs-365 day difference against the primary case lands on block 13, which
absorbs whatever remains before the next year's fixed start per decision 4).

`php artisan test`: 740 → 746 (6 new tests: 1 on `InstitutionProvenanceTest`, 2 on
`CalendarIsTheOnlyConverterTest`, 3 on `GoldenFixtureTest`), all green. `npm run test`: 109,
unchanged (no JS test files touched — only PHP-side needle lists that scan `resources/js/`
content). `npm run build` green.

**2026-08-09, Task 9 — the plan's own Step 3 instruction (add the AC-02 lifetime and
missed-days-denominator items to design doc §14 and to `docs/OPEN-DECISIONS.md` as questions)
was written before round-2 owner decisions 5 and 6 resolved both of them, so both landed as
DECIDED entries, not open ones.** The plan's own text for these two items (§14 and the "Owner
decisions still needed" section, both further down this same file) predates the round-2
decisions block at the top of this document — decision 5 settles AC-02 lifetime (7 days stays
default, becomes admin-configurable) and decision 6 settles the missed-days denominator
(unchanged, deliberately). Writing them into §14 as still-open questions, or into
`docs/OPEN-DECISIONS.md`'s "STILL OPEN" section, would have been false as of the tree Task 9
actually ran against — both design doc §14 items 13-14 and `docs/OPEN-DECISIONS.md`'s new
"DECIDED — 2026-08-08 (P1a, round-2 owner decisions 5 and 6)" section record them as resolved,
each with what remains unbuilt (AC-02's configurable-setting UI is P1c scope) rather than what
is undecided. Every other Task 9 claim was checked against the tree before writing: `04-data-
model.md` gained a calendar/periods/holidays addendum (P1a added these, the data-model spec
was silent on them); `05-day-lifecycle.md` and `10-compliance-pwa.md`'s "(Asia/Riyadh)"
parentheticals were softened to "the instance timezone, Asia/Riyadh for QCH today" since
`APP_TIMEZONE` is explicitly not a system constant (Calendar's own docblock, owner decision 3);
`DESIGN-TOKENS.md`'s stale `muted`/`ok`/`caution` hex values, its `laravel/resources/css/`
path (this repo's path has no `laravel/` prefix — that prefix names the READ-ONLY reference
codebase in CLAUDE.md, not this tree), and its "PICU is the only unit hue" claim (self-
contradicted by the same document's own already-correct "Unit hues" section at the bottom)
were all confirmed against `resources/css/app.css` directly before correcting. No claim the
plan asked for was found to be false in a way that required *omitting* it rather than
correcting its wording.

**2026-08-09, Task 8 — the plan's own `golden.json` draft is the same superseded 371-day
arithmetic Task 4's amendment already fixed once, plus a genuinely new leap-year fixture the
plan did not include.** The plan's Step 1 JSON snippet was written from reconnaissance finding
7's pre-decision-4 reading (13 blocks of 4 weeks plus a fixed 5-week block 13), the same
arithmetic Task 4 had already corrected in code and tests. Recomputed by actually running
`PeriodGenerator::weekBlocks()`/`::months()` (via a throwaway test that dumped its output,
deleted before commit — never copied numbers by hand) and reading the result back:

- `week_blocks` (`next_year_starts_on` supplied, `2027-07-01`): count 13, **total_days 365**
  (not 371), last block `Block 13, 2027-06-02 .. 2027-06-30` (not `.. 2027-07-06`) — matches
  `PeriodGenerationTest::test_week_blocks_final_block_absorbs_the_remainder_before_next_years_start`
  exactly, since both were produced by the same corrected generator.
- Added a **second** `week_blocks` case with `next_year_starts_on: null` — the FALLBACK path,
  where the final block legitimately IS the nominal 35-day/371-day-total reading, because no
  next year's start is known yet (a preview convenience, per Task 4's amendment). This is the
  one entry in the whole file where 371 is the *correct* number, and it is labelled as such so
  a future reader does not "fix" it back to 365.
- `months`: count 12, total_days 365, first/last unchanged from the plan's draft — the plan's
  month-run numbers were already correct; only the week-block run was written under the
  superseded arithmetic.
- Added, not in the plan's draft at all: a **leap-year** case (`months` run starting
  `2027-07-01`, position 8 = `February 2028, 2028-02-01 .. 2028-02-29`, 29 days) and a
  **2028-02-29** date-level case (`hijri` `1449-10-04`) — the task instructions asked for a
  leap year explicitly and the plan's original fixture had none.
- Also added beyond the plan's draft, per the task's explicit list of divergence-catching
  cases: a `day_boundary_cases` block reusing `DayBoundaryTest`'s proven
  `2026-08-08T22:30:00Z` instant (UTC day `2026-08-08` vs Riyadh day `2026-08-09`), and a
  `holiday_cases` block covering a Hijri-ruled holiday moving with `hijri_offset_days`
  (Eid al-Fitr, `2027-03-09` at offset 0 vs `2027-03-10` at offset -1) and a holiday span
  crossing a Hijri month end (`HolidayTest`'s Ramadan-tail case, `2027-03-08..11`) — both
  reusing values `HolidayTest` had already proven, not new claims.
- The plan's date-level `cases` (offset 0 and -1, the four dates plus the weekend pair) were
  **already correct** when checked against `CalendarTest`/`DayBoundaryTest` and the generator
  directly — decision 4 only affects period generation, not day-level Hijri/weekend
  resolution, so those entries were kept as the plan wrote them (plus the added leap-year
  entry).

`tests/Feature/Calendar/GoldenFixtureTest.php` loads the JSON and asserts every field against
the live code (94 assertions across 6 test methods); nothing in `golden.json` is asserted only
by inspection.

**2026-08-09, Task 7 — the plan's own migration snippet indexes `['institution_id', 'active']`,
which repeats the D11 mistake Task 4's amendment already caught and fixed on `periods`.** Every
query this task adds (`Calendar::activeHolidays()`) filters on `active` alone, never on
`institution_id` — D11 keeps `institution_id` as provenance/in-instance grouping, never a query
boundary, and `InstitutionProvenanceTest::test_no_query_filters_on_institution_id` is the
source-level guard that would catch a future site that tried. An index led by a column nothing
ever filters on is dead weight and, worse, an invitation for a future `where('institution_id',
...)` "to match the index" — the exact drift the periods migration's own docblock warns against.
Shipped as `$table->index(['active', 'calendar', 'month', 'day'])` instead, covering the columns
`Holiday::anchoredOn()`'s candidates are actually filtered and compared on.

**2026-08-09, Task 6 — three of Step 2's details did not match the tree; the shape was right,
the specifics were not.** (1) `Users.vue:126`'s `new Date(iso).toLocaleString()` was already
applied only to `PendingRegistration::requested_at` (rendered via a `fmt()` helper) — a prior
change had already server-formatted `last_login_at` as `'Y-m-d'` (via `UserManagementController`)
with no client conversion left on that field, so there is no `last_login_display` prop to add;
`requested_at` is what now arrives pre-formatted (`Y-m-d H:i`, replacing an ISO8601 string), and
`fmt()` is deleted entirely rather than partially. (2) `Index.vue`'s replacement is not a flat
`days` array of `{date, hijri, weekend, has_sheet, signed}` — the existing `dates` prop (one
entry per date WITH a sheet) is kept, dual-dated in place, and a second `listing` prop carries
the full day/gap/gap-summary merge `EndorsementController::buildListing()` computes from it via
`Calendar::datesBetween()`, replacing the deleted client computed of the same name one-for-one.
This matches the removed code's actual behaviour (only known-sheet dates plus the gaps between
them are ever shown — never an unbounded enumeration of "every date in the range") more closely
than the plan's literal array shape would have. (3) The guard test itself needed a subtlety the
plan's PHP-side precedent didn't need: `Calendar.php`'s own docblock is allowed to WRITE
`strtotime()` in prose (a carve-out `CalendarIsTheOnlyConverterTest` already has), but the new
`resources/js` guard has NO such carve-out (deliberately — "Allow-list empty at the end of this
task"), so an early draft of this task's own explanatory comments (e.g. "replacing the deleted
`new Date()` computation") tripped the guard on itself; comments in the three touched Vue files
now describe the removed calls in prose without reproducing the literal call syntax.

`previous_date` (Sheet.vue) had no removed client computation to replace — only `next_date` did
(the deleted `nextDate` computed). It is still sent, per the plan's explicit text, and given a
real consumer (a "Previous day" link beside "Day index") rather than shipped as an unused prop.

**2026-08-08, Task 5 — `newDay()`'s `->max('handover_date')` returns a raw, uncast DB scalar,
which `Calendar::parse()`'s Y-m-d-only strictness correctly rejects; caught by the full suite,
not by inspection.** After rewriting `EndorsementController::newDay()`'s
`$sourceDate`/`$isConsecutive` computation to route through `Calendar::ymd()`, seven
`EndorsementTest` cases started 500ing with `InvalidArgumentException: Calendar::parse()
accepts Y-m-d only; got "2026-07-10 00:00:00"`. Eloquent's attribute casts (`'handover_date' =>
'date'`) apply to hydrated MODEL instances, not to the scalar returned by an aggregate query —
`Handover::where(...)->max('handover_date')` returns the column's raw stored value
(`'Y-m-d H:i:s'` on this schema) unconverted. The old `Carbon::parse()` accepted that shape
silently; `Calendar::parse()` is deliberately stricter (the same strictness that rejects "+5
years"), so it is correct to reject it — the bug was in the CALLER supplying an uncast value,
not in Calendar. Fixed by replacing `->max('handover_date')` with
`->orderByDesc('handover_date')->first()`, so the value passes through the model's cast layer
before reaching `Calendar::ymd()`, matching every other `handover_date` read in the file. No
behavioural change (same date is selected; only a scalar aggregate became a full model fetch).
Recorded because it is a general hazard for anything migrating a raw-aggregate query onto
`Calendar`, not specific to this one call site.

**2026-08-08, Task 4 — the plan's own week-block fixtures were written before round-2 owner
decision 4 and are wrong; recomputed.** Task 4's Step 1 test list (and the Step 6 commit
message) describe a run of 13 blocks — twelve of four weeks plus a fixed five-week block 13 —
spanning 371 days, taken verbatim from reconnaissance finding 7's pre-decision reading. Decision
4 (round 2, binding) settles finding 7's open question the other way: the academic year resets
to a FIXED start date each year, so block 13 absorbs whatever remains before the FOLLOWING
year's start, and its length varies year to year. `PeriodGenerator::weekBlocks()` was written
to this corrected shape, not the plan's: it takes an optional `?CarbonImmutable $nextYearStart`
absent from the plan's Step 4 signature. When supplied, the final block's `ends_on` is computed
as the day before it — the last entry of `$blockWeeks` becomes a nominal fallback used only when
`$nextYearStart` is not yet known (previewing a year before the next one is configured). Worked
example for the QCH shape (13 blocks, start 2026-07-01, next year fixed at 2027-07-01): blocks
1-12 are each exactly 28 days; block 13 is **2027-06-02 .. 2027-06-30, 29 days** — not 35 — and
the whole run is **365 days**, not 371. `tests/Feature/Calendar/PeriodGenerationTest.php` pins
both the corrected fixture and the no-next-year-known fallback (35 days, matching the plan's
original nominal assumption, kept as the fallback behaviour rather than discarded).

**2026-08-08, Task 4 — the orphaned migration's unique index broke the D11 "institution-blind"
pattern; caught by the existing `InstitutionProvenanceTest` guard.** The migration left
uncommitted by the disconnected session (`2026_08_12_120002_create_periods_table.php`) declared
`unique(['institution_id', 'academic_year', 'position'])`. Every other compound unique index in
this schema deliberately omits `institution_id` (`people.short_name`, `levels.code`,
`handover_signoffs(unit_id, handover_date)`, each with a comment citing D11: one database is one
customer, so a plain unique is both honest and enforceable, and a composite including
`institution_id` would be toothless for the null-institution bootstrap/fixture rows it is
usually NULL for). `Period::booted()`'s overlap-guard closure, written to match the migration,
then filtered `->where('institution_id', $period->institution_id)` — a real query filter on
`institution_id`, which `tests/Feature/Identity/InstitutionProvenanceTest.php`'s
`test_no_query_filters_on_institution_id` exists specifically to catch (D11: the isolation
boundary is the database, not the row; a `where('institution_id', ...)` anywhere in `app/` fails
that guard by source-level regex, regardless of intent). Caught empirically: the guard went red
on the first full-suite run after Task 4's implementation. Fixed both the migration (unique on
`academic_year, position` only, matching precedent, with a docblock explaining why) and
`Period::booted()` (overlap check scoped by `academic_year` only). `institution_id` remains on
the table as a nullable, non-unique provenance/grouping column, consistent with D11.

**2026-08-08, Task 2 — the strtotime() allow-list needed two more entries than finding 2
enumerated.** Writing `CalendarIsTheOnlyConverterTest`'s guard against the actual tree (not
against finding 2's list) turned up `strtotime(` in two files reconnaissance did not name:
`app/Console/Commands/LegacyReconcile.php:97` (diagnostic count of unparseable legacy date
headers, read-only, never runs against live data) and `app/Support/Plausibility.php:95`
(`plausibleDates()`, a boolean ordering comparison over two legacy admission/discharge
strings during import validation, not a conversion feeding a screen). Both are import-adjacent
to the same one-way legacy pipeline `LegacyImport.php` was already exempted for, so both were
added to `STRTOTIME_ALLOW_LIST` alongside it, each with a comment naming why. Task 5 (not in
this scope) still shrinks the list — its own text says "to `LegacyImport` alone," which now
needs re-reading against these two additional entries when that task is planned. Also added:
`Calendar.php` itself is excluded from its own guard, because its docblock names `strtotime()`
in prose to explain the exact leniency trap it replaces — a mention, not a call — the same
carve-out the IntlCalendar-symbol check already needed.

**2026-08-08, Task 3 — the whole-suite `Asia/Riyadh` flip is GREEN; kept.** Added
`<env name="APP_TIMEZONE" value="Asia/Riyadh"/>` to `phpunit.xml` and ran the full suite under
Bash (not PowerShell — its PATH lacks `openssl`, and the backup tests self-skip there rather
than fail, which would have made a false "green" indistinguishable from a real one). Verified
the flip was genuine, not the config-only trap CLAUDE.md warns about, with a throwaway test
asserting `date_default_timezone_get() === 'Asia/Riyadh'` mid-run (it passed, then was
deleted — not part of the delivered suite) and by confirming the backup tests (19 tests, 116
assertions, ~9.8s) actually executed rather than calling `markTestSkipped()`. Full suite: 697
tests, 697 passed, 0 skipped, both before and after the flip — identical counts, so nothing
silently dropped out. This is a genuine finding worth stating plainly: 651 tests existed
before this plan, they were written and have been running at UTC for the project's whole
history, and none of them encodes a UTC-only assumption that breaks at +03:00. That is a
property of how the existing suite was written (relative dates, `now()`-relative fixtures,
no test asserting a literal UTC clock-time), not evidence the module built in Task 2 is
untested at the boundary — `DayBoundaryTest` (Task 3, Steps 1-2) is what actually exercises
the 00:00-03:00 disagreement window; the whole-suite flip is corroborating evidence on top of
that, not a substitute for it.

**2026-08-08, Task 1 — `Institution::$attributes` needed all six calendar defaults, not just
the two JSON columns.** The plan's Institution model snippet gives casts and constants but no
`$attributes` default array. `hijri_enabled`/`hijri_offset_days`/`period_type` DO carry a
column-level DB default (unlike the JSON columns), but Eloquent never reads a DB-applied
default back into an in-memory model after INSERT — a freshly `create()`d, not-yet-reloaded
Institution instance reports `null` for those columns until re-fetched. Added all six
(`hijri_enabled`, `hijri_offset_days`, `weekend_days`, `period_type`, `block_weeks`) as
`protected $attributes` on the model — JSON columns pre-encoded as their raw string form,
matching what the migration's own backfill writes — so a freshly created row matches the
documented defaults without requiring a caller to know to call `->fresh()` first. This mirrors
finding 4's rationale for the JSON-only pair, just extended to keep every calendar default
defined in one place rather than splitting it across the DB layer and the model layer by
column type.

---

## Fifteen findings from reconnaissance that shape P1

Read these before any task. Each is verified against the tree at `8886f8d`, not inferred.

1. **The whole PHP suite runs at UTC while production runs at +03:00, and no test crosses a
   day boundary at a positive offset.** `phpunit.xml:21-34` declares fourteen `<env>` entries
   and `APP_TIMEZONE` is not among them. Any calendar module built here is "proven" at +00:00
   and shipped to +03:00. `tests/Feature/Auth/ShiftClockTest.php:20` passes
   `config('app.timezone')` explicitly, which under test is UTC, so it proves nothing about
   Riyadh. CLAUDE.md already records the general form of this trap: a test that only calls
   `config(['app.timezone' => …])` does **not** move PHP's default timezone, so `now()` and
   `Carbon::parse()` do not move either. **Task 3 fixes this properly**, and it comes before
   any date-rendering task.

2. **There are five independent date-conversion paths today, and they agree only by
   accident.** Laravel calls `date_default_timezone_set(config('app.timezone'))` at boot
   (`LoadConfiguration.php:65`), so the bare `strtotime()`/`date()` pairs in
   `EndorsementController::normalizeDate()` (`:1269-1279`) and `parseDateOrToday()`
   (`:1281-1289`) currently agree with `now()`. They are not broken today — they are a second
   implicit converter outside any module, which is precisely what AR-08 forbids. The other
   three: `MissedDays.php:26-56`, `LegacyImport.php:457-478`, and four hand-rolled JS helpers
   (`Index.vue:106,108-118`; `Sheet.vue:170-180`; `Users.vue:126`).

3. **`strtotime()` leniency already created real backdated clinical rows.** The
   `date_format:Y-m-d` rule at `EndorsementController.php:551-554` exists because
   `strtotime()` accepted `"+5 years"` and `"last monday"`. The calendar module is a
   **normaliser, not a replacement for input validation**: route dates stay regex-pinned
   (`routes/web.php:43,50,52,59,61`), writes keep `date_format:Y-m-d`, and `Calendar::parse()`
   must **throw** on anything that is not exactly `Y-m-d`. Re-admitting leniency behind a
   friendly API resurrects the bug.

4. **`institutions` has nowhere to put calendar configuration.**
   `2026_07_24_120001_create_reference_tables.php:19-25` is `id, name, code, active,
   timestamps`. `units` (`2026_08_08_120001`) carries every per-unit difference but nothing
   temporal. `AppSettings` (`app/Support/AppSettings.php:24-40`) is a global string key/value
   store with no per-department dimension. Every AR-08 config value needs an additive nullable
   migration before the module can read anything.

5. **Timezone has two candidate homes and the owner decision picks one.** `config/app.php:73`
   makes it per-instance env; `docs/munawib/SPEC.md:192-193` puts it in per-department
   settings. Owner decision 3 says *"the instance timezone and a per-department
   `hijriOffsetDays`"* — that settles it: **timezone stays `APP_TIMEZONE`, and no
   `institutions.timezone` column is created.** Do not add one "for symmetry". Under D11
   there is one institution per database, so the two would be one fact in two places — the
   exact drift class that produced the audit-chain false alarm.

6. **Changing the day boundary after the first clinical write is unrecoverable, and a
   configurable calendar makes that hazard reachable from a UI.**
   `create_handover_signoffs_table.php:77` is `UNIQUE(unit_id, handover_date)`;
   `docs/RUNBOOK-PROVISION.md:25` states this is the one setting that cannot be corrected
   afterwards. Because finding 5 keeps the timezone out of the database, no screen in P1 can
   change it — but if a later plan ever adds one, it must hard-lock once any `handovers` row
   exists.

7. **13 four-week blocks with a five-week block 13 is 371 days; a Gregorian year is 365.**
   `[4,4,4,4,4,4,4,4,4,4,4,4,5]` sums to 53 weeks. The generator therefore **must not assume
   the blocks tile a Gregorian year.** It takes a start date plus a list of block lengths and
   computes the end; the next academic year's start date is entered explicitly, and the
   generator reports the gap or overlap between consecutive years as a **warning, not an
   error**. *Owner confirmation wanted (non-blocking): is the academic year deliberately 371
   days, with each year's start drifting ~6 days later, or does the department reset to a
   fixed start date and absorb the difference?* Both are supported; only the warning text
   differs.

8. **`person_levels` has no overlap constraint.** The only unique is
   `(person_id, effective_from)` (`2026_08_10_120002:51`). Two open-ended spans for one person
   can coexist and `Person::levelAt()` (`Person.php:119-129`) silently resolves the later
   `effective_from` with no error. LV-03's promotion must close the prior span inside its own
   transaction; nothing at the DB level will catch it if it does not. **Highest-risk gap for
   the promotion feature** (P1c).

9. **`person_levels` has no batch identity, no reason, no author.** A promotion is not
   addressable or reversible as a unit, and "this cohort advanced on this date" cannot be
   rendered or undone. Adding `promotion_batch_id`/`reason`/`created_by` is an additive
   nullable migration (permitted); retrofitting after the first promotion has run is not.
   **This must land before the first promotion, i.e. in P1c** (P1c task list, item 5).

10. **`Person::levelAt()` is one query per person with no set-wise sibling.** An LV-03 cohort
    preview or an LV-04 history render over a list is N+1 by construction. A
    `levelsAt(Collection, $date)` resolver is needed — sharing **one** predicate definition
    with `levelAt()`, or it becomes the two-copies-of-one-fact drift CLAUDE.md blames for the
    audit-chain false alarm.

11. **`AuditLog::record()` has no batch path, and `AuditAnomalies` will either scream or stay
    silent.** Each call opens its own transaction and takes `lockForUpdate` on the audit tail
    (`AuditLog.php:58-83`); N promoted people is N serialized chain appends. Reusing
    `user_role_change` fires `OpsAlert::critical` once per person
    (`AuditAnomalies.php:83-94,106-109`); a fresh action name goes unmonitored unless
    deliberately added to that list. The established convention is **one summary row plus one
    row per changed item** (`AccessControlController.php:190-208`, rationale at `:197-199`).
    Any batching helper must route through `AuditChain::canonical()`/`::hash()` and never
    re-derive the string.

12. **Set-blind guards fail on bulk operations.** `isLastActiveAdministrator()`
    (`UserManagementController.php:433-447`) asks "does another active admin exist besides
    *this one*" — a bulk deactivation of the last N administrators passes all N individual
    checks and empties the admin set permanently. Same shape as the 2026-07-26 `pickerRule()`
    finding. And `ManagerScope::assertMayTarget()` `abort(403)`s
    (`ManagerScope.php:65`) while auditing the refusal at `:58-63` — inside a transaction the
    audit row unwinds with it, so the attempt vanishes from the trail.
    `InvitationController::store():89-105` already solves both by authorizing the **entire**
    selection in a full pass before any mutation. Copy that ordering.

13. **`bg-panel-soft` has no token and compiles to nothing.** Used at `Users.vue:176`,
    `AcceptInvitation.vue:43`, `StaffPrivacyNotice.vue:25`; `resources/css/app.css:39-91`
    defines no `--color-panel-soft`. New screens use `bg-ground-deep` (table headers, inset
    surfaces) or `bg-panel`. Relatedly `Users.vue:364` has `colspan="7"` on an 8-column table
    — copy `Sheet.vue:75-80`'s computed `desktopColumnCount` instead of hardcoding, which a
    rota grid with a variable column count needs anyway.

14. **`docs/DESIGN-TOKENS.md` is stale; `resources/css/app.css` is authoritative.** The live
    values are `--color-muted: #526d70` (`:48`), `--color-ok: #0c7358` (`:61`),
    `--color-caution: #8f5d13` (`:63`), and four unit hues exist (`:71-74`). The document's
    *rules* — light-only, semantic classes, the conversion map, `rounded-md` everywhere, the
    accessibility floor — still hold.

15. **`npm run build` must precede `php artisan test`, or two build guards silently skip
    rather than pass.** `CompiledCssIsLightOnlyTest.php:70-91` (dark-scheme grep over
    `public/build/assets/*.css`) and the print-CSS check at `:102-130` both skip when no build
    artifact exists. Every verification step in this plan therefore builds first.

**One correction to the reconnaissance itself,** since it will be read alongside this plan:
its bulk-operations report implies new capability keys added to `AccessControlSeeder`'s
`ROLE_DEFAULTS` after first seed may not apply. They do. The `applied_role_defaults` marker is
per `(position, capability_id)` pair (`AccessControlSeeder.php:141-155`), so a **new** key has
never been marked and lands on the next `db:seed`. Only a key an administrator has since
revoked stays revoked — which is the intended behaviour.

---

## Where the design doc and the Munawib spec are wrong about this codebase

| Claim | Reality |
|---|---|
| Design §6.1: *"New: `levels` (LV-01), `user_levels` (LV-04)"* | Shipped as **`person_levels`**, not `user_levels` — a consequence of the D3 reversal that §5 records but §6.1 was never updated for. `levels` exists but is **empty**; §6.1 reads as though the ladder were provided. Owner decision 1 supplies it. Task 9 corrects §6.1. |
| Design §6.1: *"Units also gain Munawib UN-02's three independent capability flags … and UN-03 import aliases"* | **Not shipped.** `2026_08_08_120001_add_configuration_to_units.php` added `display_order`, `active`, `extra_row_fields`, `bed_label`, `consultant_pair`, `consultant_by_label`, `bar_class`, `print_plan_label`, `print_narrative_label` — and nothing else. There is no `rotation`/`call_target`/`clinic_owner` flag, no `aliases`, no `color` beyond `bar_class`, and no `name2` for UN-05. P1b adds all of them. |
| Design §7: *"one `App\Support\Calendar` (PHP) plus a mirrored calendar inside `packages/engine`"* | **`packages/` does not exist**, the repo has no TypeScript toolchain, and no client date library of any kind (`resources/` contains no dayjs/date-fns/luxon/moment). P1a ships **one** implementation and makes the client stop converting entirely — see [Decision A](#decision-a-one-implementation-not-two). The mirror is deferred to P2, where the package and its toolchain are created anyway, and P1a builds the shared fixture corpus that will validate it. |
| Design §7: implies the calendar reads a **per-institution timezone** | Overridden by owner decision 3 and finding 5: timezone stays `APP_TIMEZONE`. |
| Munawib ST-01 lists *"slots and coverage templates from a preset; conditions from a preset"* as setup-wizard steps | Those are **P3 and P2** content (design §13). The Stage 1 wizard covers profile/branding, calendar, level ladder, units, holidays, roster import and invitations, and must say plainly that the slot and condition steps arrive later rather than presenting empty steps. |
| Munawib §5 permits *"link-public"* viewer access, and MR-05 asks for a rota *"publishable to residents"* | D7 and design §9.1: **no unauthenticated route exists anywhere**. MR-05's publish view is a logged-in, `cap:`-gated screen in P1d. Tokenized share links are **P3**, not Stage 1. |
| Munawib AC-02: invitations *"expire (default 14 days)"* | `Invitation::LIFETIME_DAYS = 7` (`app/Models/Invitation.php:18`). One constant, but **an owner decision, not a developer one** — invitation lifetime is a credential-exposure window. Listed in [Owner decisions still needed](#owner-decisions-still-needed). |
| Munawib PE-03: external people *"flagged everywhere"* | The `people.external` column exists and **nothing ever sets it true.** Both writers hard-code `false` (`UserManagementController.php:162`, backfill `120001:113`); `SignoffPickers::rosteredIn()` neither filters nor surfaces it, and `offer()` returns only `{id,name,retired?}`. P1c makes the flag real. |
| Munawib AR-05 `masterRota/{periodId} { assignments: { [personId]: {…} } }` | A Firestore document shape. The relational equivalent (design §6.3) is one row per person per period with date-bounded split rows — **never** a JSON blob keyed by person id, which would reintroduce SC-03's whole-document last-write-wins that SC-03 itself forbids. |
| Munawib AR-05 `people/{personId} { levelId, levelHistory: [...] }` | Both a current pointer **and** a history array. This repo deliberately has only the history (`2026_08_10_120002:10-14`: *"a denormalized current pointer beside a history table is two definitions of one fact"*). Keep it that way; finding 10's set-wise resolver is the performance answer, not a pointer column. |

---

## What is already safe — do not spend effort here

Verified against the tree at `8886f8d`.

- **The effective-dated span idiom is proven and tested to the boundary day.**
  `Person::levelAt()` (`Person.php:119-129`) with both bounds inclusive, backed by
  `tests/Feature/Identity/LevelHistoryTest.php` (9 tests). MR-02's date-bounded split
  sub-assignments and P1d's period containment reuse this exact shape — do not invent a
  second one.
- **The invitation → claim pipeline is complete and tested** (`ClaimLifecycleTest`, 13 cases).
  AC-01 is largely done; AC-02's gaps are additive.
- **The bulk-operation shape exists and is correct.** `AccessControlController::updateRoles()`
  (`:108-145`) validates the whole submission, rejects unknown keys, runs every guard across
  the whole set **before any write**, wraps the applies in one transaction, and computes its
  delta inside the transaction so the audit describes what changed rather than what was
  requested. LV-02 and LV-03 copy it rather than inventing.
- **The audit chain, its canonical string and its verifier are settled.** `AuditChain` v3
  hashes the stored datetime byte-verbatim (`:65-67`). The calendar module **never** touches
  it. `Calendar` converts for display and scheduling day-boundary math only.
- **`ext-intl` is present everywhere it needs to be** — locally (ICU 77.1), in CI
  (`ci.yml:30`), and in the production image (`Dockerfile:76`). Umm al-Qura conversion costs
  no new dependency.
- **The frontend conventions are documented and consistent.** Page skeleton, the three write
  idioms, `preserveScroll`/`preserveState`, the `SaveStatus` machine, the table markup,
  mobile-cards-plus-desktop-table, semantic tokens, live-region handling. New screens follow
  `Sheet.vue` / `Users.vue` / `AccessControl.vue`; they do not invent.
- **The reserved unit code guard shipped** (`Unit::RESERVED_CODES`, derived from the router by
  `ReservedUnitCodesTest`). P1b's unit-creation UI is the surface it was written for. Any new
  literal route segment under `/endorsement` extends it **in the same commit**.

---

## Decision A: one implementation, not two

Design §7 prescribes a PHP calendar **and** a mirrored JS calendar. P1a ships **one**.

The client stops converting dates altogether: the server sends pre-formatted labels
(Gregorian and Hijri), enumerated date ranges, and day types as Inertia props, and
`resources/js` performs no date arithmetic at all. This is a stricter reading of AR-08
(*"nothing outside that module converts dates"*), not a weaker one — two implementations are
two definitions of one fact, which is the failure `AuditChain::canonical()` and
`Person::levelAt()` both carry docblocks about.

It also permanently kills the trap the four existing JS helpers each carry a warning comment
about: at +03:00, `toISOString()` rewinds local midnight to the previous date, which silently
broke "Start next day" once already (`Sheet.vue:170-180`, `Index.vue:106`).

**What this defers, explicitly:** UX-05 requires P2's conditions engine to evaluate hints
client-side without a network round trip, and that engine needs client-side date math. The
mirror therefore lands in P2, inside `packages/engine` — where the package, its TypeScript
toolchain and its golden-fixture harness are being created anyway. **P1a builds the shared
fixture corpus now** (Task 8), as JSON, precisely so that mirror has something to be
cross-validated against on the day it is written. Recorded in design §7 by Task 9.

---

## The split: P1a … P1e

P1 as scoped is larger than P0 was in total. P0a was 39k, P0b 19k, P0c 150k, P0d 102k of plan
text; P1 covers a foundational module that does not exist, four new administrative surfaces,
a data grid with two period systems and split assignments, an import pipeline requiring a new
dependency decision, and a setup wizard over all of it. **Written as one plan it would be
unexecutable, and the first task would invalidate half of it.**

The split follows the dependency order, and every boundary is a point where the tree is
deployable and the suite green.

| Sub-plan | Scope | Binding requirements | Depends on |
|---|---|---|---|
| **P1a — Calendar, periods, holidays** | The calendar module; per-department calendar settings; both period systems and the `periods` table; the `holidays` model and day-type resolver; absorption of all five existing converters; the dual-timezone test harness; the shared fixture corpus; first dual-dated screens. **No new route.** | AR-08, ST-06, MR-01 (as data), UX-04 (partial), holidays data model (§30) | P0a–P0d |
| **P1b — Structure administration** | Units CRUD with UN-02 flags, UN-03 aliases, UN-05 secondary name, colour, order, deactivate, **merge**; the level ladder seeded per owner decision 1 plus its CRUD; the calendar/period/holiday settings screens (ST-02); new capability keys and nav. | UN-01…05, LV-01, ST-02, ST-06 | P1a |
| **P1c — People, roster and accounts** | The People screen; PE-01 full field set; PE-02 contact-visibility policy; PE-03 external people made real; LV-02 bulk operations; LV-03 annual promotion; LV-04 history rendering; AC-02 resend and claim status; AC-03 unbinding; AC-04 per-person roles; ST-04 roster import. | PE-01…03, AC-01…04, LV-02…04, ST-04 | P1a, P1b |
| **P1d — Master rota** | `periods` × people grid by level; one unit per person per period; split periods as date-bounded sub-assignments; vacations at week or exact-date granularity; fill-down/across and copy-period; import/export; the publish view with search, level filter and per-person period strip; per-period availability summaries. | MR-01…03, MR-05…07 | P1a, P1b, P1c |
| **P1e — Clinics, setup wizard, demo department** | Clinics and attendees; the weekly clinic map; the setup wizard threading every step above; the removable demo department seed. | CL-01…05, ST-01, ST-03 (Stage-1 subset), ST-05 | P1b, P1c, P1d |

**MR-04 is deliberately absent from P1d.** *"The master rota drives on-call eligibility
automatically"* has nothing to drive until slots and call rosters exist in P3. P1d ships the
rota's data and its screens; the eligibility derivation lands with the thing it feeds, and
P1d's plan must record the hook it leaves rather than build half of it.

**CL-03 is likewise absent from P1e.** *"Clinics feed conditions"* is a P2 condition type.
P1e ships the clinic data those conditions will read.

**Each of P1b–P1e gets its own plan, written when its predecessor merges.** Their task lists
below are scoping, not implementation detail — enough to plan from, not enough to execute.

---

## Migration ordering

P0c used `2026_08_10_*`; P0d used `2026_08_11_120001`. P1a uses `2026_08_12_*` so it sorts
strictly after both:

```
2026_08_12_120001_add_calendar_settings_to_institutions   (Task 1 — additive, nullable)
2026_08_12_120002_create_periods_table                    (Task 4)
2026_08_12_120003_create_holidays_table                   (Task 7)
```

All three are additive. `120001` adds nullable columns with defaults to a table holding at
most one real row per deployment; `120002` and `120003` create new tables. Nothing is
retyped, nothing is dropped, no clinical table is touched. The owner runs production
migrations (CLAUDE.md); Task 9 supplies the verification queries for
`docs/RUNBOOK-DEPLOY.md`.

Later sub-plans continue the sequence: P1b `2026_08_13_*`, P1c `2026_08_14_*`,
P1d `2026_08_15_*`, P1e `2026_08_16_*`.

---

# P1a — tasks

---

### Task 1: The department's calendar configuration gets a home

**Files:**
- Create: `database/migrations/2026_08_12_120001_add_calendar_settings_to_institutions.php`
- Modify: `app/Models/Institution.php`
- Modify: `config/endorsement.php`
- Modify: `database/seeders/ReferenceSeeder.php`
- Modify: `.env.example`
- Modify: `docker-compose.production.yml`
- Test: `tests/Feature/Calendar/InstitutionCalendarSettingsTest.php`
- Test: modify `tests/Feature/Build/DeploymentInvariantsTest.php`

The migration, the seeder read and the **compose passthrough** are one commit. P0d's Task 9
amendment records exactly this defect shipping: `INSTANCE_SLUG` and `INSTITUTION_CODE` were
taught to `config/` and `.env.example` but never added to the compose `environment:` block, so
a value pasted into Coolify had **zero effect** and a throwaway instance configured
`INSTITUTION_CODE=TSA` seeded as `QCH` anyway. A `HIJRI_OFFSET_DAYS` that does not reach the
container renders every Hijri date one day wrong, silently.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Calendar/InstitutionCalendarSettingsTest.php`. Cover:

- a freshly migrated `institutions` row has `hijri_enabled = true`, `hijri_offset_days = 0`,
  `weekend_days = [5, 6]`, `period_type = 'week_blocks'`, `block_weeks = [4,4,4,4,4,4,4,4,4,4,4,4,5]`,
  and `academic_year_start` null;
- the casts return **types**, not strings: `weekend_days` and `block_weeks` are arrays,
  `hijri_enabled` a bool, `hijri_offset_days` an int;
- `db:seed --force` with `HIJRI_OFFSET_DAYS=-1` set writes `-1`;
- `db:seed --force` with the variable **unset** leaves an existing non-zero offset alone (a
  customer's calibration must survive a re-seed — the same defect
  `ReferenceSeeder.php:113-124` already fixed for `name`);
- an offset outside `-2 … 2` is refused at the seeder with a message naming the variable. A
  three-day "calibration" is not a calibration; it is a wrong timezone or a wrong hospital.

Add to `tests/Feature/Build/DeploymentInvariantsTest.php`, beside the existing
`test_instance_and_institution_variables_reach_the_container`:

- `test_hijri_offset_reaches_the_container` — asserts `docker-compose.production.yml`
  contains `HIJRI_OFFSET_DAYS: ${HIJRI_OFFSET_DAYS:-0}` in the `app` service's `environment:`
  block, in `${VAR:-default}` form. The default belongs in the compose file **and** in
  `config/`, because `env('X', 'default')` returns `''` — not `'default'` — for a variable
  that is present but empty, which is what a bare `${HIJRI_OFFSET_DAYS}` would put in the
  container of a deployment that has never set it.

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter InstitutionCalendarSettings | tail -30
```

- [x] **Step 2: The migration**

`database/migrations/2026_08_12_120001_add_calendar_settings_to_institutions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib AR-08 / ST-01: the per-department calendar configuration `App\Support\Calendar`
 * reads. Additive and nullable-or-defaulted throughout; `institutions` holds one real row per
 * deployment (D11), so this cannot be a slow migration.
 *
 * DELIBERATELY ABSENT: `timezone`. Owner decision 3 (2026-08-08) puts the timezone on the
 * INSTANCE (`APP_TIMEZONE`, config/app.php:73) and only `hijri_offset_days` on the DEPARTMENT.
 * A per-department timezone column beside the env var would be one fact in two places, and it
 * would make the handover day boundary — UNIQUE(unit_id, handover_date), uncorrectable after
 * the first clinical write per docs/RUNBOOK-PROVISION.md:25 — editable from a screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // Whether Hijri dates are shown at all (UX-04). Display only — never storage,
            // never a query key, never an audit value.
            $table->boolean('hijri_enabled')->default(true)->after('active');

            // The signed calibration applied to algorithmic Umm al-Qura conversion, verified
            // against the department's OWN published calendar. 0 for a new customer: an
            // uncalibrated offset invented on that department's behalf would be a guess
            // rendered as fact on every screen. QCH sets -1 via HIJRI_OFFSET_DAYS.
            $table->smallInteger('hijri_offset_days')->default(0)->after('hijri_enabled');

            // ISO-8601 weekday numbers (Mon=1 … Sun=7). Numbers, not names: names are
            // locale-dependent and this column is compared, not displayed.
            $table->json('weekend_days')->nullable()->after('hijri_offset_days');

            // MR-01: 'months' or 'week_blocks'.
            $table->string('period_type', 20)->default('week_blocks')->after('weekend_days');

            // MR-01: block lengths in weeks, in order, one entry per block. Lengths MAY vary
            // within a year — QCH is thirteen blocks, the last of five weeks. Ignored when
            // period_type is 'months'.
            $table->json('block_weeks')->nullable()->after('period_type');

            // The first day of the current academic year. Department-set (MR-01); null until
            // the setup wizard or the settings screen supplies it.
            $table->date('academic_year_start')->nullable()->after('block_weeks');
        });

        // Defaults for the JSON columns, which cannot carry one in MySQL.
        \DB::table('institutions')->whereNull('weekend_days')->update([
            'weekend_days' => json_encode([5, 6]),      // Friday, Saturday
            'block_weeks' => json_encode([4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5]),
        ]);
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'hijri_enabled', 'hijri_offset_days', 'weekend_days',
                'period_type', 'block_weeks', 'academic_year_start',
            ]);
        });
    }
};
```

- [x] **Step 3: The model**

In `app/Models/Institution.php`, extend `$fillable` with the six keys and add casts:

```php
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'hijri_enabled' => 'boolean',
            'hijri_offset_days' => 'integer',
            'weekend_days' => 'array',
            'block_weeks' => 'array',
            'academic_year_start' => 'date',
        ];
    }

    public const PERIOD_MONTHS = 'months';
    public const PERIOD_WEEK_BLOCKS = 'week_blocks';

    /** The bounds a calibration may take. Beyond this it is a wrong timezone, not an offset. */
    public const HIJRI_OFFSET_BOUNDS = [-2, 2];
```

Note `Institution::current()` (`:44-49`) already returns null on zero or ≥2 active
institutions. `Calendar` must treat that null as "use the defaults", never as an error —
`RefreshDatabase` runs every test against an empty `institutions` table until something seeds
one, and a calendar that throws there breaks 600+ unrelated tests.

- [x] **Step 4: Config, seeder, env, compose**

`config/endorsement.php`, beside the existing `institution` block:

```php
        /*
         * The department's Hijri calibration, verified against ITS OWN published calendar
         * across a month boundary. Set once at provisioning; changing it afterwards changes
         * every Hijri date the system has ever displayed. QCH: -1.
         */
        'hijri_offset_days' => env('HIJRI_OFFSET_DAYS'),
```

In `ReferenceSeeder::run()`, immediately after the institution `save()`:

```php
        // Written on CREATE, or when HIJRI_OFFSET_DAYS is explicitly provided. NEVER reverted
        // to 0 by a re-seed of a deployment that has since calibrated: `db:seed --force` runs
        // on every deploy, and silently un-calibrating a live department's calendar is the
        // same defect the `name` write above already fixed once.
        $configured = config('endorsement.hijri_offset_days');

        if ($configured !== null && $configured !== '') {
            $offset = (int) $configured;
            [$min, $max] = Institution::HIJRI_OFFSET_BOUNDS;

            if ($offset < $min || $offset > $max) {
                throw new \InvalidArgumentException(
                    "HIJRI_OFFSET_DAYS must be between {$min} and {$max}. Got {$offset} — an "
                    .'offset that large is a wrong timezone or a wrong calendar, not a calibration.'
                );
            }

            $institution->hijri_offset_days = $offset;
            $institution->save();
        }
```

`.env.example`, beneath `INSTITUTION_NAME`:

```
# Hijri calibration for this department, verified against its own published calendar across a
# month boundary. QCH: -1. Leave blank for an uncalibrated new department (offset 0).
HIJRI_OFFSET_DAYS=
```

`docker-compose.production.yml`, in the `app` service `environment:` block beside
`INSTITUTION_NAME`:

```yaml
      # Present-but-empty must not become 0 for a calibrated deployment — the compose default
      # exists for the same reason INSTITUTION_CODE's does (P0d Task 9).
      HIJRI_OFFSET_DAYS: ${HIJRI_OFFSET_DAYS:-0}
```

- [x] **Step 5: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
npm run build 2>&1 | tail -5
php artisan test | tail -5
```

```bash
git add database/migrations app/Models/Institution.php config/endorsement.php \
        database/seeders/ReferenceSeeder.php .env.example docker-compose.production.yml tests/
git commit -m "feat: a department's calendar has somewhere to be configured"
```

> **OWNER ACTION, after this deploys:** set `HIJRI_OFFSET_DAYS=-1` in Coolify for the QCH
> instance, redeploy, and confirm `php artisan tinker --execute="echo
> App\Models\Institution::current()->hijri_offset_days;"` prints `-1`. Until then QCH renders
> Hijri dates one day late. Record the value in `docs/RUNBOOK-DEPLOY.md`'s identifiers table
> alongside `INSTANCE_SLUG` (Task 9).

---

### Task 2: `App\Support\Calendar` — the one converter

**Files:**
- Create: `app/Support/Calendar.php`
- Create: `lang/en/calendar.php`
- Modify: `composer.json`
- Test: `tests/Unit/CalendarTest.php`
- Test: `tests/Feature/Build/CalendarIsTheOnlyConverterTest.php`

- [x] **Step 1: Write the failing tests**

`tests/Unit/CalendarTest.php` — at minimum:

- `parse('2026-08-08')` returns a `CarbonImmutable` at 00:00:00 in the instance timezone;
- `parse` **throws** `InvalidArgumentException` for `'+5 years'`, `'last monday'`,
  `'08/08/2026'`, `'2026-8-8'`, `'2026-02-30'` (PHP silently rolls this to 2026-03-02 — the
  round-trip check is what catches it) and `''`;
- `tryParse` returns null for each of those and a date for a good one;
- `ymd()` accepts a `DateTimeInterface`, a `CarbonImmutable` and a `Y-m-d` string and returns
  the same string for all three;
- `datesBetween('2026-08-01','2026-08-05')` returns exactly five strings, inclusive both ends,
  and returns `[]` when `to` precedes `from`;
- `hijri('2026-08-08')` with offset `0` is `1448-02-25` and with offset `-1` is `1448-02-24`;
- **the month-boundary case, which is the whole reason the offset is applied to the Gregorian
  instant rather than by decrementing a Hijri day number:** `hijri('2026-07-15')` with offset
  `0` is `1448-02-01`, and with offset `-1` is `1448-01-29` — *not* the impossible
  `1448-02-00`;
- `hijriLabel('2026-08-08')` with offset `-1` is `'24 Safar 1448'`;
- `isWeekend()` is true for a Friday and a Saturday and false for a Sunday under the default
  `[5,6]`, and flips correctly when `weekend_days` is `[6,7]`;
- with no institution row at all, every method still works on the defaults and nothing throws.

`tests/Feature/Build/CalendarIsTheOnlyConverterTest.php` — the guard, same species as
`CompiledCssIsLightOnlyTest`. Assert over the whole **set** of matches, never in a `foreach`
that can silently stop guarding (that failure mode is written up at
`CompiledCssIsLightOnlyTest.php:44-52`):

- no file under `app/` other than `app/Support/Calendar.php` contains `IntlCalendar`,
  `IntlDateFormatter`, or `islamic-umalqura`;
- no file under `app/` contains `strtotime(` except an explicit allow-list, which at the end
  of this task is `['app/Http/Controllers/EndorsementController.php',
  'app/Console/Commands/LegacyImport.php']` and which **Task 5 shrinks to `LegacyImport`
  alone**. The test carries the allow-list as a constant with a comment naming why each entry
  is on it, so removing an entry is a deliberate edit rather than a silent widening.

```bash
php artisan test --filter CalendarTest | tail -30
php artisan test --filter CalendarIsTheOnlyConverter | tail -30
```

- [x] **Step 2: `lang/en/calendar.php`**

AR-07 requires externalized strings — no hard-coded UI text — from launch, so the month names
are a language file from day one rather than a later migration:

```php
<?php

/*
 * Umm al-Qura month names, indexed 1..12. Munawib AR-07: strings are externalized from
 * launch so a future locale is translation work, not a rewrite. English-only at launch.
 */
return [
    'hijri_months' => [
        1 => 'Muharram',   2 => 'Safar',       3 => 'Rabi al-Awwal', 4 => 'Rabi al-Thani',
        5 => 'Jumada al-Ula', 6 => 'Jumada al-Akhirah', 7 => 'Rajab', 8 => "Sha'ban",
        9 => 'Ramadan',   10 => 'Shawwal',    11 => 'Dhu al-Qidah', 12 => 'Dhu al-Hijjah',
    ],
];
```

- [x] **Step 3: `App\Support\Calendar`**

```php
<?php

namespace App\Support;

use App\Models\Institution;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlCalendar;
use InvalidArgumentException;

/**
 * Munawib AR-08: ALL date logic flows through this class, applying the instance timezone and
 * the department's hijriOffsetDays. Nothing outside it converts a date.
 *
 * WHAT THIS IS NOT:
 *
 *  - It is NOT input validation. Route dates stay regex-pinned (routes/web.php:43,50,…) and
 *    writes keep `date_format:Y-m-d`. `parse()` THROWS on anything that is not exactly Y-m-d,
 *    because `strtotime()` leniency accepted "+5 years" and created real backdated clinical
 *    rows (EndorsementController.php:551-554). Do not add a lenient sibling.
 *
 *  - It is NOT for audit canonicalization. `AuditChain::canonical()` v3 hashes the stored
 *    naive datetime BYTE-VERBATIM (AuditChain.php:65-67) precisely so no timezone can
 *    reinterpret history. Never route an audit value through here.
 *
 *  - It is NOT for `dob`. `App\Casts\EncryptedDateTime` holds PHI as ciphertext; it cannot be
 *    range-queried or sorted, and its getter can return a string marker (:26-44).
 *
 * TIMEZONE lives on the INSTANCE (`APP_TIMEZONE`), not the department — owner decision 3,
 * 2026-08-08. HIJRI OFFSET lives on the department.
 */
final class Calendar
{
    public const YMD = 'Y-m-d';

    /** Umm al-Qura. ICU ships it; ext-intl is installed in the image and in CI. */
    private const HIJRI_LOCALE = 'en@calendar=islamic-umalqura';

    /** Defaults for a deployment whose institution row does not exist yet. */
    private const DEFAULT_WEEKEND = [5, 6];

    private static ?array $settings = null;

    /** Tests and long-running processes must be able to drop the memoized settings. */
    public static function flush(): void
    {
        self::$settings = null;
    }

    public static function timezone(): string
    {
        return (string) config('app.timezone');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    public static function todayYmd(): string
    {
        return self::today()->format(self::YMD);
    }

    /**
     * Y-m-d ONLY. Throws on anything else, including a well-formed-but-impossible date such
     * as 2026-02-30, which PHP would otherwise roll forward to 2026-03-02.
     */
    public static function parse(string $date): CarbonImmutable
    {
        $trimmed = trim($date);

        try {
            $parsed = CarbonImmutable::createFromFormat('!'.self::YMD, $trimmed, self::timezone());
        } catch (\Throwable) {
            $parsed = null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format(self::YMD) !== $trimmed) {
            throw new InvalidArgumentException(
                'Calendar::parse() accepts Y-m-d only; got "'.substr($trimmed, 0, 32).'". '
                .'Leniency here is what let "+5 years" create backdated clinical rows.'
            );
        }

        return $parsed;
    }

    public static function tryParse(?string $date): ?CarbonImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return self::parse($date);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function ymd(DateTimeInterface|CarbonImmutable|string $date): string
    {
        return self::coerce($date)->format(self::YMD);
    }

    public static function addDays(DateTimeInterface|string $date, int $days): CarbonImmutable
    {
        return self::coerce($date)->addDays($days);
    }

    /** @return list<string> inclusive both ends, empty when `$to` precedes `$from`. */
    public static function datesBetween(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
    ): array {
        $start = self::coerce($from);
        $end = self::coerce($to);

        if ($end->lessThan($start)) {
            return [];
        }

        $out = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $out[] = $day->format(self::YMD);
        }

        return $out;
    }

    public static function hijriEnabled(): bool
    {
        return (bool) self::settings()['hijri_enabled'];
    }

    public static function hijriOffsetDays(): int
    {
        return (int) self::settings()['hijri_offset_days'];
    }

    /**
     * @return array{year:int, month:int, day:int}
     *
     * The offset is applied to the GREGORIAN instant before conversion, never by adjusting
     * the resulting Hijri day number. Decrementing the day number produces 1448-02-00 at a
     * month boundary; shifting the instant produces 1448-01-29, which is the real answer.
     */
    public static function hijri(DateTimeInterface|string $date): array
    {
        // Noon, so no timezone or transition edge can move the day under us. Riyadh has no
        // DST, but this module must be right for any instance timezone.
        $instant = self::coerce($date)->addDays(self::hijriOffsetDays())->setTime(12, 0);

        $calendar = IntlCalendar::createInstance(
            new DateTimeZone(self::timezone()),
            self::HIJRI_LOCALE,
        );
        $calendar->setTime($instant->getTimestamp() * 1000);

        return [
            'year' => (int) $calendar->get(IntlCalendar::FIELD_YEAR),
            'month' => (int) $calendar->get(IntlCalendar::FIELD_MONTH) + 1, // ICU months are 0-based
            'day' => (int) $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH),
        ];
    }

    /** e.g. "24 Safar 1448". Empty string when the department has Hijri display off. */
    public static function hijriLabel(DateTimeInterface|string $date): string
    {
        if (! self::hijriEnabled()) {
            return '';
        }

        $h = self::hijri($date);
        $months = (array) __('calendar.hijri_months');

        return $h['day'].' '.($months[$h['month']] ?? $h['month']).' '.$h['year'];
    }

    /**
     * The one shape every screen renders a date as (UX-04).
     *
     * @return array{date:string, hijri:string, weekend:bool}
     */
    public static function label(DateTimeInterface|string $date): array
    {
        $day = self::coerce($date);

        return [
            'date' => $day->format(self::YMD),
            'hijri' => self::hijriLabel($day),
            'weekend' => self::isWeekend($day),
        ];
    }

    /** @return list<int> ISO-8601 weekday numbers, Mon=1 … Sun=7. */
    public static function weekendDays(): array
    {
        return self::settings()['weekend_days'];
    }

    public static function isWeekend(DateTimeInterface|string $date): bool
    {
        return in_array((int) self::coerce($date)->isoWeekday(), self::weekendDays(), true);
    }

    private static function coerce(DateTimeInterface|CarbonImmutable|string $date): CarbonImmutable
    {
        if (is_string($date)) {
            return self::parse($date);
        }

        return CarbonImmutable::instance($date)
            ->setTimezone(self::timezone())
            ->startOfDay();
    }

    /**
     * Memoized per process. `Institution::current()` returns null on a zero- or
     * multi-institution deployment (Institution.php:44-49) — that is NOT an error here.
     * RefreshDatabase runs every test against an empty institutions table, and a calendar that
     * threw there would take 600+ unrelated tests with it.
     *
     * @return array{hijri_enabled:bool, hijri_offset_days:int, weekend_days:list<int>,
     *               period_type:string, block_weeks:list<int>, academic_year_start:?string}
     */
    private static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $institution = Institution::current();

        return self::$settings = [
            'hijri_enabled' => (bool) ($institution?->hijri_enabled ?? true),
            'hijri_offset_days' => (int) ($institution?->hijri_offset_days ?? 0),
            'weekend_days' => array_map('intval', $institution?->weekend_days ?: self::DEFAULT_WEEKEND),
            'period_type' => (string) ($institution?->period_type ?? Institution::PERIOD_WEEK_BLOCKS),
            'block_weeks' => array_map('intval', $institution?->block_weeks ?: []),
            'academic_year_start' => $institution?->academic_year_start?->format(self::YMD),
        ];
    }
}
```

`Calendar::flush()` must be called from `TestCase::setUp()` beside whatever else resets
static state, or a test that seeds an institution inherits the previous test's memoized
defaults.

- [x] **Step 4: Declare the dependency**

`ext-intl` is installed everywhere but declared nowhere. Add to `composer.json`'s `require`,
after `"php": "^8.3"`:

```json
        "ext-intl": "*",
```

Then `composer update --lock` to refresh the lock hash only. This is not bureaucracy: without
it, a future `composer install` on a host lacking `intl` succeeds and the failure surfaces as
a fatal error the first time a screen renders a Hijri date.

- [x] **Step 5: Verify and commit**

```bash
npm run build 2>&1 | tail -5
php artisan test | tail -5
```

```bash
git add app/Support/Calendar.php lang/ composer.json composer.lock tests/
git commit -m "feat: one calendar module, and a test that says so"
```

---

### Task 3: Prove it at +03:00, not only at UTC

**Files:**
- Create: `tests/Feature/Calendar/DayBoundaryTest.php`
- Modify: `tests/TestCase.php`
- Modify: `phpunit.xml` *(conditionally — see Step 3)*

Finding 1. This task comes **before** any task that renders a date, because a day-boundary
regression at +03:00 is invisible in a suite that runs at +00:00, and the whole point of the
module is to be right about day boundaries.

- [x] **Step 1: A helper that genuinely moves the timezone**

CLAUDE.md is explicit that `config(['app.timezone' => …])` alone proves nothing — it does not
move PHP's default timezone, so `now()` and `Carbon::parse()` do not move either. Add to
`tests/TestCase.php`:

```php
    /**
     * Run a closure with BOTH Laravel's configured timezone and PHP's default timezone moved,
     * restoring both afterwards. Setting only the config is the trap CLAUDE.md records: it
     * leaves date_default_timezone_get() where it was, so now() never moves and the test
     * proves nothing.
     */
    protected function withTimezone(string $timezone, callable $callback): mixed
    {
        $previousConfig = config('app.timezone');
        $previousDefault = date_default_timezone_get();

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
        \App\Support\Calendar::flush();

        try {
            return $callback();
        } finally {
            config(['app.timezone' => $previousConfig]);
            date_default_timezone_set($previousDefault);
            \App\Support\Calendar::flush();
        }
    }
```

- [x] **Step 2: The failing test**

`tests/Feature/Calendar/DayBoundaryTest.php`. Use PHPUnit 12's **attribute** form —
`#[\PHPUnit\Framework\Attributes\DataProvider('...')]`; the `@dataProvider` docblock was
removed in PHPUnit 12 and fails silently with "Too few arguments" (P0d Task 1 amendment).

Cover, over a provider of `['UTC', 'Asia/Riyadh']`:

- at the instant `2026-08-08T22:30:00Z`, `Calendar::todayYmd()` is `2026-08-08` under UTC and
  `2026-08-09` under `Asia/Riyadh` — the 00:00–03:00 window where the two calendars disagree;
- `Calendar::parse('2026-08-08')` produces midnight **in the active timezone**, so its
  timestamp differs by 10800 seconds between the two;
- `Calendar::datesBetween` returns the same list under both (it is calendar arithmetic, not
  instant arithmetic — this is the assertion that catches an implementation that drifted into
  timestamps);
- `Calendar::hijri('2026-08-08')` is identical under both (Hijri conversion is anchored at
  local noon, so it must not move with the timezone);
- a handover written at `2026-08-08T22:30:00Z` files under `2026-08-09` in Riyadh — the
  concrete statement of why `APP_TIMEZONE` cannot change after the first clinical write
  (`docs/RUNBOOK-PROVISION.md:25`).

- [x] **Step 3: Try moving the whole suite to Riyadh, and report honestly**

Add `<env name="APP_TIMEZONE" value="Asia/Riyadh"/>` to `phpunit.xml` and run the full suite.

```bash
php artisan test | tail -20
```

**If it is green, keep it** — the suite now matches production, which is worth more than any
single test.

**If anything goes red, do not "fix" the test to make it pass.** Each failure is either (a) a
genuine production bug that has been invisible because the suite runs at the wrong offset — in
which case record it in *Amendments*, and fix it if it is inside P1's scope or raise it as a
finding if it is not; or (b) a test that hardcodes a UTC assumption, in which case fix the
test. If the red is large or lands outside P1a's scope, **revert the `phpunit.xml` change**,
keep the explicit dual-timezone harness from Steps 1–2, and record in *Amendments* exactly what
went red and why. A suite-wide timezone flip is not worth blocking the calendar module on.

- [x] **Step 4: Verify and commit**

```bash
npm run build 2>&1 | tail -5
php artisan test | tail -5
```

```bash
git add tests/ phpunit.xml
git commit -m "test: the suite has been proving day boundaries at the wrong offset"
```

---

### Task 4: Periods — both systems, neither privileged

**Files:**
- Create: `database/migrations/2026_08_12_120002_create_periods_table.php`
- Create: `app/Models/Period.php`
- Create: `app/Support/PeriodGenerator.php`
- Create: `database/factories/PeriodFactory.php`
- Modify: `app/Support/Calendar.php`
- Test: `tests/Feature/Calendar/PeriodGenerationTest.php`

Owner decision 2. Both systems are first-class; no code path may assume a period is a calendar
month.

- [x] **Step 1: Write the failing test**

`tests/Feature/Calendar/PeriodGenerationTest.php`:

- `PeriodGenerator::months()` from `2026-07-01` produces 12 periods, the first
  `2026-07-01…2026-07-31`, the last `2027-06-01…2027-06-30`, labelled `July 2026` …
  `June 2027`, contiguous with no gap and no overlap;
- February in a leap year ends on the 29th (start the run at `2027-07-01` so `2028-02` is in
  range);
- `PeriodGenerator::weekBlocks()` from `2026-07-01` with `[4,…,4,5]` produces **13** periods
  labelled `Block 1` … `Block 13`; blocks 1–12 span exactly 28 days each; block 13 spans
  exactly 35 days; the whole run spans **371** days and the assertion says `371` explicitly,
  because that number is the finding-7 surprise and a test that computes it from the input
  would hide it;
- consecutive blocks are contiguous: block N's `ends_on` is the day before block N+1's
  `starts_on`;
- `weekBlocks` **rejects** an empty list, a block length below 1 or above 8, and more than 26
  blocks, each with a message naming the value;
- `Calendar::periodFor()` resolves a date inside a block, resolves the **first** and **last**
  day of a block (both bounds inclusive, matching `Person::levelAt()`'s established idiom),
  and returns null outside every generated range;
- persisting a period that overlaps an existing one in the same institution and academic year
  **throws**, and the message names both labels;
- persisting an academic year whose first period starts before the previous year's last period
  ends produces a **warning** (returned by the generator, surfaced by its caller) and not an
  exception — finding 7.

```bash
php artisan test --filter PeriodGeneration | tail -30
```

- [x] **Step 2: The migration**

```php
Schema::create('periods', function (Blueprint $table) {
    $table->id();
    $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

    // e.g. "2026-2027". A department-set label, not a computed one: finding 7 means the run
    // need not align with a Gregorian year and the department names its own years.
    $table->string('academic_year', 20);

    // 'month' | 'week_block'. Stored per row, not read from the institution at render time:
    // a department that switches period systems mid-year must not silently relabel the
    // periods it already scheduled against.
    $table->string('kind', 20);

    $table->unsignedSmallInteger('position');       // 1-based; "Block 11" is position 11
    $table->string('label');                        // "Block 11" / "August 2026"
    $table->date('starts_on');
    $table->date('ends_on');
    $table->timestamps();

    $table->unique(['institution_id', 'academic_year', 'position'], 'periods_year_position_unique');
    $table->index(['starts_on', 'ends_on']);
});
```

`position`, not `index` — `index` is a reserved word in MySQL and a Blueprint method name.

- [x] **Step 3: The model, with the overlap guard**

`app/Models/Period.php`: `$fillable`, casts (`starts_on`/`ends_on` → `date`, `position` →
`integer`), `institution()`, `scopeForYear()`, `scopeOrdered()` (`orderBy('starts_on')`), and
`contains(string|DateTimeInterface $date): bool` delegating to `Calendar`.

The overlap guard is a `saving` model event, because the database cannot express it:

```php
    protected static function booted(): void
    {
        static::saving(function (self $period): void {
            $clash = static::query()
                ->where('institution_id', $period->institution_id)
                ->where('academic_year', $period->academic_year)
                ->when($period->exists, fn ($q) => $q->whereKeyNot($period->getKey()))
                ->whereDate('starts_on', '<=', $period->ends_on)
                ->whereDate('ends_on', '>=', $period->starts_on)
                ->first();

            if ($clash !== null) {
                throw new \RuntimeException(
                    "Period \"{$period->label}\" overlaps \"{$clash->label}\". Two periods "
                    .'covering one day means one person is on two rotations that day, which the '
                    .'master rota grid has no way to render and the call roster no way to resolve.'
                );
            }
        });
    }
```

Note the `whereDate` — not equality. `EndorsementController.php:325-331` documents the live
hazard: a `'date'` cast round-trips from MySQL as `'Y-m-d 00:00:00'`, so equality comparisons
against a `Y-m-d` string never match. Every date comparison in this module uses `whereDate`.

- [x] **Step 4: `App\Support\PeriodGenerator`**

Pure functions returning arrays; **no database writes**, so a preview screen and the committing
caller share one definition. Signatures:

```php
    /** @return list<array{position:int,label:string,starts_on:string,ends_on:string,kind:string}> */
    public static function months(CarbonImmutable $start, int $count = 12): array

    /**
     * @param  list<int>  $blockWeeks  one entry per block, in order; lengths MAY vary (MR-01)
     * @return list<array{position:int,label:string,starts_on:string,ends_on:string,kind:string}>
     */
    public static function weekBlocks(CarbonImmutable $start, array $blockWeeks): array

    /**
     * @param  list<array{starts_on:string,ends_on:string}>  $generated
     * @return list<string> human-readable warnings; EMPTY is the good case
     *
     * Finding 7: thirteen blocks with a five-week thirteenth is 371 days and a Gregorian year
     * is 365, so a run need not tile a year. A gap or an overlap against the adjacent academic
     * year is reported, never rejected — the department sets its own start dates.
     */
    public static function warningsAgainstNeighbours(array $generated, ?Period $previousYearLast, ?Period $nextYearFirst): array
```

- [x] **Step 5: Teach `Calendar` about periods**

Add to `Calendar`:

```php
    public static function periodType(): string
    public static function blockWeeks(): array
    public static function academicYearStart(): ?CarbonImmutable
    public static function periodFor(DateTimeInterface|string $date): ?Period
    /** @return list<Period> */
    public static function periodsForYear(string $academicYear): array
```

`periodFor()` uses `whereDate('starts_on','<=',$d)->whereDate('ends_on','>=',$d)`, both bounds
inclusive — say so in the docblock and name `Person::levelAt()` as the idiom it matches, so a
future reader does not "fix" one of them to be half-open.

- [x] **Step 6: Verify and commit**

```bash
npm run build 2>&1 | tail -5
php artisan test | tail -5
```

```bash
git add database/migrations app/Models/Period.php app/Support/ database/factories tests/
git commit -m "feat: months and week-blocks, and a year whose last block varies"
```

> **Amended commit message.** The plan's original message — "a year that is 371 days long" —
> was written under reconnaissance finding 7's pre-decision-4 reading (13 blocks of 4 weeks
> plus a fixed 5-week block 13 = 371 days). Owner decision 4, round 2, overrides that: the
> academic year does not drift, block 13 absorbs whatever remains before the next year's fixed
> start, and its length varies. 371 days was never shipped; using it in the commit message
> would have documented an arithmetic error as a fact. See the Amendments entry below.

---

### Task 5: Absorb the existing converters

**Files:**
- Modify: `app/Http/Controllers/EndorsementController.php`
- Modify: `app/Support/MissedDays.php`
- Modify: `app/Support/ShiftClock.php`
- Modify: `app/Console/Commands/SendHandoverReminders.php`
- Modify: `app/Models/Person.php`
- Modify: `tests/Feature/Build/CalendarIsTheOnlyConverterTest.php`
- Test: `tests/Feature/Calendar/ConverterAbsorptionTest.php`

The enumeration below is from the reconnaissance, verified line by line. It is a checklist:
work it, do not re-derive it.

- [x] **Step 1: Write the failing test**

`tests/Feature/Calendar/ConverterAbsorptionTest.php`:

- `GET /endorsement/picu/+5%20years` **404s** (it does today, via the route regex — this test
  pins that behaviour survives the rewrite, since `normalizeDate` is the belt behind that
  brace);
- `GET /endorsement/picu/2026-02-30` 404s rather than silently serving 2026-03-02;
- `MissedDays::forRange()` over a range containing a Friday still counts that Friday — the
  denominator is **unchanged** by this task, and this assertion is what stops a later refactor
  quietly changing every historical compliance figure (finding: calendar report risk 6);
- a submitted empty date still defaults to today, and today is computed in the instance
  timezone (assert inside `withTimezone('Asia/Riyadh', …)` at 22:30 UTC).

Then tighten the guard: remove `EndorsementController.php` from
`CalendarIsTheOnlyConverterTest`'s `strtotime` allow-list and watch it go red.

- [x] **Step 2: `EndorsementController`**

Replace the two private helpers:

```php
    /** Normalize a `{date}` route param to `Y-m-d`, rejecting anything unparseable with a 404. */
    private function normalizeDate(string $date): string
    {
        $parsed = Calendar::tryParse($date);

        if ($parsed === null) {
            abort(404);
        }

        return $parsed->format(Calendar::YMD);
    }

    /** Parse a submitted date to `Y-m-d`, defaulting to today's date when absent/empty. */
    private function parseDateOrToday(mixed $value): string
    {
        return (is_string($value) ? Calendar::tryParse($value)?->format(Calendar::YMD) : null)
            ?? Calendar::todayYmd();
    }
```

This is stricter than what it replaces: `strtotime` accepted `08/08/2026`, `Calendar::parse`
does not. That is the point. The route regex already rejected those shapes, so no live URL
changes behaviour — pinned by the tests above.

Then replace every remaining `now()->format('Y-m-d')` with `Calendar::todayYmd()`, every
`Carbon::parse($x)->subDay()` with `Calendar::addDays($x, -1)`, and route the display
formatting through `Calendar::ymd()`. The sites, from the recon:
`:46`, `:162-163`, `:198-201`, `:199`, `:203`, `:281`, `:576-579`, `:891`, `:956`, `:976`,
`:1074-1075`.

**Leave `:891` alone if it touches `dob`** — that value comes from `EncryptedDateTime`, whose
getter can return a string marker for foreign ciphertext (`:26-44`). Read the site before
changing it; if it is the dob path, add a comment saying it is deliberately outside the module
and why.

- [x] **Step 3: `MissedDays`, `ShiftClock`, `SendHandoverReminders`, `Person`**

- `MissedDays.php:26-56` — `Carbon::parse` → `Calendar::parse`, `Carbon::today()` →
  `Calendar::today()`, the `addDay()` loop → `Calendar::datesBetween()`. **The denominator does
  not change.** Add a docblock line: *"Every calendar day counts. Weekend and holiday awareness
  would change `total_days` and therefore every historical compliance figure this system has
  ever produced — a data-meaning change requiring an owner ruling, deliberately not made
  here."*
- `ShiftClock.php:27` — `Carbon::now(config('app.timezone'))` → `Calendar::today()`-consistent
  construction via `Calendar::timezone()`. The hardcoded phase buckets at `:57-63` stay; they
  are shift semantics, not calendar semantics.
- `SendHandoverReminders.php:35,49,89` — `now()->format('Y-m-d')` → `Calendar::todayYmd()`;
  `Carbon::parse(now()->format('Y-m-d').' '.$time)` → build from `Calendar::today()`.
- `Person.php:121` — `levelAt()`'s own `today()` + `Carbon::parse` → `Calendar`. This is the
  cheapest and most symbolic conversion: the effective-dated idiom P1d reuses now shares the
  module with everything else.

- [x] **Step 4: Write the carve-outs down**

Three converters stay outside the module **deliberately**, and the guard test's allow-list
must carry the reason inline:

- `app/Console/Commands/LegacyImport.php:457-478` — normalises *source* strings from a legacy
  MySQL dump (`0000-00-00` and epoch sentinels), not application dates. It is a one-way,
  read-only importer against a frozen source.
- `app/Support/AuditChain.php:53-70` — v3 hashes the stored datetime byte-verbatim. Routing it
  through anything that parses is the exact defect that once made the live system declare its
  whole trail tampered.
- `app/Casts/EncryptedDateTime.php` — PHI, unqueryable by construction, and its getter can
  return a string marker.

- [x] **Step 5: Verify and commit**

```bash
npm run build 2>&1 | tail -5
php artisan test | tail -5
```

```bash
git add app/ tests/
git commit -m "refactor: five date converters become one, and three stay out on purpose"
```

> **Step 2's site list needed judgment, not literal application.** The recon line numbers
> (`:46, :162-163, :198-201, :199, :203, :281, :576-579, :891, :956, :976, :1074-1075`) were
> taken from an earlier commit and had drifted; sites were matched by pattern instead. Three
> categories emerged that the plan's blanket "route the display formatting through
> `Calendar::ymd()`" instruction does not fit cleanly, resolved as follows (see the Amendments
> entry below for the full reasoning): the `:281` `printed_at` site needed a timestamp WITH
> time-of-day, so `Calendar::now(): CarbonImmutable` was added (today() truncates to midnight);
> the `signed_off_at`/`reopened_at` display sites (`HandoverSignoff` timestamps, formerly
> `:956`/`:976`) format an ALREADY-Carbon attribute with `H:i` — `Calendar::ymd()` would
> silently drop the time, so they were left as native `->format()` calls, which is display
> formatting of a typed value, not parsing an ambiguous string; and `recordRevisions()`'s
> generic before/after diff formatter (formerly `:1074-1075`) was left alone because it runs
> over EVERY tracked field including `dob`, and routing a possibly-PHI, possibly-string-marker
> value through Calendar is exactly what Calendar's own docblock excludes.

---

### Task 6: The client stops converting dates

**Files:**
- Modify: `app/Http/Controllers/EndorsementController.php`
- Modify: `app/Http/Controllers/Admin/UserManagementController.php`
- Modify: `resources/js/Pages/Endorsement/Index.vue`
- Modify: `resources/js/Pages/Endorsement/Sheet.vue`
- Modify: `resources/js/Pages/Admin/Users.vue`
- Modify: `tests/Feature/Build/CalendarIsTheOnlyConverterTest.php`
- Test: `tests/js/EndorsementIndex.test.js`, `tests/js/EndorsementSheet.test.js` (modify)
- Test: `tests/e2e/mobile-sheet.spec.js` (modify)

Decision A. Four hand-rolled JS date helpers exist; each carries a warning comment recording a
real +03:00 bug. This task deletes all four and replaces them with server-supplied props.

- [x] **Step 1: Extend the guard, and watch it go red**

Add to `CalendarIsTheOnlyConverterTest`: no file under `resources/js/` contains `new Date(`,
`toISOString(`, or `toLocaleString(`. Allow-list empty at the end of this task.

- [x] **Step 2: Server-supplied dates**

- **`Index.vue`** — `localYmd()` (`:106`) and `datesBetween()` (`:108-118`) go. The controller
  sends a `days` array, one entry per date in the range, each
  `{ date, hijri, weekend, has_sheet, signed }`, built from `Calendar::datesBetween()`. The
  `GAP_RENDER_LIMIT = 7` cap (`:102`) stays, applied server-side.
- **`Sheet.vue`** — `nextDate` (`:170-180`) goes; the controller sends `next_date` and
  `previous_date`.
- **`Users.vue:126`** — `new Date(iso).toLocaleString()` goes; `UserManagementController::index()`
  sends `last_login_display` already formatted. This is the app's only browser-timezone-dependent
  rendering; removing it removes a whole class of "the timestamp is wrong on my laptop".

- [x] **Step 3: Dual dating appears (UX-04)**

The sheet header and the index gain the Hijri date beside the Gregorian, from
`Calendar::label()`:

```html
<span class="readout">{{ day.date }}</span>
<span v-if="day.hijri" class="text-sm text-muted"> · {{ day.hijri }}</span>
```

Semantic tokens only — `.readout` for the numeral, `text-muted` for the secondary date. No
`dark:` utility (guarded by `CompiledCssIsLightOnlyTest`), no raw palette class, no hex.
Weekend days are marked with a **class and a label**, never colour alone (NF-06/UX-02:
severity and day type never by colour alone).

This is also how the owner verifies the −1 calibration: open two consecutive days that cross a
Hijri month boundary and compare against the department's published calendar. Say so in the
commit message.

- [x] **Step 4: Update the component and e2e tests**

`tests/js/EndorsementIndex.test.js` and `EndorsementSheet.test.js` must feed the new props.
`tests/e2e/mobile-sheet.spec.js:5-9,57-61` asserts persistence after reload by
`data-row-id` — that shape is unchanged; only the date props move.

- [x] **Step 5: Verify and commit**

```bash
npm run build 2>&1 | tail -5
npm test 2>&1 | tail -5
php artisan test | tail -5
npm run test:e2e 2>&1 | tail -5
```

```bash
git add app/ resources/js/ tests/
git commit -m "feat: dual Gregorian-Hijri dates, and no date arithmetic left in the browser"
```

---

### Task 7: Holidays and day types

**Files:**
- Create: `database/migrations/2026_08_12_120003_create_holidays_table.php`
- Create: `app/Models/Holiday.php`
- Create: `database/factories/HolidayFactory.php`
- Modify: `app/Support/Calendar.php`
- Test: `tests/Feature/Calendar/HolidayTest.php`

Munawib §30 `holidays/{holId} { name, rule:{greg?|hijri?}, equityTracked }`. The model and the
resolver land here; the **CRUD screen lands in P1b** with the rest of structure administration.

- [x] **Step 1: Write the failing test**

- a Gregorian rule (month 9, day 23) matches 2026-09-23 and 2027-09-23 and nothing else;
- a Gregorian rule with an explicit `year` matches that year only;
- a **Hijri** rule (month 10, day 1 — Eid al-Fitr) matches the Gregorian date whose
  offset-applied Hijri date is 1448-10-01, and **moves when `hijri_offset_days` changes** —
  the assertion that proves Hijri holidays resolve *through* the calibration rather than
  around it;
- `duration_days = 4` on a Hijri rule matches the anchor day and the three following days,
  **including across a Hijri month end**;
- `dayType()` returns `'HOL'` for a holiday that falls on a Friday — **holiday wins over
  weekend**, stated as a precedence rule because SL-03's coverage templates key on it;
- `dayType()` returns `'WE'` for a plain Friday and `'WD'` for a Tuesday;
- an inactive holiday matches nothing.

- [x] **Step 2: The migration**

```php
Schema::create('holidays', function (Blueprint $table) {
    $table->id();
    $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
    $table->string('name');

    // 'gregorian' | 'hijri'. A Hijri rule resolves THROUGH hijri_offset_days, so a department
    // that recalibrates moves its Eids with everything else rather than acquiring a second,
    // silently divergent calendar.
    $table->string('calendar', 20);

    $table->unsignedTinyInteger('month');
    $table->unsignedTinyInteger('day');
    $table->unsignedSmallInteger('year')->nullable();   // null = recurs every year
    $table->unsignedTinyInteger('duration_days')->default(1);

    // Munawib HE-*: whether working this holiday counts toward holiday equity (Stage 4).
    // Stored now so the Stage 1 screen captures it once rather than asking again later.
    $table->boolean('equity_tracked')->default(true);

    $table->boolean('active')->default(true);
    $table->timestamps();

    $table->index(['institution_id', 'active']);
});
```

- [x] **Step 3: The resolver**

Add to `Calendar`:

```php
    public const DAY_WEEKDAY = 'WD';
    public const DAY_WEEKEND = 'WE';
    public const DAY_HOLIDAY = 'HOL';

    /** @return list<Holiday> */
    public static function holidaysOn(DateTimeInterface|string $date): array

    public static function isHoliday(DateTimeInterface|string $date): bool

    /**
     * SL-03's day type. HOLIDAY WINS OVER WEEKEND — a coverage template that asks for holiday
     * staffing must get it on a holiday that happens to fall on a Friday, not weekend staffing.
     */
    public static function dayType(DateTimeInterface|string $date): string
```

`holidaysOn()` walks back up to `duration_days - 1` days from the queried date and asks whether
any anchor matches the rule; that handles multi-day spans across both a Gregorian and a Hijri
month end without special-casing either. Memoize the active holiday list beside the settings,
and clear it in `Calendar::flush()`.

Extend `Calendar::label()` with `holiday` (the name or null) and `day_type`, so the one shape
every screen renders keeps being the one shape.

- [x] **Step 4: Verify and commit**

```bash
npm run build 2>&1 | tail -5
php artisan test | tail -5
```

```bash
git add database/ app/ tests/
git commit -m "feat: holidays, and a holiday on a Friday is a holiday"
```

---

### Task 8: The shared fixture corpus

**Files:**
- Create: `tests/fixtures/calendar/golden.json`
- Create: `tests/Feature/Calendar/GoldenFixtureTest.php`
- Modify: `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md` *(Task 9)*

Design §AR-03 and §4.3 make a **shared golden fixture suite** the mechanism that stops the two
engine runtimes drifting; §12 and QA-01 make it a release gate. Decision A defers the client
mirror to P2 — this task builds the corpus now so that mirror has something to be validated
against on the day it is written, rather than being written and then having fixtures
retro-fitted to whatever it already does.

- [x] **Step 1: The corpus**

`tests/fixtures/calendar/golden.json` — a plain JSON document, deliberately readable by both
PHP and JS with no framework:

```json
{
  "timezone": "Asia/Riyadh",
  "cases": [
    {
      "settings": { "hijri_offset_days": 0, "weekend_days": [5, 6] },
      "dates": [
        { "date": "2026-08-08", "hijri": "1448-02-25", "iso_weekday": 6, "weekend": true,  "day_type": "WE" },
        { "date": "2026-07-15", "hijri": "1448-02-01", "iso_weekday": 3, "weekend": false, "day_type": "WD" },
        { "date": "2026-01-01", "hijri": "1447-07-12", "iso_weekday": 4, "weekend": false, "day_type": "WD" },
        { "date": "2026-12-31", "hijri": "1448-07-22", "iso_weekday": 4, "weekend": false, "day_type": "WD" }
      ]
    },
    {
      "settings": { "hijri_offset_days": -1, "weekend_days": [5, 6] },
      "dates": [
        { "date": "2026-08-07", "hijri": "1448-02-23", "iso_weekday": 5, "weekend": true,  "day_type": "WE" },
        { "date": "2026-08-08", "hijri": "1448-02-24", "iso_weekday": 6, "weekend": true,  "day_type": "WE" },
        { "date": "2026-07-15", "hijri": "1448-01-29", "iso_weekday": 3, "weekend": false, "day_type": "WD" },
        { "date": "2026-07-16", "hijri": "1448-02-01", "iso_weekday": 4, "weekend": false, "day_type": "WD" }
      ]
    }
  ],
  "period_runs": [
    {
      "kind": "week_block",
      "starts_on": "2026-07-01",
      "block_weeks": [4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5],
      "expect": {
        "count": 13,
        "total_days": 371,
        "first": { "label": "Block 1",  "starts_on": "2026-07-01", "ends_on": "2026-07-28" },
        "last":  { "label": "Block 13", "starts_on": "2027-06-02", "ends_on": "2027-07-06" }
      }
    },
    {
      "kind": "month",
      "starts_on": "2026-07-01",
      "count": 12,
      "expect": {
        "count": 12,
        "total_days": 365,
        "first": { "label": "July 2026", "starts_on": "2026-07-01", "ends_on": "2026-07-31" },
        "last":  { "label": "June 2027", "starts_on": "2027-06-01", "ends_on": "2027-06-30" }
      }
    }
  ]
}
```

The `-1` case's `2026-07-15 → 1448-01-29` pair is the month-boundary verification design §7
requires and the one the owner can check against the department's published calendar. The
`371` is finding 7 written down where a future reader will trip over it.

**Compute the `last` block dates and the `total_days` values by running the generator, then
read them and confirm they are right** — do not copy the numbers above on faith. If they
differ, the fixture is wrong and the *Amendments* section records what the real values are.

- [x] **Step 2: The test**

`GoldenFixtureTest` loads the JSON, applies each `settings` block to an institution row, calls
`Calendar::flush()`, and asserts every field of every case. It is a data-driven test, so
adding a case is editing JSON.

Add a docblock stating in one sentence: *"P2's `packages/engine` calendar asserts against this
same file. A change here that is not also a change there is the drift this file exists to
catch."*

- [x] **Step 3: Verify and commit**

```bash
npm run build 2>&1 | tail -5
php artisan test | tail -5
```

```bash
git add tests/
git commit -m "test: the golden calendar corpus P2's mirror will have to agree with"
```

---

### Task 9: Correct the documents this invalidates

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`
- Modify: `docs/RUNBOOK-DEPLOY.md`
- Modify: `docs/OPEN-DECISIONS.md`
- Modify: `docs/DESIGN-TOKENS.md`

- [x] **Step 1: `CLAUDE.md`**

Add to *Non-negotiable rules*:

> - **All date logic goes through `App\Support\Calendar`** (Munawib AR-08). Nothing else calls
>   `strtotime`, constructs an `IntlCalendar`, or does date arithmetic — including
>   `resources/js`, which receives formatted labels and enumerated ranges as props and does
>   none of its own. Guarded by
>   `tests/Feature/Build/CalendarIsTheOnlyConverterTest.php`. Three deliberate carve-outs, each
>   commented at its site: `LegacyImport` (normalises legacy *source* strings), `AuditChain`
>   (byte-verbatim, never re-parsed), `EncryptedDateTime` (PHI, unqueryable). Timezone is
>   per-INSTANCE (`APP_TIMEZONE`); the Hijri offset is per-DEPARTMENT.

Add to *Toolchain*: the suite's timezone status after Task 3 Step 3 — either "the suite runs at
`Asia/Riyadh`, matching production" or "the suite runs at UTC; day-boundary behaviour is
covered explicitly by `DayBoundaryTest`, and `config(['app.timezone' => …])` alone still proves
nothing".

- [x] **Step 2: The design doc**

- §6.1: `user_levels` → `person_levels`; note the ladder is **seeded** per owner decision 1
  and that UN-02's flags and UN-03's aliases are **not** shipped and land in P1b.
- §7: record Decision A — one implementation in P1a, the `packages/engine` mirror deferred to
  P2, and `tests/fixtures/calendar/golden.json` as the contract between them. Record that the
  timezone is per-instance, not per-department, and why.
- §13: P1's row gains the five-way split with a one-line scope each.
- §14: add an item for the `MissedDays` denominator ruling, and one for the AC-02 invitation
  lifetime (see below).

- [x] **Step 3: `docs/RUNBOOK-DEPLOY.md` and `docs/OPEN-DECISIONS.md`**

- The identifiers table gains `HIJRI_OFFSET_DAYS` beside `INSTANCE_SLUG`, with QCH's value and
  the sentence that it must be verified against the department's own published calendar across
  a month boundary before anyone trusts a Hijri date on screen.
- Verification queries for the three migrations:
  `SELECT hijri_enabled, hijri_offset_days, period_type FROM institutions;`,
  `SELECT COUNT(*) FROM periods;`, `SELECT COUNT(*) FROM holidays;`.
- `docs/OPEN-DECISIONS.md` gains the two owner decisions still needed (below), each with what
  it blocks and what happens by default until it is made.

- [x] **Step 4: `docs/DESIGN-TOKENS.md`**

Finding 14: correct the stale colour table (`muted #526d70`, `ok #0c7358`,
`caution #8f5d13`), the claim that PICU is the only unit hue, and the token path — and add a
line at the top stating that `resources/css/app.css` is authoritative and this document
records the rules. P1b–P1e all build screens from this document; leaving it wrong for four
sub-plans guarantees four screens built against wrong values.

Do **not** add a `--color-panel-soft` token to make the three existing `bg-panel-soft` uses
work (finding 13). Those three sites are cosmetically wrong today and changing them is a
visual change, not a documentation fix — P1c touches `Users.vue` anyway and can correct them
there with the change visible in its own diff.

- [x] **Step 5: Verify and commit**

```bash
npm run build 2>&1 | tail -5
php artisan test | tail -5
```

```bash
git add CLAUDE.md docs/
git commit -m "docs: the calendar is a module now, and four documents said otherwise"
```

---

## Definition of done — P1a

- `php artisan test` passes with **no fewer tests than before Task 1**, run via **Bash**
  (`export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"`).
  PowerShell's PATH lacks `openssl`, so the backup tests self-skip there and the suite reports
  green without exercising them (P0d environment note). `npm test`, `npm run build` and
  `npm run test:e2e` green.
- `npm run build` has been run **before** `php artisan test`, or
  `CompiledCssIsLightOnlyTest`'s artifact layer and the print-CSS check skip rather than pass.
- `App\Support\Calendar` is the only file under `app/` mentioning `IntlCalendar`,
  `IntlDateFormatter` or `islamic-umalqura`, and the only `strtotime(` left under `app/` is
  `LegacyImport`'s — asserted by `CalendarIsTheOnlyConverterTest` over the whole match set,
  not in a `foreach`.
- `resources/js` contains no `new Date(`, `toISOString(` or `toLocaleString(` — the four
  hand-rolled date helpers are gone and the +03:00 midnight-rewind trap is unreachable.
- `Calendar::parse()` throws on `+5 years`, `last monday`, `08/08/2026`, `2026-8-8` and
  `2026-02-30`; `/endorsement/picu/2026-02-30` 404s.
- `Calendar::hijri('2026-07-15')` is `1448-02-01` at offset 0 and `1448-01-29` at offset −1 —
  the month boundary, resolved by shifting the Gregorian instant, never by decrementing a
  Hijri day number.
- `DayBoundaryTest` runs the same assertions under `UTC` and `Asia/Riyadh` with **PHP's
  default timezone actually moved**, and asserts that a 22:30 UTC write files under the next
  day in Riyadh.
- Week-blocks `[4×12, 5]` from `2026-07-01` produce 13 contiguous periods spanning **371**
  days; months produce 12 contiguous periods spanning 365; `Calendar::periodFor()` resolves
  both bounds of every period inclusively; an overlapping period **throws** and names both
  labels; a gap or overlap against the neighbouring academic year **warns**.
- A Hijri-ruled holiday moves when `hijri_offset_days` changes; a holiday on a Friday reports
  `HOL`, not `WE`.
- `MissedDays` counts exactly the days it counted before — asserted, so a later refactor
  cannot quietly change every historical compliance figure.
- `tests/fixtures/calendar/golden.json` exists, every value in it was produced by running the
  code rather than copied from this plan, and `GoldenFixtureTest` asserts all of it.
- `docker-compose.production.yml` passes `HIJRI_OFFSET_DAYS: ${HIJRI_OFFSET_DAYS:-0}` through
  to the container, asserted by `DeploymentInvariantsTest` — the P0d Task 9 defect does not
  recur.
- `composer.json` declares `ext-intl`.
- The endorsement sheet and index render the Hijri date beside the Gregorian, in semantic
  classes only, with no `dark:` utility and no colour-only distinction.
- **OWNER ACTION outstanding and recorded:** `HIJRI_OFFSET_DAYS=-1` set in Coolify for QCH and
  verified against the department's published calendar across a month boundary.

---

## P1b — P1e: task lists

Scoping, not implementation. Each becomes its own plan, written when its predecessor merges.

### P1b — Structure administration *(UN-01…05, LV-01, ST-02)*

1. Units gain UN-02's three independent flags (`training_rotation`, `call_target`,
   `clinic_owner`), UN-03 `aliases` (json), UN-05 `name2`, and an explicit `color` distinct
   from `bar_class` — all additive and nullable; design §6.1 claims these shipped and they did
   not.
2. New capability keys — `structure.manage` at minimum — seeded in `AccessControlSeeder`'s
   `CATALOG` **and** `ROLE_DEFAULTS`, **and** added to `AppLayout.vue:72-73`'s `canAdmin`, or a
   user holding only the new capability sees no Administration section at all (recon frontend
   risk 10).
3. Units CRUD screen: create, rename, colour, order, deactivate. `Unit::RESERVED_CODES` is
   already enforced on every write — this is the surface it was written for. Deactivation
   hides forward and never deletes (UN-04).
4. **Unit merge (UN-01)** is its own task: it re-points `handovers.unit_id`,
   `handover_signoffs.unit_id` (which carries `UNIQUE(unit_id, handover_date)` — two units
   merged on a day both signed is a collision the merge must resolve explicitly, not
   discover), `unit_field_definitions`, and any P1d rota rows. Clinical rows are never
   hard-deleted. This is the highest-risk task in P1b.
5. Level ladder: seed `R1, R2, R3, R4, EXT` per owner decision 1 with distinct `display_order`
   values (**not** the `1000` default — LV-03's "advance one level" is undefined without them),
   `EXT` flagged external and ordered last. `levels.institution_id` needs setting by the
   seeder; the P0d backfill deliberately skipped `levels`.
6. `levels` gains the LV-01 `external` flag and a **terminal/graduating marker** — without one,
   LV-03 has no way to know who graduates. Additive migration, before the first promotion.
7. Levels CRUD screen; a level with history cannot be deleted (`restrictOnDelete` already), so
   the screen offers deactivate and says why.
8. Calendar, period and holiday settings screens (ST-02): period type, block lengths, academic
   year start with the period-run preview and its gap/overlap warning, weekend days, Hijri
   toggle and offset, holidays CRUD. Every write audited by key, never by value.

**2026-08-09 — superseded by `docs/superpowers/plans/2026-08-09-p1b-structure-admin.md`,
written when P1a merged and SHIPPED the same day.** This scoping is left as written above (the
P0a–P0d convention: amend, do not silently rewrite, so a reader can compare original intent
against what actually shipped), but three items changed once the sub-plan was read against the
real tree:

- **Item 1's "an explicit `color` distinct from `bar_class`" was rejected (Decision B).** A
  second colour column would be two definitions of one fact. `bar_class` itself widened to an
  eight-entry allow-list (`Unit::BAR_CLASSES`) that both offers the choice on the units screen
  and validates it — one column, one list, zero data migration.
- **Item 6's "terminal/graduating marker" was rejected (Owner Decision A, folded into the
  sub-plan's own binding decisions block the same day this scoping predates).** `levels` gained
  `external` only. A wrong terminal marker fails silently in two directions — an unmarked top
  level advances a cohort into a level that does not exist, a wrongly-marked middle level
  graduates one a year early — and Decision A removes the whole failure class by having P1c's
  promotion screen take the **target level as explicit operator input** instead of inferring
  "one step up" from a column. The same correction applies to this section's own "Next plan"
  paragraph below, written before Decision A landed.
- **Item 8's "the period-run preview and its gap/overlap warning" undersold what shipped.** The
  sub-plan's own finding 4: `PeriodGenerator` had ZERO production callers, so a preview alone
  would leave `periods` permanently empty and P1d's rota grid with no columns to render. The
  sub-plan ships preview **and** generate-and-commit **and** delete-a-year (the hard-lock's own
  unlock path) — scope this list's one line did not name.
- **Not a correction, an omission this list never named at all:** `AppLayout.vue`'s hardcoded
  sidebar array and `app.css`'s four-hue palette had to move to configuration (the sub-plan's
  own Task 3) *before* item 3's unit-creation screen could land — otherwise the first unit an
  administrator created would be invisible in the sidebar and colourless, a defect shipped by
  the plan rather than inherited from before it.

Confirmed honoured throughout: both of the "Next plan" section's P1a outputs below held for
every P1b task — no screen formats a date except through `Calendar::label()`/`::ymd()`, and
`tests/fixtures/calendar/golden.json` was never touched.

### P1c — People, roster and accounts *(PE-01…03, AC-01…04, LV-02…04, ST-04)*

1. `people.manage` capability, gated route group, `PeopleController`, and the first People
   screen — `Users.vue`'s table is the styling precedent; the screen is **person**-scoped where
   `Users.vue` is account-scoped, and the two must not be conflated.
2. PE-01's full field set gets a write path: `short_name`, `phone`, `joined_at`, `notes`,
   `constraints`. `updateProfile()` writes only `full_name` and `email` today.
3. PE-02 contact visibility: a policy-gated **projection**, additive over
   `Person::$hidden = ['phone','notes']` — never a removal of `$hidden`, which is currently the
   only thing keeping staff phone numbers out of Inertia props. `app/Policies/` does not exist
   yet; this creates it.
4. PE-03 made real: `external` set by a writer, surfaced by `SignoffPickers::offer()`, and
   flagged in every list. Note `people.email` is nullable and `matchByEmail()` returns null for
   a null address, so ad-hoc external people bypass the only matcher in the system —
   de-duplication needs an explicit answer.
5. `person_levels` gains `promotion_batch_id`, `reason`, `created_by` (findings 8–9) **before**
   any promotion runs, plus an application-level overlap guard, plus a `PersonLevelFactory`
   (none exists).
6. `Person::levelsAt(Collection, $date)` — set-wise, sharing **one** predicate definition with
   `levelAt()` (finding 10).
7. LV-02 bulk operations: multi-select, authorize the **entire** selection before any write
   (finding 12), one transaction, set-aware guards replacing `isLastActiveAdministrator()`'s
   per-row check.
8. LV-03 annual promotion: preview, single-transaction commit, one summary audit row plus one
   row per person (ids only), a **new action name deliberately added to `AuditAnomalies`'
   watch list**, and an explicit answer to `unique(person_id, effective_from)` — pre-check and
   skip, or upsert.
9. LV-04 history rendering, using the P1a calendar for every date.
10. AC-02: invitation lifetime (owner decision needed), resend singly and in bulk, and claim
    status made visible — `openInvitations()` returns open only, so accepted/revoked/expired
    never render. Bulk resend has nowhere to surface N one-time links and only works by mail,
    which is conditional on SMTP and swallows failures: a bulk resend that silently mails
    nothing is a real possibility and needs designing against.
11. AC-03 unbinding: an explicit, audited write — never leaning on the `nullOnDelete` FK, which
    would leave an orphaned credential whose `position`, `full_name` and `member_email`
    accessors all silently return null and make `AccessControl::resolve()` resolve against a
    null position. State what an unbound account resolves to.
12. AC-04 per-person roles: `user_capabilities` is keyed to the **account**, so a roster-only
    person can hold no grant. Moving it touches `AccessControl::resolve()`, `holdersOf()` and
    the cache key — a security-boundary change deserving its own task and the 2026-07-26
    "offered and validated from one definition" discipline.
13. ST-04 roster import: **needs a dependency decision** — there is no spreadsheet package in
    `composer.lock` at all. `openspout` streams and suits the 256M `memory_limit`;
    PhpSpreadsheet is heavier. `upload_max_filesize=4M`/`post_max_size=8M` cap the file, and
    commit `8886f8d` is proof that file POSTs have broken in this deployment once.
    Dry-run-by-default per `DataRetention.php:31`; reconciliation report per
    `LegacyImport.php:480-538`; **in-file duplicates must be caught before insert** —
    `people.email` and `short_name` are UNIQUE outright and two spreadsheet rows sharing an
    address pass row-by-row validation and 23000 on insert.
14. **CSV export needs formula-injection neutralisation** — nothing in the codebase escapes a
    cell beginning `=`, `+`, `-`, `@`, tab or CR, and a hospital spreadsheet imported and
    re-exported is exactly the round trip that weaponises it.

### P1d — Master rota *(MR-01…03, MR-05…07)*

1. `master_rota_assignments`: person × period × unit, one unit per person per period.
2. MR-02 split periods as **date-bounded sub-assignments**, reusing `Person::levelAt()`'s
   inclusive-both-bounds idiom — never a JSON blob keyed by person id, which would reintroduce
   the whole-document last-write-wins SC-03 forbids.
3. `vacations`: week or exact-date granularity, `source`.
4. The grid: rows by level, columns by period, both period systems. Needs `desktopColumnCount`
   computed (finding 13), stable `data-row-id` **and** `data-col-key` (the grid re-sorts, so
   index- or value-based selectors address a moving target), per-cell PATCH sending only the
   changed key against a merge-aware endpoint, and `Sheet.vue:89-149`'s `SaveStatus` machine
   lifted verbatim — including `preserveState: true`, without which Inertia remounts and wipes
   the indicator.
5. MR-06 fill-down/across, copy period, import/export.
6. MR-05 publish view: search, level filter, per-person period strip. **Logged-in and
   `cap:`-gated** — D7 permits no anonymous route; tokenized share links are P3.
7. MR-07 per-period availability summaries by level and unit, including who is on vacation each
   week — this is the Stage 1 acceptance criterion (*"availability summaries match reality"*).
8. The e2e persistence test asserts after **reload**, never the save indicator alone.

### P1e — Clinics, setup wizard, demo department *(CL-01…02, CL-04…05, ST-01, ST-03 subset, ST-05)*

1. `clinics` (owning unit, name, weekday, session AM/PM, location, note, active) and
   `clinic_attendees` (CL-02: rotators attach by default, refined by level or named people).
2. CL-05 weekly clinic map (unit × weekday × session).
3. CL-04's personal-schedule and coverage-board surfaces exist only from P3 — P1e ships the
   data and records the hook.
4. The setup wizard (ST-01) threading profile/branding, calendar, level ladder, units,
   holidays, roster import and invitations — every step revisitable in Settings (ST-02), and
   the slot/coverage/condition steps **stated as arriving in P2/P3** rather than presented as
   empty steps.
5. ST-05 the one-click, clearly-labelled, **removable** demo department seed. `DemoSeeder` and
   `E2eSeeder` already exist and are the precedent; "removable" is the hard part and needs a
   provenance marker on every row it creates.

---

## Owner decisions still needed

Neither blocks P1a. Both block a specific later task, and both have a stated default.

1. **Invitation lifetime: 7 days or 14?** `Invitation::LIFETIME_DAYS = 7`
   (`app/Models/Invitation.php:18`); Munawib AC-02 says 14. A one-constant change, but
   invitation lifetime is a credential-exposure window and that is not a developer's call.
   *Blocks:* P1c task 10. *Default if unanswered:* stays 7, and the P1c plan records the
   spec deviation explicitly rather than silently.

2. **Does the missed-days denominator become weekend- and holiday-aware?** `MissedDays` is the
   system's only aggregate and the metric the product exists to improve. Making it skip
   weekends and holidays changes **every historical compliance figure** — a data-meaning
   change, not a refactor. *Blocks:* nothing in P1; it is a standing question the calendar
   makes answerable for the first time. *Default if unanswered:* unchanged, every calendar day
   counts, asserted by a test in Task 5 so it cannot drift by accident.

3. *(Confirmation, not a decision — finding 7.)* Is the QCH academic year deliberately 371
   days, with each year's start drifting about six days later, or does the department reset to
   a fixed start date and absorb the difference in block 13? Both are implemented; only the
   wording of the gap/overlap warning depends on the answer.

---

## Stage 1 acceptance (§35), after P1a

> *Accepted:* the pilot's real master rota and clinics live; residents claimed accounts;
> availability summaries match reality.

P1a satisfies **none** of these and is not meant to. It makes them reachable: the rota's
columns are periods, and periods did not exist; the grid's cells are dates, and nothing could
render one correctly at +03:00 with a Hijri companion; vacations and availability summaries
are date-range arithmetic, and there was no date-range arithmetic outside a controller's
private method.

The three acceptance criteria are met by **P1d** (master rota and availability summaries),
**P1e** (clinics) and **P1c** (claimed accounts) respectively, and none of the three can be
honestly started before P1a merges.

---

## Next plan

**P1b — Structure administration.** Units gain UN-02's flags, UN-03's aliases and UN-05's
secondary name; the level ladder is seeded per owner decision 1 and gains its terminal marker;
the calendar, period and holiday settings screens land so ST-02's "every step revisitable"
holds before ST-01's wizard is built over it; and the first new capability key of the Munawib
era enters the catalog, `ROLE_DEFAULTS`, and `AppLayout`'s `canAdmin` in one commit.

Two P1a outputs P1b must respect: `App\Support\Calendar` is the only converter, and its guard
test fails the build for any new one — a settings screen that formats a date does it through
`Calendar::label()`. And `tests/fixtures/calendar/golden.json` is a contract with P2, not a
convenience: changing a value in it without a stated reason is the drift it exists to catch.
