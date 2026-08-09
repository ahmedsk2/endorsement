# Provisioning a new customer instance

D11 makes the **database** the isolation boundary: one customer, one Compose stack, one
MySQL, its own backup bucket. There is no automated pipeline that can stand this up —
`.github/workflows/ci.yml` deliberately has no image push, no registry and no deploy job, and
the tokens that could drive Coolify are owner-held by policy (see the design doc §3.4
correction, Task 10). What follows is what is honestly automatable — `scripts/new-instance.sh`
generates conforming secrets and refuses a colliding slug; `docker/instance-env.sh` resolves
one stack's containers or refuses; `php artisan instance:show` proves an instance is fully
provisioned — plus the parts that are irreducibly manual, in the order that avoids the traps
each has already caused once.

This is a cold start for **one new customer**, done beside an existing live one. Nothing here
touches another instance — the isolation described above is what makes that true.

---

## 1. Decide the five identifiers first

| Identifier | Why it cannot be changed casually later |
| --- | --- |
| **Slug** (`INSTANCE_SLUG`) | Names the backup archive, its own prune glob, the host scripts' config/log/state files, and the bucket. Changing it after the first slugged archive exists leaves an un-prunable generation behind (P0d Task 1, finding 4) — decide once. |
| **Hostname** | `APP_URL`, the Coolify domain field, the DNS record. |
| **Institution code & name** | `INSTITUTION_CODE`/`INSTITUTION_NAME` seed the one `institutions` row this deployment carries (D11: provenance, not a filter). There is no admin UI for this yet — changing it after go-live means a database edit. |
| **Timezone** (`APP_TIMEZONE`) | **The one that cannot be changed afterwards without moving the handover day boundary under existing rows.** `now()` moves with it: a 01:00 write files under a different calendar date, `handover_signoffs`' `UNIQUE(unit_id, handover_date)` day identity shifts under rows that already exist, and the 07:30/15:30 reminders and the 01:30 backup fire at a different wall-clock time. It is **not** an audit-chain hazard — `AuditChain` v3 hashes the stored datetime verbatim for exactly that reason (`app/Support/AuditChain.php:36-52`) — but it must still be correct **before the first clinical write**, not fixed afterwards. |

## 2. Generate the secrets

```bash
bash scripts/new-instance.sh --slug rgh --host endorse.rgh.example --timezone Asia/Riyadh
```

It **generates at run time and prints** — it writes nothing to disk and transmits nothing.
Refuses a slug that does not match `^[a-z0-9][a-z0-9-]{0,31}$` (the same pattern
`App\Support\Instance::SLUG_PATTERN` enforces in PHP), and refuses when
`/etc/endorsement/<slug>.conf` already exists on the host it is run on — a slug collision
would have two customers writing one state file and one log.

**Every generated password is alphanumeric only, and this is not cosmetic.** Coolify feeds
these values through `docker compose`, which performs `$`-interpolation on env values, so a
password containing `$` is silently truncated into something weaker — the *set* password is
not the one written down.

**The two-store custody rule.** `APP_KEY` and `BACKUP_PASSPHRASE` go in **different** stores —
a backup and the key that opens it must never sit together, and *both* are needed to read an
archive: `BACKUP_PASSPHRASE` opens the encrypted archive, `APP_KEY` then decrypts the PHI
columns *inside* the dump.

**The pairing register.** The script prints the `APP_KEY` fingerprint — the same
domain-separated hash `App\Support\Instance::keyFingerprint()` computes, and the same one every
backup's `.meta.json` sidecar carries (Task 1). Write it down now, before the key is ever used:
slug ↔ `APP_KEY` fingerprint ↔ where `BACKUP_PASSPHRASE` lives ↔ bucket name ↔ heartbeat URL.
At N customers that is 2N secrets that are worthless unless correctly paired — an operator
standing in front of a bucket full of ciphertext has nothing else to go on.

Copy the printed block into Coolify (next section) and the fingerprint/custody details into
your password manager, **then close the terminal.**

## 3. Coolify

- **Its own Coolify project** — isolates the environment-variable store from other apps on
  the host and rules out an accidental operation against the wrong one.
