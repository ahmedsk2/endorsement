> ## OWNER DECISIONS, 2026-08-08 — READ BEFORE ANY TASK
>
> **1. Instance slug for the live deployment: `qch`.** Fixed now, because changing it after
> the first slugged archive is written leaves an un-prunable generation behind. Archives
> become `endorsement-qch-{timestamp}`.
>
> **2. Backups: one bucket per customer**, with its own credentials. A leaked or
> misconfigured sync for one customer cannot reach another's archives; offboarding is
> dropping a bucket; "which archive belongs to whom" is answered by location, not filename.
> Task 7 provisions per-slug credentials accordingly — do NOT collapse to a shared bucket
> with slug-prefixed paths.
>
> **3. Co-tenancy on the shared `coolify` network: ACCEPTED, with a documented trigger.**
>
> Both customers' `app` containers are mutually reachable and `TRUSTED_PROXIES` covers
> `172.16.0.0/12`, so a compromised neighbour could forge `X-Forwarded-For` — reviving the
> forgeable-audit-IP and bypassable-lockout risk the 2026-07-26 audit closed. The owner has
> accepted this rather than provisioning a host per customer.
>
> **This is not a note to bury.** Task 10 must record it as a named, accepted risk in
> `docs/COMPLIANCE.md`, the PDPL pack and `docs/OPEN-DECISIONS.md`, each stating the trigger
> verbatim: **revisit before a second customer carries real patient data.** An accepted risk
> that is not written where an auditor will find it is an undocumented one.

# P0d — Tenancy & Provisioning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a **second customer deployment** safe to stand up and safe to operate *beside
the first*. D11 already chose the isolation model — one database per customer — and the
reconnaissance confirmed the compose stack honours it by construction. What is not safe is
everything *around* the compose file: the operator commands that select a container by image
ancestry, the two host scripts that hardcode one customer's volume and one customer's state
file, the seeder that stamps every customer "Qatif Central Hospital", and a backup filename
that cannot say which customer it belongs to while its retention sweep deletes across them.

**Architecture:** No new isolation mechanism is built. One token — the **instance slug** —
is threaded through the four places that currently cannot tell two customers apart: the
archive filename and its prune glob, the host scripts' config/log/state paths, the off-host
destination, and the operator's container selection. Everything else becomes a variable with
today's value as its default, so the existing deployment's behaviour is unchanged by every
commit in this plan.

**Tech Stack:** Laravel 13, PHPUnit (SQLite in-memory), bash host scripts, Docker Compose,
Coolify 4.1.2 + Traefik.

**Scope:** Last of four P0 plans (design doc §13). P0a (units as configuration), P0b (bounded
custom fields) and P0c (identity & auth lifecycle) are merged. This plan ships working
software on its own: for the single live deployment, every behaviour is identical except that
its archives gain a name that says whose they are.

**Owner decisions this implements — D11** (`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md:178-194`),
and **design §14 item 6** (reserved unit codes), which P0c's *Next plan* section assigned here.

**What this plan is NOT.** It does not add row-level tenancy, does not add a global scope, does
not make any UNIQUE index institution-aware, and does not touch the seven institution-blind
UNIQUE constraints the schema is now one-way committed to. D11 rejected row tenancy because it
**fails open**; a half-built version of it is the worst of the three states. If you find
yourself writing `where('institution_id', …)` in a clinical query, you are building the
rejected design.

---

## Amendments made during execution

*(Empty at plan time. Follow the P0c convention: when a task turns up something the plan's
enumeration missed — a site not listed, a test that goes red for a reason the plan did not
predict, a migration that behaves differently on SQLite than on MySQL — record it here, dated,
with what was found and how it was resolved. Findings caught empirically rather than by
inspection are the ones worth writing down.)*

**2026-08-08, Task 1 (`InstanceSlugTest`), empirically found:** PHPUnit 12 (this project's
version) removed the `@dataProvider` docblock annotation entirely — it must be
`#[\PHPUnit\Framework\Attributes\DataProvider('methodName')]`. The docblock form silently
produced "Too few arguments" errors instead of running the six malformed-slug cases. Fixed by
importing `PHPUnit\Framework\Attributes\DataProvider` and using the attribute.

**2026-08-08, Task 1 (`BackupInstanceIdentityTest`), empirically found:** the
`test_unslugged_legacy_archives_are_left_alone_and_warned_about` case initially called
`$result->assertExitCode(0)` before `$result->expectsOutputToContain(...)`. Laravel's
`PendingCommand::expectsOutputToContain()` registers a Mockery expectation on the console
output mock and only works if called **before** the command actually runs; `assertExitCode()`
triggers `run()` internally, so calling it first means the expectation is registered against
output that already happened and is never satisfied — the assertion fails even though the
command printed the correct warning. Confirmed by reproducing the warning manually outside
PHPUnit. Fixed by switching that one assertion to `Artisan::call()` + `Artisan::output()`,
which captures real output without the call-order requirement, rather than reordering the
fragile mock-based API.

**2026-08-08, environment note (not a plan defect):** `openssl` is not on PATH in the
PowerShell shell this machine's toolchain otherwise uses (only `php84`/`composer-bin` are
prepended there), but it is on PATH in Git Bash. Every openssl-dependent backup test —
`BackupRunTest`'s two real-archive tests plus all five of this plan's new
`BackupInstanceIdentityTest` tests — self-skips via `hasOpenssl()`/`markTestSkipped()` when run
under PowerShell, so `php artisan test` still reports "passed" there (614 tests, 607 passed, 7
skipped) without exercising the slug-collision or legacy-archive behaviour at all. This project
predates this plan; noted here because it means **verifying Task 1's backup behaviour requires
running the suite via Bash** (`export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"`
first), not the PowerShell invocation the top-level instructions default to. Confirmed green
there: 614/614 passed, 0 skipped.

**2026-08-08, Task 4 (`InstitutionProvenanceTest`), empirically found:** the bootstrap admin
created by `user:create-admin` has `setup_completed_at` NULL — the command never sets it, unlike
`UserFactory`, which defaults it to `now()`. `App\Http\Middleware\RequireSetup` redirects any
account with a null `setup_completed_at` to `/setup` on its first authenticated request to
anything outside its allow-list, so the "clinical row created by the admin" and "invitation
issued by the admin" test cases silently redirected there instead of exercising `storeRow`/
`InvitationController::store` — the request still returned a redirect (so `assertRedirect()`
alone did not catch it), it just redirected to the wrong place and created nothing. Unrelated to
this task's scope (a pre-existing onboarding flow, not a provenance defect), so the test bypasses
it directly with `$admin->forceFill(['setup_completed_at' => now()])->save();` rather than
touching `CreateAdmin` or `RequireSetup`.

**2026-08-08, Task 4 (`InstitutionProvenanceTest`), empirically found:** `Handover::where('mrn',
$plaintext)` never matches a row, even one just created with that exact `mrn`, because `mrn` is
encrypted at rest (`App\Casts\EncryptedString`) — the `WHERE` clause compares the literal value
against ciphertext, which cannot match. `EndorsementTest` already works around this
(`Handover::latest('id')->get()->firstWhere('mrn', ...)` — fetch, then filter in PHP after the
cast decrypts); `InstitutionProvenanceTest`'s clinical-row case had to switch to the same
pattern after `firstOrFail()` failed against a row provably already in the table.

