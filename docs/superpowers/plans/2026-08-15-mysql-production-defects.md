# 2026-08-15 — MySQL 8.4 rehearsal defects, fixed

**Branch:** `fix/mysql-production-defects`, off `main` at `c97c2bb`.

Four defects found by a MySQL 8.4 rehearsal (image `mysql:8.4`, `sql_mode` = MySQL 8.4
default including `STRICT_TRANS_TABLES`, tables `utf8mb4_unicode_ci`) — none visible to the
SQLite test suite, because SQLite either lacks the limit MySQL enforces (`TEXT`'s 65,535-byte
ceiling) or rebuilds the whole table for an operation MySQL performs as ordered ALTER
statements (dropping an FK-backed unique index). This note exists so the two constants and
one mechanism below never have to be re-derived: **1.783×** encrypted-envelope growth, the
byte-vs-character validation trap, and MySQL's "no transactional DDL across separate
statements within one migration" behaviour.

All four findings below were reproduced empirically against a real MySQL 8.4 container as
part of this fix, not just fixed by inspection — see each section's "Verified" line.

---

## 1. The deploy runbook's migration grant wedged the database at migration 2 of 16

**Root cause:** `docs/RUNBOOK-DEPLOY.md` and `docs/RUNBOOK-PROVISION.md` both granted the
app's runtime credential `ALTER` only, for the duration of `php artisan migrate --force`,
then revoked it. That is enough for every migration that alters an existing table, but not
for `Schema::create()` on a table with a foreign key: MySQL compiles the FK as a **separate**
`ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY ... REFERENCES` statement following the base
`CREATE TABLE`, and MySQL has no transactional DDL across separate statements — so the base
table commits, then the FK statement fails on the missing `REFERENCES` privilege, and the
migration throws without ever recording itself in the `migrations` table. Every retry then
fails differently (`1050 Table already exists`), which reads like a new problem.

**Fix:**
- `docs/RUNBOOK-DEPLOY.md`, `docs/RUNBOOK-PROVISION.md`: grant/revoke `ALTER, CREATE,
  REFERENCES` (not `ALTER` alone) for `migrate`; a separate, occasional `+ DROP` grant for
  `migrate:rollback`, kept OUT of the routine deploy grant.
- `docs/RUNBOOK-DEPLOY.md` gained a "recovery before you retry" procedure: check
  `migrate:status` for the first Pending migration, read its `up()` for what it creates,
  check the database directly for partial residue, drop it by hand if present, then retry.
