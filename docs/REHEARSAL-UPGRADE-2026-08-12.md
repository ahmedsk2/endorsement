# Upgrade-path rehearsal — 2026-08-12

**What this is.** `docs/DEPLOY-P1-2026-08-12.md` is the plan for taking the live instance from
`8886f8d` to `main`. Nothing had ever run that path. Every test run in this repository does
`migrate:fresh` from zero; **nobody had ever migrated the deployed schema *forward*, with rows in
it**, which is the only path production will take.

This document records a rehearsal that did exactly that, and what it found.

**Method.** A `git worktree` checked out at `8886f8d`; `composer install` at that commit's lock;
`migrate` + `db:seed --force` to reach the schema production is on; then a hand-built department —
13 accounts across every shape (linked, deactivated, soft-deleted, `full_name IS NULL`,
`institution_id IS NULL`, no email), 146 handovers on five units across sixteen dates, 45
sign-offs naming people in all four roles with signed, locked and reopened days, ~48 KB of
encrypted rich text with Arabic and `&`, four invitations in four states, push subscriptions,
reminder opt-ins including one on a unit nobody uses, and a 60-row hash-chained `audit_log`
written through `AuditLog::record()`. Then the worktree was switched to `main` and
`php artisan migrate --force` was run — the real upgrade, not `migrate:fresh`.

**Engine.** SQLite, because Docker was down on the machine and MySQL was unavailable. §11 below is
the list of what that structurally cannot show. Everything in §1–§10 was measured.

---

## 0. Verdict

| | |
| --- | --- |
| Migrations pending at `8886f8d` | **22**, exactly the 22 of the plan's §2, in that order |
| `migrate --force` | **exit 0**, all 22 applied, 2.19 s wall |
| `db:seed --force` | exit 0 |
| `audit:verify` before | `Audit chain intact: 60 rows verified.` exit 0 |
| `audit:verify` after | `Audit chain intact: 60 rows verified.` exit 0 |
| Rows lost | **none** — every table reconciles |
| App after the upgrade | every screen 200, including all fourteen new ones |
| **Defects found** | **three**, all in `down()` methods (§6) — fixed, with a test |
| Divergences from the plan | **nine** (§7) |

**The chain survived.** That was the single most important question and the answer is yes: the
same 60 rows verified before and after, and three more rows written by the upgraded code after the
migration chained onto them cleanly (63 verified).

---

## 1. The forward run, migration by migration

`php artisan migrate --force`, against the populated database. Laravel's own per-migration timings:

| # | Migration | Time |
|---|---|---|
| 1 | `2026_08_08_120001_add_configuration_to_units` | 137.69 ms |
| 2 | `2026_08_09_120001_create_unit_field_definitions_table` | 51.38 ms |
| 3 | `2026_08_09_120002_add_extra_fields_to_handovers` | 26.86 ms |
| 4 | `2026_08_10_120001_create_people_and_link_users` | **274.72 ms** |
| 5 | `2026_08_10_120002_create_levels_and_person_levels` | 42.45 ms |
| 6 | `2026_08_10_120003_move_name_and_position_off_users` | 25.16 ms |
| 7 | `2026_08_10_120004_add_person_ids_to_handover_signoffs` | **196.06 ms** |
| 8 | `2026_08_10_120005_add_person_id_to_invitations` | 52.39 ms |
| 9 | `2026_08_11_120001_backfill_institution_on_identity_rows` | 9.18 ms |
| 10 | `2026_08_12_120001_add_calendar_settings_to_institutions` | 36.27 ms |
| 11 | `2026_08_12_120002_create_periods_table` | 16.19 ms |
| 12 | `2026_08_12_120003_create_holidays_table` | 11.12 ms |
| 13 | `2026_08_13_120001_add_munawib_configuration_to_units` | 59.81 ms |
| 14 | `2026_08_13_120002_add_external_to_levels` | 13.03 ms |
| 15 | `2026_08_14_120001_add_contact_visibility_to_institutions` | 19.18 ms |
| 16 | `2026_08_14_120002_add_provenance_to_person_levels` | 98.14 ms |
| 17 | `2026_08_15_120001_widen_rich_text_handover_columns` | 72.52 ms |
| 18 | `2026_08_15_120002_correct_ward_clinic_owner` | 4.75 ms |
| 19 | `2026_08_15_120003_create_master_rota_assignments_table` | 19.90 ms |
| 20 | `2026_08_15_120004_create_vacations_table` | 16.32 ms |
| 21 | `2026_08_16_120001_create_clinics_and_attendees_tables` | 31.77 ms |
| 22 | `2026_08_16_120002_create_demo_rows_table` | 16.49 ms |
| | **total** | **2,194 ms wall** |