**2026-08-08, Task 6, test design flaw caught by actually running the guard test:**
`HostScriptsAreInstanceScopedTest`'s own explanatory comments — added to `docs/RUNBOOK-DEPLOY.md`
and `docs/OWNER-CHECKLIST.md` to say *why* an operator must not select a container by image
ancestry — quoted the literal string `ancestor=mysql:8.4` while explaining its removal, which
tripped the guard's own `assertStringNotContainsString('ancestor=mysql', ...)` assertion. The
guard's `docs/superpowers/` exclusion covers plans and specs quoting the bad selector
deliberately, but not the live operator runbooks, which must never contain it even in prose
explaining why not — a copy-pasted sentence is still a copy-pasted selector. Fixed by rewording
both docs to describe the hazard ("select the database container by matching the shared MySQL
image") without reproducing the exact flag syntax. The same guard also caught
`docs/sql/least-privilege.sql`'s own new header comment reproducing `` `endorsement` `` in
backticks while explaining the substitution requirement — reworded for the same reason.

**2026-08-08, Task 6, test design flaw found before it could mask a real regression:** the
first draft of `test_backup_offhost_sync_requires_an_instance_slug` asserted
`assertStringNotContainsString('endorsement-backups/_data', $script, ...)`. That substring is
the docker volume's actual name and correctly stays literal in the rewritten script — only the
Coolify-app-UUID *prefix* on that path needed to become a variable (finding 1's sibling issue).
The assertion as written would have failed forever regardless of correctness, and was caught by
running the test and reading why it failed rather than by inspection. Fixed to assert the
derived form `SRC="/var/lib/docker/volumes/${PROJECT_UUID}_endorsement-backups/_data"` is
present instead.

**2026-08-08, Task 8 (`scripts/new-instance.sh`), empirically found by actually running the
script rather than only `bash -n`-checking it:** `rnd() { LC_ALL=C tr -dc 'A-Za-z0-9'
</dev/urandom | head -c 48; }` (the same generator `docker/smoke.sh` already used) aborts the
whole script under `set -euo pipefail` when its result is captured by a plain assignment
(`mysql_password="$(rnd)"`). `head -c 48` closes its end of the pipe once it has enough bytes;
`tr`, still reading an effectively infinite stream from `/dev/urandom`, receives SIGPIPE and
exits 141; under `pipefail` that becomes the pipeline's exit status, and because the call site
is a genuine assignment *statement* — not, as in `docker/smoke.sh`, text substituted inside a
`cat <<EOF` heredoc, whose own exit status is `cat`'s and does not inherit an embedded
substitution's — `errexit` treated it as a real failure and killed the script before it printed
anything, discarding 48 already-good random bytes. Confirmed by reproducing the failure in
isolation (`bash -c 'set -euo pipefail; x=$(tr -dc A-Za-z0-9 </dev/urandom | head -c 48); echo
"${#x}"'` exits 141) before fixing it. Fixed by appending `true` as the generator's last
statement, which fixes the *function's* exit status to 0 regardless of the pipeline's — this is
scoped to `scripts/new-instance.sh` only; `docker/smoke.sh`'s heredoc usage was never actually
affected and was left unchanged.

---

## Nine findings from reconnaissance that shape this plan

Read these before any task. Each is a bug, a trap, or a whole task's worth of work that would
otherwise be discovered late — most of them only after a second customer's data existed.

1. **The documented migration procedure can be aimed at the wrong customer's clinical
   database, and if both customers use the default names it succeeds silently.**
   `docs/RUNBOOK-DEPLOY.md:183-191` resolves the app container by UUID but the database
   container by image ancestry:

   ```bash
   APP=$(sudo docker ps -qf name=app-oo7d7si62yhyi7fx10hrck6q | head -1)
   DB=$(sudo docker ps -qf ancestor=mysql:8.4 | head -1)
   ```

   With two stacks up there are two `mysql:8.4` containers and `head -1` picks by arbitrary
   `docker ps` ordering. `DBNAME` and `DBUSER` are then read from *that* container
   (`:186-187`), so the `GRANT ALTER` and the `REVOKE ALTER` land coherently on the wrong
   customer while the intended migration fails for lack of ALTER. `docker/smoke.sh` brings up
   a second `mysql:8.4` container on the same host by design, so a second container already
   exists during the exact window the runbook is most likely to be read. **This is the single
   most important thing P0d fixes** (Task 6).

2. **`docker/uptime-check.sh` is not merely unscoped at N=2, it is incorrect.** `LOG` and
   `STATE` are absolute literals (`:19-20`); only `URL` is overridable (`:18`). Two crons
   sharing `/var/lib/endorsement-uptime.state` each read the other's last state, so the
   transition logic at `:35-45` emits a permanent stream of false `CRITICAL`/`recovered`
   pairs. The log is the outage record; corrupting it is worse than having no monitor (Task 7).

3. **Backup retention deletes across customers, and renaming the archive alone does not fix
   it.** `BackupRun::prune()` globs `endorsement-*.sql.gz.enc`, `rsort`s and deletes past
   `--keep` (`app/Console/Commands/BackupRun.php:393-406`). Filenames carry no instance token
   (`:56-58`, `:125`), every instance writes at 01:30 `Asia/Riyadh` (`routes/console.php`), and
   `rsort` on an identical prefix is a pure timestamp sort — so in any shared directory a
   14-archive retention eats the *older* customer's history first. The rename and the
   glob-scoping must land in one commit or there is a window where retention matches nothing
   and archives accumulate unbounded (Task 1).

4. **Slugging the filename regresses retention on the EXISTING deployment, and the recon did
   not catch this.** After Task 1, the live instance writes `endorsement-qch-<stamp>.sql.gz.enc`
   while fourteen `endorsement-<stamp>.sql.gz.enc` files already sit in the volume. A
   slug-scoped glob will never match them, so they are never pruned and the disk grows forever.
   The fix is *not* to keep pruning the legacy pattern — that is the cross-customer delete
   again. `backup:run` warns, by count, when un-slugged archives are present, and the runbook
   carries the one-time cleanup (Task 1, Step 6).

5. **A slug prefix can swallow another slug.** `endorsement-qch-*` matches
   `endorsement-qch-2-2026-08-08_013000.sql.gz.enc`. Two customers named `qch` and `qch-2`
   sharing a pull directory reintroduces finding 3 in a form that looks fixed. The prune
   pattern is therefore anchored on the timestamp shape, not left open:
   `endorsement-{slug}-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9].sql.gz.enc`.
   There is a test for exactly this pair (Task 1).

6. **`APP_TIMEZONE` is a hardcoded literal AND a green test asserts that it is.**
   `docker-compose.production.yml:62` is `APP_TIMEZONE: Asia/Riyadh`, and
   `tests/Feature/Build/DeploymentInvariantsTest.php:243-250` asserts the literal string
   `'APP_TIMEZONE: Asia/Riyadh'` appears in the compose file. Parameterising the one without
   rewriting the other turns the suite red, so they are **one task and one commit** (Task 2).

7. **The audit-chain hazard around `APP_TIMEZONE` is historical; the live hazard is the day
   boundary.** The brief and the reconnaissance both frame a timezone change as a threat to the
   audit trail. It was, once — and `App\Support\AuditChain` was changed for that reason.
   `AuditChain::VERSION = 3` hashes the stored datetime **verbatim** (`canonical()`,
   `$version >= 3 ? $stored : Carbon::parse(...)`), with the incident written into the docblock
   at `:36-52`. A timezone change can no longer re-render history. What it still does is move
   `now()`: the handover day boundary shifts, so a 01:00 write files under a different calendar
   date, `handover_signoffs`' `UNIQUE(unit_id, handover_date)` day identity moves under existing
   rows, and the 07:30/15:30 reminders and the 01:30 backup fire at different wall-clock times.
   That is why it must be set **before the first clinical write** — state the real reason, not
   the retired one.

8. **Two seeder defects, one of which brands every customer.** `ReferenceSeeder.php:113-117`
   hardcodes `Institution::updateOrCreate(['code' => 'QCH'], ['name' => 'Qatif Central Hospital', …])`,
   and `db:seed --force` is a mandatory go-live step (`docs/RUNBOOK-DEPLOY.md:199`). Worse, `name`
   is in the *update* payload, so the seeder **reverts a customer's rename on every re-seed** —
   the opposite of the `firstOrNew` discipline the units already use two blocks above
   (`:102-111`, and the docblock at `:16-21` explains exactly why). Both fixed in Task 3.

9. **`institution_id` has no writer, so D11's "defence in depth" is currently untrue — and a
   legacy-import customer lands half-populated.** Nothing in `app/` ever sets
   `users.institution_id`; every downstream site copies it from the acting user
   (`EndorsementController.php:339,601,632,658`, `Invitation.php:67`,
   `InvitationAcceptController.php:104,161`, `UserManagementController.php:152,177`), and
   `CreateAdmin.php:86-91` never sets it on the bootstrap admin. So it is NULL everywhere on a
   fresh deployment — while `LegacyImport.php:137,231` stamps a real id on every imported row.
   Non-null history, null present, is worse than uniformly null. Task 4 gives the column its one
   writer.

---

## Where the design doc is wrong, and what this plan does instead

The design doc is a decision record, not a description of the operational reality. Each row is
resolved here and corrected in the doc itself in Task 10.

| Design doc says | Reality / this plan |
|---|---|
| §3.4: "a provisioning script stands up a separate Compose stack with its own MySQL per customer" | **No such script can exist end to end.** `.github/workflows/ci.yml` has two jobs (`test`, `audit`), `permissions: contents: read`, and an explicit rationale that the token must not reach the branch Coolify deploys to the machine holding patient data. There is no image push, no registry, no deploy job. Every Coolify step — project, application, deploy key, domain, environment variables — is a human in the UI, and the tokens that could automate it are owner-held by policy. P0d ships what is honestly automatable: `scripts/new-instance.sh` (generates conforming secrets, prints the exact env block, refuses a colliding slug), `docker/instance-env.sh` (resolves one stack's containers or refuses), `php artisan instance:show` (proves an instance is fully provisioned), and `docs/RUNBOOK-PROVISION.md` for the parts that are irreducibly manual. |
| §3.4: "`institution_id` is **retained** as in-instance grouping and defence in depth" | It is neither today — no writer, NULL on every row (finding 9). Task 4 makes the claim true at its root (`user:create-admin`) and backfills the column where exactly one institution exists. It stays out of every clinical query. |
| §3.4: "one codebase, one image, one CI pipeline; FL-03's *no per-instance code changes, ever* holds" | Holds **inside** `docker-compose.production.yml`. It does not hold in the repo: `APP_TIMEZONE` (compose `:62`), the QCH institution (`ReferenceSeeder.php:114-117`), `docs/sql/least-privilege.sql:32,34` (hardcoded `endorsement`/`endorse`), `docker/backup-offhost-sync.sh:16-19` (one UUID, one bucket, one log), `docker/uptime-check.sh:19-20`, and `docker/smoke.sh:28`. Until P0d lands, customer 2 requires per-instance edits to six files. |
| §3.4: "Accepted cost: N backups, monitors, upgrade runs and restore drills once N exceeds one" | Understates it. At N=2 the uptime monitor is **incorrect**, not merely duplicated (finding 2); the backup retention is **destructive** across customers (finding 3); and the migration procedure can hit the **wrong database** (finding 1). These are defects that arrive with the second customer, not linear operating cost. |
| §15 risk row: "Provisioning, backup and restore are scripted from the start" | Backup is scripted and tested (`BackupRunTest`, 11 tests; `docker/smoke.sh:145-165` exercises it against real MySQL). **Provisioning and restore are prose.** The restore drill has been run once ever — 2026-07-25, before any patient data existed — and nothing records when it last ran or alerts when a quarter passes. Task 8 makes the drill a recorded, per-instance obligation. |
| §14 item 6: the reserved-unit-code guard is "needed before any admin UI can create units", attributed to "P0d/P0b" | P0b shipped without it. There is still no unit-creation UI (`grep -rn "Unit::create" app/ routes/` returns nothing outside tests and seeders), so the guard can land ahead of the surface that needs it. Task 5. |
| §3.1 topology table implies each customer deployment is the compose stack "plus two sidecars" | Out of scope here and unchanged by P0d, but note for P2/P4: every finding in this plan about per-stack naming applies again to `engine` and `solver` when they land. The slug introduced in Task 1 is the token they should reuse. |

**One correction to the reconnaissance itself,** since it will be read alongside this plan:
its `APP_TIMEZONE` risk entry ("the exact condition that once made the live audit chain report
itself as tampered") describes a failure mode that `AuditChain` v3 closed. See finding 7 for
what is still true.

---

## What is genuinely already safe — do not spend effort here

Verified by the reconnaissance against the repository at `8886f8d`; re-deriving it is wasted work.

- **Volumes, networks and container names are compose-project-scoped by the Coolify app UUID.**
  There is no `name:` and no `container_name:` anywhere in `docker-compose.production.yml`, so
  customer 2 gets `<uuid2>_endorsement-db`, `<uuid2>_endorsement-storage`,
  `<uuid2>_endorsement-backups`, its own `internal` bridge and `app-<uuid2>` / `db-<uuid2>`.
  Evidence in-tree: `docker/backup-offhost-sync.sh:17` carries a UUID-prefixed volume path.
- **No host ports are published by either stack** — 80/443/3306/8080 are free of contention,
  asserted on every smoke run (`docker/smoke.sh:215-216`).
- **The `internal`-only database network isolates customers from each other** as well as from
  co-tenants: customer A's app cannot reach customer B's MySQL.
- **The mysql digest pin, the Dockerfile digest pins and the Traefik network hint are correct
  for every customer** and must stay shared and unedited.
- **CI performs no deployment**, so nothing in this plan can be automated into the pipeline by
  accident.

**One thing that is NOT safe and is out of scope, recorded so it is a decision rather than an
oversight:** both customers' `app` containers sit on the shared external `coolify` network.
`bootstrap/app.php:73-75` already documents that a co-tenant container can reach the app
directly, bypassing Traefik's host routing; `TRUSTED_PROXIES` covers `172.16.0.0/12`, so a
compromised neighbour is inside the trusted-proxy range and can forge `X-Forwarded-For` —
reviving the forgeable-audit-IP and bypassable-lockout failure CLAUDE.md lists as
non-regressable. Mitigating it means a separate host per customer, which is an owner
infrastructure decision, not a code change. Task 10 records it in `docs/OPEN-DECISIONS.md` as
an accepted risk with a named trigger (the second customer going live).

---

## Migration ordering

P0d adds **one** migration. P0c's five use the `2026_08_10_*` prefix; this one uses
`2026_08_11_120001` so it sorts strictly after them:

```
2026_08_11_120001_backfill_institution_on_identity_rows   (P0d Task 4 — DATA MIGRATION, guarded)
```

It is additive and non-destructive: it sets `institution_id` on `people` and `users` rows where
it is currently NULL, **and only when the `institutions` table holds exactly one row**. Zero
institutions or two or more, and it makes no change and says so. There is no `down()` data
restore, because there is nothing to restore — it only fills nulls, and re-running it is a
no-op. The owner runs production migrations (CLAUDE.md); Task 4 Step 5 supplies the
verification queries for `docs/RUNBOOK-DEPLOY.md`.

---

### Task 1: The instance slug, in the archive name, in the prune glob, in the audit row

**Files:**
- Create: `app/Support/Instance.php`
- Modify: `config/endorsement.php`
- Modify: `app/Console/Commands/BackupRun.php`
- Modify: `.env.example`
- Test: `tests/Feature/Console/BackupInstanceIdentityTest.php`
- Test: `tests/Unit/InstanceSlugTest.php`

The rename and the prune-scoping are **one commit** for the reason in finding 3: with the new
name and the old glob, retention matches nothing and archives accumulate; with the old name and
the new glob, retention deletes nothing. Either half alone is a regression.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/InstanceSlugTest.php`. Cover, at minimum:

- a configured `INSTANCE_SLUG` of `qch` resolves to `qch`;
- an unset slug falls back to `Str::slug(config('app.name'))` — `"Paediatric Endorsement"` →
  `paediatric-endorsement`;
- an app name that slugs to the empty string falls back to `instance`, never to `''` (an empty
  slug produces `endorsement--<stamp>` and a glob that matches nothing);
- a **configured but malformed** slug (`QCH Central`, `../etc`, `a*b`, a 40-character string)
  throws `InvalidArgumentException` naming the variable and the permitted shape. It must throw
  rather than normalise: a silently-normalised slug disagrees with the
  `/etc/endorsement/<slug>.conf` filename and the bucket prefix the operator created by hand,
  and that disagreement is invisible until a restore.

Create `tests/Feature/Console/BackupInstanceIdentityTest.php` (SQLite driver, as
`BackupRunTest` does):

- `backup:run` writes `endorsement-{slug}-{Y-m-d_His}.sql.gz.enc` and, when signatures exist,
  `endorsement-signatures-{slug}-{Y-m-d_His}.tar.gz.enc`;
- a `.meta.json` sidecar is written beside the archive containing `slug`, `stamp`, `bytes`,
  `app_key_fingerprint` and `hostname`, and **no** passphrase, no key, no path outside the
  target directory, and nothing resembling PHI;
- **the prefix-collision test (finding 5):** seed a directory with 20 archives named for slug
  `qch` and 20 named for slug `qch-2`, run `backup:run --keep=14` as `qch`, and assert that
  *no* `qch-2` file was deleted and that exactly the oldest `qch` files past 14 were;
- **the legacy-archive test (finding 4):** seed the directory with un-slugged
  `endorsement-<stamp>.sql.gz.enc` files, run `backup:run`, assert none of them were deleted
  **and** that the command output contains a warning naming the count;
- the `backup_created` audit row's `detail` contains `instance={slug}` alongside the existing
  `bytes=`/`seconds=`, and still contains no path and no PHI.

Run them and watch them fail.

```powershell
php artisan test --filter InstanceSlug | Select-Object -First 30
php artisan test --filter BackupInstanceIdentity | Select-Object -First 30
```

- [ ] **Step 2: `App\Support\Instance`**

```php
<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one token that tells two customer deployments apart.
 *
 * D11 makes the DATABASE the isolation boundary, so nothing about correctness depends on this
 * value — but the operational surface outside the container does. It names the archive, scopes
 * the archive's own retention sweep, names the host script's config, log and state files, and
 * prefixes the off-host destination. An operator holding a bucket full of ciphertext has
 * nothing else to go on.
 *
 * Read through config, not env(): the entrypoint runs `config:cache` at boot against the real
 * process environment, so the cached value is correct. (BackupRun reads BACKUP_PASSPHRASE via
 * env() for a different reason — it is a secret, and the invariant is about not caching those.)
 */
final class Instance
{
    /** Filename-safe, glob-safe, DNS-label-shaped. Deliberately narrow. */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    public static function slug(): string
    {
        $configured = trim((string) config('endorsement.instance.slug', ''));

        if ($configured !== '') {
            if (! preg_match(self::SLUG_PATTERN, $configured)) {
                throw new \InvalidArgumentException(
                    'INSTANCE_SLUG must match '.self::SLUG_PATTERN.' — lowercase letters, digits '
                    .'and hyphens, starting alphanumeric, at most 32 characters. It names files on '
                    .'the host and objects in the backup bucket, so it is not normalised for you.'
                );
            }

            return $configured;
        }

        $derived = Str::slug((string) config('app.name'));

        return $derived !== '' ? substr($derived, 0, 32) : 'instance';
    }

    /**
     * A stable, non-invertible label for the APP_KEY this instance encrypts with, so an
     * operator can pair an archive with the key that opens it WITHOUT holding either. Domain
     * separated so it cannot be compared against a hash of the key computed for any other
     * purpose.
     */
    public static function keyFingerprint(): string
    {
        return substr(hash('sha256', 'endorsement-key-fingerprint:'.(string) config('app.key')), 0, 16);
    }
}
```

Add to `config/endorsement.php`:

```php
    /*
     * Names this deployment in artefacts that leave it: the backup archive, its prune glob,
     * the off-host object prefix, and the host scripts' config/log/state paths. Unset, it is
     * derived from APP_NAME. One customer per database (D11) — this is how an operator tells
     * two customers' ciphertext apart.
     */
    'instance' => [
        'slug' => env('INSTANCE_SLUG'),
    ],
```

Add `INSTANCE_SLUG=` to `.env.example` beneath `APP_NAME`, with a one-line comment.

- [ ] **Step 3: Name the archives**

In `BackupRun::handle()`, replace lines 56-58:

```php
        $slug = Instance::slug();
        $stamp = now()->format('Y-m-d_His');
        $plain = $dir.DIRECTORY_SEPARATOR."endorsement-{$slug}-{$stamp}.sql";
        $archive = $plain.'.gz.enc';
```

and in `backupSignatures()` line 125:

```php
        $tar = $dir.DIRECTORY_SEPARATOR."endorsement-signatures-{$slug}-{$stamp}.tar";
```

(pass `$slug` in, or call `Instance::slug()` — one call site each, either is fine; do not
recompute it in a loop.)

- [ ] **Step 4: Scope the prune glob, anchored on the timestamp**

Replace `prune()`'s default pattern. The timestamp classes are what stop `qch` matching
`qch-2` (finding 5) — do not simplify them to `*`:

```php
    /** `Y-m-d_His`, as a glob. This is the anchor that stops slug `qch` matching slug `qch-2`. */
    private const STAMP_GLOB = '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]';

    private function prune(string $dir, int $keep, ?string $pattern = null): void
    {
        $pattern ??= 'endorsement-'.Instance::slug().'-'.self::STAMP_GLOB.'.sql.gz.enc';
        ...
    }
```

and the signature call site (`:163`):

```php
        $this->prune($dir, (int) $this->option('keep'),
            'endorsement-signatures-'.$slug.'-'.self::STAMP_GLOB.'.tar.gz.enc');
```

The two patterns still do not overlap (`.sql.gz.enc` vs `.tar.gz.enc`), so they cannot eat each
other. Prune the `.meta.json` sidecars with the same anchored pattern so they do not accumulate.

- [ ] **Step 5: The sidecar and the audit detail**

Write `{archive}.meta.json` after the archive verifies, containing exactly:

```json
{"slug":"qch","stamp":"2026-08-11_013000","bytes":214512,"app_key_fingerprint":"9f2c…","hostname":"…"}
```

This is the direct answer to "which `APP_KEY` opens this archive" — the question an operator
standing in front of a bucket cannot answer today, because the only in-band identity is the
ciphertext and reading it requires already knowing whose it is. It is plaintext by design: it
must be readable **without** the passphrase. It carries no PHI, no path outside `$dir`, and no
secret.

Then extend the audit row (`:89`):

```php
\App\Models\AuditLog::record('backup_created', 'instance='.$slug.' bytes='.$bytes.' seconds='.$seconds, null, null);
```

- [ ] **Step 6: Warn about un-slugged archives (finding 4)**

Before pruning, count files matching `endorsement-`.self::STAMP_GLOB.`.sql.gz.enc` — the
pre-slug shape. If any exist, print:

```
14 archive(s) in this directory predate the instance slug and are no longer pruned
automatically. They belong to this instance. Remove or rename them once you have confirmed a
slugged archive restores: docs/RUNBOOK-BACKUP.md.
```

Do **not** delete them and do **not** widen the prune pattern to include them — that is
finding 3 restored under a new name.

- [ ] **Step 7: Verify and commit**

```powershell
php artisan test | Select-Object -Last 5
npm run build 2>&1 | Select-Object -Last 5
```

```bash
git add app/Support/Instance.php app/Console/Commands/BackupRun.php config/endorsement.php .env.example tests/
git commit -m "feat: an archive that cannot say whose it is gets deleted by someone else's retention"
```

- [ ] **Step 8: OWNER ACTION — set `INSTANCE_SLUG` on the live deployment before the next deploy**

The existing deployment has no `INSTANCE_SLUG`, so it falls back to `Str::slug(APP_NAME)` and
starts writing `endorsement-paediatric-endorsement-<stamp>.sql.gz.enc`. That is valid and
prunes correctly, but it will disagree with the `/etc/endorsement/<slug>.conf`,
`/var/log/endorsement-<slug>-*.log` and bucket names the operator creates in Task 7, and a slug
cannot be changed later without leaving a second, un-pruned generation of archives behind
(finding 4, again).

Set `INSTANCE_SLUG=qch` in Coolify's Environment Variables for the `endorsement` app **before**
the deploy that carries this commit, so the first slugged archive already has the intended name.
Confirm afterwards with `php artisan instance:show` (Task 8) or, until that exists:

```bash
eval "$(sudo bash docker/instance-env.sh <live-uuid>)" && sudo docker exec "$APP" printenv INSTANCE_SLUG
```

Record the chosen slug in the identifiers table of `docs/RUNBOOK-DEPLOY.md` (Task 10, Step 4).

---

### Task 2: `APP_TIMEZONE` becomes a per-customer variable, with the test that pins it

**Files:**
- Modify: `docker-compose.production.yml`
- Modify: `tests/Feature/Build/DeploymentInvariantsTest.php`

One task and one commit, for the reason in finding 6: `DeploymentInvariantsTest:243-250`
currently asserts the literal `'APP_TIMEZONE: Asia/Riyadh'` in the compose file. Changing the
compose file first leaves the tree undeployable; changing the test first leaves it asserting
nothing. They move together.

- [ ] **Step 1: Rewrite the guard test to assert the stronger property (red)**

Replace `test_the_deployment_sets_the_wards_timezone()` with a test that requires the value to
be **settable and defaulted**, keeping the reason the original existed:

```php
    /**
     * Hardcoded to 'UTC', config/app.php once ignored APP_TIMEZONE entirely and the container
     * ran three hours behind the ward: reminders at 10:30 and 18:30 instead of 07:30 and 15:30,
     * and a day boundary at 03:00 filing night-shift entries under the wrong date.
     *
     * Since D11 there is one deployment per customer and a customer may not be in the Kingdom,
     * so the value is a variable — but it keeps Asia/Riyadh as its default, because an
     * unset variable must not silently mean UTC and reintroduce the original fault.
     */
    public function test_the_deployment_timezone_is_settable_and_defaults_to_the_ward(): void
    {
        $this->assertMatchesRegularExpression(
            '/APP_TIMEZONE:\s*\$\{APP_TIMEZONE:-Asia\/Riyadh\}/',
            $this->compose(),
            'APP_TIMEZONE must be per-customer AND default to Asia/Riyadh: a customer outside '
            .'Saudi Arabia cannot edit the shared compose file, and an unset variable must not '
            .'mean UTC.',
        );
    }
```

```powershell
php artisan test --filter DeploymentInvariants | Select-Object -First 30
```

- [ ] **Step 2: Parameterise the compose file (green)**

`docker-compose.production.yml:62`:

```yaml
      # Per customer since D11. It must be correct BEFORE the first clinical write, and it is
      # not a routine config change afterwards: `now()` moves with it, so the handover day
      # boundary moves under existing rows — a 01:00 entry files under a different calendar
      # date, and handover_signoffs' UNIQUE(unit_id, handover_date) day identity shifts with
      # it. The reminder and backup schedules move too. (It is NOT an audit-chain hazard any
      # more: AuditChain v3 hashes stored datetimes verbatim, precisely so a timezone change
      # cannot re-render history. See app/Support/AuditChain.php:36-52.)
      APP_TIMEZONE: ${APP_TIMEZONE:-Asia/Riyadh}
```

- [ ] **Step 3: Verify and commit**

```powershell
php artisan test | Select-Object -Last 5
```

```bash
git add docker-compose.production.yml tests/Feature/Build/DeploymentInvariantsTest.php
git commit -m "feat: a customer outside the Kingdom cannot edit the shared compose file to fix its clock"
```

---

### Task 3: The institution stops being hardcoded, and stops reverting a customer's rename

**Files:**
- Modify: `config/endorsement.php`
- Modify: `database/seeders/ReferenceSeeder.php`
- Modify: `.env.example`
- Modify: `tests/Feature/ReferenceSeederTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/ReferenceSeederTest.php`:

- with `INSTITUTION_CODE=RGH` / `INSTITUTION_NAME="Riyadh General Hospital"` in config, seeding
  creates that institution and **no** `QCH` row;
- with nothing configured, seeding still creates `QCH` / `Qatif Central Hospital` — the live
  deployment's behaviour is unchanged;
- **the rename test (finding 8):** seed, rename the institution row to `"Qatif Children's
  Hospital"`, seed again, and assert the rename survived. This fails today.

The existing `test_it_is_idempotent` asserts `institutions` has a `QCH` row; it stays valid
under the default and needs no change.

- [ ] **Step 2: Config**

```php
    /*
     * The customer this deployment belongs to. D11 makes the database the isolation boundary,
     * so there is exactly one of these per deployment and it is provenance, not a filter.
     * Defaults preserve the first deployment's identity.
     */
    'institution' => [
        'code' => env('INSTITUTION_CODE', 'QCH'),
        'name' => env('INSTITUTION_NAME', 'Qatif Central Hospital'),
    ],
```

Add both to `.env.example`.

- [ ] **Step 3: The seeder**

Replace `ReferenceSeeder.php:113-117` with the same `firstOrNew` discipline the units use, and
say why in a comment that names the failure:

```php
        // The tenant anchor — one row, this deployment's customer (D11). `name` is written on
        // CREATE ONLY, for the same reason as the unit profile columns above: `updateOrCreate`
        // with `name` in the payload silently reverted a customer's rename on every re-seed,
        // and `db:seed --force` is a mandatory step of every deploy.
        $institution = Institution::firstOrNew(['code' => (string) config('endorsement.institution.code')]);

        if (! $institution->exists) {
            $institution->name = (string) config('endorsement.institution.name');
        }

        $institution->active = true;
        $institution->save();
```

- [ ] **Step 4: Verify and commit**

```powershell
php artisan test --filter ReferenceSeeder | Select-Object -First 30
php artisan test | Select-Object -Last 5
```

```bash
git add config/endorsement.php database/seeders/ReferenceSeeder.php .env.example tests/Feature/ReferenceSeederTest.php
git commit -m "fix: every customer was seeded as Qatif Central Hospital, and a rename was undone on the next deploy"
```

---

### Task 4: `institution_id` acquires its one writer, so the column is true rather than aspirational

**Files:**
- Modify: `app/Models/Institution.php` (a `current()` resolver)
- Modify: `app/Console/Commands/CreateAdmin.php`
- Create: `database/migrations/2026_08_11_120001_backfill_institution_on_identity_rows.php`
- Test: `tests/Feature/Identity/InstitutionProvenanceTest.php`
- Modify: `tests/Feature/Console/CreateAdminCommandTest.php`

D11 keeps the column as **in-instance grouping and provenance**. Finding 9 shows it is null
everywhere except on legacy-imported rows, which is the one state worse than uniformly null.
This task fixes the root — the bootstrap admin — because every other site copies from the actor.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Identity/InstitutionProvenanceTest.php`:

- after `db:seed` + `user:create-admin`, the bootstrap admin's `people.institution_id` and
  `users.institution_id` both equal the single institution's id;
- a clinical row created by that admin (`newDay`, `storeRow`) carries the same id — proving the
  existing copy-from-actor chain now propagates something real;
- an invitation issued by that admin, and the person created when it is claimed, carry it too;
- with **zero** institutions, `user:create-admin` still succeeds and leaves the column NULL,
  with a warning line — it must never become a reason the only way into the system fails;
- **the guard that keeps this honest:** no clinical query filters on `institution_id`. Assert it
  as a source-level fact so a future session cannot quietly turn provenance into a fail-open
  security boundary:

  ```php
  public function test_no_query_filters_on_institution_id(): void
  {
      $hits = [];
      foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()))) as $file) {
          if ($file->getExtension() !== 'php') { continue; }
          $src = (string) file_get_contents($file->getPathname());
          if (preg_match('/(where|whereBelongsTo)\w*\(\s*[\'"]institution_id/', $src)) {
              $hits[] = $file->getPathname();
          }
      }

      $this->assertSame([], $hits,
          'D11: the isolation boundary is the DATABASE, not the row. Row-level tenancy fails '
          .'open — one missing scope and one customer reads another\'s PHI. institution_id is '
          .'provenance. If you need to scope a query by customer, you need a second deployment.');
  }
  ```

- [ ] **Step 2: `Institution::current()`**

```php
    /**
     * The single institution this deployment belongs to (D11: one database, one customer).
     *
     * Returns null when there is none — a deployment that has not been seeded — or when there
     * is more than one, because in that case there is no right answer and guessing would stamp
     * clinical provenance with a coin flip. Callers treat null as "leave it NULL".
     */
    public static function current(): ?self
    {
        $rows = static::query()->where('active', true)->limit(2)->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }
