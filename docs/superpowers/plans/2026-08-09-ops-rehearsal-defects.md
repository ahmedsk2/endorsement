# 2026-08-09 — second ops rehearsal, defects fixed

**Branch:** `fix/ops-rehearsal-defects`, off `main` at `120cf4d`.

Six findings from a second production rehearsal — this time against a real `mysql:8.4`
pulled from the pinned digest, a real production image build, a 200,000-row audit chain,
and real backup/restore — plus corrections to three runbooks. Every fix below was reproduced
empirically as part of THIS branch, not just fixed by inspection: real `docker build`, a real
MySQL 8.4 container (`mysql:8.4@sha256:8dbcf531a03aade657e181b9cf2f1d1803ce621a1d55610cb44cb531ab7d7db6`,
matching `docker-compose.production.yml`), the real production image built from this
Dockerfile, and the real `docs/sql/least-privilege.sql` substituted and applied. This note
exists so the measured numbers and the one wrong turn below never have to be re-derived.

---

## 1. The production image did not build — `ext-intl` missing from the vendor stage

**Root cause:** `Dockerfile` stage 2 (`composer:2@sha256:5946476...`) runs `composer install`
to resolve `composer.json`'s dependencies, including its platform check for `ext-intl`
(required since commit `2108c39`; `composer.lock`'s own `platform` block repeats it). The
`composer:2` image's PHP (8.5.8, Alpine 3.24) does not have `ext-intl` compiled in, so the
check — genuinely correct, not a false positive — failed the build at stage 2, exit 2, before
stage 3 (which DOES install `intl`, for runtime) was ever reached. A Coolify deploy of `main`
before this fix fails outright.

**Fix, and why this option over the alternative:**
- `Dockerfile` stage 2 now installs `ext-intl` for real, for the duration of that one `RUN`
  (`icu-libs` kept permanently — the compiled `intl.so` `dlopen()`s it — `icu-dev` and the
  compiler toolchain held in a virtual package and removed after, same split stage 3 already
  uses). Considered instead: `composer install --ignore-platform-req=ext-intl`, which also
  makes the build pass and is not dishonest about the FINAL image (stage 3 does install
  `intl`) — but it is dishonest about what THAT COMMAND verified, and installing the real
  extension costs nothing (this stage's only output is `vendor/`; nothing else survives into
  the final image).
  - **First attempt at this fix failed differently**: putting `icu-dev` inside the SAME
    virtual package as `icu-libs` meant `apk del` cascade-removed `icu-libs` too as a
    now-unneeded transitive dependency, leaving `intl.so` unloadable
    (`Error loading shared library libicuio.so.78`) — `composer install` then failed with
    the ORIGINAL "extension is missing" error again, just from a different cause. Fixed by
    installing `icu-libs` outside the virtual group, exactly like the runtime stage below it
    already does.
- **The PHP-version mismatch** (vendor stage 8.5.8, runtime stage 8.4.23 — `Dockerfile:54`)
  is real but was, until this fix, silent: `composer.json` gained
  `"config": {"platform": {"php": "8.4.23"}}`, which pins composer's dependency RESOLUTION to
  the runtime version regardless of which PHP happens to run composer itself. Verified this
  changes nothing today — regenerating the lock (`composer update --lock`) altered only
  `content-hash` and added a `platform-overrides` block; zero package versions moved — so the
  gap was real in principle but not yet triggered. Not fixed by swapping stage 2's base image
  to match stage 3's exactly (the larger, not-requested change the task called out): that
  would trade a known, well-understood risk for an unevaluated one (does `php:8.4-fpm-alpine`
  have what composer needs to extract zip dist packages — unverified).
- **CI never caught this**, because `test`/`audit` both run `composer install` on the
  RUNNER's own PHP, never the Dockerfile's own vendor stage. `.github/workflows/ci.yml`
  gained a `docker-build` job: a bare `docker build .`, deliberately not the fuller
  `docker/smoke.sh` rehearsal (owner-run, credential-bearing, out of scope for CI) — this job
  answers exactly the question that was missing: does the image build at all.

**Verified:** `docker build .` succeeds end to end from a clean cache; the resulting image's
`php -m` lists `intl`, `php -v` reports `8.4.23` (matching the platform pin exactly); this
same image is what findings 2 and 5 below were verified against.

---

## 2. `backup:run` could not tell a hardened database from an un-hardened one — and the first fix attempt was itself wrong

**Root cause:** `docs/sql/least-privilege.sql` deliberately withholds the `TRIGGER`
privilege from the application's runtime credential (`SELECT, INSERT, UPDATE, DELETE` only),
so `mariadb-dump`/`mysqldump`, run under that same credential, silently omit the two
append-only triggers on `audit_log` — exit 0, nothing on stderr. `BackupRun::assertPlausibleDump()`
never checked for them, so a restore from an archive taken this way has zero triggers and
`UPDATE`/`DELETE` on `audit_log` both succeed — the append-only control is gone, and nothing
in the restore drill's checklist notices.

**First fix attempt, and why it was wrong.** The obvious reading of "make
`assertPlausibleDump()` fail when the source has triggers the payload lacks" is: scan the
dump text for `CREATE TRIGGER ...`. Implemented, unit-tested with hand-written fixture
payloads containing that text, and the tests passed — but a real dump, taken under least
privilege, against a REAL hardened MySQL 8.4 instance, NEVER contains a trigger definition,
because `mariadb-dump` needs the same `TRIGGER` privilege to SEE a trigger that it needs to
back one up, and the app's credential never holds it. Proven four ways against a real
container: the app's own credential sees zero rows from `information_schema.TRIGGERS` for a
table that demonstrably has two; adding `PROCESS` changes nothing; recording the trigger's
`DEFINER` as the app's own account changes nothing (MySQL then refuses to even FIRE the
trigger — `TRIGGER command denied` — since the definer itself lacks `TRIGGER`); and a real
`mariadb-dump` run under the app's credential, against a database WITH working triggers,
still emits zero `CREATE TRIGGER` statements. So the first fix would have rejected EVERY real
MySQL backup, forever, the moment an instance was properly hardened — the opposite of what
"hardened" is supposed to buy. Caught only by testing the fixed code against a real MySQL
8.4 container rather than trusting the synthetic PHPUnit fixtures, which is why this
paragraph exists: the wrong design looked completely correct from its own tests.