**Read these timings only for their SHAPE, not their size.** They are SQLite on a laptop over 13
accounts and 146 handover rows. The shape is: the three data migrations (#4, #7, #16) dominate,
and #17 — the one the plan says to schedule a window for — is cheap here **because SQLite does not
do the `ALGORITHM=COPY` rebuild MySQL will**. §1.6 of the plan is still the sizing you must do.

Nothing failed. Nothing left the database mid-way.

---

## 2. What the data migrations actually did

| Backfill | Result |
|---|---|
| #4 → `people` | 13 rows, one per account, **soft-deleted account included**. `full_name IS NULL` on one account fell back to `member_name` exactly as the migration's comment says. |
| #4 → `users.person_id` | 13 of 13 written, 0 NULL, 0 shared |
| #7 → `handover_signoffs.*_person_id` | 45 rows: `endorsed_by` 45/45, `endorsed_to` 44/44, `consultant_by` 44/44, `consultant_to` 33/33. All four "user id set but person id null" checks return 0. |
| #9 → `institution_id` | 2 NULL `users` rows filled; 0 NULL on `people` and `users` afterwards |
| #1 / #13 / #18 → `units` | the four seeded codes fully configured, WARD `clinic_owner = 1` |

The plan's §5.2 "copy-instead-of-join" cross-check returned exactly one row, and it was the one
planted for it — a sign-off whose stored name had been edited after signing. Read, not assumed.

---

## 3. `db:seed --force`, measured

| Table | Before | After | Plan says |
|---|---|---|---|
| `capabilities` | 9 | **14** | 9 → 14 ✅ |
| `role_capabilities` | 22 | **35** | +13 ✅ |
| `applied_role_defaults` | 22 | **35** | "49 → 62" ❌ see §7.1 |
| `levels` | 0 | **5** (R1 R2 R3 R4 EXT, 10/20/30/40/90, EXT external) | ✅ |

The 13 new `(position, capability)` pairs are **exactly** the thirteen the plan's §5.4 lists, and
§5.4's last query — `rota.manage` at any position other than 0 — returns nothing. §6.1's "expect a
no-op on this instance" holds.

### `hijri_offset_days` — the plan's correction to the runbook is right, and it matters

Four runs, each starting from an administrator-calibrated `hijri_offset_days = -1`:

| `HIJRI_OFFSET_DAYS` in the environment | After `db:seed --force` |
|---|---|
| absent | **-1 — survives** (this is what the seeder's own docblock describes) |
| `0` | **0 — the calibration is silently reverted** |
| `-1` | -1 |
| `-5` | seed **throws** `InvalidArgumentException`, row unchanged at -1 |

`docker-compose.production.yml` passes `${HIJRI_OFFSET_DAYS:-0}`, so the variable is **always
present in the container** and row two is the production case. The plan's §3.3 correction to
`docs/RUNBOOK-DEPLOY.md` is correct and the seeder's docblock ("NEVER reverted to 0 by a re-seed")
is wrong under this compose file.

**One consequence the plan does not draw:** the out-of-range throw happens at the END of
`ReferenceSeeder`, so `AccessControlSeeder` never runs. A bad `HIJRI_OFFSET_DAYS` does not just
fail the seed step — it fails it with the capability seed not applied. Recoverable by fixing the
variable and re-running; worth knowing before you stare at an empty Access Control screen.

### §6.6's two standing hazards, both confirmed

- Renaming PICU and re-seeding: **the rename is reverted.** `units.name` is re-asserted every run.
- Renaming the institution and re-seeding: **the rename survives.** `institutions.name` is
  CREATE-only, as `ReferenceSeederTest` claims.

---

## 4. After the upgrade, the app

Every screen was driven as a real request against the upgraded database (as Inertia XHR, so
controllers, policies and prop-builders all ran):

```
/up                            200      <- proves the DB connection
/endorsement/PICU              200      Endorsement/Index
/endorsement/WARD              200      Endorsement/Index
/admin/setup                   200      Admin/DepartmentSetup
/admin/structure/department    200      Admin/DepartmentProfile
/admin/structure/units         200      Admin/Units
/admin/structure/levels        200      Admin/Levels
/admin/structure/calendar      200      Admin/CalendarSettings
/admin/structure/periods       200      Admin/Periods
/admin/structure/holidays      200      Admin/Holidays
/admin/structure/clinics       200      Admin/Clinics
/clinics                       200      Clinics/Map
/admin/people                  200      Admin/People
/admin/rota                    200      Admin/MasterRota
/rota                          200      Rota
/admin/access-control          200      Admin/AccessControl
/admin/users                   200      Admin/Users
/admin/settings                200      Admin/Settings
```

A signed, locked day opened with all four names resolved through the backfilled `*_person_id`
columns (`Omar Bin Salem & Sons Trust Fellow`, `Hana Al-Otaibi`, `د. عبدالرحمن الشمري`,
`Faisal Al-Dosari`), its ~48 KB rich-text fields round-tripping intact with the Arabic and the `&`
still there, and `extra_fields` present on the row.

**`/rota` and `/admin/rota` project no `email` and no `phone`** — asserted against the response
bytes for an administrator holding `people.manage`, which is the most permissive viewer the system
can produce. P1d-2 Decision C holds on real data.

---

## 5. The retired unit — §5.1's counter-check firing for real

The rehearsal world carried a fifth `units` row (`PCCU`) that had stopped being used. Migration
#1's backfill is code-matched to the four seeded codes, so it left that row `active = 0`,
`display_order = 1000`, `bar_class IS NULL`, and #13 left `training_rotation = 0`,
`call_target = 0`.

```
SELECT COUNT(*) FROM units WHERE bar_class IS NULL OR display_order = 1000;   -- 1
```

The plan says this MUST return 0 and calls a non-zero answer "a units row the backfill did not
match — it is now inactive and unroutable". Measured, that is precisely right, and the visible
consequence is: **`/endorsement/PCCU` returns 404 and the two historical handovers on that unit
become unreachable from the app.** The rows are not lost; the screen is. The remedy is data
(`active = 1`, a `bar_class`, a `display_order`), never editing a migration that has run.

At `8886f8d` `units` has no creation screen and no `active` column, so on the QCH instance this
count is expected to be 0 — but this is the check to run, and it now has a measured meaning.

---

## 6. Defects found — three broken `down()` methods

**All three are fixed on this branch, in one commit, with a test that was watched failing against
each of them in turn.** No `up()` was touched; the forward deploy path is unchanged.

`migrate:rollback` fails on SQLite at three separate migrations, each with the same message:

```
error in index <name> after drop column: no such column: "<column>"
```

| Migration | The column | The index nothing dropped |
|---|---|---|
| `2026_08_14_120002_add_provenance_to_person_levels` | `promotion_batch_id` | `person_levels_promotion_batch_id_index` |
| `2026_08_10_120005_add_person_id_to_invitations` | `person_id` | `invitations_person_id_index` |
| `2026_08_10_120001_create_people_and_link_users` | `person_id` | `users_person_id_unique` |

SQLite refuses to drop a column while any index still names it.
`dropConstrainedForeignId()` emits `dropForeign` + `dropColumn` and **nothing that removes a
separate index on the same column**.

The third is the instructive one. Its `down()` carries a comment stating the opposite as fact:

> `dropConstrainedForeignId()` drops the foreign key constraint AND its column (and, with it, the
> unique index that lived on that same column) in one pass

That belief is what the 2026-08-09 MySQL rehearsal left behind when it fixed InnoDB's
`1553 Cannot drop index 'users_person_id_unique': needed in a foreign key constraint` by deleting
the `dropUnique()` call. **The two engines constrain opposite ends of the same statement** —
InnoDB will not drop the index while the FK exists, SQLite will not drop the column while the
index does — so the MySQL fix broke the SQLite path and nothing noticed, because nothing in this
suite has ever called a `down()`.

The fix is the order that satisfies both: **constraint, then index, then column.**

### The part that is worse than a failed command

`migrate:rollback` exits 1 **mid-batch**. By the time #16 refuses, the five migrations after it
have already been reversed and their tables dropped:

```
2026_08_16_120002_create_demo_rows_table ................ DONE
2026_08_16_120001_create_clinics_and_attendees_tables ... DONE
2026_08_15_120004_create_vacations_table ................ DONE
2026_08_15_120003_create_master_rota_assignments_table .. DONE
2026_08_15_120002_correct_ward_clinic_owner ............. DONE
2026_08_14_120002_add_provenance_to_person_levels ....... FAIL
```

`migrations` holds 39 of 44 rows, migrations 1–17 read `Ran` and 18–22 read `Pending`, and the
rota, the vacations, the clinics and the demo ledger are gone. The plan's §7.3 rollback table
describes each `down()` in isolation; it does not say that a rollback which stops in the middle
has already destroyed everything below the stopping point.

**The same is true of migration #17's refusal, which is not a defect.** Its `down()` throws rather
than truncate a clinical field over TEXT's 65,535 bytes, and it does exactly that — measured, with
a 95,976-byte row:

```
RuntimeException: Refusing to roll back: `handovers`.`disease` holds a value 95976 bytes long,
over TEXT's 65,535-byte limit. Rolling back would silently truncate a clinical field.
```

The guard works. But by the time it speaks, `demo_rows`, `clinics`, `clinic_attendees`,
`vacations` and `master_rota_assignments` have already been dropped. §7.3's "17 refuses rather
than truncating — that refusal is the migration working, not a fault" is true about #17 and
misleading about the operation as a whole.

### The clean rollback, once the three are fixed

All 22 reverse in 1.0 s, and the result is the `8886f8d` schema:

- schema `diff` against the pre-upgrade snapshot: **cosmetic only** — SQLite's table rebuilds add
  `on update no action` to some foreign keys, and `users.full_name` / `users.position` come back
  at the END of the column list because SQLite ignores `after()`.
- data: 13 users, 146 handovers, 45 sign-offs, 63 audit rows, 4 invitations, 5 units — **all
  intact**.
- `audit:verify`: `Audit chain intact: 63 rows verified.`
- migration #6's `down()` restored `full_name` and `position` correctly for all 13 accounts
  through `person_id`, soft-deleted account included.
- re-applying all 22 afterwards works, and re-derives every backfill.

**One lossy detail in that round trip, worth knowing:** the account whose `full_name` was NULL
comes back with `full_name = 'legacy.import.42'` — its `member_name`, via #4's deliberate
fallback. A NULL name does not survive an up-then-down.

---

## 7. Divergences from `docs/DEPLOY-P1-2026-08-12.md`

### 7.1 `applied_role_defaults` is 22 → 35, not 49 → 62 — and 49 is not producible

§1.5 says to expect 49 rows before the deploy and §5.4 says 62 after. On a database seeded from
`8886f8d` the counts are **22 and 35**. The delta the plan cares about (+13) is exactly right; the
absolutes are not.

49 cannot be produced by any commit in this repository's history. `AccessControlSeeder` writes one
marker per `(position, capability)` in `ROLE_DEFAULTS` and never re-asserts, so the count is the
size of the union of every `ROLE_DEFAULTS` the instance has ever seeded. Across the whole history
of that file: 17 (`7954ea2`) → 22 (`1baa154`) → 23 (`2c48ec9`) → 24 (`5badf19`) → 31 → 30 → 35, and
`8886f8d` sits at **22**. Even counting the one marker a very old instance would keep from the
retired Nurse position, the ceiling at `8886f8d` is **23**.

Treat §1.5's captured `migrate:status`/count output as authoritative on the day, and check the
**delta**, not the absolute.

### 7.2 A redeployed old image does not 500 — it 403s, and `/up` stays green

§0 and §7.2 say redeploying `8886f8d` after migration #6 "produces a site that 500s on every
request", because the old code "reads both as real columns in twelve files". Measured, running the
`8886f8d` code against the upgraded schema:

```
$user->full_name = NULL          <- Eloquent returns null for a missing attribute; no error
$user->position  = NULL
/up                      200     <- the container reports healthy
/endorsement/PICU        403
/profile                 403
/admin/users             403
WRITE users.full_name    QueryException: no such column: full_name
```

`$user->position` resolving to NULL means `AccessControl` matches no `role_capabilities` row, so
**every `cap:`-gated route denies — including `/profile`**. The site does not fall over; it stays
up, healthy, and refuses everybody. Any write naming a dropped column is a 500.

The conclusion the plan draws is unchanged and still correct — **do not redeploy the old image
after #6** — but the detection story is the opposite of what it says: `/up` returns 200, so the
container healthcheck and `endorsement-uptime-check` will both be satisfied while nobody can open
a handover sheet. Nothing pages you.

(`app/` at `8886f8d` mentions `full_name` in **14** files, not twelve. `HandleInertiaRequests` is
among them, as stated.)

### 7.3 The rollback table does not say that a refusal has already destroyed what came after it

§6 above. Applies to #16's defect and to #17's working guard alike.

### 7.4 `migrate:rollback` cannot be rehearsed at all before the fix in §6

§7.3 offers `migrate:rollback --step=N` as a real option and says the full-batch order "was
verified clean against real MySQL 8.4 for the identity subset". On the engine every developer and
all of CI actually has, it fails at the first `down()` that drops an indexed column. The plan's
rollback section was, in practice, untestable.

### 7.5 §5.1's expected output assumes exactly four `units` rows

The stated result lists four rows and the following query "MUST return 0". Both are true of a
four-unit instance; a fifth row (which the new Admin → Structure → Units screen can now create at
any time after this deploy) changes both. Phrase the check as *"every row this deploy expects to be
routable has a `bar_class` and a `display_order` under 1000"*, and read the rest.

### 7.6 A pre-`8886f8d` migration was edited after the deploy

`git diff 8886f8d..HEAD -- database/migrations` lists **23** files, not 22.
`2026_07_24_120001_create_reference_tables.php` — already run in production — was modified in
`6a2f4c5`. The change is **comment-only** (D11 wording on `institutions`) and no schema differs, so
a fresh install and an upgraded instance still converge. Harmless here; worth stating, because "22
migrations" and "23 changed migration files" are both true and only one of them is the deploy.

### 7.7 Commit and file counts

§0 says 262 commits and deploying commit `b186219`. `main` is now `be565d0`, **267** commits ahead
of `8886f8d`; the five extra are the documentation commits of 2026-08-12 (including the date-cast
correction and this plan itself). No migration or app-code change among them. Update the table's
"Deploying commit" line on the day, as §0 already instructs.

### 7.8 §9.3's collation query works and returns what it should

A deliberate probe pair (`ahmed.k` / `Ahmed.K`) — a shape MySQL's `utf8mb4_unicode_ci` UNIQUE index
would have refused and SQLite accepts — is reported by the plan's own query:

```
SELECT LOWER(member_name) AS folded, COUNT(*) FROM users GROUP BY folded HAVING COUNT(*) > 1;
-- ahmed.k  2
```

The query is right. Note it can only ever return rows on a SQLite rehearsal; on production it is a
check on historical `LegacyImport` output, as §9.3 says.

### 7.9 `db:seed --force` timing, and what else it touched

Both seeders together: ~1.0 s, exit 0. `positions`, `units` (beyond `name`), `institutions.name`,
`app_settings`, `user_capabilities` — all unchanged, as §4.3's table says. `sessions` and `cache`
grew only because of the rehearsal's own requests.

Everything else in §2, §4.3, §5.1, §5.2, §5.3 and §5.4 that could be checked on SQLite **checked
out exactly as written**, including: the 22 pending migrations and their order; `extra_fields` is
`text` and not `json`; `master_rota_assignments.starts_on`/`ends_on` NOT NULL; `vacations` carries
no `period_id`; `demo_rows` UNIQUE `(table_name, row_id)`; all six new-table counts zero; the five
new capability keys; and `ALTER, CREATE, REFERENCES` sufficing (no `up()` of the 22 calls
`dropIfExists` or `DROP TABLE`; only #6 drops anything, and it does so with `ALTER`).

---

## 8. Row-count reconciliation

| Table | Before | After | |
|---|---|---|---|
| `users` | 13 | 13 | |
| `handovers` | 146 | 146 | |
| `handover_signoffs` | 45 | 45 | |
| `handover_revisions` | 12 | 12 | |
| `invitations` | 4 | 4 | |
| `units` | 5 | 5 | |
| `institutions` | 1 | 1 | |
| `push_subscriptions` | 2 | 2 | |
| `reminder_preferences` | 3 | 3 | |
| `user_capabilities` | 1 | 1 | |
| `app_settings` | 1 | 1 | |
| `positions` | 5 | 5 | |
| `audit_log` | 60 | 63 | +3 written by the rehearsal's own requests, chained cleanly |
| `capabilities` | 9 | 14 | seed |
| `role_capabilities` | 22 | 35 | seed |
| `applied_role_defaults` | 22 | 35 | seed |
| `migrations` | 22 | 44 | +22 |
| `people` | — | 13 | migration #4 |
| `levels` | — | 5 | seed |
| 9 other new tables | — | 0 | |

**No table lost rows. No table disappeared.**

---

## 9. The reminder opt-in on the retired unit

`reminder_preferences` still holds a row pointing at `PCCU`, which is now `active = 0`. Nothing
broke, and nothing surfaces it — the same stranding shape design §14 item 23 records for a merged
unit, reached through a different door (retirement rather than merge). Recorded, not fixed; it is
outside this rehearsal's scope.

---

## 10. What this rehearsal changed in the repository

- `database/migrations/2026_08_10_120001_create_people_and_link_users.php` — `down()`
- `database/migrations/2026_08_10_120005_add_person_id_to_invitations.php` — `down()`
- `database/migrations/2026_08_14_120002_add_provenance_to_person_levels.php` — `down()`
- `tests/Feature/Build/MigrationChainRollsBackTest.php` — new; reverses the whole chain and
  re-applies it, so no future migration silently escapes it

Nothing else. No `up()`, no application code.

---

## 11. What this rehearsal structurally could NOT test

Docker was down; MySQL was unavailable. Everything here ran on SQLite, and these are the questions
that answer differently — or cannot be asked at all — on the engine production uses.

1. **`ALGORITHM=COPY` on migration #17.** SQLite rewrites the table cheaply and reports 72 ms.
   MySQL 8.4 cannot apply `TEXT` → `MEDIUMTEXT` in place and rebuilds `handovers` four times, once
   per column, **blocking writes** for the duration. The plan's §1.6 sizing is still entirely
   unverified, and this rehearsal's timing is actively misleading about it. The same applies to
   #17's `down()`.
2. **Non-atomic DDL.** SQLite has transactional DDL, so every migration here was all-or-nothing
   and no migration could half-apply. **MySQL has none.** A `Schema::create` whose `CREATE TABLE`
   commits and whose `ADD CONSTRAINT` then fails leaves a table that is not recorded in
   `migrations` — the `1050 Table already exists` trap the plan's §4.2 documents. Migrations #1,
   #7, #10, #13, #16, #19, #20 and #21 all emit more than one statement. **Nothing in this
   rehearsal could have produced that failure.**
3. **Collation.** `utf8mb4_unicode_ci` is case- and accent-insensitive and PAD SPACE.
   `users.member_name`, `people.short_name`, `levels.code` and `unit_field_definitions.key` all
   compare differently there. The rehearsal deliberately created a case-colliding pair MySQL would
   have refused outright.
4. **`lockForUpdate()`.** `SQLiteGrammar::compileLock()` returns `''`, so all four call sites —
   the audit chain tail above all — were no-ops here, exactly as they are in the 1,679-test suite.
   A forked chain under concurrency is not reachable in this rehearsal.
5. **`DATE` truncation.** MySQL's `DATE` type is expected to drop a time component that SQLite
   stores verbatim (the plan's §9.1, itself explicitly marked *reasoned, not measured*). Still
   unmeasured.
6. **Privileges.** `GRANT ALTER, CREATE, REFERENCES` was verified by reading all 22 `up()`
   methods, not by running under a restricted credential. SQLite has no privilege model.
7. **JSON columns.** MySQL re-serialises a `JSON` column on `SELECT` (the space-after-comma the
   plan notes) and rejects the base64 an encrypted payload would put in one. SQLite maps `json()`
   to TEXT and no difference is observable.
8. **Index usage.** Every `EXPLAIN` question in §9.6 — the 47 non-sargable `whereDate()` call
   sites against the new rota indexes — is unanswerable here.
9. **The real data volume.** 146 handovers, not tens of thousands. Every timing above should be
   read as "the migration ran", never as "the migration is fast".
10. **The deploy itself.** Coolify, the container recreate caused by the new `db.command`, the
    `INSTANCE_SLUG` rename and its effect on backup retention, `ext-intl` and the Umm al-Qura data
    in the built image, `scripts/verify-live.sh`, and the backup/restore drill are all outside a
    local worktree entirely.

**The one thing a SQLite rehearsal was uniquely able to find** is the class of defect in §6: three
`down()` methods that MySQL would have accepted and SQLite refuses. They were invisible for the
opposite reason to everything in this section — not because the engine hid them, but because
nothing had ever run the code path at all.