```

- [ ] **Step 3: `user:create-admin` sets it**

In `CreateAdmin::handle()`, inside the existing transaction (`:85-106`), resolve once and apply
to both rows:

```php
        // The root of the provenance chain. Every downstream site copies institution_id from the
        // acting user (EndorsementController::339/601/632/658, Invitation::67,
        // InvitationAcceptController::104/161, UserManagementController::152/177), so a bootstrap
        // admin without one makes the column NULL forever — while LegacyImport stamps a real id
        // on imported rows. Half-populated is worse than uniformly null. D11: this is grouping
        // and provenance, never a filter.
        $institutionId = Institution::current()?->id;
```

Add `'institution_id' => $institutionId` to both the `Person::create()` and `User::create()`
payloads (both models already have it in `$fillable` — `Person.php:31`, `User.php:26`).

After the transaction, print one line so the operator sees which customer they just bootstrapped
into, or that they bootstrapped into none:

```php
        $this->line($institutionId === null
            ? 'No single active institution found — institution_id left NULL. Run `php artisan db:seed --force` first if this is a fresh instance.'
            : 'Attached to institution: '.Institution::current()?->code);
```

- [ ] **Step 4: The backfill migration**

`database/migrations/2026_08_11_120001_backfill_institution_on_identity_rows.php`. Additive,
guarded, idempotent, no schema change:

```php
    public function up(): void
    {
        $ids = DB::table('institutions')->where('active', true)->limit(2)->pluck('id');

        // Exactly one, or do nothing. Zero means unseeded; two or more means there is no right
        // answer, and stamping clinical provenance with a guess is worse than leaving it null.
        if ($ids->count() !== 1) {
            return;
        }

        $id = (int) $ids->first();

        DB::table('people')->whereNull('institution_id')->update(['institution_id' => $id]);
        DB::table('users')->whereNull('institution_id')->update(['institution_id' => $id]);
    }

    public function down(): void
    {
        // Nothing to restore: this only filled nulls, and which rows were null is not recorded.
        // Re-running up() is a no-op, which is the property that matters.
    }