**The actual fix:** a live probe, `BackupRun::assertAuditLogAppendOnlyTriggersAreActive()`,
run before the dump even starts. It inserts a disposable row into `audit_log`, attempts an
`UPDATE` then a `DELETE` against it (the exact writes the triggers exist to block), and
checks whether each was actually blocked — all inside one transaction that is UNCONDITIONALLY
rolled back in a `finally`, whether or not the writes were blocked, so nothing this probe does
can ever persist (verified: zero residual rows either way). This needs no new privilege and
no new credential: `UPDATE`/`DELETE` are already granted schema-wide, precisely so the
trigger has something to override. The decision logic —
`BackupRun::missingAppendOnlyProtections(bool $updateBlocked, bool $deleteBlocked): array` —
is split out as a pure function so it is unit-testable without a database at all; the live
probe itself is verified empirically, not by PHPUnit (no real MySQL trigger exists for the
suite's sqlite connection to fire).

Also required, and non-obvious: MySQL/InnoDB does NOT poison a transaction after an ordinary
statement error the way some other databases do — a `SIGNAL`-raised error aborts only that
ONE statement, so the same transaction can immediately try the next probe and finally roll
back cleanly. Confirmed with a raw PDO script before writing the Laravel version, since this
assumption is load-bearing for the whole design.

One more sharpening after the design above: the probe's `catch` blocks originally treated ANY
`\Throwable` from the `UPDATE`/`DELETE` attempt as "blocked" — which would misreport a real
infrastructure fault (a dropped connection, a lock-wait timeout) as a healthy trigger and let
a broken backup report success. `BackupRun::isAppendOnlyTriggerSignal()` now matches on the
triggers' own message text (`"audit_log is append-only"`) specifically; anything else
propagates and fails the backup loudly instead of being silently absorbed. Re-verified end to
end after this change with the same before/after-hardening pair described above.

`docs/RUNBOOK-BACKUP.md`'s restore section gained a MANDATORY step: re-run
`docs/sql/least-privilege.sql` against the restored database, because a restored database has
NO triggers even from a perfectly healthy backup (the dump structurally cannot carry them,
hardened or not — restoring a good archive and restoring a bad one look identical on this
axis, and the trigger re-application is what actually re-establishes the protection either
way).

**Do NOT grant `TRIGGER` to the application credential** to make any of this pass — that
would let it drop the very triggers it must not be able to drop. Neither fix attempt does
this.

**Verified**, against the real production image (finding 1) and a real MySQL 8.4 container,
in this order: fresh un-hardened database, low-privilege credential — `backup:run` FAILS,
naming both missing protections, zero rows left behind. `docs/sql/least-privilege.sql`
applied (root, substituted). Same command, same credential — `backup:run` SUCCEEDS, archive
written and verified, `audit_log` contains only the legitimate `backup_created` row (no probe
residue from either the failed or the successful run).

---

## 3. `audit:verify`'s hash depends on MySQL's session `time_zone`, which nothing pins

**Root cause:** `audit_log.created_at` is a MySQL `TIMESTAMP` — stored as UTC internally, but
RE-RENDERED per the SESSION `time_zone` on every read. `AuditChain::canonical()` v3
deliberately hashes that rendered string verbatim (so an `APP_TIMEZONE` change can never
retroactively invalidate history — this is the fix for the 2026-07-26 incident). Nothing
pinned the DATABASE's own session zone: `config/database.php`'s mysql connection sets no
`timezone`, so the session tracks `SYSTEM` — whatever the container's OS reports, which can
change across a redeploy, a base-image bump, or a host migration, silently, with no code
change to explain why `audit:verify` suddenly calls the whole trail broken.

**Reproduced empirically**, own container (no `TZ` env set, matching production's
`docker-compose.production.yml`; confirmed `SYSTEM` already resolves to UTC here — `NOW()` and
`UTC_TIMESTAMP()` return identical values): wrote a 20-row chain, `audit:verify` intact.
`SET GLOBAL time_zone='+03:00'` against the SAME unchanged rows — BROKEN at row 1. Reverted
to `SYSTEM` — intact again. The rows never changed; only how MySQL rendered them on read did.

**Decision, and why nothing riskier was attempted.** A `hash_version` bump or a one-time
re-render of history would be the complete fix, but doing that safely requires the owner's
live data in front of whoever runs it — attempting it blind, on a branch with no access to
production, is exactly the kind of thing this task's own instructions said to stop and report
rather than improvise. The zero-risk interim, and all that was implemented:
`docker-compose.production.yml`'s `db.command` now includes `--default-time-zone=+00:00` — a
NUMERIC offset (not a named zone like `UTC`, which needs the `mysql.time_zone*` lookup tables
populated; a numeric offset needs no lookup at all, verified both forms actually work against
a fresh container, chose the one with fewer moving parts). This PINS today's already-correct,
already-self-consistent behaviour so it cannot drift out from under the chain by accident — it
changes nothing about what is stored or how existing rows read back, verified: an identical
container started with this flag reports `@@global.time_zone = +00:00`, `NOW() ==
UTC_TIMESTAMP()`, same as the implicit `SYSTEM` default it replaces.

This does NOT stop a superuser from running `SET GLOBAL time_zone` at runtime — that remains
a privileged, deliberate operator action, out of scope for a startup flag, and the rule stays
simple: never run it against this database.

**Secondary, no code change:** `created_at` currently holds Riyadh wall-clock recorded AS IF
it were UTC (self-consistent inside Laravel, since nothing in `app/` calls
`NOW()`/`CURDATE()`/`UTC_TIMESTAMP()`/`CURRENT_TIMESTAMP` — confirmed by grep), so the column
means something slightly different from its declared type. Not a live bug; the column's
declared semantics not matching its content is a documentation gap, recorded here, not a
migration — see `docs/RUNBOOK-DEPLOY.md`'s `APP_TIMEZONE` note for the general rule this
falls under.

---

## 4. `audit:verify` was quadratic — `chunk()` paginates with OFFSET

**Root cause:** `AuditVerify.php` walked `audit_log` with `DB::table(...)->orderBy('id')->chunk(500, ...)`.
`chunk()` pages with `LIMIT/OFFSET`, so every page re-scans and discards every row before it —
O(n²) overall as the table grows, and `data:retention` never prunes `audit_log`, so it only
grows.

**Fix:** `chunkById(500, ...)`, which pages with `WHERE id > :lastId ORDER BY id` — an index
seek, O(n) overall. Safe specifically here: the chain's own definition IS id order, and the
table is append-only (enforced by the triggers in finding 2) — `chunkById`'s usual caveat,
that a concurrent delete of an already-yielded row can let a later row shift into a processed
id range and get skipped, cannot happen to a table nothing can delete from.

**Measured**, own environment (localhost PHP against the docker-exposed MySQL port — absolute
numbers will differ from a container-to-container network path, but the SHAPE is the point):

| walk | 20,000 rows | 200,000 rows |
|---|---|---|
| `chunk(500)` | 0.99s | 42.82s |
| `chunkById(500)` | 0.88s | 8.95s |

At 20,000 rows the two are close — OFFSET's cost only dominates once the table is large
enough that re-scanning discarded pages actually matters. At 200,000 rows `chunk()` is
already ~4.8x slower, and the curve is quadratic, not linear, so the gap widens with every
row `data:retention` never prunes. The original rehearsal's own numbers (20,000 rows: 1.3s
either way; 200,000 rows: `chunk()` 33.5s vs `chunkById()` 0.70s, a ~43x gap) are the ones to
trust for absolute magnitude — they ran container-to-container, without a host-port hop in
the path — but both runs agree on the mechanism and the direction.

