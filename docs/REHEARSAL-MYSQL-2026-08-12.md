# MySQL rehearsal — 2026-08-12

**What this is.** `docs/REHEARSAL-UPGRADE-2026-08-12.md` ran the real upgrade path — `8886f8d` →
`main`, 22 migrations, against a populated database — **on SQLite**, because Docker was down. Its
§11 listed ten things that engine structurally could not show. `docs/DEPLOY-P1-2026-08-12.md` §9
listed the MySQL-only hazards those ten leave open, several of them explicitly marked *reasoned,
not measured*.

This document is that list, run. **Nothing in this repository had ever executed against MySQL.**
Now the forward path, the rollback, a forced mid-migration failure, the privileges, the collation,
the `DATE` question, `lockForUpdate()`, `audit:verify`, the `EXPLAIN` plans and the **full 1,680-test
suite** have.

Branch `test/mysql-upgrade-rehearsal`. **Not merged.**

**What was changed, and it is deliberately small.** One migration — `2026_08_14_120002`, which has
**not run in production** — has its `hasColumn` guard split so the index and the foreign key carry
their own existence checks (§5.6, the one real defect). One test assertion that had never tested
anything is repaired (§13). Six code comments in `EndorsementController::updateSignoff()` are
updated to say *measured* where they said *reasoned* (§8). Everything else is documentation.
No behaviour of the running application is altered by any of it.

---

## 0. Verdict

| | |
| --- | --- |
| Engine | MySQL **8.4.10**, the digest `docker-compose.production.yml` pins, production's flags |
| Pending migrations at `8886f8d` | **22**, the plan's §2 list, in order |
| `migrate --force`, 190 handovers | **exit 0**, 22 DONE, **6.6 s** of migration time, 8.1 s wall |
| `migrate --force`, 24,320 handovers | **exit 0**, 22 DONE, **2 m 48 s** — of which **#17 is 2 m 42 s** |
| `db:seed --force` | exit 0; 9→14 capabilities, 22→35 role_capabilities, 22→35 applied_role_defaults, 5 levels — **the SQLite rehearsal's numbers exactly** |
| `audit:verify` before / after / after new writes | intact 60 / intact 60 / intact 63 |
| `migrate:rollback` (all 22) | **exit 0, 2.8 s** — the three `down()` fixes made for SQLite are correct on MySQL too |
| Rows lost | none, in either direction |
| Every screen after the upgrade | 200 (except `/endorsement/PCCU`, the retired unit — 404 by design) |
| **New defects in application code** | **one** (§5.6) — a `hasColumn` guard that skips an index on retry, silently and permanently. Fixed, with a test watched failing first |
| **Corrections to `docs/DEPLOY-P1-2026-08-12.md`** | **seven** (§12) |

**The single most valuable sentence:** the deploy plan's §9.0 statement counts — compiled, never
executed — are **exactly right**, all 22 of them, and `ALTER, CREATE, REFERENCES` genuinely
suffices. The one number that is badly wrong is **§1.6's sizing query**, which under-reported this
rehearsal's `handovers` table by **150×** (§4.2). That is the number an operator uses to decide
whether they need a maintenance window.

---

## 1. The target

```
docker run -d --name endorse-mysql-rehearsal \
  -e MYSQL_ROOT_PASSWORD=… -e MYSQL_DATABASE=endorsement \
  -e MYSQL_USER=endorse_app -e MYSQL_PASSWORD=… -p 13306:3306 \
  mysql:8.4@sha256:8dbcf531a03aade657e181b9cf2f1d1803ce621a1d55610cb44cb531ab7d7db6 \
  --general-log=0 --skip-log-bin --default-time-zone=+00:00
```

The image is pinned by the **same digest** as `docker-compose.production.yml`, and the three flags
are the three that file passes — including `--default-time-zone=+00:00`, added after the 2026-08-09
ops rehearsal. **The compose file specifies nothing else**: no charset, no collation, no `sql_mode`.
Verified rather than assumed — what the container therefore runs, and what production therefore
runs, is:

| | |
|---|---|
| `character_set_server` / `collation_server` | `utf8mb4` / **`utf8mb4_0900_ai_ci`** (8.4's own default) |
| `sql_mode` | `ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION` |
| `transaction_isolation` | `REPEATABLE-READ` |
| `innodb_lock_wait_timeout` / `lock_wait_timeout` | 50 s / **31,536,000 s (one year)** — see §4.4 |
| global + session `time_zone` | `+00:00`; `NOW()` and `UTC_TIMESTAMP()` agree |
| `log_bin` / `general_log` | 0 / 0 |

**But every table is `utf8mb4_unicode_ci`, not the server default** — all 27 at `8886f8d`, all 38
after the upgrade. That is `config/database.php` line 67 reaching the MySQL grammar's
`compileCreate`, exactly as the plan's §9 header says. The divergence between server default and
table collation is real and is what §5 is about.

**Method.** Two `git worktree`s — one detached at `8886f8d`, one at the branch tip — each with its
own `composer install` at its own lock, sharing one `APP_KEY` so the encrypted columns written by
the old tree are readable by the new one. The developer database, the real `.env` and `main` were
never touched.

## 2. The world

`docs/REHEARSAL-UPGRADE-2026-08-12.md` §2's department, rebuilt through the models at `8886f8d` so
every cast, sanitiser and encryption envelope is the shape production holds:

- **13 accounts** in every shape the backfills must survive — linked, deactivated, soft-deleted,
  `full_name IS NULL`, `institution_id IS NULL`, no email
- **5 units** — the four seeded codes plus a retired `PCCU` that migration #1's code-matched
  backfill will not recognise
- **190 handovers** across five units and sixteen dates, ~25 KB of encrypted rich text per row,
  Arabic and `&` throughout, PHI columns genuinely encrypted
- **48 sign-offs** naming people in all four roles, with signed, locked and reopened days, one row
  with no `endorsed_to`, one with no `consultant_by`, and one whose stored name was edited after
  signing (the plan's §5.2 copy-instead-of-join detector)
- **4 invitations** in four states; push subscriptions; reminder opt-ins including one on the unit
  nobody uses
- **60 hash-chained `audit_log` rows** written through `AuditLog::record()`

**One thing could not be built, and that is itself a measurement.** The SQLite rehearsal planted an
oversized rich-text row. On MySQL at `8886f8d` that row **cannot exist**:

```
SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'disease'
```

Migration #17 is what makes it possible. The refusal is the migration's premise, reproduced on the
engine that enforces it.

**Then scaled.** A clone (`endorsement_big`) with the `handovers` rows doubled seven times:

| | |
|---|---|
| rows | **24,320** |
| rich text (`SUM(LENGTH())` over the four columns) | **600 MB** |
| tablespace (`INNODB_TABLESPACES.FILE_SIZE`) | **908 MB** |
| average per row | ~25.9 KB of rich text, ~37 KB of tablespace |

**How that compares to the live instance.** QCH deployed `8886f8d` on 2026-08-08 and has been in
use four days, on four units, with no legacy import run. A plausible live figure is **low hundreds
of handover rows**, i.e. this rehearsal's *unscaled* 190 — and its rich text will be far smaller
per row than this synthetic 25 KB, which was sized deliberately to make the rebuild measurable.
**Read §4 as an upper bound by a wide margin, and measure the real table with §4.2's corrected
query before the deploy.**

---

## 3. The forward run

Both volumes, Laravel's own per-migration timings. `migrations` reached 44 rows in both.

| # | Migration | 190 rows | 24,320 rows |
|---|---|---|---|
| 1 | `add_configuration_to_units` | 341.87 ms | 235.93 ms |
| 2 | `create_unit_field_definitions_table` | 190.77 ms | 136.47 ms |
| 3 | `add_extra_fields_to_handovers` | 48.87 ms | **95.00 ms** |
| 4 | `create_people_and_link_users` | 691.24 ms | 434.80 ms |
| 5 | `create_levels_and_person_levels` | 542.79 ms | 251.06 ms |
| 6 | `move_name_and_position_off_users` | 130.36 ms | 131.70 ms |
| 7 | `add_person_ids_to_handover_signoffs` | 597.73 ms | 762.00 ms |
| 8 | `add_person_id_to_invitations` | 207.89 ms | 129.23 ms |
| 9 | `backfill_institution_on_identity_rows` | 30.50 ms | 6.53 ms |
| 10 | `add_calendar_settings_to_institutions` | 375.43 ms | 139.60 ms |
| 11 | `create_periods_table` | 163.31 ms | 102.09 ms |
| 12 | `create_holidays_table` | 210.94 ms | 108.24 ms |
| 13 | `add_munawib_configuration_to_units` | 278.64 ms | 167.99 ms |
| 14 | `add_external_to_levels` | 62.12 ms | 39.07 ms |
| 15 | `add_contact_visibility_to_institutions` | 47.59 ms | 30.52 ms |
| 16 | `add_provenance_to_person_levels` | 341.61 ms | 190.66 ms |
| 17 | `widen_rich_text_handover_columns` | **1.00 s** | **2 m 42 s** |
| 18 | `correct_ward_clinic_owner` | 5.00 ms | 24.67 ms |
| 19 | `create_master_rota_assignments_table` | 564.02 ms | 690.94 ms |
| 20 | `create_vacations_table` | 222.35 ms | 228.92 ms |
| 21 | `create_clinics_and_attendees_tables` | 463.25 ms | 832.25 ms |
| 22 | `create_demo_rows_table` | 83.89 ms | 104.88 ms |
| | **wall** | **8.1 s** | **2 m 48 s** |

**Every migration except #17 is flat in the size of `handovers`.** Migration #3 adds
`extra_fields` to that same 908 MB table in **95 ms** — MySQL applies an added nullable column with
`ALGORITHM=INSTANT`, which is why only #17's *retype* costs anything. #7's 762 ms is 12 statements
over 48 sign-off rows, not a function of handover volume.

### Backfills, verified

| Backfill | Result |
|---|---|
| #4 → `people` | 13 rows, one per account, soft-deleted account included; `full_name IS NULL` fell back to `member_name` |
| #4 → `users.person_id` | 13 of 13, 0 NULL, 0 shared |
| #7 → `handover_signoffs.*_person_id` | `endorsed_by` 48/48, `endorsed_to` 45/45, `consultant_by` 45/45, `consultant_to` 36/36; all four "user id set, person id null" checks return 0 |
| #9 → `institution_id` | 0 NULL on both `people` and `users` afterwards |
| #1 / #13 / #18 → `units` | four seeded codes fully configured, WARD `clinic_owner = 1`; **`SELECT COUNT(*) FROM units WHERE bar_class IS NULL OR display_order = 1000` returns 1**, the retired `PCCU`, and `/endorsement/PCCU` 404s — §5.1's counter-check firing for real on MySQL |

`db:seed --force` afterwards: capabilities 9→14, `role_capabilities` 22→35,
`applied_role_defaults` 22→35, `levels` 0→5 (`R1 R2 R3 R4 EXT`, 10/20/30/40/90, `EXT.external=1`).
`SELECT LOWER(code), COUNT(*) FROM levels GROUP BY 1 HAVING COUNT(*)>1` returns nothing.

### Every screen, on MySQL

Driven as real Inertia XHRs against the upgraded database, as an administrator holding
`people.manage` — the most permissive viewer the system can produce:

```
/up 200 · /endorsement/PICU 200 · /endorsement/WARD 200 · /endorsement/PCCU 404 (retired, by design)
/admin/setup 200 · /admin/structure/{department,units,levels,calendar,periods,holidays,clinics} 200
/clinics 200 · /admin/people 200 · /admin/rota 200 · /rota 200
/admin/access-control 200 · /admin/users 200 · /admin/settings 200
```

`/rota` and `/admin/rota` project **no `email` and no `phone`** — P1d-2 Decision C holds on MySQL.
A signed, locked day resolved all four named roles through the backfilled `*_person_id`
(`Hana Al-Otaibi`, `Sara Al-Qahtani`, `د. عبدالرحمن الشمري`, `Faisal Al-Dosari`), and its rich text
round-tripped with the Arabic and the `&` intact.

---

## 4. Migration #17 — the rebuild, timed

### 4.1 Per statement

Laravel sends **four separate `ALTER TABLE handovers MODIFY`** statements, one per column
(confirmed from MySQL's own general query log — §6). Each is a full `ALGORITHM=COPY` rebuild of the
whole table. Timed individually at 24,320 rows / 908 MB:

| Statement | `TEXT` → `MEDIUMTEXT` (the deploy) | `MEDIUMTEXT` → `TEXT` (the rollback) |
|---|---|---|
| `MODIFY disease` | 22.3 s | 21.2 s |
| `MODIFY details` | 38.1 s | 42.9 s |
| `MODIFY plan` | 42.1 s | 46.0 s |
| `MODIFY nevent` | 44.9 s | 40.3 s |
| **four statements** | **147.4 s** | **150.4 s** |
| Laravel-reported for the migration | **162 s** | |

The four are not proportional to their columns' sizes — `disease` is the largest and the fastest —
because each rebuilds the same table; the spread is I/O warm-up, not column width. Forward and
reverse cost the same. **Effective rebuild throughput: 4 × 908 MB / 150 s ≈ 24 MB/s**, on Docker
Desktop for Windows on arm64, through a virtualised filesystem. A bare-metal NVMe host will be
faster; treat this as an upper bound.

### 4.2 Sizing it — and the correction to §1.6

**`docs/DEPLOY-P1-2026-08-12.md` §1.6's query is wrong in a direction that will reassure an
operator falsely.** It reads `(data_length + index_length)` from `information_schema.TABLES`, which
for InnoDB is derived from *persistent statistics* that can be arbitrarily stale. Measured on this
rehearsal's own tables:

| | §1.6's query | after `ANALYZE TABLE` | real (`INNODB_TABLESPACES.FILE_SIZE`) |
|---|---|---|---|
| 190-row `handovers` | **0.08 MB** | 8.58 MB | **15 MB** |
| 24,320-row `handovers` | 893.6 MB | 893.6 MB | **908 MB** |

**A 150× under-report on the small table** — which is the size the live instance actually is. The
same query's `table_rows` is an estimate too: 121 against an actual 190, and 16,216 against an
actual 24,320 (**-33%**).

Use this instead. It is exact, needs no `ANALYZE`, and reads one row:

```sql
SELECT ROUND(FILE_SIZE/1024/1024, 1) AS handovers_mb
  FROM information_schema.INNODB_TABLESPACES
 WHERE NAME = '<DBNAME>/handovers';

SELECT COUNT(*) AS handover_rows FROM handovers;          -- exact, unlike table_rows
```

**Then: `seconds ≈ handovers_mb / 6`.** (908 / 6 = 151 s against a measured 147–162 s; 15 / 6 =
2.5 s against a measured 1.0 s — the formula is conservative at small sizes, which is the safe
direction.) At any plausible live figure — a few hundred rows, well under 50 MB — **this migration
is under ten seconds and needs no window at all.** Measure before you believe that.

### 4.3 Reads continue, writes block — measured

With `ALTER TABLE handovers MODIFY disease text NULL` running on the 908 MB table, from a second
session:

```
SELECT COUNT(*) … WHERE unit_id=1 AND handover_date='2026-07-25'    →  completed in     5.8 ms
INSERT INTO handovers …                                             →  blocked  for 33.4 s
```

The read was unaffected. The write blocked until the `ALTER` finished. The migration's own
docblock and the runbook's "do not run this during a shift change" are correct.

### 4.4 The hazard nobody has written down: `lock_wait_timeout` is one year

`@@lock_wait_timeout` is **31,536,000 seconds** — MySQL 8.4's default, and this stack sets nothing
else. That is the timeout on the **metadata** lock an `ALTER TABLE` must take. If any session holds
an open transaction that has touched `handovers` when migration #17 starts — a long-running report,
an abandoned connection, a `mysql` shell somebody left in a `BEGIN` — the `ALTER` does not fail. It
**waits, silently, indefinitely**, and every write to `handovers` queues behind it, because a
pending metadata lock request blocks new readers too.

`@@innodb_lock_wait_timeout` (50 s) does not cover this; it governs row locks, not metadata locks.

**Recommended, and not currently in the plan:** before running the migrations, check for open
transactions, and consider setting a bounded `lock_wait_timeout` for the migrating session so a
blocked `ALTER` fails fast and visibly instead of hanging the ward.

```sql
SELECT trx_id, trx_started, trx_mysql_thread_id, trx_query
  FROM information_schema.INNODB_TRX ORDER BY trx_started;   -- expect none older than seconds
SHOW PROCESSLIST;                                            -- look for long `Sleep` in a txn
```

---

## 5. Non-transactional DDL — the trap, sprung

### 5.1 The statement counts are exactly right

`docs/DEPLOY-P1-2026-08-12.md` §9.0's table was **compiled** against Laravel's MySQL grammar, never
executed, and says so. Executed — MySQL's own general query log, parsed per migration by the
`insert into migrations` rows that bracket each one:

```
migrations: 22   statements: 139   emitting >1: 18
```

**18 of 22, the plan's number, and every per-migration count matches its table** (#1 reads 14 in
the raw log only because a connection-level `use `endorsement`` lands inside it; the 13 the plan
states is right). #4 is 35 — 9 DDL plus two DML per account × 13 accounts — precisely as the plan
predicts. #3, #14, #15 are one `ALTER` each and #18 is one `UPDATE`, so exactly three of the 22 are
a single DDL statement.

### 5.2 One correction that matters for recovery

The plan describes #7 as *"12 — 4 adds + 4 constraints + 4 updates"*. The **order is interleaved**,
not grouped:

```
1. alter table handover_signoffs add endorsed_by_person_id …
2. alter table handover_signoffs add constraint handover_signoffs_endorsed_by_person_id_foreign …
3. alter table handover_signoffs add endorsed_to_person_id …
4. alter table handover_signoffs add constraint … endorsed_to …
5. add consultant_by_person_id      6. add constraint … consultant_by
7. add consultant_to_person_id      8. add constraint … consultant_to
9.–12. four UPDATE backfills
```

An operator reading "4 adds then 4 constraints" would count columns and infer that no constraint
had landed. **Wrong: every column that exists already has its foreign key.** The correct recovery
is per column, and it must drop the constraint *before* the column — measured:

```
ALTER TABLE handover_signoffs DROP COLUMN endorsed_by_person_id;
ERROR 1828 (HY000): Cannot drop column 'endorsed_by_person_id': needed in a foreign key constraint
```

### 5.3 Forced failure A — a `Schema::create` that stops between its two tables

A decoy `clinic_attendees` was planted, so migration #21 fails at statement 6 of 10. **What it left
behind:**

- `clinics` **fully built** — all twelve columns, both foreign keys (`institution_id` → SET NULL,
  `unit_id` → RESTRICT), both indexes.
- `migrations` at **42 of 44**. `migrate:status` lists #21 and #22 as `Pending`.
- **Nothing recorded anywhere that `clinics` exists.**

Removing the original cause and re-running `migrate` — the natural operator reflex — gives:

```
2026_08_16_120001_create_clinics_and_attendees_tables .. 18.97ms FAIL
SQLSTATE[42S01]: … 1050 Table 'clinics' already exists
```

**This is the 1050 trap §4.2 documents, reproduced.** `docs/RUNBOOK-DEPLOY.md`'s recovery is
correct and sufficient: `DROP TABLE clinics;` as root, then `migrate` — measured, all remaining
migrations applied, chain intact.

### 5.4 Forced failure B — the one that leaves the data half-migrated

Same method against migration #7 (a planted `consultant_to_person_id` stops it at statement 7 of
12). **What it left behind:**

- **3 of 4** `*_person_id` columns, and **3 of 4** foreign keys.
- **0 of 4 backfills.** `SELECT COUNT(*) FROM handover_signoffs WHERE endorsed_by_person_id IS NOT
  NULL` → **0**.
- `migrations` at 28 of 44 — everything after #7 unapplied.

The failure mode to understand is the second bullet. Had this been a failure at statement 10
instead of 7, the schema would be *complete* and only **some** of the four roles backfilled — a
database that looks migrated and silently drops names off medico-legal sign-offs. The plan's §5.2
verification queries are the detector, and this is why they are not optional.

Recovery needed both halves, in order — drop three foreign keys, then three columns, then re-run:
16 migrations applied and the backfill landed (48 rows).

### 5.5 Six of the 22 are safe to re-run over their own residue

Not previously stated anywhere. Migrations **#1, #3, #13, #14, #15 and #16** guard every column with
`Schema::hasColumn` (migration #1's `up()` says why, at length, and it is right). Measured: a
planted `units.active` column did **not** stop #1 — it skipped that column, added the other eight,
ran all four `UPDATE`s and completed. **The other sixteen carry no guard** and will fail on a
re-run against their own partial residue. That is the map for "can I just try again": for six of
them, yes — **five cleanly, and the sixth only after §5.6's fix, which is what pulling on this
found.** (#1 was proven empirically; the other five were read at source and every column they add
is guarded.)

---

### 5.6 THE ONE REAL DEFECT: a guard that covers a block, not a statement

**Found by pulling on §5.5, and it is the only application-code defect this rehearsal found.**
Fixed on this branch, in its own commit, with a test watched failing first.

Five of the six guarded migrations add plain columns and nothing else, so one `hasColumn` per block
is one guard per statement. **`2026_08_14_120002_add_provenance_to_person_levels` is the exception**
— it emits **five** statements from three blocks, because `->index()` and `->constrained()` are
each their own `ALTER`:

```
1. alter table `person_levels` add `promotion_batch_id` char(36) null after `effective_to`
2. alter table `person_levels` add index `person_levels_promotion_batch_id_index`(`promotion_batch_id`)
3. alter table `person_levels` add `reason` varchar(255) null after `promotion_batch_id`
4. alter table `person_levels` add `created_by` bigint unsigned null after `reason`
5. alter table `person_levels` add constraint `person_levels_created_by_foreign` … on delete set null
```

A failure landing between **1 and 2** leaves the column present. The retry's `hasColumn` check is
then true, so it skips the whole block — **including the index** — and Laravel records the
migration as Ran. Measured on MySQL 8.4, against exactly that residue:

```
2026_08_14_120002_add_provenance_to_person_levels .. 132.55ms DONE

index after re-run   0        <- gone, for ever
reason column        1        restored
created_by column    1        restored
created_by FK        1        restored
migrations rows     44        recorded as Ran; nothing will ever run it again
```

**The retry succeeds. That is what makes it dangerous** — there is no error, no warning, and
`migrate:status` reads clean. The same shape applies between **4 and 5**: the foreign key on
`created_by` disappears the same way.

Neither is catastrophic on its own — `promotion_batch_id`'s index is a performance aid for grouping
promotion audit rows, and `created_by`'s key is referential integrity the application also
maintains — but both are silent, permanent, and land on the *recovery* path, which is exactly when
nobody is looking closely. And this migration has **not run in production**: it is one of the 22
pending, so correcting it now is free.

**The fix**: the index and the foreign key carry their own existence checks
(`Schema::hasIndex()`, `Schema::getForeignKeys()`) rather than riding on the column's. On a database
where the migration already ran, every check is false and it is a no-op — asserted.

**The test**: `tests/Feature/Build/GuardedMigrationsRetryCompletelyTest.php`. It builds the residue
with the schema builder (engine-independent), re-runs `migrate`, and asserts the index and the key
come back — plus a third case proving a re-run against a *complete* table changes nothing, because
a guard that only works on residue would be worse than none. **Watched failing against the
pre-fix migration**: two of its three cases red, with the index and the key absent while every
other assertion passed. Green after the split, on SQLite and on MySQL.

**The general rule, worth carrying:** in a `hasColumn`-guarded migration, count the STATEMENTS the
block emits, not the columns. `->index()`, `->unique()` and `->constrained()` each add one.

---

## 6. Privileges — measured, not read

Provisioned the way the image does, on a database whose name contains an underscore
(`endorse_live`), which is the case `docs/sql/least-privilege.sql`'s header is about. Confirmed
first: `mysql.db.Db` genuinely stores `endorse\_live`, escaped.

| Step | Result |
|---|---|
| `migrate --force` holding only `SELECT, INSERT, UPDATE, DELETE` | **FAILS at statement 1**: `1142 ALTER command denied … for table 'units'` |
| `GRANT ALTER, CREATE, REFERENCES` then `migrate --force` | **all 22 DONE**, exit 0 |
| `REVOKE`, then `db:seed --force` | **exit 0** — no elevated privilege needed, as the plan says |
| `migrate:rollback` after the revoke | **FAILS**: `1142 DROP command denied … for table 'demo_rows'` — `DROP` really is rollback-only |
| `audit:verify` under least privilege | `Audit chain intact` |
| `AuditLog::record()` under least privilege | inserts fine |

**`ALTER, CREATE, REFERENCES` is exactly sufficient for all 22 `up()` methods, and the app cannot
migrate without it — both halves now measured rather than inferred.**

`docs/sql/least-privilege.sql` applied cleanly with its own documented substitution recipe, produced
both append-only triggers, and they hold:

```
UPDATE audit_log … as the app user   → ERROR 1644 (45000): audit_log is append-only: rows cannot be modified.
DELETE FROM audit_log … as the app user → ERROR 1644 (45000): audit_log is append-only: rows cannot be deleted.
DELETE FROM audit_log … as ROOT         → ERROR 1644 (45000): audit_log is append-only: rows cannot be deleted.
```

**One caution about the recipe, verified the hard way.** The four-backslash form the file documents
(`DBNAME_ESCAPED="${DBNAME//_/\\\\_}"`) is **correct**; a two-backslash form silently produces the
*unescaped* name and the `REVOKE` then dies `1141` with the triggers never created — the exact
silent-looking failure the header warns about. Copy the line, do not retype it.

---

## 7. Collation — every claim in §9.2–§9.4 confirmed, and one more

All the columns in question are `utf8mb4_unicode_ci`. Measured:

```
'r1' = 'R1'        EQUAL      'PICU' = 'PICU '   EQUAL  (PAD SPACE)
'a'  = 'á'         EQUAL      'ﺍﺣﻤﺪ' = 'احمد'    EQUAL  (Arabic presentation forms fold)
```

**Through the app's own lookups:** `Level::query()->where('code', 'r1')->first()` returns `R1`. So a
roster CSV containing `r1` **imports on production and is refused in every SQLite test**, exactly as
§9.2 says.

**Every UNIQUE index folds case.** Each of these was attempted as a raw INSERT against a row that
differs only in case, and each was refused `1062`:

| Index | Colliding pair | Result |
|---|---|---|
| `levels_code_unique` | `r1` / `R1` | **1062** |
| `users_member_name_unique` | `ahmed.k` / `AHMED.K` | **1062** |
| `people_short_name_unique` | `hana.o` / `HANA.O` | **1062** |
| `unit_field_definitions_unit_id_key_unique` | `weight` / `Weight` | **1062** |
| `units_code_unique` | `PICU` / `PICU ` (trailing space) | **1062** — PAD SPACE, and the server default `utf8mb4_0900_ai_ci` would **not** have refused this |
| `users_member_name_unique` vs a **soft-deleted** row | `softdeleted.acct` / `SOFTDELETED.ACCT` | **1062** — §9.3's provisioning surprise, confirmed |

**One the plan does not list: `people_email_unique` folds case too.** `HANA.O@EXAMPLE.TEST` is
refused against `hana.o@example.test`. That is benign — `Rule::unique` folds the same way, so the
user sees a 422 — but it means `Person::matchByEmail()` and `Person::accountEmailRule()` behave
differently on the two engines and no test can see it.

**So: yes, several UNIQUE indexes are stricter in production than the suite believes** — and the
SQLite rehearsal's deliberately planted `ahmed.k` / `Ahmed.K` pair could not have been created here
at all, which is what §9.3 predicted.

---

## 8. The DATE question — ruling 72's MySQL half, settled

§9.1's MySQL paragraph was labelled **REASONED, NOT MEASURED**. It is now measured, and **it was
right.**

A model with `protected $casts = ['d' => 'date']` over a MySQL `DATE` column, five consecutive rows,
needle `'2026-08-12'`:

```
SENT BY LARAVEL : '2026-08-12 00:00:00'        (Grammar::getDateFormat(), unchanged for MySQL)
STORED (DATE)   : '2026-08-12'                 <- MySQL TRUNCATES on storage

op     bare where()          whereDate()          verdict
=      08-12                 08-12                agrees
<=     08-10,08-11,08-12     08-10,08-11,08-12    agrees
>      08-13,08-14           08-13,08-14          agrees
<      08-10,08-11           08-10,08-11          agrees
>=     08-12,08-13,08-14     08-12,08-13,08-14    agrees
```

**All five operators agree on MySQL. All the divergence is SQLite's**, where the stored text
`"2026-08-12 00:00:00"` sorts strictly after the bare `Y-m-d` needle.

Two things worth adding, because they are not what the reasoning predicted:

1. **A `DATETIME` column written by the same cast also agrees.** The whole matrix passes there too.
   So the mechanism is not only `DATE`'s truncation — it is that MySQL coerces the `'2026-08-12'`
   *literal* to the column's type before comparing, whereas SQLite compares strings. Stating the
   rule as "MySQL truncates" understates why it is safe.
2. Under this `sql_mode` (`STRICT_TRANS_TABLES`), a raw `INSERT … VALUES ('2026-08-12 13:45:00')`
   into a `DATE` column is **accepted** and stored as `2026-08-12` — a warning, not an error.

**Ruling 72 stands, and its MySQL half can now be marked measured.** The mitigation is unchanged
and still correct on both engines: `whereDate()`, never a bare comparison. The six corrected code
comments need no further change — but see §9, which is the cost of that mitigation.

---

## 9. `whereDate()` and the indexes — §9.6 answered

`EXPLAIN` on the scaled database (24,320 handovers, 82,368 `master_rota_assignments`, statistics
refreshed):

| Query | wrapped `DATE(col)` — what `whereDate()` sends | bare |
|---|---|---|
| rota, full academic year | `type: ALL`, **82,194 rows** | `type: range`, 21,068 |
| rota, one block | `type: ALL`, **82,194 rows** | `type: range`, **264** |
| handovers, day range for a unit | `type: ref`, `key_len` **9**, 8,108 rows | `type: range`, `key_len` **13**, 92 |
| **handovers, one day for a unit** | `type: ref`, `key_len` **9**, **8,108 rows** | `type: ref`, `key_len` **13**, **4 rows** |

`EXPLAIN ANALYZE` on the last pair — the app's hottest query, the one behind
`/endorsement/<code>?date=…` and six other `EndorsementController` sites:

```
wrapped: Index lookup … (unit_id=1), with index condition (cast(handover_date as date) = '2026-07-25')
         (cost=2432 rows=8108) (actual time=1.85..3.22 rows=4)
bare:    Index lookup … (unit_id=1, handover_date=DATE'2026-07-25')
         (cost=4.4 rows=4)     (actual time=0.0168..0.0427 rows=4)
```

**75× slower in wall time, 2,000× in rows examined, to return the same four rows.**

Two refinements to §9.6, which says *"`type: ALL` means a full scan"*:

- **On the rota tables that is exactly right** — the wrapped column is the index's *leading*
  column, so the index becomes unusable and MySQL scans the table.
- **On `handovers` it is not `ALL`, and that is worse to reason about.** The composite index is
  `(unit_id, handover_date)`; `unit_id` is still an equality, so MySQL uses the index — but only
  its first column, and then filters every row for that unit. It looks like the index is working.
  It is doing a third of the work it should.

The fix §9.6 proposes — a half-open range `>= $from AND < $toPlusOne` — is confirmed correct on both
engines by §8's storage facts. **Do not attempt it during this deploy.** At the live instance's
current volume the amplification is a few hundred rows and invisible; it becomes real as
`handovers` grows, and it is the first performance work P2 should schedule.

---

## 10. `lockForUpdate()` — it locks

`SQLiteGrammar::compileLock()` returns `''`, so all four call sites are no-ops in all 1,680 tests.
Two concurrent PHP processes running the exact expression at `app/Models/AuditLog.php:64`:

```
[holder/mysql] took the chain-tail lock … holding 6s
[waiter/mysql] lockForUpdate() returned after 4474 ms  -> BLOCKED by the holder (the lock is REAL)
  SQL sent: select * from `audit_log` order by `id` desc limit 1 for update
```

The same probe against SQLite, as a control:

```
[waiter/sqlite] lockForUpdate() returned after 19 ms  -> returned immediately (NO LOCK)
  SQL sent: select * from "audit_log" order by "id" desc limit 1
```

**The chain-tail serialisation is real on the engine production runs.** Two concurrent audited
actions cannot read the same `prev_hash`. The same mechanism backs
`InvitationAcceptController`'s two locks and `DemoDepartment::remove()`'s pre-flight — and the
isolation level is `REPEATABLE-READ`, which is the premise ruling 59's `lockForUpdate()` fix was
written against, now confirmed rather than assumed.

---

## 11. `audit:verify` across the upgrade, at production's session timezone

| | |
|---|---|
| Before the upgrade | `Audit chain intact: 60 rows verified.` |
| After all 22 migrations | `Audit chain intact: 60 rows verified.` |
| After 3 more rows written by the **upgraded** code | `Audit chain intact: 63 rows verified.` |
| After a full `migrate:rollback` of all 22 | `Audit chain intact: 63 rows verified.` |
| Under `least-privilege.sql` (SELECT-only + triggers) | `Audit chain intact` |

`created_at` stores the Riyadh wall clock verbatim (`2026-08-12 12:42:33` with `APP_TIMEZONE=Asia/Riyadh`
and session `time_zone=+00:00`), so the round trip is the identity — which is what makes
`AuditChain::canonical()` v3's byte-verbatim hash stable.

**And the 2026-08-09 hazard reproduces exactly.** With the same unchanged rows:

```
SET GLOBAL time_zone='+03:00';   →  Audit chain BROKEN at row 1 — the row (or one before it) was altered or removed.
SET GLOBAL time_zone='+00:00';   →  Audit chain intact: 63 rows verified.
```

Nothing was written between those two commands. `docker-compose.production.yml`'s
`--default-time-zone=+00:00` is the only thing standing between this deployment and an alert saying
its clinical audit trail has been tampered with. **Never run `SET GLOBAL time_zone` against this
database.**

---

## 12. Corrections to `docs/DEPLOY-P1-2026-08-12.md`

| # | The plan says | Measured | Where |
|---|---|---|---|
| 1 | §1.6 sizes `handovers` with `(data_length + index_length)` from `information_schema.TABLES` | **Under-reports by 150× on a small table** (0.08 MB vs a real 15 MB) because InnoDB's persistent statistics are stale, and its `table_rows` is 33% low. Use `INNODB_TABLESPACES.FILE_SIZE` and `COUNT(*)` | §4.2 |
| 2 | §2/§9.0 note #17 is slow, with no figure | **147–162 s at 908 MB / 24,320 rows; ~24 MB/s of rebuild; `seconds ≈ MB / 6`.** Reads unaffected, writes block for the whole duration (33.4 s measured against one of the four statements) | §4.1, §4.3 |
| 3 | (nothing) | **`lock_wait_timeout` is one year.** An open transaction touching `handovers` makes #17 hang indefinitely rather than fail, and queues every subsequent read behind it. Check `INNODB_TRX` first | §4.4 |
| 4 | §9.0 lists #7 as "4 adds + 4 constraints + 4 updates" | **The order is interleaved, add-then-constraint per column.** Every existing column already has its FK, and recovery must drop the constraint first (`1828`) | §5.2 |
| 5 | §4.2: "assume [a half-apply] is possible for almost every one of the 22" | True for **sixteen**. **Six (#1, #3, #13, #14, #15, #16) guard every column with `hasColumn` and re-run cleanly over their own residue** — measured, not read | §5.5 |
| 6 | §9.1's MySQL half: "REASONED, NOT MEASURED … treat it as an inference" | **Measured, and correct.** Also true for `DATETIME` columns, because the mechanism is literal coercion, not only `DATE` truncation. Ruling 72's MySQL half can be marked measured | §8 |
| 7 | §9.6: "`type: ALL` means a full scan" | True on the **rota** tables. On `handovers` the wrapped predicate yields `type: ref` on the index's *leading column only* — it looks indexed and examines 2,000× the rows. 75× slower in wall time | §9 |

Also confirmed rather than corrected, and worth recording so it is not re-derived:

- §9.0's per-migration statement counts: **all 22 exact**, 18 emitting more than one statement.
- §9.8's encrypted-envelope constant: re-measured at **1.7825×** asymptotically (100 B → 3.72×,
  1 KB → 1.96×, 10 KB → 1.798×, 36,751 B → **65,508 B**, 27 bytes under `TEXT`'s ceiling). The
  bisected 36,751-byte plaintext ceiling is exact.
- `handovers.extra_fields` is `text`, **not** `json` — P0b's rule held through the migration.
- MySQL **does** re-serialise a `json` column on `SELECT`: `units.extra_row_fields` written as
  `["age","ward_unit"]` reads back as `["age", "ward_unit"]`. §5.1's space-after-comma is real.
- All 22 `down()` methods succeed on MySQL. **The three fixed for SQLite in `3967ca4` — constraint,
  then index, then column — are correct on MySQL too**, which is the direction the 2026-08-09
  rehearsal got wrong when it fixed InnoDB's `1553` by deleting a `dropUnique()`.
- #17's `down()` guard fires on MySQL against a real 133,008-byte row, **and by the time it speaks
  five tables are already gone** (`migrations` at 39 of 44, 33 of 38 tables). The SQLite
  rehearsal's §6 warning is engine-independent.

---

## 13. The full suite on MySQL — the first time it has ever been run

`DB_CONNECTION=mysql` against the container, `phpunit.xml` copied with the four `DB_*` keys forced
and `LOG_CHANNEL=null`. **1,680 tests, 15 m 54 s, 142 MB peak.**

| | |
|---|---|
| Passed | **1,659** |
| Failed / errored | **21** |
| Of those, genuine production risks | **0** |
| Of those, a test that never tested anything | **1** — fixed |
| Of those, an artifact of this rehearsal's own server | **5** — proven, see below |

**None of the 21 is a defect in application behaviour.** Every one is a test whose fixture, or
whose harness, assumes SQLite. That is a genuinely good result for a 1,680-test suite meeting a
different engine for the first time — and the interesting part is *which* assumptions they are.

### The taxonomy

| # | Class | Tests | What it is | Verdict |
|---|---|---|---|---|
| 1 | **`users.id` and `people.id` only agree on SQLite** | 4 | `ChiefResidentTest::test_a_chief_remains_a_resident_clinically`, `HandoverSignoffTest::test_sheet_exposes_the_signoff_and_the_staff_pickers`, `RosterOnlyCannotAuthenticateTest` ×2. Each compares a **user** id against a value that is a **person** id (or assumes "the first person takes id 1"). SQLite's rowid allocation is restored by `RefreshDatabase`'s transaction rollback, so the two sequences march in lockstep and the comparison passes by accident. **MySQL's `AUTO_INCREMENT` is not rolled back**, so they drift — measured `people.id = 34` where the test expected `users.id = 28`, and `people.id = 28` where it expected 1 | **Fixture assumption, and it violates an invariant CLAUDE.md states in as many words** ("`people.id` and `users.id` are INDEPENDENT sequences — never compare or copy them positionally"). No production code makes the assumption; four tests do, and nothing could have told us |
| 2 | **`backup:run`'s tests build a SQLite scratch source** | 9 | `BackupInstanceIdentityTest` (6), `BackupRunTest` (3). They create a throwaway `*.sqlite` file as the backup SOURCE, then the command migrates/reads it — but `migrate` runs on the DEFAULT connection, which is now MySQL, so the scratch file has no `audit_log`. One is literally named `…_is_a_no_op_for_sqlite` | **By design, but it exposes a real coverage gap: `backup:run` against MySQL — i.e. the `mysqldump` path production actually uses — is exercised by NO test on either engine** |
| 3 | **`TableCounts::snapshot()` is not schema-scoped** | 5 | `DemoRemoveTest`'s whole-schema before/after comparison. `Schema::getTableListing()` under a root credential returns **every schema on the server**, and `TableCounts` then counts the *connection's* table under each schema's key | **An artifact of THIS rehearsal's server** (five throwaway databases, root credential). Proven: after dropping the other four schemas, all 27 demo tests pass on MySQL. Production is one database per customer (D11), so not a risk — but a caveat for anyone running the suite against a shared MySQL |
| 4 | **A test that had never tested anything** | 1 | `RosterImportTest::test_only_a_genuine_position_change_writes_a_role_change_audit_row` — see below | **REAL. Fixed** |
| 5 | **Collation folds two importer rows into one** | 1 | `RotaImportTest::test_a_row_that_resolves_to_nobody_is_not_merged_with_one_that_resolves` — "actual size 1 matches expected size 2" | **Exactly what the deploy plan's §9.4 predicts, now demonstrated.** On MySQL `ahmed` and `AHMED` resolve to one person and become ONE cell. Not a defect; the test encodes the SQLite outcome |
| 6 | **A SQL-string assertion carrying SQLite's quoting** | 1 | `UnitConfigurationTest::test_ordered_breaks_ties_by_id` asserts the compiled SQL contains `order by "display_order" asc, "id" asc`; MySQL emits backticks | Fixture assumption |

### The one that matters — a `LIKE` that was never a `LIKE`

```php
$this->assertDatabaseMissing('audit_log',
    ['action' => 'user_role_change', 'detail' => 'like', 'person='.$existing[0]->id.';%']);
```

`assertDatabaseMissing()` takes `column => value`. A `LIKE` written as a where-*triple* gives the
third element the numeric key `0`, so the query MySQL rejected was:

```sql
select exists(select * from `audit_log`
  where `action` = 'user_role_change' and `detail` = 'like' and `0` = 'person=805;%')
```

**`1054 Unknown column '0'`.** On SQLite it is not an error at all: a quoted identifier that
resolves to no column **falls back to a string literal**, so it compared `'0'` with
`'person=805;%'`, matched nothing, and `assertDatabaseMissing` passed — vacuously, twice, since the
day it was written. Both lines were guarding P1c review finding 6 (*"only a genuine position change
writes a role change audit row"*), and neither could ever have failed.

The guarantee itself was still covered by the `assertSame(1, …count())` three lines above, so
nothing was actually unprotected — but two of the three assertions in that test were decoration
that looked like verification, which is precisely the class of defect this project keeps a name for.
Rewritten as an explicit `->where('detail', 'like', …)->count()`, and **mutation-checked**: pointed
at the person who *did* change position it fails (`1 is identical to 0`), pointed at the two who did
not it passes.

### What the run does NOT tell you

`RefreshDatabase` migrates once and wraps each test in a transaction, so **the suite exercises MySQL
DDL exactly twice** (the initial `migrate:fresh`, and `MigrationChainRollsBackTest`'s full down-and-up).
Everything else is DML inside a transaction. A green suite on MySQL is evidence about *queries*, not
about *migrations* — which is why §3–§5 above had to be run separately.

---

## 14. What is still untestable, even now

1. **The deploy mechanics.** Coolify, the container recreate the new `db.command` forces, the
   `INSTANCE_SLUG` rename and its effect on backup retention, `ext-intl` and the Umm al-Qura data
   in the built image, `scripts/verify-live.sh`, and the backup/restore drill. §1, §3, §4.1, §6 and
   §8 of the plan remain rehearsed by nothing. This was true after the SQLite rehearsal and is
   still true.
2. **Real data.** 24,320 synthetic handovers with identical 25 KB payloads are not 24,320 real
   ones, and the live table is far smaller than either. The §4.2 query is the only honest answer.
3. **The performance of a real MySQL host.** Everything here ran through Docker Desktop's
   virtualised filesystem on arm64. The *ratios* (wrapped vs bare, per-statement spread) transfer;
   the absolute seconds do not.
4. **`LegacyImport` against MySQL.** Still never run there. §9.8's "two legacy rows differing only
   in case collapse into one `users` row" is now *supported* by §7's measurements but not
   demonstrated end to end.
5. **Concurrency beyond the two-session lock proof.** `DemoDepartment::remove()`'s nine-table lock
   ladder contending with `AuditLog`'s chain-tail lock — the `1213`/`1205` surface ruling 59
   describes — is reachable now that §10 shows the locks are real, but was not driven here.
6. **The roster import against a real staff list.** Unchanged: §9.4's instruction to do the dry-run
   preview against production, not against a rehearsal, is the right one and this rehearsal does
   not replace it.
7. **`backup:run` against MySQL.** §13's class 2: every test of that command builds a **SQLite**
   scratch source, so the `mysqldump` path — the one production uses nightly, and the one the whole
   restore drill depends on — is exercised by no test on either engine. The 2026-08-09 ops
   rehearsal ran it by hand once; nothing pins it. **This is the largest remaining untested surface
   this rehearsal found**, and it is a better candidate for the next piece of work than anything in
   §12.
8. **DDL under the suite.** `RefreshDatabase` migrates once and then uses transactions, so a green
   1,680-test run on MySQL says almost nothing about migrations. Twice is how many times the suite
   executes MySQL DDL. Everything in §3–§5 had to be run outside it, and would have to be again.

---

## 15. Teardown

The container, its volumes, both worktrees and every scratch database were removed. `git status`
clean.