```

It deliberately does **not** touch `handovers`, `handover_signoffs`, `invitations` or `levels`.
Those are clinical or issued rows whose provenance is whatever it was at the time; rewriting it
retrospectively would be inventing history. New rows inherit correctly from the actor once the
accounts carry the value.

- [ ] **Step 5: The runbook verification query**

Append to `docs/RUNBOOK-DEPLOY.md`, in the same shape as the existing migration-verification
sections:

```sql
-- 2026_08_11_120001_backfill_institution_on_identity_rows
-- Both must return 0 on an instance that has been seeded.
SELECT COUNT(*) FROM users  WHERE institution_id IS NULL;
SELECT COUNT(*) FROM people WHERE institution_id IS NULL;

-- And there must be exactly ONE institution. More than one means the backfill made no change
-- and every count above is expected to be non-zero.
SELECT id, code, name, active FROM institutions;
```

- [ ] **Step 6: Verify and commit**

```powershell
php artisan test | Select-Object -Last 5
```

```bash
git add app/Models/Institution.php app/Console/Commands/CreateAdmin.php database/migrations/ docs/RUNBOOK-DEPLOY.md tests/
git commit -m "fix: institution_id was null on every row the app wrote and real on every row it imported"
```

---

### Task 5: Reserved unit codes, before there is a UI that can create one

**Files:**
- Modify: `app/Models/Unit.php`
- Test: `tests/Feature/Units/ReservedUnitCodesTest.php`

Design §14 item 6. `routes/web.php` declares `/endorsement/today`, `/endorsement/compliance` and
`/endorsement/rows/{handover}` **before** `/endorsement/{unit}` so those literal segments never
bind as a unit code — a trick that works only while the unit registry is hardcoded. A unit
created with code `TODAY` would be permanently route-shadowed and unreachable, with no error
anywhere. P0a made units configuration; P1 adds the admin UI. The guard lands first.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Units/ReservedUnitCodesTest.php`:

- `Unit::create(['code' => 'TODAY', …])` throws, and so do `'today'`, `' today '` and
  `'Today'` — the code mutator normalises before the guard sees it (`Unit::code()` Attribute);
- the same for `COMPLIANCE` and `ROWS`;
- a non-reserved code (`RGH1`, `PICU2`) still creates normally, and `ReferenceSeeder` still
  seeds its four units;
- **the derived guard, which is the one that keeps working after someone adds a route:**

  ```php
  /**
   * The reserved list is not a hand-maintained opinion — it is the set of literal first
   * segments already declared under /endorsement. A new literal route added without extending
   * RESERVED_CODES silently makes a unit code unroutable, and this is the only thing that
   * would notice.
   */
  public function test_the_reserved_list_covers_every_literal_route_segment(): void
  {
      $literals = collect(\Route::getRoutes()->getRoutes())
          ->map(fn ($r) => $r->uri())
          ->filter(fn ($uri) => str_starts_with($uri, 'endorsement/'))
          ->map(fn ($uri) => explode('/', $uri)[1])
          ->reject(fn ($seg) => str_starts_with($seg, '{'))
          ->map(fn ($seg) => strtoupper($seg))
          ->unique()->values()->all();

      $this->assertEqualsCanonicalizing($literals, Unit::RESERVED_CODES);
  }
  ```

  Today that yields `TODAY`, `COMPLIANCE`, `ROWS` — exactly the three the design doc names.