---

## 5. `least-privilege.sql` silently applied nothing against a database name containing an underscore

**Root cause, reproduced empirically** (fresh `mysql:8.4` container, `MYSQL_DATABASE=endorse_live`,
`MYSQL_USER=endorse_live` — this project's own `<slug>_live` convention, not a hypothetical
customer): the mysql image's own auto-provisioning grant stores the pattern with the
underscore ESCAPED — `mysql.db.Db = 'endorse\_live'`, confirmed by direct `SELECT` — because
`_`/`%` are wildcard characters in that column and the image's entrypoint escapes
user-supplied names for exactly that reason. `least-privilege.sql`'s `REVOKE ... ON
\`{{DATABASE}}\`.*`, substituted with the literal UNESCAPED name, does not match that stored
pattern byte-for-byte and dies `ERROR 1141: There is no such grant defined`, aborting the
whole batch before section 2's triggers are ever created — no grant reduction, no triggers,
credential still `ALL PRIVILEGES`. The file's OWN header already anticipated a failure mode
shaped exactly like this (an operator seeing triggers listed in section 3 and concluding it
worked, having actually failed halfway) — this is a second, previously-unlisted way into it.

**Fix:** two placeholders now name the database, not one — `{{DATABASE}}` (the literal name,
used in comments and the schema-scoped verification query, where a byte-for-byte STRING
comparison is correct) and `{{DATABASE_ESCAPED}}` (the same name with `_`/`%`
backslash-escaped, used in the GRANT/REVOKE identifier positions, where it is not). `REVOKE
IF EXISTS` (MySQL 8.0.16+) was tried and also makes the error go away, but by turning "no
matching grant" into a silent no-op — precisely the failure shape the file's own header warns
about — so it is not used. The documented run recipe builds `DBNAME_ESCAPED` with bash
parameter substitution, NOT `sed`: piping an already-correctly-escaped value through `sed
-e "s/{{DATABASE_ESCAPED}}/$DBNAME_ESCAPED/g"` silently ATE the backslash (sed's replacement
text treats a bare `\` before a non-special character as an escape of that character, not a
literal backslash — confirmed byte-for-byte with `xxd` after the sed version kept producing
unescaped output despite `$DBNAME_ESCAPED` itself being correct), so the whole recipe now
uses `${SQL//\{\{TOKEN\}\}/$value}`-style bash substitution throughout, which does not
re-interpret a backslash already present in the replacement VALUE.

**Verified end to end**, real MySQL 8.4 container, `endorse_live`/`endorse_live`, using the
exact documented recipe: no `ERROR 1141`; `SHOW GRANTS` afterward shows `SELECT, INSERT,
UPDATE, DELETE` only, with the same escaped pattern the original auto-grant used; a live
`UPDATE`/`DELETE`/`CREATE TABLE` against `audit_log` from that credential all fail with the
expected errors.

**Minor, same fix:** the verification query (`SELECT ... FROM information_schema.TRIGGERS
WHERE EVENT_OBJECT_TABLE = 'audit_log'`) was not schema-scoped. Reproduced: with a second,
unrelated schema present on the same server (a leftover scratch database, `scratchdb`, with
its own `audit_log` and its own two same-named triggers), the unscoped query returned 4 rows
for what were genuinely 2 triggers in the target schema. Fixed:
`AND EVENT_OBJECT_SCHEMA = '{{DATABASE}}'` (the PLAIN form — this is a literal string
comparison against `information_schema`, which stores schema names unescaped, so the escaped
placeholder would break a correctly-hardened instance's OWN verification query). Verified:
scoped query returns exactly 2 rows against the same two-schema setup that made the unscoped
one return 4.

---

## 6. Runbook corrections

- **`docs/RUNBOOK-BACKUP.md`'s restore command** said `mysql ... -u <user> ...` with no user
  named, which read as "use the app's own credential." After `least-privilege.sql` that
  credential cannot restore anything (no `CREATE`/DDL privilege) — the command now says
  `-u root` explicitly, with the reasoning (restoring is root's job, same as running
  `least-privilege.sql` itself or `migrate --force`).
- **New: "`audit:verify` reports BROKEN, but nothing was tampered with"** (§4 of
  `docs/RUNBOOK-BACKUP.md`). The append-only triggers block `UPDATE`/`DELETE`, never
  `INSERT`, so a bad hand-`INSERT` gets into `audit_log` cleanly and only `audit:verify`
  notices. Repair is root-only (drop both triggers, remove/correct the row, recreate both
  triggers) and, verified empirically, does NOT make `audit:verify` pass again: deleting the
  bad row just moves the reported break to the next row (whose `prev_hash` pointed at a hash
  that no longer exists) — permanently. Re-splicing every subsequent row's hash to make
  `audit:verify` green again is not offered as a step: it is indistinguishable from the
  tampering this control exists to catch. The documented outcome is an explained, permanent
  break plus an incident record, not a clean bill of health.
- **New: the `2026_08_15_120001_widen_rich_text_handover_columns` write-lock note**
  (`docs/RUNBOOK-DEPLOY.md`, "OWNER ACTION — schedule..."). MySQL 8.4 refuses both
  `ALGORITHM=INSTANT` and `ALGORITHM=INPLACE` for a `TEXT`->`MEDIUMTEXT` change (the on-disk
  length prefix itself widens, 2 bytes -> 3) and falls back to `COPY`: reads continue, writes
  to `handovers` block for the duration. Measured ~26 MB/s: 20,000 rows / 157 MB ≈ 6 seconds,
  scaling with table SIZE not row count. A sizing query and scheduling guidance were added;
  the migration file's own docblock now points at it.

---

## Suite

`php artisan test`: **1063 passed** (1060 pre-existing + 3 net new in
`tests/Feature/Console/BackupRunTest.php`), 4652 assertions. `npm run build`: green,
including the light-only CSS guard. New/changed coverage:
`tests/Feature/Console/BackupRunTest.php` (live-probe decision logic via
`missingAppendOnlyProtections()`, corrected `assertPlausibleDump()` expectations, the sqlite
no-op path); `tests/Feature/Console/AuditVerifyCommandTest.php` unchanged (same behaviour,
now walked via `chunkById`).