- `docs/sql/least-privilege.sql`'s comment used to suggest `docker exec -e
  DB_USERNAME=root ... migrate --force` as the alternative to a temporary grant. That does
  not work — this app's config is cached at boot (`config:cache`), so an already-running
  PHP-FPM worker never re-consults `env()` — and the runbook already said so. The two
  documents contradicted each other; `least-privilege.sql` now points at the runbook's actual
  (working) procedure instead of repeating a wrong one.

**Verified**, against a throwaway MySQL 8.4 container, in this exact order:
1. Fresh database, full migration chain up through `2026_08_08_120001` applied as root.
2. `endorse2app`@`%` granted `SELECT, INSERT, UPDATE, DELETE, ALTER` only (the old grant) —
   `php artisan migrate --force` dies on `2026_08_09_120001_create_unit_field_definitions_table`
   with `1142 CREATE command denied ... for table 'unit_field_definitions'`.
3. `CREATE` granted (still no `REFERENCES`) — retry dies on the SAME migration with `1142
   REFERENCES command denied ... for table 'units'`, and `SHOW TABLES` now shows
   `unit_field_definitions` **already exists**, uncommitted to the `migrations` table.
4. Retry without fixing anything — `1050 Table 'unit_field_definitions' already exists`,
   reproducing the "wedged" failure mode exactly.
5. `REFERENCES` granted, the partial table dropped by hand — `php artisan migrate --force`
   completes the remaining 15 migrations cleanly. `INDEX` was never granted at any point,
   confirming it is not needed.
6. `migrate:rollback --step=1` with `ALTER, CREATE, REFERENCES` (no `DROP`) against
   `2026_08_12_120003_create_holidays_table`'s `down()` — `1142 DROP command denied ... for
   table 'holidays'`, matching the reported error verbatim. `DROP` granted — rollback
   succeeds.

---

## 2. The four rich-text handover fields could lose clinical text

**Root cause, two compounding bugs:**
- `disease`/`details`/`plan`/`nevent` stayed `TEXT` (65,535-byte ceiling) when the
  `mrn`/`patient_name`/`dob` columns were widened for encryption
  (`2026_07_25_150001_widen_handover_columns_for_encryption`).
- `EndorsementController::validateRow()` bounded these fields at `max:20000`, which Laravel
  measures in **characters of the raw value**. The database enforces a limit in **bytes of
  the sanitized, encrypted value**. Those two disagree for any script wider than ASCII, and
  `Crypt::encryptString()`'s real growth is a measured **1.783×** — not the ~1.4× the audit
  originally estimated (`SPC-RPT-058`, corrected in place; see
  `docs/SECURITY-AUDIT-2026-07-26.md`). True ceiling on the old `TEXT` column: **36,751
  post-sanitize plaintext bytes** — reachable at as few as 7,350 characters (ASCII `&`, which
  the sanitizer expands 5× to `&amp;`), 9,187 (emoji, 4 B), 12,250 (CJK/Arabic-presentation,
  3 B), or 18,375 (Arabic, 2 B) — all comfortably under the validator's stated 20,000-
  character allowance. `nevent` carries forward on every new day (owner ruling), so the risk
  compounds with length of stay.

**Fix:**
- `database/migrations/2026_08_15_120001_widen_rich_text_handover_columns.php` — `TEXT` ->
  `MEDIUMTEXT` (16,777,215 bytes) on all four columns. Additive **widening**, not a retype:
  same logical string type both sides, only the length-prefix size changes; every existing
  row's ciphertext is unaffected. `down()` refuses to shrink back if any row's stored value
  would be truncated (`MAX(LENGTH())` per column, matching `SPC-RPT-058`'s own prescribed
  fix) — verified empirically: a 70,000-byte row blocks rollback with a named, specific error;
  an empty table rolls back cleanly.
- `App\Casts\SanitizedHtml::MAX_PLAINTEXT_BYTES` (100,000 bytes, post-sanitize, pre-
  encryption) — the same shape as `App\Casts\EncryptedJson::MAX_BYTES`, derived from the
  sanitizer's own worst-case 5× entity expansion (100,000 / 5 = 20,000, so the ceiling admits
  AT LEAST the original 20,000-character intent for the worst-conceivable content, and
  strictly more for every real script). `SanitizedHtml::set()` throws if exceeded — defense
  in depth, mirroring `EncryptedJson::set()`.
- `App\Rules\MaxSanitizedBytes` — the HTTP-boundary rule, wired onto all four fields in
  `EndorsementController::validateRow()` in place of `max:20000`. Re-runs
  `RichTextSanitizer::clean()` (pure, idempotent) and measures the SANITIZED byte length, so
  the guard is measured in the same unit as what it protects.
- `docs/SECURITY-AUDIT-2026-07-26.md`'s `SPC-RPT-058` entry corrected in place: the 1.4×
  estimate and "~46 KB" ceiling replaced with the measured 1.783× and 36,751-byte figures,
  marked `FIXED` with the migration/rule/constant that closed it.

**Verified:**
- Real MySQL 8.4: `DESCRIBE handovers` shows `mediumtext` on all four columns after the
  migration; a direct 70,000-byte insert (over `TEXT`'s old ceiling) succeeds; `down()`
  refuses to run against that row and succeeds once it is removed.
- PHPUnit (SQLite, since the validator is a Laravel-layer concern, not a database one):
  - `HandoverSanitizeTest::test_a_value_over_the_sanitized_byte_ceiling_is_refused_by_the_cast`
    / `test_a_value_at_the_sanitized_byte_ceiling_is_accepted_by_the_cast` — the cast's own
    defense-in-depth throw, exact boundary.
  - `EndorsementTest::test_arabic_rich_text_that_used_to_silently_fail_now_saves` — 20,000
    Arabic characters (40,000 bytes), the validator's OWN stated old allowance, now actually
    round-trips end to end.
  - `EndorsementTest::test_arabic_rich_text_over_the_byte_ceiling_is_refused_by_validation` —
    60,000 Arabic characters (120,000 bytes, over the 100,000-byte ceiling) is refused by
    **validation** (`assertSessionHasErrors`), never reaches the database, and the row is
    left untouched.
  - `EndorsementTest::test_ascii_rich_text_is_accepted_at_the_byte_ceiling_and_refused_one_byte_over`
    — byte-exact boundary proof for ASCII, where sanitized length equals raw length.

---

## 3. `migrate:rollback` was broken on MySQL for the P0c identity migration

**Root cause:** `2026_08_10_120001_create_people_and_link_users.php`'s `down()` called
`dropUnique(['person_id'])` before `dropConstrainedForeignId('person_id')`. InnoDB requires
an index on an FK column, and that unique index was the only one — so dropping it first while
the FK constraint still referenced it failed with `1553 Cannot drop index
'users_person_id_unique': needed in a foreign key constraint`. SQLite rebuilds the whole
table for both operations, so statement order never mattered there; this is the only
migration (of 38 checked) with the pattern.

**Fix:** reorder to `dropConstrainedForeignId('person_id')` alone —it drops the FK
constraint, the column, and (as a consequence of the column going away) the now-orphaned
unique index that lived solely on it, in the right order, in one call. Then
`Schema::dropIfExists('people')`.

**Verified**, against a real MySQL 8.4 container, the exact 5-step documented rollback order
(`docs/RUNBOOK-DEPLOY.md`'s "Verifying the 2026-08-10 identity migrations"):
`120005 -> 120004 -> 120003 -> 120002 -> 120001`, all five `DONE`, zero errors. Post-rollback
state confirmed directly: `people` table gone, `users.person_id` column gone, `users
.full_name` intact (restored by `120003`'s own `down()`, as documented) — zero residue.

---

## 4. Owner Decision B (WARD is the sole clinic owner) never reached an upgraded deployment

**Root cause, two writers disagreeing:** `database/seeders/ReferenceSeeder.php` has always
set `clinic_owner => true` for WARD (Owner Decision B, 2026-08-09), but only writes unit
profile columns **on CREATE** — deliberate, so a re-seed never silently reverts an
administrator's configuration. `2026_08_13_120001_add_munawib_configuration_to_units`'s
backfill — the path an **upgrade** takes, for a `units` row that already existed — set
`training_rotation`/`call_target` for all four seeded codes but left `clinic_owner` false for
all four, including WARD, because its own docblock predated the decision. Once a `units` row
exists with the wrong value, nothing after `ReferenceSeeder`'s CREATE-only guard can correct
it — including `php artisan db:seed --force`, which is a **mandatory step of every deploy**
per the runbook.

**Decision made, and why:** add a separate, unconditional, idempotent corrective migration
(`2026_08_15_120002_correct_ward_clinic_owner.php`) rather than relying only on fixing
`120001`'s backfill in place. Editing an already-shipped migration's `up()` only changes
behaviour for a database that has not yet applied it — it does nothing for one that already
has, and this defect was found BY a rehearsal stack that had already run `120001` with the
bug. A brand-new, always-run UPDATE is a no-op wherever WARD is already correct (a cold
start, or a fresh chain that never saw the old backfill) and a fix everywhere else,
regardless of when or whether the bug was hit. `120001`'s docblock is corrected in place
(comment only — the statement it documents is unchanged) so a future reader does not
re-derive the same wrong conclusion from it. `docs/RUNBOOK-DEPLOY.md`'s P1b verification
query, which asserted `clinic_owner=0` for all four units — a false failure on any current
install — is corrected to expect `1` for WARD alone.

**Verified**, PHPUnit (`tests/Feature/Units/UnitCapabilityFlagsTest.php`):
- `test_it_corrects_an_existing_ward_row_left_wrong_by_the_old_backfill` — simulates the
  upgrade defect (WARD row exists, `clinic_owner=false`), proves `db:seed --force` alone does
  NOT fix it, then proves the corrective migration does, without touching the other three
  units.
- `test_it_is_a_no_op_when_ward_is_already_correct` — cold start is untouched.
- `test_it_does_nothing_when_no_units_exist_yet` — a bare schema does not error.
- Also run directly against the real MySQL 8.4 container (no matching row present there) —
  no error.

---

## Suite

`php artisan test` (1052 pre-existing + new tests) and `npm run build` both green after these
changes — see the branch's final commit for exact totals.