- [ ] **Step 2: The guard**

On `App\Models\Unit`:

```php
    /**
     * Codes that can never be a unit, because routes/web.php declares them as literal segments
     * before /endorsement/{unit}. A unit with one of these codes would be permanently shadowed
     * by the earlier route and unreachable — silently, with no error at creation and a 404 at
     * use. Impossible while the registry was hardcoded; reachable the moment a UI creates units.
     *
     * Kept in sync with the router by ReservedUnitCodesTest, which derives the list from the
     * registered routes rather than trusting this constant.
     */
    public const RESERVED_CODES = ['TODAY', 'COMPLIANCE', 'ROWS'];
```

Enforce in `booted()` on `saving`, so a seeder, a console command, a factory and a future
controller are all covered by one gate — and compare against the **normalised** code, since the
`code` Attribute uppercases and trims on write:

```php
    protected static function booted(): void
    {
        static::saving(function (self $unit): void {
            if (in_array($unit->code, self::RESERVED_CODES, true)) {
                throw new \InvalidArgumentException(
                    "Unit code [{$unit->code}] is reserved by a route under /endorsement and would "
                    .'be unreachable. Choose another code.'
                );
            }
        });
    }
```

Add a `Rule::notIn(Unit::RESERVED_CODES)` alongside it in whatever validates a unit code — there
is no such surface yet, so the model guard is the whole enforcement today. Note in the docblock
that the P1 admin UI must surface it as a validation message rather than an exception.

- [ ] **Step 3: Verify and commit**

```powershell
php artisan test | Select-Object -Last 5
```

```bash
git add app/Models/Unit.php tests/Feature/Units/ReservedUnitCodesTest.php
git commit -m "feat: a unit coded TODAY would be shadowed by an earlier route and unreachable"
```

---

### Task 6: No operator command can address the wrong customer's stack

**Files:**
- Create: `docker/instance-env.sh`
- Modify: `docs/RUNBOOK-DEPLOY.md`
- Modify: `docs/OWNER-CHECKLIST.md`
- Modify: `docs/sql/least-privilege.sql`
- Modify: `docker/smoke.sh`
- Test: `tests/Feature/Build/HostScriptsAreInstanceScopedTest.php`

Finding 1. This is the highest-consequence item in P0d and the only one whose failure mode is
*writes to the wrong customer's clinical database*.

- [ ] **Step 1: Write the failing guard test**

`tests/Feature/Build/HostScriptsAreInstanceScopedTest.php` — file reads, no database, in the
style of `DeploymentInvariantsTest`:

```php
    /**
     * `docker ps -qf ancestor=mysql:8.4 | head -1` picks an arbitrary MySQL container. With two
     * customer stacks on one host — and docker/smoke.sh brings up a second one deliberately —
     * the GRANT ALTER / migrate / REVOKE sequence can run against the other customer's clinical
     * database, and if both use the default MYSQL_DATABASE/MYSQL_USER names every command
     * reports success.
     */
    public function test_no_operator_document_selects_a_container_by_image_ancestry(): void
    {
        // Scanned, not enumerated: a list would go stale the moment a runbook is added, and
        // RUNBOOK-PROVISION.md does not exist yet. `docs/superpowers/` is excluded because the
        // plans and specs QUOTE the bad selector while explaining why it was removed — this
        // plan among them.
        foreach ($this->operatorDocs() as $path) {
            $this->assertStringNotContainsString(
                'ancestor=mysql',
                (string) file_get_contents($path),
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                .' selects a container by image ancestry; use docker/instance-env.sh',
            );
        }
    }

    /** @return list<string> every runbook, checklist and SQL script an operator runs from */
    private function operatorDocs(): array
    {
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('docs'))) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['md', 'sql'], true)) {
                continue;
            }

            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'superpowers'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $found[] = $file->getPathname();
        }

        $this->assertNotEmpty($found, 'expected to find operator documents under docs/');

        return $found;
    }
```

Plus, in the same file (these also cover Task 7, and can be written now and satisfied there):

- no repository file outside `docs/` historical prose contains the literal Coolify UUID
  `oo7d7si62yhyi7fx10hrck6q` — `docker/backup-offhost-sync.sh` in particular;
- `docker/uptime-check.sh` contains no unscoped `/var/lib/endorsement-uptime.state`;
- `docker/smoke.sh`'s project name is overridable (`PROJECT="${PROJECT:-endorse-smoke}"`);
- `docs/sql/least-privilege.sql` contains no bare `` `endorsement` `` schema literal and no
  `'endorse'@` user literal.

- [ ] **Step 2: `docker/instance-env.sh`**

An executable that **prints shell assignments** and refuses to guess. It is eval'd, so its
failure path must leave the caller with the variables unset *and* a non-zero status:

```bash
#!/usr/bin/env bash
#
# Resolve ONE customer stack's containers, or refuse.
#
#   eval "$(sudo bash docker/instance-env.sh <coolify-app-uuid>)" && \
#   sudo docker exec -u app "$APP" php artisan migrate --force
#
# Why this exists: the previous runbook selected the database container with
# `docker ps -qf ancestor=mysql:8.4 | head -1`. With two customer stacks up that is an
# arbitrary choice, and if both customers use the default MYSQL_DATABASE/MYSQL_USER names,
# a GRANT/migrate/REVOKE aimed at the wrong customer's clinical database reports success.
#
# It prints APP, DB, DBNAME and DBUSER. It deliberately does NOT print the root password —
# read that from the container as the runbook shows, so it never reaches a terminal that is
# scrolled back through.
set -uo pipefail

uuid="${1:-}"

refuse() {
    printf 'unset APP DB DBNAME DBUSER; false\n'
    printf 'instance-env.sh REFUSED: %s\n' "$1" >&2
    exit 1
}

[ -n "$uuid" ] || refuse "no Coolify app UUID given. Usage: instance-env.sh <uuid>"

app=$(docker ps -qf "name=app-${uuid}")
db=$(docker ps -qf "name=db-${uuid}")

[ "$(printf '%s\n' "$app" | grep -c .)" = "1" ] || refuse "expected exactly one running app container matching name=app-${uuid}"
[ "$(printf '%s\n' "$db"  | grep -c .)" = "1" ] || refuse "expected exactly one running db container matching name=db-${uuid}"

dbname=$(docker exec "$db" printenv MYSQL_DATABASE)
dbuser=$(docker exec "$db" printenv MYSQL_USER)

# Say out loud which customer is about to be operated on. Correct selection is necessary;
# VISIBLE selection is what stops the wrong one being operated on confidently.
printf 'instance-env.sh: app=%s db=%s database=%s user=%s\n' \
    "$(docker inspect -f '{{.Name}}' "$app")" "$(docker inspect -f '{{.Name}}' "$db")" "$dbname" "$dbuser" >&2

printf 'APP=%s; DB=%s; DBNAME=%s; DBUSER=%s\n' "$app" "$db" "$dbname" "$dbuser"
```

- [ ] **Step 3: Rewrite the runbook's database-operations section**

`docs/RUNBOOK-DEPLOY.md:182-192` becomes:

```bash
eval "$(sudo bash docker/instance-env.sh oo7d7si62yhyi7fx10hrck6q)" && \
PW=$(sudo docker exec "$DB" printenv MYSQL_ROOT_PASSWORD) && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "GRANT ALTER ON \`$DBNAME\`.* TO '$DBUSER'@'%'; FLUSH PRIVILEGES;" && \
sudo docker exec -u app "$APP" php artisan migrate --force && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "REVOKE ALTER ON \`$DBNAME\`.* FROM '$DBUSER'@'%'; FLUSH PRIVILEGES;"
```

The `&&` chaining is load-bearing: `instance-env.sh` prints `false` on refusal, so nothing
downstream runs. **Read the stderr line it prints and confirm the database name is the customer
you meant** before typing anything else. Add that sentence to the runbook in bold, and add the
UUID of each customer's Coolify app to the identifiers table at the top of the file (the values
themselves stay in the owner's `infra/state.env`, as today).

Apply the same `instance-env.sh` form to `docker exec -it $(docker ps -qf name=app-…) sh` at
`:160` and to the two `docker ps -qf name=db-…` invocations in `docs/OWNER-CHECKLIST.md:179,198`.

- [ ] **Step 4: Template `docs/sql/least-privilege.sql`**

Replace the hardcoded names at `:32,34` with placeholders, and the header's container selector
with the `instance-env.sh` form:

```sql
REVOKE ALL PRIVILEGES ON `{{DATABASE}}`.* FROM '{{USER}}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `{{DATABASE}}`.* TO '{{USER}}'@'%';
```

Add to the header, naming the partial failure this prevents:

```
-- SUBSTITUTE BEFORE RUNNING. On a customer whose database is not `endorsement`, the old
-- hardcoded form failed HALFWAY: the REVOKE/GRANT errored (no such grant, no such user) while
-- the audit_log triggers still applied to whatever schema the connection had selected. The
-- operator saw the triggers listed by section 3 and concluded it had worked, leaving the
-- runtime credential holding ALL PRIVILEGES — including DROP, and including UPDATE/DELETE on
-- audit_log. The placeholders make an unsubstituted run a syntax error instead.
--
--   eval "$(sudo bash docker/instance-env.sh <uuid>)" && \
--   sed -e "s/{{DATABASE}}/$DBNAME/g" -e "s/{{USER}}/$DBUSER/g" docs/sql/least-privilege.sql | \
--   sudo docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot '"$DBNAME"
```

Update section 3's verification to `SHOW GRANTS FOR '{{USER}}'@'%';`.

- [ ] **Step 5: Parameterise `docker/smoke.sh`'s project name**

`:28` becomes `PROJECT="${PROJECT:-endorse-smoke}"`. Two concurrent smoke runs currently share a
compose project, and `cleanup()`'s `down -v --remove-orphans` (`:34-38`) deletes the other run's
volumes mid-test. Add a line to the file header saying that a second concurrent run must pass
both `PROJECT` and `PORT`.

- [ ] **Step 6: Verify**

Syntax-check, then prove the refusal path works using the tools already in the repo — this is
the verification, and it costs nothing because `docker/smoke.sh` creates the second stack for
you:

```bash
bash -n docker/instance-env.sh
bash docker/instance-env.sh                       # expect: REFUSED (no UUID), exit 1
bash docker/instance-env.sh definitely-not-a-stack # expect: REFUSED (no matching app container), exit 1
eval "$(bash docker/instance-env.sh definitely-not-a-stack)"; echo "status=$?; APP=${APP:-<unset>}"
                                                   # expect: status=1; APP=<unset>
```

On the host, with the live stack running:

```bash
eval "$(sudo bash docker/instance-env.sh <live-uuid>)" && echo "APP=$APP DB=$DB DBNAME=$DBNAME"
```

Expect one stderr line naming both containers and the database, then the assignments. The
database name printed **must** be the live customer's.

```powershell
php artisan test --filter HostScriptsAreInstanceScoped | Select-Object -First 30
php artisan test | Select-Object -Last 5
```

- [ ] **Step 7: Commit**

```bash
git add docker/instance-env.sh docker/smoke.sh docs/ tests/Feature/Build/HostScriptsAreInstanceScopedTest.php
git commit -m "fix: the runbook picked a mysql container by image, so two customers meant a coin flip on whose database got migrated"
```