- **Docker Compose build pack**, compose path `/docker-compose.production.yml` (the same file
  every instance shares — nothing here is per-instance code, see finding table in the plan).
- **Its own read-only ed25519 deploy key** on the same repo. Not a GitHub App, not an account
  credential.
- **Domain** set on the **`app`** service as `https://<host>:8080` and nothing in the compose
  file — Coolify generates the Traefik router, the redirect and the certificate from that one
  field. A hand-written `rule`/`entrypoints` label would create a second router competing for
  the same host (`docs/RUNBOOK-DEPLOY.md`'s 2026-07-27 outage).
- Paste the environment block from step 2. **Delete the preview-environment copies** of every
  variable — PR previews are not in use, and a second copy of a production secret is not worth
  having.

## 4. DNS and TLS, in this order

1. Create the A record **grey (DNS-only)**.
2. Deploy.
3. Let Let's Encrypt issue the certificate over HTTP-01 (Traefik answers it on port 80).
4. **Then** switch the record to orange (proxied).

With the orange cloud on from the start, Cloudflare terminates TLS itself and the HTTP-01
challenge never reaches Traefik — the certificate never issues.

Then confirm **SSL/TLS mode is Full (strict)** in the Cloudflare dashboard by hand — the API
token used elsewhere cannot read zone settings (error 9109), so this one needs a human with
the dashboard open. Flexible is ruled out behaviourally (the origin 302s HTTP→HTTPS, so
Flexible loops forever); Full and Full (strict) cannot be told apart from outside, and only
strict validates the origin certificate.

Two proxied-mode consequences that make a correct setup look broken, so they are not
mistaken for a fault later:

- **The served certificate is Cloudflare's**, not the origin's — any check that
  string-matches the certificate CN against the hostname fails on a perfectly correct setup.
  `scripts/verify-live.sh` checks the certificate is *valid for the hostname* instead.
- **Cloudflare 1010-blocks unusual user agents** on a proxied zone — automation without a
  real `User-Agent` gets a 403 that looks exactly like a revoked token.

## 5. Bring-up, in this exact order, all owner-run

The entrypoint deliberately migrates nothing (`docker/entrypoint.sh`), so a fresh deploy is
up and every real page 500s until you act. **Never select a container by matching the shared
MySQL image** — with the live instance's stack also running, that is an arbitrary choice
between two customers' databases (P0d Task 6, finding 1). `docker/instance-env.sh` resolves
the one stack matching this instance's Coolify app UUID, or refuses:

**The grant is `ALTER, CREATE, REFERENCES` — not `ALTER` alone.** A first-ever bring-up runs
the FULL migration chain, including several `Schema::create()` calls with a foreign key —
MySQL compiles the foreign key as a separate `ALTER TABLE ... ADD CONSTRAINT ... REFERENCES`
statement, needing its own privilege, and `ALTER` alone gets a bring-up only as far as the
first such table before failing with `1142 CREATE command denied` (or, one grant later,
`1142 REFERENCES command denied`, since MySQL has no transactional DDL and the base table has
already committed by then). `INDEX` is not needed. See `docs/RUNBOOK-DEPLOY.md`'s "Migrations
need a privilege the app does not have" for the full empirical detail and — if `migrate
exit=` below comes back non-zero — the recovery procedure for a mid-chain failure (MySQL will
have left a partially-created object behind that needs a manual `DROP` before you retry).

```bash
eval "$(sudo bash docker/instance-env.sh <this-instance's-uuid>)" && \
PW=$(sudo docker exec "$DB" printenv MYSQL_ROOT_PASSWORD) && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "GRANT ALTER, CREATE, REFERENCES ON \`$DBNAME\`.* TO '$DBUSER'@'%'; FLUSH PRIVILEGES;" && {
  sudo docker exec -u app "$APP" php artisan migrate --force; rc=$?
  sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "REVOKE ALTER, CREATE, REFERENCES ON \`$DBNAME\`.* FROM '$DBUSER'@'%'; FLUSH PRIVILEGES;"
  echo "migrate exit=$rc"
}
```

`&&` up to and including the `GRANT` is load-bearing — `instance-env.sh` prints `false` on
refusal, so nothing downstream runs at all. Past that point the brace group runs the `REVOKE`
**unconditionally**, whatever `migrate` did: a failed migration on a first-ever bring-up is
exactly the moment the elevated privileges must not linger on the runtime credential while
you stop to debug it. `rc` captures the migration's real exit code and `migrate exit=$rc`
prints it back — anything but `0` means stop and investigate before continuing (per the
recovery procedure referenced above), but the schema privilege is already gone either way.
**Read the stderr line `instance-env.sh` prints and confirm the database name is the customer
you meant** before typing anything else. **Verify the revoke landed** — `SHOW GRANTS FOR
'$DBUSER'@'%';` should show none of `ALTER, CREATE, REFERENCES`.

```bash
sudo docker exec -u app "$APP" php artisan db:seed --force
```

`db:seed` runs exactly `ReferenceSeeder` (the four units + the `INSTITUTION_CODE`/
`INSTITUTION_NAME` institution) then `AccessControlSeeder` (capability catalogue and role
grants), in that order. **Never** `DemoSeeder` or `E2eSeeder` — they create fictional logins
whose password is published in the repo docs, and they throw under `APP_ENV=production`.

Least-privilege grants, substituted per this instance's actual database/user names — the
old hardcoded form failed halfway on any customer whose database was not literally
`endorsement` (P0d Task 6):

```bash
eval "$(sudo bash docker/instance-env.sh <this-instance's-uuid>)" && \
sed -e "s/{{DATABASE}}/$DBNAME/g" -e "s/{{USER}}/$DBUSER/g" docs/sql/least-privilege.sql | \
sudo docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot '"$DBNAME"
```

```bash
sudo docker exec -u app "$APP" php artisan user:create-admin
```

**This is the only way in** — registration only ever produces a pending Resident, and
approving one requires an administrator who does not exist yet. Without `db:seed` first, the
bootstrap admin authenticates and holds zero capabilities, so every route 403s.

## 6. First login — and the lockout trap

> **⚠ Choose TOTP. Record the recovery codes at enrolment.**
>
> `user:create-admin` sets `email_verified_at = now()`, so the setup wizard will happily
> accept **email one-time codes** as the second factor. But `mail.default` is still `'log'`
> until SMTP is stored in Admin → Settings — which requires being signed in. Enrol email OTP
> and the *next* login sends the code to a log file on the server instead of an inbox, and
> with only one administrator there is no reset path (`user:create-admin` refuses an existing
> username and has no reset mode). The system locks its only door from the inside.
>
> Enrol **TOTP** (any authenticator app) instead. Save the recovery codes it shows you. You
> can switch to email codes later, once step 7 is done and you have sent yourself a test
> email.

## 7. Configure before calling it live — Admin → Settings

- **SMTP** — host, port, encryption, username, password, from-address, from-name. Use the
  **send test email** button and confirm it arrives.
- **"Operational alerts to"** — until this **and** SMTP are both set, `OpsAlert` is log-only:
  a failed nightly backup or a broken audit chain escalates to a log file on the server that
  nobody reads (`app/Support/OpsAlert.php`). `instance:show` (step 10) refuses to report this
  instance ready until both are set.
- **Generate the VAPID pair** — one click, the private key is stored encrypted. Absent, this
  is *silently degrading* rather than blocking: the setup wizard's completion check does not
  require it, so an operator can sail straight past and push reminders are simply never armed.

## 8. Create a second administrator, with its own second factor

One admin is one lockout away from an unadministrable system. `AccessControlSeeder`'s role
defaults are one-shot (`applied_role_defaults`) — if position 0 (Admin) ever loses
`access.manage`, re-running `db:seed` will **not** restore it.

## 9. Host wiring

- `/etc/endorsement/<slug>.conf`, `0600 root:root` (`docs/RUNBOOK-DEPLOY.md` has the exact
  fields: `PROJECT_UUID`, `RCLONE_CONF`, `DEST`, `PUBLIC_URL`, `HEARTBEAT_FILE`).
- The dedicated bucket, **in-Kingdom** (PDPL Art. 29 applies per customer and is pinned here,
  not left to the operator) — **its own bucket, never a shared one with slug-prefixed paths**
  (owner decision 2026-08-08).
- The object-lock/retention rule on that bucket, so write credentials cannot also delete.
- The per-instance heartbeat URL, with a grace period of a few hours before it pages.
- The per-customer external HTTP monitor on `/up`, at a few minutes' interval.
- The two cron lines, with the slug:

  ```cron
  5 2 * * *  /usr/local/bin/endorsement-backup-sync <slug>
  */5 * * * * /usr/local/bin/endorsement-uptime-check <slug>
  ```

## 10. Prove it

```bash
sudo docker exec -u app "$APP" php artisan instance:show   # must exit 0
curl -sI "https://<host>/up"                                # 200, HSTS, CSP, DENY, no-referrer
sudo docker exec -u app "$APP" php artisan schedule:list    # six jobs
sudo docker exec -u app "$APP" php artisan audit:verify     # exits 0
bash scripts/verify-live.sh "https://<host>"                # passes
```

`instance:show` prints the slug, institution, effective timezone, the `APP_KEY` fingerprint,
and a `set`/`NOT SET` line for every owner-managed secret — **never a secret value** — and
exits non-zero until `BACKUP_PASSPHRASE`, `mail_host` and `alert_email` are all configured. It
is the single command that answers "did I finish provisioning?" instead of a checklist line
nobody re-reads.

## 11. The first restore drill, before the instance carries real data

Per `docs/RUNBOOK-BACKUP.md`. Record the date in the per-instance register alongside the
pairing details from step 2 — at N customers, "quarterly" is N obligations nobody is
tracking unless someone writes down when each last happened.

---

## See also

- `docs/RUNBOOK-DEPLOY.md` — the identifiers table, routine deploys, database operations for
  an **existing** instance, and the incident history.
- `docs/RUNBOOK-BACKUP.md` — key custody, the restore recipe, the drill cadence.
- `docs/OWNER-CHECKLIST.md` — the first-deployment version of several of these steps, in more
  detail, written before a second instance existed.
- `docs/superpowers/plans/2026-08-08-p0d-tenancy-provisioning.md` — why each of these traps
  exists and what P0d changed to close it.

---

## Appendix: the 2026-08-08 dress rehearsal (P0d Task 9)

**A provisioning script that has never been run against a throwaway instance is not done.**
This is that run — and it caught a real defect, which is the point of running it. Everything
below is what was genuinely executed and its actual output, not a description of what should
happen.

### Environment, stated plainly

This machine is a Windows 11 workstation, not the OCI production host. It has Docker Desktop
(client 29.6.2, engine 4.83.0, Linux/aarch64 via WSL2) and real Git Bash, but **no** real
`/etc/endorsement`, `/var/lib`, or `/var/log` — Windows has no such paths — and no Coolify
instance, no production DNS, and no Oracle Object Storage credentials. There is also no "live"
stack on this machine to stand the drill up beside; the real live instance runs on the owner's
OCI host. So this rehearsal stood up **two throwaway stacks side by side** (slugs `drilla` and
`drillb`, institutions `TSA`/`TSB`) rather than one drill beside a live one — sufficient to
prove genuine two-customer discrimination, which is what finding 1 is actually about, but not
identical to the production topology. What follows says exactly which parts are a faithful
rehearsal and which are a stand-in.

### What was genuinely rehearsed, on real Docker, and its output

1. **Two throwaway stacks, brought up for real** via `scripts/new-instance.sh --slug drilla
   ... --institution-code TSA ...` and the same for `drillb`/`TSB` (the actual, unmodified
   script — confirmed it writes nothing to disk and printed 48-character alphanumeric
   passwords and a `base64:` `APP_KEY`, exactly as documented), then
   `docker compose -p endorse-drilla -f docker-compose.production.yml --env-file ... up -d
   --build` for each. A local, throwaway override (not committed) set `container_name:
   app-<slug>` / `db-<slug>` on each stack, to reproduce Coolify's own container naming
   (`docs/OWNER-CHECKLIST.md`'s existing `name=app-<uuid>` selector shows Coolify assigns this
   at deploy time; a vanilla `docker compose -p <name>` project without that override names
   containers `<name>-app-1`, which `docker/instance-env.sh`'s filter does not match — worth
   knowing if a future rehearsal skips the override and wrongly concludes the script is
   broken).

2. **Two real `mysql:8.4` containers existed simultaneously.** `docker ps` showed `db-drilla`
   and `db-drillb`, both healthy, both using `MYSQL_DATABASE=endorsement` /
   `MYSQL_USER=endorse` (deliberately identical — D11 isolates by container, not by name).

3. **`docker/instance-env.sh` selected correctly, every time:**
   - `instance-env.sh drilla` → resolved `app-drilla`/`db-drilla` only, printed the stderr
     identity line, `DBNAME=endorsement`.
   - `instance-env.sh drillb` → resolved `app-drillb`/`db-drillb` only, same database name,
     proving selection is by container identity, not by content.
   - `instance-env.sh` (no argument) → refused, `APP`/`DB` unset, exit 1.
   - `instance-env.sh definitely-not-a-stack` → refused (no matching container), exit 1.
   - `instance-env.sh drill` (a prefix matching **both** `app-drilla` and `app-drillb`) →
     refused ("expected exactly one running app container"), `DB` unset, exit 1. This is the
     ambiguous-match refusal the plan calls out specifically, and it worked.

4. **The full bring-up sequence from §5 ran verbatim, on both stacks, from a fresh database
   each time:** `migrate --force` (via the GRANT ALTER / REVOKE ALTER dance, using
   `instance-env.sh`-resolved credentials) → `db:seed --force` → `docs/sql/least-privilege.sql`
   substituted and applied (verified: the runtime user's grants afterwards were exactly
   `SELECT, INSERT, UPDATE, DELETE`, and both append-only triggers were present) →
   `user:create-admin` (piped, non-interactively, since it is deliberately interactive).
   `drilla`'s admin bootstrapped attached to institution `TSA`; `drillb`'s to `TSB` — two
   different institutions, two different databases, proving cross-stack isolation rather than
   asserting it.

5. **`php artisan instance:show` on `drilla`:** `slug: drilla`, `institution: TSA — Test Stack
   A`, `APP_KEY fingerprint: 68f409ec9cf22ad6`, `BACKUP_PASSPHRASE: set`, `mail_host`/
   `alert_email` correctly `NOT SET` with the documented consequence lines, **exit code 1**
   (correctly refusing to call an unfinished instance ready). On `drillb`: `slug: drillb`,
   `institution: TSB — Test Stack B`, a *different* key fingerprint. Two instances, visibly
   distinguishable from one command each.

6. **The reserved-code and `institution_id` guards, on a genuinely fresh instance** (not the
   PHPUnit suite — this repository's actual code, run inside the actual container):
   `Unit::create(['code' => 'TODAY', ...])` threw `InvalidArgumentException` with the
   documented message. The bootstrap admin's `people.institution_id` and `users.institution_id`
   both resolved to the `TSA` institution's id — the provenance chain, proven end to end
   instead of unit-tested in isolation.

7. **Archive identity and retention scoping, with a real destructive test.** `backup:run` on
   `drilla` wrote `endorsement-drilla-<stamp>.sql.gz.enc` plus a `.meta.json` sidecar whose
   `app_key_fingerprint` (`68f409ec9cf22ad6`) matched `instance:show`'s own output exactly —
   the pairing Task 1 Step 5 exists for. Then: seeded 20 `endorsement-drilla-2026-01-*`
   archives plus **one empty file named for a different slug**
   (`endorsement-qch-2026-01-01_010000.sql.gz.enc`, standing in for a co-located customer's
   archive), ran `backup:run --keep=14`. Result: the eight oldest `drilla` archives were
   pruned, the `qch`-named file was **untouched**, confirmed by listing the directory
   afterwards. Under the pre-P0d glob this file would have been deleted; here it could not be.

8. **A real restore drill**, following `docs/RUNBOOK-BACKUP.md`'s recipe: `openssl enc -d
   -aes-256-cbc -pbkdf2 | gunzip` on the newest `drilla` archive using the real
   `BACKUP_PASSPHRASE`, loaded into a scratch database (`restore_drill`) inside the `drilla`
   MySQL container, then `php artisan audit:verify` against **the restored copy** (not the
   live database — see the correction below) reported `Audit chain intact: 2 rows verified`,
   exit 0.

9. **Both host scripts, run for real against real data**, inside a plain `debian:bookworm-slim`
   container standing in for the host (real `/etc`, `/var/log`, `/var/lib` — see the stand-in
   note below): with the actual `endorse-drilla_endorsement-backups` Docker volume bind-mounted
   at the literal path the script expects, and `rclone` installed for real:
   - `endorsement-backup-sync` with no argument → usage error, exit 2.
   - `endorsement-backup-sync drilla` → real `rclone copy` to a local destination, logged "ok:
     off-host copy complete, 17 objects", correctly reported no heartbeat configured.
   - `endorsement-uptime-check` with no argument → usage error, exit 2.
   - `endorsement-uptime-check nosuchinstance` → "no URL for instance nosuchinstance", exit 2.
   - `endorsement-uptime-check drilla` and `endorsement-uptime-check drillb`, run against each
     stack's own app container, wrote to **separate** log and state files
     (`endorsement-drilla-uptime.log`/`.state` vs the `drillb` equivalents) with **no
     crosstalk** — the exact N=2 correctness bug (finding 2) disproved rather than reasoned
     about. A second real run against `drilla` after fixing the check URL logged a genuine
     `down` → `recovered (HTTP 200)` transition.

10. **Torn down completely.** `docker compose down -v --remove-orphans` on both stacks, the
    stand-in host container removed, both throwaway images removed, the generated `.env` files
    and `rclone` config shredded. `docker ps -a` / `docker volume ls` confirmed nothing named
    `drill*` remained.

### What this rehearsal found and corrected — the deliverable

**A real defect, not a documentation gap: `INSTANCE_SLUG`, `INSTITUTION_CODE` and
`INSTITUTION_NAME` never reached the container.** `docker-compose.production.yml`'s `app`
service `environment:` block never referenced `${INSTANCE_SLUG}`, `${INSTITUTION_CODE}` or
`${INSTITUTION_NAME}` — Tasks 1 and 3 added the config plumbing and `.env.example` entries, but
nobody wired the compose passthrough. A value pasted into Coolify's Environment Variables
screen — exactly what this document's §2–3 and `scripts/new-instance.sh`'s own printed block
tell the owner to do — would have had **zero effect**: `printenv INSTANCE_SLUG` inside the
first `drilla` container exited 1 (truly absent), and the seeded institution came back `QCH`
regardless of the configured `TSA`. This means **the OWNER ACTION recorded in Task 1 Step 8 of
the plan — setting `INSTANCE_SLUG=qch` in Coolify before the next deploy — would not have taken
effect even if already done**, and must be reconfirmed once the deploy carrying this fix ships.

Fixed in this commit, with a regression test first (red, then green):
`tests/Feature/Build/DeploymentInvariantsTest.php::test_instance_and_institution_variables_reach_the_container`
asserts the three lines are present in the compose file's `environment:` block, in the
`${VAR:-default}` form — the default matters as much as the passthrough, because Laravel's
`env('X', 'default')` returns `''`, not `'default'`, when a variable is *present but empty*,
which is exactly what a bare `${INSTITUTION_CODE}` (no compose-level default) would have put in
the container the moment this variable started being passed through at all, silently reverting
the live QCH institution's code to empty on the next re-seed. Re-ran the full bring-up on a
fresh `drilla` after the fix: `INSTANCE_SLUG=drilla`, `INSTITUTION_CODE=TSA` both now present
inside the container, `instance:show` and the seeded institution both correct.

**A documentation gap, corrected in Task 10:** `docs/RUNBOOK-BACKUP.md`'s restore recipe says
to run `php artisan audit:verify` after loading the archive into a scratch database, but
running it *inside the already-booted app container* with `docker exec -e DB_DATABASE=...`
silently verifies the **live** database instead — `config:cache` bakes the database name at
container boot, so the environment override has no effect and the command still reports
success, just against the wrong data. The genuine drill needed two things the runbook does not
mention: `GRANT SELECT` for the app's least-privilege user on the scratch database, and forcing
a live reconnect inside the same PHP process (`config(['database.connections.mysql.database' =>
'...']); DB::purge('mysql');`) before calling the command. Recorded as a correction to
`docs/RUNBOOK-BACKUP.md`.

**An engine-version-dependent nuance, informational, no code change:** on this Docker Desktop
version, the bare-tag filter this plan's finding 1 quotes (`docker ps -qf` selecting by image
ancestry) matched **zero** containers rather than two, because the compose file pins the MySQL
image by digest and this engine does not resolve a bare tag against a digest-referenced
container for that filter. The digest-qualified equivalent matched both. Either outcome is
worse than `instance-env.sh`'s name-based selection — zero matches fails an operator's command
outright with no explanation, two matches is the coin flip finding 1 describes — so this does
not change what was built; it is recorded because the exact failure mode an operator sees may
differ from the production host's Docker Engine version, and `instance-env.sh` is correct
regardless of which one it is.

**Nothing else needed correcting.** The bring-up order, the least-privilege substitution
recipe, the `instance:show` output shape, the archive naming and retention scoping, and both
host scripts' slug-argument and refusal behaviour all matched the runbook exactly.

### What could NOT be rehearsed here, and what the owner must run instead

This machine cannot exercise the parts of provisioning that are genuinely host-, Coolify- or
DNS-shaped. The host-script proof above (item 9) ran inside a plain Linux container standing in
for the host — a real execution of the real script against real data, but not the literal
production host, its cron, or its root-owned files. Everything below still needs a first real
run, by the owner, on the actual host:

- [ ] **Install the two binaries at their real paths** (`/usr/local/bin/endorsement-backup-sync`,
      `/usr/local/bin/endorsement-uptime-check`) and the real per-instance config at
      `/etc/endorsement/<slug>.conf`, `0600 root:root`, per §9 above — this rehearsal proved the
      scripts' *logic*, not the host installation recipe in `docs/RUNBOOK-DEPLOY.md:141-149`.
- [ ] **Install the two cron lines** (`5 2 * * *` / `*/5 * * * *`) and confirm both binaries run
      correctly under cron's minimal environment (not an interactive shell's), for the *existing*
      `qch` instance specifically — see the note below.
- [ ] **Run `docker/backup-offhost-sync.sh` against the real off-host destination** (the
      per-customer in-Kingdom Oracle Object Storage bucket with its own `rclone` remote and real
      credentials) — this rehearsal used a local `rclone` "local" remote as a throwaway
      destination and never touched real object storage.
- [ ] **The Coolify UI steps in §3** (project, Docker Compose build pack, deploy key, domain
      field, deleting preview-environment variable copies) — there is no Coolify instance on this
      machine; these are a human in a UI regardless of what P0d automates.
- [ ] **The DNS/TLS sequence in §4** (grey → deploy → Let's Encrypt → orange, confirming Full
      (strict) by hand) — no real domain or Cloudflare zone was available here.
- [ ] **First login, TOTP enrolment and recovery codes (§6), SMTP/alert-email/VAPID
      configuration (§7), and the second-administrator step (§8)** — all UI flows requiring a
      real browser session against a real domain; not exercised by this console-only rehearsal.
- [ ] **`curl -sI https://<host>/up` and `bash scripts/verify-live.sh https://<host>` (§10)**
      against the real public hostname — this rehearsal checked `/up` over a container-internal
      hostname instead (and had to add a `/etc/hosts` alias to satisfy `TrustHosts`, since the
      app correctly refuses an unrecognised `Host:` header — `bootstrap/app.php:73-90` — which is
      itself a small, live demonstration of why a co-tenant container reaching the app directly
      is a real, if currently accepted, exposure; see owner decision 3).
- [ ] **The most important item: on the existing `qch` deployment specifically**, once the
      compose fix above ships — confirm `INSTANCE_SLUG=qch` is actually set in Coolify's
      Environment Variables screen (it may have been set already, per Task 1 Step 8, but had no
      effect until this fix), redeploy, and run
      `eval "$(sudo bash docker/instance-env.sh <live-uuid>)" && sudo docker exec "$APP" printenv
      INSTANCE_SLUG` to confirm it now reads `qch` — **before** the next `01:30` backup, or the
      archive name silently reverts to the un-derived fallback the moment this deploys.

This rehearsal forced one code correction (the compose passthrough, above) and one runbook
correction (the restore recipe's `audit:verify` step, folded into Task 10). Everything else
matched what was documented.