---

### Task 7: The host scripts become instance-scoped — one binary, per-instance config and state

**Files:**
- Modify: `docker/backup-offhost-sync.sh`
- Modify: `docker/uptime-check.sh`
- Modify: `docs/RUNBOOK-DEPLOY.md` (the install recipe)
- Test: covered by `tests/Feature/Build/HostScriptsAreInstanceScopedTest.php` from Task 6

Findings 2 and 3 in the reconnaissance's own ranking. **One binary each, slug as `$1`** — not N
copies. `docs/RUNBOOK-DEPLOY.md:135-139` records the host copy of the sync script already
drifting to 48 lines against the repository's 86, so the failure this design avoids has already
happened once at N=1; N copies would make it N invisible drifts protecting the only off-site
copy of the clinical record.

- [ ] **Step 1: `docker/backup-offhost-sync.sh` takes a slug and a config file**

Replace the hardcoded block at `:16-19` and `:58`:

```bash
slug="${1:-}"
[ -n "$slug" ] || { echo "usage: endorsement-backup-sync <instance-slug>" >&2; exit 2; }

CONF_FILE="/etc/endorsement/${slug}.conf"
[ -r "$CONF_FILE" ] || { echo "no config at $CONF_FILE" >&2; exit 2; }

# 0600, root-only. Supplies: PROJECT_UUID, RCLONE_CONF, DEST, HEARTBEAT_FILE.
# shellcheck source=/dev/null
. "$CONF_FILE"

: "${PROJECT_UUID:?PROJECT_UUID missing from $CONF_FILE}"
: "${RCLONE_CONF:?RCLONE_CONF missing from $CONF_FILE}"
: "${DEST:?DEST missing from $CONF_FILE}"

# Derived, never pasted: the volume prefix is the Coolify app UUID and differs per stack.
# Pasting it is how customer 2's sync job ends up copying customer 1's volume.
SRC="/var/lib/docker/volumes/${PROJECT_UUID}_endorsement-backups/_data"
LOG="/var/log/endorsement-${slug}-backup-sync.log"
```

The slug argument is **required**, with no default. A default would silently point a
misconfigured customer-2 cron at customer 1's volume, which is precisely the failure being
removed; failing loudly at 02:05 with a non-zero exit is recoverable, and the dead-man's switch
below the failure never fires, so the monitor alarms.

Keep unchanged: `copy` not `sync` and its reasoning (`:28-31`), the placeholder-vs-real
heartbeat URL validation (`:63-73` — it caught a real miss), the swallow-errors-on-ping rule
(`:76-82`), and every failure path exiting non-zero *before* the ping.

Scope the freshness assertions to this instance's destination — `DEST` is already
per-customer, so `lsf`/`lsl` against it are correct once `DEST` is per-customer, but add the
slug to the log lines so a shared syslog can be read.

- [ ] **Step 2: `docker/uptime-check.sh` takes a slug**

```bash
slug="${1:-}"
[ -n "$slug" ] || { echo "usage: endorsement-uptime-check <instance-slug>" >&2; exit 2; }

CONF_FILE="/etc/endorsement/${slug}.conf"
[ -r "$CONF_FILE" ] && . "$CONF_FILE"

URL="${URL:-${PUBLIC_URL:-}}"
[ -n "$URL" ] || { echo "no URL for instance $slug (set PUBLIC_URL in $CONF_FILE)" >&2; exit 2; }

LOG="/var/log/endorsement-${slug}-uptime.log"
STATE="/var/lib/endorsement-${slug}-uptime.state"
```

This is the N=2 correctness bug: two crons sharing one `STATE` file each read the other's last
value and the transition logic emits permanent false `CRITICAL`/`recovered` pairs. There is no
default URL any more — a hardcoded `https://endorse.towardpcc.com/up` fallback is how a
customer-2 monitor silently watches customer 1.

- [ ] **Step 3: The per-instance config file, documented**

Add to `docs/RUNBOOK-DEPLOY.md`, replacing the "host scripts are NOT deployed by a deploy"
section's install recipe. `/etc/endorsement/<slug>.conf`, `0600 root:root`:

```sh
# /etc/endorsement/qch.conf — one per customer instance. Root-only: HEARTBEAT_FILE names a
# secret and DEST names the off-host copy of the clinical record.
PROJECT_UUID=oo7d7si62yhyi7fx10hrck6q
RCLONE_CONF=/etc/endorsement/rclone.conf
DEST=oci-qch:endorsement-backups-qch
PUBLIC_URL=https://endorse.towardpcc.com/up
HEARTBEAT_FILE=/etc/endorsement/qch-heartbeat.url
```

**A separate bucket per customer, not a shared bucket with prefixes.** Three reasons, all
already in the repository: the existing design note chose a dedicated bucket "so children's
health data does not sit alongside unrelated projects" (`docs/OWNER-CHECKLIST.md:125-131`); the
outstanding object-lock/retention rule (`:143-147`) applies per bucket, so a shared bucket makes
one customer's retention policy another's; and the freshness check that treats an empty
destination as a failure is only meaningful when the destination belongs to one customer —
otherwise customer B's fresh upload satisfies customer A's assertion and A's backups can stop
permanently while the heartbeat keeps firing.

Cron, per instance:

```cron
5 2 * * *  /usr/local/bin/endorsement-backup-sync qch
*/5 * * * * /usr/local/bin/endorsement-uptime-check qch
```

Update the `scp`+`install` recipe at `:141-149` to install the single binary and then run it
once **with the slug**, keeping the existing discipline (back up first, `bash -n` before
installing, roll back on non-zero exit). Add a line: **installing the new binary breaks the old
crontab entries, which pass no slug — update cron in the same session and confirm both scripts
run by hand before leaving the host.** A script that exits 2 every night is safer than one that
guesses, but only if someone notices, and the first night is when they will not.

- [ ] **Step 4: Verify**

```bash
bash -n docker/backup-offhost-sync.sh
bash -n docker/uptime-check.sh
bash docker/backup-offhost-sync.sh          # expect: usage error, exit 2
bash docker/uptime-check.sh                 # expect: usage error, exit 2
bash docker/uptime-check.sh nosuchinstance  # expect: "no URL for instance nosuchinstance", exit 2
```

Then a real run against a throwaway config, on the host, with an rclone remote pointed at a
local temp directory rather than object storage:

```bash
sudo install -m 0600 /dev/stdin /etc/endorsement/drill.conf <<'EOF'
PROJECT_UUID=endorse-tenant-drill
RCLONE_CONF=/etc/endorsement/rclone.conf
DEST=drill-local:/tmp/drill-backups
PUBLIC_URL=http://127.0.0.1:9912/up
EOF
sudo /usr/local/bin/endorsement-backup-sync drill; echo "exit=$?"
sudo tail -3 /var/log/endorsement-drill-backup-sync.log
```

Expect `ok: off-host copy complete, N objects in drill-local:/tmp/drill-backups`, a `newest
object:` line, and `no heartbeat configured` (no `HEARTBEAT_FILE` in the drill config). The
throwaway stack that supplies the volume is Task 9's; do this step as part of Task 9 if the
stack is not up yet.

- [ ] **Step 5: Commit**

```bash
git add docker/backup-offhost-sync.sh docker/uptime-check.sh docs/RUNBOOK-DEPLOY.md
git commit -m "fix: two customers on one host shared an uptime state file and one backup destination"
```

---

### Task 8: `instance:show`, `scripts/new-instance.sh`, and `docs/RUNBOOK-PROVISION.md`

**Files:**
- Create: `app/Console/Commands/InstanceShow.php`
- Create: `scripts/new-instance.sh`
- Create: `docs/RUNBOOK-PROVISION.md`
- Test: `tests/Feature/Console/InstanceShowTest.php`

The bootstrap sequence is three manual, unordered, unenforced steps that nothing checks: a
deploy that stops after `migrate` gives a healthy container, a passing `/up`, and 403s on every
capability-gated route; a deploy that stops after `db:seed` has no way in at all. `instance:show`
turns "did I finish provisioning?" from a checklist line into a command with an answer.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Console/InstanceShowTest.php`:

- output contains the slug, the institution code and name, the effective timezone and the
  current local time, the `APP_KEY` fingerprint, and a `set`/`NOT SET` line for each of
  `BACKUP_PASSPHRASE`, `mail_host`, `alert_email`, `vapid_public_key`, `vapid_private_key`;
- **the value of every secret is absent from the output.** Set a recognisable
  `BACKUP_PASSPHRASE` and a recognisable stored `mail_password`, run the command, and assert
  neither string appears. Assert `config('app.key')` does not appear either — only its
  fingerprint;
- the command exits non-zero when any of `BACKUP_PASSPHRASE`, `mail_host` or `alert_email` is
  missing, so it can be the gate in a provisioning script rather than something to read;
- the exit code is zero on a fully-configured instance.

- [ ] **Step 2: `php artisan instance:show`**

Reads `Instance::slug()`, `Instance::keyFingerprint()`, `Institution::current()`,
`config('app.timezone')`, `now()`, `env('BACKUP_PASSPHRASE')` presence, and
`AppSettings::get()` presence for the four DB-backed keys. Prints a table. Names the
consequence of each missing item rather than just its absence — e.g. for `alert_email`:
`"OpsAlert is log-only: a failed nightly backup will escalate to a log nobody reads"`
(`app/Support/OpsAlert.php:54-71`), and for `mail_host`: `"mail.default is still 'log' and all
mail silently no-ops"` (`app/Support/AppSettings.php:149-151`).

- [ ] **Step 3: `scripts/new-instance.sh`**

Owner-run, on the owner's machine or the host. It **generates** the secrets at run time and
prints them; it never stores them, never transmits them, and nothing in this repository or this
plan ever holds a production credential.

```bash
#!/usr/bin/env bash
#
#   bash scripts/new-instance.sh --slug rgh --host endorse.rgh.example --timezone Asia/Riyadh
#
# Prints the environment block to paste into Coolify's Environment Variables screen, and the
# custody note. It writes NOTHING to disk. Everything it prints is a secret except APP_NAME,
# APP_URL, APP_TIMEZONE, INSTANCE_SLUG, INSTITUTION_*, MYSQL_DATABASE, MYSQL_USER and
# TRUSTED_PROXIES — close the terminal when you are done.
set -euo pipefail
umask 077

# Alphanumeric ONLY, and this is not cosmetic: Coolify feeds these through `docker compose`,
# which performs $-interpolation on env values, so a password containing '$' is silently
# truncated into something weaker. Same generator as docker/smoke.sh:42.
rnd() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 48; }
```

It must:

- validate the slug against `Instance::SLUG_PATTERN` (`^[a-z0-9][a-z0-9-]{0,31}$`) and refuse
  otherwise, quoting the same message the PHP side gives;
- refuse if `/etc/endorsement/<slug>.conf` already exists, when run on the host — a slug
  collision would have two customers writing one state file and one log;
- emit `APP_KEY=base64:$(openssl rand -base64 32)` (base64 can contain `+/=` but never `$`, so
  it is safe through compose) and `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `BACKUP_PASSPHRASE`
  from `rnd()`;
- emit the full block: `APP_NAME`, `APP_URL`, `APP_TIMEZONE`, `INSTANCE_SLUG`,
  `INSTITUTION_CODE`, `INSTITUTION_NAME`, `APP_KEY`, `MYSQL_DATABASE`, `MYSQL_USER`,
  `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `BACKUP_PASSPHRASE`, `TRUSTED_PROXIES` — with the
  Cloudflare choice spelled out: behind Cloudflare use
  `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`; not behind it, append `,no-cloudflare`, or the app
  trusts a forged `X-Forwarded-For` from anyone who can reach it from a Cloudflare range.
  Never `*`;
- print the `APP_KEY` fingerprint (the same domain-separated hash `Instance::keyFingerprint()`
  computes) so the operator can write the archive↔key pairing into the custody register before
  the key is ever used;
- print the custody rule in full: `APP_KEY` and `BACKUP_PASSPHRASE` go in **different** stores —
  a backup and its key never sit together — and both are needed to read an archive, because
  `APP_KEY` decrypts the PHI columns *inside* the dump.

- [ ] **Step 4: `docs/RUNBOOK-PROVISION.md`**

The document that did not exist. It carries the whole cold start for one new customer, in
order, with the traps named where they bite. Sections, at minimum:

1. **Decide the five identifiers first** — slug, hostname, institution code and name, timezone.
   The timezone is the one that cannot be changed afterwards without moving the handover day
   boundary under existing rows (finding 7); the slug is the one that names files on the host and
   objects in the bucket.
2. **Generate the secrets** — `scripts/new-instance.sh`; the alphanumeric constraint and why;
   the two-store custody rule; the pairing register (slug ↔ `APP_KEY` fingerprint ↔
   `BACKUP_PASSPHRASE` location ↔ bucket ↔ heartbeat URL), which at N customers is 2N secrets
   that are worthless unless correctly paired.
3. **Coolify** — its own project (isolating the environment-variable store and accidental
   cross-app operations); Docker Compose build pack; compose path `/docker-compose.production.yml`;
   its own read-only ed25519 deploy key on the same repo; the domain field on the **`app`**
   service as `https://<host>:8080` and nothing in the compose file; delete the
   preview-environment copies of every variable.
4. **DNS and TLS, in this order** — create the A record **grey (DNS-only)**, deploy, let
   Let's Encrypt issue over HTTP-01, *then* switch to orange. With the orange cloud on from the
   start, Cloudflare terminates TLS and the challenge never reaches Traefik. Then confirm
   SSL/TLS mode is **Full (strict)** by hand — the API token cannot read zone settings (9109).
   Note the two proxied-mode consequences that make a correct setup look broken: the served
   certificate is Cloudflare's, so CN string-matching fails, and Cloudflare 1010-blocks unusual
   user agents, so automation without a real `User-Agent` gets a 403 that looks like a revoked
   token.
5. **Bring-up, in this exact order, all owner-run** — the entrypoint deliberately migrates
   nothing (`docker/entrypoint.sh:4-8`), so a fresh deploy is up and 500-ing until you act:
   `migrate --force` (via `instance-env.sh`, Task 6) → `db:seed --force` → least-privilege
   grants (substituted, Task 6) → `user:create-admin`. `db:seed` runs `ReferenceSeeder` then
   `AccessControlSeeder`, in that order, and **never** `DemoSeeder` or `E2eSeeder` — they
   create fictional logins whose password is published in the repo docs and they throw under
   `APP_ENV=production`. Without `db:seed` the bootstrap admin authenticates and holds zero
   capabilities, so every route 403s.
6. **First login — and the lockout trap, in a warning block.** `user:create-admin` sets
   `email_verified_at = now()`, so the setup wizard will happily accept **email** as the second
   factor — but `mail.default` is still `log` until SMTP is stored in Admin → Settings, which
   requires being signed in. On the *next* login the OTP goes to a log file and the only account
   in the system is locked out, with no reset path (`user:create-admin` refuses an existing
   username and has no reset mode). **Choose TOTP. Record the recovery codes at enrolment.**
   `docs/OWNER-CHECKLIST.md:65-78` already says this for the first deployment; it must be in the
   provisioning path, not only the checklist.
7. **Configure before calling it live** — Admin → Settings: SMTP, send the test email,
   "Operational alerts to", generate the VAPID pair. Until `mail_host` *and* `alert_email` are
   set, `OpsAlert` is log-only and a nightly backup can fail unheard. VAPID absent is silently
   degrading rather than blocking: the wizard's `complete()` only checks the second factor, so
   the admin sails past and push reminders are simply never armed.
8. **Create a second administrator with its own second factor.** One admin is one lockout away
   from an unadministrable system, and `applied_role_defaults` is one-shot — if position 0 ever
   loses `access.manage`, re-running `db:seed` will not restore it
   (`AccessControlSeeder.php:142-167`).
9. **Host wiring** — `/etc/endorsement/<slug>.conf`, the dedicated bucket **in-Kingdom**
   (PDPL Art. 29 applies per customer and is not an operator choice), the object-lock/retention
   rule, the per-instance heartbeat URL with a 3-hour grace, the per-customer external HTTP
   monitor on `/up` at ≥3-minute intervals, and the two cron lines with the slug.
10. **Prove it** — `php artisan instance:show` exits 0; `curl -sI https://<host>/up` returns 200
    with HSTS, CSP, `x-frame-options: DENY`, `referrer-policy: no-referrer`;
    `php artisan schedule:list` shows six jobs; `php artisan audit:verify` exits 0;
    `bash scripts/verify-live.sh https://<host>` passes (the base URL is already a positional
    argument, so it is reusable as-is).
11. **The first restore drill, before the instance carries real data**, per
    `docs/RUNBOOK-BACKUP.md` — and record the date in the per-instance register. At N customers
    "quarterly" is N obligations nobody is tracking.

- [ ] **Step 5: Verify and commit**

```bash
bash -n scripts/new-instance.sh
bash scripts/new-instance.sh --slug 'Not A Slug' --host x --timezone UTC   # expect refusal, exit non-zero
bash scripts/new-instance.sh --slug drill --host drill.invalid --timezone Asia/Riyadh
```

Check by eye that the printed `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` and `BACKUP_PASSPHRASE`
are 48 characters and contain nothing outside `[A-Za-z0-9]`, and that `APP_KEY` starts
`base64:`. Then discard that terminal — those values are not for anything.

```powershell
php artisan test --filter InstanceShow | Select-Object -First 30
php artisan test | Select-Object -Last 5
```

```bash
git add app/Console/Commands/InstanceShow.php scripts/new-instance.sh docs/RUNBOOK-PROVISION.md tests/
git commit -m "feat: provisioning was prose, and three of its steps are unenforced and unordered"
```

---

### Task 9: The dress rehearsal — run the whole thing against a throwaway instance

**Files:**
- Modify: `docs/RUNBOOK-PROVISION.md` (an appendix recording the rehearsal and its output)

**A provisioning script that has never been run against a throwaway instance is not done.**
This task runs Tasks 1–8 end to end on the host, against a second stack that holds nothing,
alongside the live one — which is also the only way to prove finding 1's fix in the conditions
it exists for. Nothing here touches the live stack. It is owner-run, because it needs the host
and generates credentials.

- [ ] **Step 1: Stand up a throwaway second stack**

On the host, in a checkout of this repo:

```bash
bash scripts/new-instance.sh --slug drill --host drill.invalid --timezone Asia/Riyadh > /tmp/drill.env
chmod 600 /tmp/drill.env
docker compose -p endorse-tenant-drill -f docker-compose.production.yml --env-file /tmp/drill.env up -d --build
```

There is deliberately **no** Coolify app, no domain and no DNS: this stack is never routed. The
compose project name `endorse-tenant-drill` takes the place of a Coolify UUID everywhere below.

- [ ] **Step 2: Prove the wrong-database failure is now impossible**

With **two** `mysql:8.4` containers running (live + drill), demonstrate both halves:

```bash
sudo docker ps -qf ancestor=mysql:8.4 | wc -l           # expect: 2 — the old selector's coin flip
eval "$(sudo bash docker/instance-env.sh endorse-tenant-drill)" && echo "DBNAME=$DBNAME"
```

The stderr line must name `db-endorse-tenant-drill…` and the drill database. Then confirm the
refusal is not merely theoretical:

```bash
eval "$(sudo bash docker/instance-env.sh endorse)"; echo "status=$?; DB=${DB:-<unset>}"
```

A partial UUID that matches both stacks must **refuse**, leave `DB` unset, and exit 1. If it
resolves anything, the count check is wrong — fix it before continuing.

- [ ] **Step 3: Run the bring-up sequence from `docs/RUNBOOK-PROVISION.md` verbatim**

Migrate → seed → least-privilege (substituted) → `user:create-admin`. Then:

```bash
sudo docker exec -u app "$APP" php artisan instance:show
```

Expect `slug: drill`, the institution from `/tmp/drill.env`, `Asia/Riyadh`, a key fingerprint,
`BACKUP_PASSPHRASE: set`, and `mail_host` / `alert_email` **NOT SET** with a non-zero exit —
that is the command correctly reporting an instance that is not yet live.

- [ ] **Step 4: Prove archive identity and retention**

```bash
sudo docker exec -u app "$APP" php artisan backup:run
sudo docker exec "$APP" ls -1 storage/backups
```

Expect `endorsement-drill-<stamp>.sql.gz.enc` and `endorsement-drill-<stamp>.sql.gz.enc.meta.json`.
Read the sidecar and confirm the `app_key_fingerprint` matches what `instance:show` printed —
that pairing is the whole point of Task 1 Step 5.

Then the destructive case, which is the one that matters. Copy the live instance's newest
archive name **as an empty file** into the drill volume alongside 20 drill archives, run
`backup:run --keep=14`, and confirm the live-named file is untouched:

```bash
sudo docker exec -u app "$APP" sh -c 'for i in $(seq -w 1 20); do : > "storage/backups/endorsement-drill-2026-01-${i}_010000.sql.gz.enc"; done'
sudo docker exec -u app "$APP" sh -c ': > storage/backups/endorsement-qch-2026-01-01_010000.sql.gz.enc'
sudo docker exec -u app "$APP" php artisan backup:run --keep=14
sudo docker exec "$APP" ls -1 storage/backups | grep qch
```

The `qch` file must still be there. Under the pre-P0d glob it would not be.

- [ ] **Step 5: Run the host scripts against the drill instance**

Task 7 Step 4, now that the drill volume exists. Confirm
`/var/log/endorsement-drill-backup-sync.log` and `/var/lib/endorsement-drill-uptime.state`
exist and that the live instance's `/var/log/endorsement-qch-uptime.log` and state file are
untouched and un-flapping — the N=2 correctness bug, disproved rather than reasoned about.

- [ ] **Step 6: Restore drill, on the drill instance**

`docs/RUNBOOK-BACKUP.md`'s recipe against the drill archive, into a scratch database inside the
drill stack: `openssl enc -d` → `gunzip` → load → `php artisan audit:verify` exits 0. This is
also the first exercise of the corrected commands from Task 10.

- [ ] **Step 7: Tear down and record**

```bash
docker compose -p endorse-tenant-drill -f docker-compose.production.yml --env-file /tmp/drill.env down -v --remove-orphans
sudo rm -f /etc/endorsement/drill.conf /tmp/drill.env /var/log/endorsement-drill-*.log /var/lib/endorsement-drill-uptime.state
```

`-v` matters: the drill volumes hold a generated `APP_KEY`'s ciphertext and nothing else, and
leaving them behind leaves an `endorsement-drill` volume set that a later operator will have to
identify.

Append to `docs/RUNBOOK-PROVISION.md` an appendix dated with the rehearsal: what was run, what
each step printed, and **anything the runbook got wrong** — the steps that needed a command the
document did not have are the finding, and correcting them is the deliverable. If nothing needed
correcting, say so explicitly; a rehearsal with no corrections is a claim, and it should be
recorded as one.

```bash
git add docs/RUNBOOK-PROVISION.md
git commit -m "docs: the provisioning runbook, as corrected by actually running it against a throwaway stack"
```

---

### Task 10: Correct the documents this invalidates

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md` (§3.4, §14, §15)
- Modify: `docs/RUNBOOK-BACKUP.md`
- Modify: `docs/RUNBOOK-DEPLOY.md`
- Modify: `docs/OWNER-CHECKLIST.md`
- Modify: `docs/OPEN-DECISIONS.md`
- Modify: `docs/COMPLIANCE.md`, `docs/PDPL-PACK.md`
- Modify: `database/migrations/2026_08_09_120001_create_unit_field_definitions_table.php`
- Modify: `database/migrations/2026_07_24_120001_create_reference_tables.php`, `app/Models/User.php`

All of these are read as law by future sessions, and each now says something false.

- [ ] **Step 1: `CLAUDE.md`**

Add to *Non-negotiable rules*:

```
- ONE DATABASE PER CUSTOMER (D11). The isolation boundary is the database, not the row.
  `institution_id` is provenance and in-instance grouping — never a query filter; row-level
  tenancy fails open and the schema is one-way committed against it (seven institution-blind
  UNIQUE indexes). `App\Support\Instance::slug()` is the one token that tells two deployments
  apart: it names the backup archive, scopes that archive's own retention sweep, and names the
  host scripts' config, log and state files. Operator commands select a stack with
  `docker/instance-env.sh <uuid>`, never by image ancestry.
- `APP_TIMEZONE` is per customer and must be correct BEFORE the first clinical write: `now()`
  moves with it, so the handover day boundary moves under existing rows. (It is not an
  audit-chain hazard — v3 hashes stored datetimes verbatim for exactly that reason.)
- Secrets that pass through Coolify are 48-character ALPHANUMERIC. `docker compose`
  $-interpolates env values, so a `$` in a password is silently truncated into something
  weaker. `APP_KEY` and `BACKUP_PASSPHRASE` are stored in DIFFERENT places — a backup and its
  key never sit together, and both are needed to read an archive.
```

- [ ] **Step 2: The design doc**

§3.4: replace "a provisioning script stands up a separate Compose stack" with what actually
exists (Task 8's three artefacts plus the manual Coolify steps, and the reason CI cannot do it);
correct the "defence in depth" claim to what Task 4 made true; state that FL-03's "no
per-instance code changes, ever" now holds because P0d removed the six hardcodes, and name them;
replace "Accepted cost: N backups, monitors, upgrade runs and restore drills" with the three
defects that arrive with the second customer and where each is fixed.

§14: strike item 6 (reserved unit codes — shipped, Task 5). Add: **co-tenancy on the shared
`coolify` network is an accepted risk with a named trigger** (see Step 6); **the restore drill
has no last-run record and no ageing alert**; and **`institutions` still has no admin surface** —
the code and name are env-only and changing them after go-live means a DB edit.

§15: correct the risk row "Provisioning, backup and restore are scripted from the start" to the
truth, and add a row for the wrong-database hazard with `docker/instance-env.sh` as its
mitigation.

- [ ] **Step 3: `docs/RUNBOOK-BACKUP.md` — two instructions that are actively wrong**

`:36-41` tells the operator to confirm a `* * * * * … php artisan schedule:run` cron exists.
**That cron does not exist and must not** — supervisord runs `schedule:work` inside the app
container (`docker/supervisord.conf:34-48`, with the comment explaining the drift that would
silently skip `dailyAt('01:30')` some nights). An operator provisioning customer 2 from this
page adds a duplicate scheduler. Replace it with the in-container check.

`:23-26` says `openssl rand -base64 48`, which emits `+` and `/` and is inconsistent with the
alphanumeric constraint at `docs/RUNBOOK-DEPLOY.md:70-72`. Replace with the `rnd()` generator
from `scripts/new-instance.sh`, and say why in one line so it is not "tidied" back.

Then update the restore recipe's filenames to the slugged shape (`:64-66`, `:90`), add the
one-time cleanup of pre-slug archives (Task 1, finding 4), and add the per-instance drill
register: instance, date of last successful drill, who ran it.

- [ ] **Step 4: `docs/RUNBOOK-DEPLOY.md` and `docs/OWNER-CHECKLIST.md`**

Both now describe one deployment as if it were the only one. Add a line at the top of each
pointing at `docs/RUNBOOK-PROVISION.md` for a new customer, and make the identifiers table name
the instance slug alongside the Coolify UUID. Confirm no `ancestor=mysql` selector survives
anywhere (the Task 6 guard test asserts this, but the prose around it needs reading too).

- [ ] **Step 5: `docs/COMPLIANCE.md` and `docs/PDPL-PACK.md`**

Add a paragraph to each: isolation is by database, so non-commingling and right-to-erasure are
true by construction and evidenced by a dropped volume rather than by a query; backups are
per-customer, in separate in-Kingdom buckets, encrypted with per-customer passphrases (PDPL Art.
29 applies per customer and is pinned at provisioning, not left to the operator); an archive
carries no PHI in its name, only the instance slug; and `institution_id` is provenance, not a
control — no compliance claim may rest on it.

- [ ] **Step 6: `docs/OPEN-DECISIONS.md`**

Record the co-tenancy risk as an owner decision with a trigger rather than leaving it implicit:
both customers' `app` containers on the shared external `coolify` network, `bootstrap/app.php:73-75`'s
documented reachability, `TRUSTED_PROXIES` covering `172.16.0.0/12`, and therefore forgeable
`X-Forwarded-For` from a compromised neighbour — the forgeable-audit-IP and bypassable-lockout
failure CLAUDE.md lists as non-regressable. Options: separate host per customer (clean, costs an
instance), or accept and document. **Trigger: before the second customer carries real patient
data.**

Also record the two items the reconnaissance found still open at N=1 and inherited by all N: no
object-lock/retention rule on the backup bucket (write credentials are delete credentials), and
the external HTTP monitor is unbuilt with its account tied to a personal email.

- [ ] **Step 7: The three misleading comments and the one false one**

`database/migrations/2026_08_09_120001_create_unit_field_definitions_table.php:18-19` is
**factually wrong**: *"No `institution_id` — the unit already carries it via `foreignId('unit_id')`"*.
`units` has no `institution_id` column and never had one (`2026_07_24_120001:36-41` creates
`id, code, name, timestamps`; `2026_08_08_120001:87-120` is the only `Schema::table('units')` and
adds five config columns, none of them that). The conclusion is right under D11; the stated
reason is not, and a reader who trusts it will write a join that cannot resolve. Replace with the
D11 reason.

`2026_07_24_120001:14` (`// Multi-institution tenant anchor.`), `:23` (`// … the tenant FK on
users.`) and `app/Models/User.php:208` (`The institution (tenant) this user belongs to.`) all
predate D11 and each reads as a promise of row-level isolation. Reword to "provenance /
in-instance grouping — NOT a security boundary; see D11" so a future reader does not assume
scoping exists somewhere and skip adding it.

- [ ] **Step 8: Verify and commit**

```powershell
php artisan test | Select-Object -Last 5
npm run build 2>&1 | Select-Object -Last 5
```

```bash
git add CLAUDE.md docs/ database/migrations/ app/Models/User.php
git commit -m "docs: the boundary is the database, and three comments still promised a row-level one"
```

---

## Definition of done

- `php artisan test` passes with **no fewer tests than before Task 1**; `npm test` and
  `npm run build` are green.
- `Select-String -Path docs\ -Pattern "ancestor=mysql" -Recurse | Where-Object { $_.Path -notmatch 'superpowers' }`
  returns nothing (the plans and specs quote the removed selector deliberately), and
  `HostScriptsAreInstanceScopedTest` asserts the same thing by scanning rather than by list.
- `Select-String -Path docker\ -Pattern "oo7d7si62yhyi7fx10hrck6q" -Recurse` returns nothing.
- `Select-String -Path app\ -Pattern "where\w*\(\s*['""]institution_id" -Recurse` returns
  nothing, and `InstitutionProvenanceTest` asserts it.
- `backup:run` writes `endorsement-{slug}-{stamp}.sql.gz.enc` plus a `.meta.json` sidecar
  carrying the `APP_KEY` fingerprint, and its prune glob is anchored on the timestamp shape so
  slug `qch` cannot delete slug `qch-2`'s archives — asserted by a test that seeds both.
- Un-slugged archives left by the pre-P0d deployment are **warned about, never deleted**, and
  the runbook carries the one-time cleanup.
- The live deployment has `INSTANCE_SLUG` set explicitly in Coolify — not left to the
  `APP_NAME` fallback — and the value is recorded in `docs/RUNBOOK-DEPLOY.md`'s identifiers
  table, matching the host config, log, state and bucket names.
- `docker-compose.production.yml` sets `APP_TIMEZONE: ${APP_TIMEZONE:-Asia/Riyadh}`, and the
  guard test asserts the variable form rather than the literal.
- A fresh `db:seed --force` with `INSTITUTION_CODE`/`INSTITUTION_NAME` set creates that
  institution and no `QCH` row; with them unset it creates `QCH`; a customer's rename survives a
  re-seed.
- After `db:seed` + `user:create-admin`, `people.institution_id` and `users.institution_id` are
  non-null, and a clinical row written by that admin carries the same id.
- `Unit::create()` refuses `TODAY`, `COMPLIANCE` and `ROWS` in any casing, and a test derives
  that list from the registered routes rather than trusting the constant.
- `bash docker/instance-env.sh` with no argument, an unknown UUID, or a prefix matching two
  stacks **refuses**, leaves `APP`/`DB` unset, and exits 1.
- `docker/backup-offhost-sync.sh` and `docker/uptime-check.sh` require an instance slug, derive
  every path from it, and share no file between two instances.
- `docs/sql/least-privilege.sql` cannot run unsubstituted.
- `php artisan instance:show` prints the slug, institution, timezone, key fingerprint and the
  configured/not-configured state of every owner-managed secret, exits non-zero until SMTP and
  `alert_email` are set, and contains **no secret value** in its output — asserted by a test.
- `docs/RUNBOOK-PROVISION.md` exists, and its appendix records a dress rehearsal actually
  performed against a throwaway stack — including the corrections that rehearsal forced, or an
  explicit statement that it forced none.
- `docs/RUNBOOK-BACKUP.md` no longer instructs the operator to create a `schedule:run` cron, and
  no longer generates a passphrase with `openssl rand -base64`.
- The false `institution_id` comment on `2026_08_09_120001` is corrected, and the three "tenant"
  comments no longer promise a boundary that does not exist.

## Next plan

**P1 — Munawib Stage 1** (design §13): people, invitations and roles on the merged identity;
the master rota; clinics; holidays. It depends on P0c's `people` and on this plan only for the
reserved-code guard, which its unit-admin surface is the first thing to need.

Two P0d outputs P1 must respect: `Unit::RESERVED_CODES` is derived from the router, so any new
literal route under `/endorsement` must extend it in the same commit; and `Instance::slug()` is
the token to reuse when the `engine` and `solver` sidecars land in P2/P4 — every finding in this
plan about per-stack naming applies to them again.
