# Deployment runbook — endorse.towardpcc.com

Hosted on the existing Coolify host in **OCI me-riyadh-1** (in-Kingdom, which is what keeps
the PDPL data-residency position simple). Coolify 4.1.2, Traefik owns :80/:443 on the
`coolify` docker network.

| | |
| --- | --- |
| Host public IP | `145.241.105.239` |
| Domain | `endorse.towardpcc.com` |
| Coolify dashboard | `deploy.towardpcc.com` |
| Coolify project | `clinical` — deliberately separate from `demos` / `migrated-sites` |
| Coolify app | `endorsement` (Docker Compose build pack) |
| Repo | `github.com/ahmedsk2/endorsement`, branch `main` |
| Compose file | `/docker-compose.production.yml` |
| Coolify app UUID | `oo7d7si62yhyi7fx10hrck6q` — pass this to `docker/instance-env.sh` |
| Instance slug (`INSTANCE_SLUG`) | see Task 1/Task 10 owner-action note below; not yet confirmed set in Coolify |
| Hijri calibration (`HIJRI_OFFSET_DAYS`) | QCH: `-1`. Not yet set in Coolify — see the P1a owner action below. Until set, QCH renders every Hijri date **one day late**. Must be verified against the department's own published calendar across a month boundary before anyone trusts a Hijri date on screen — do not just trust the seeded default. |

Identifiers (project/app/DNS-record/deploy-key UUIDs) are recorded in
`ORACLE MCP/infra/state.env` as `ENDORSE_*`, alongside the other apps on this host.

> **Deploying P1 (2026-08-12): read `docs/DEPLOY-P1-2026-08-12.md` first.** This file is the
> standing document — how the deployment is wired, the grant/revoke shape, the per-slice
> verification queries, the outage post-mortems. That one is the ordered, single-use runbook for
> the 22-migration P1 release: pre-flight, the migration list with the two that are not routine,
> the environment variables that must be set before the seed, the post-deploy queries, the
> operator actions that are not migrations, and an honest rollback picture (there is no clean
> code-only rollback past `2026_08_10_120003`). It also records three corrections to this file.

**A second customer gets its own row in this table and its own entry in
`docs/RUNBOOK-PROVISION.md`.** Never reuse this deployment's UUID or slug for another
customer, and never select the database container by matching the shared MySQL image name —
with two stacks on one host that picks an arbitrary one. Every command below resolves a
stack with `docker/instance-env.sh <uuid>`, which refuses rather than guessing.

---

## As configured

**DNS.** Cloudflare `A endorse → 145.241.105.239`, now **Proxied (orange cloud)**.

It was grey during setup, and that mattered then: Let's Encrypt validates over HTTP-01 and
Traefik answers that on port 80, but with the orange cloud on Cloudflare terminates TLS
itself and the challenge never reaches Traefik. Once the certificate existed the record was
switched to proxied.

Two consequences of being proxied, both already hit:

- **The served certificate is Cloudflare's** (`CN=towardpcc.com`), not the origin's. Any
  check that string-matches the certificate CN against the hostname fails on a perfectly
  correct setup — `scripts/verify-live.sh` verifies the certificate is *valid for the
  hostname* instead, which is the property that actually matters.
- **Cloudflare 1010-blocks unusual user agents** on this zone. The Coolify API sits behind
  it too, so any automation must send a real `User-Agent` or get a 403 that looks like a
  revoked token.

**Confirm SSL/TLS mode is Full (strict)** in the dashboard. Flexible is ruled out
behaviourally — the origin 302s HTTP to HTTPS, so Flexible would loop forever and the site
would be dead — but Full and Full (strict) cannot be told apart from outside, and only
strict validates the origin certificate. The API token used here cannot read zone settings
(it returns 9109), so this one needs a human with the dashboard open.

**Routing.** The domain is set on the compose `app` service as
`https://endorse.towardpcc.com:8080` — the `:8080` is Coolify's syntax for the container
port (the app runs unprivileged and cannot bind 80). Coolify generates the Traefik routers,
the `redirect-to-https` middleware and the Let's Encrypt certificate from that one field.
`docker-compose.production.yml` therefore defines **no Traefik router of its own** — adding
a `rule` or `entrypoints` label would create a second router competing for the same host.

It carries exactly one `traefik.*` label, and that one is a *hint* rather than a router:
`traefik.docker.network=coolify`, which tells Traefik which of the container's three
networks to dial. Without it Traefik guesses, and guessing wrong is a total outage — see
the 2026-07-27 entry at the end of this file.

**Repo access.** A dedicated ed25519 **read-only** deploy key, held in Coolify and
registered on the GitHub repo. Not a GitHub App, not an account credential — it can clone
this one repository and nothing else.

**Database.** A dedicated `mysql:8.4` container on an `internal`-only network: not on the
shared `coolify` network, and publishing no host port. Children's health data does not
share an engine, a root account or a backup with unrelated projects, and no other app on
this host can reach it. `docker/smoke.sh` asserts both properties on every run.

**Secrets.** `APP_KEY`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` and `BACKUP_PASSPHRASE` live
in Coolify's environment-variable store and are mirrored in `infra/secrets.env` as
`ENDORSE_*`. They are 48-character alphanumeric by construction: Coolify feeds these into
`docker compose`, which performs `$`-interpolation on env values, so a password containing
`$` would be silently truncated into something weaker. Coolify's preview-environment copies
of each variable were deleted — PR previews are not in use, and a second copy of the
production database password is not worth having.

> **APP_KEY is not a routine secret.** Patient name, MRN, DOB and all four rich-text fields
> are encrypted with it in the database. Lose it and the clinical record is unrecoverable
> ciphertext; a restored backup will not help. Rotating it is a data migration, not a config
> change. Keep it in your password manager, and **not** in the same place as
> `BACKUP_PASSPHRASE` — a backup and its key should never sit together. Custody rules:
> `docs/RUNBOOK-BACKUP.md`.

SMTP and web-push VAPID keys are **not** environment variables — configure them in-app at
**Admin → Settings**, where they are stored encrypted.

---

## Deploying a change

```bash
git push origin main
```

Then **Deploy** in Coolify (or let the webhook fire, if you enable one). The container
**does not migrate at boot** — `docker/entrypoint.sh` deliberately never touches the
schema, so an unattended 3am restart can never alter a clinical database. It does rebuild
the config/route/view caches against the real environment and refuses to start without
`APP_KEY`.

If a release adds migrations, run them yourself after the deploy (next section).

### Before you push anything that touches the image or the compose file

```bash
bash docker/smoke.sh
```

Run it **on the host**. It brings up a throwaway copy of the real production stack — its own
compose project, its own volumes, generated credentials — and asserts what the PHP and JS
suites structurally cannot: the image boots, MySQL accepts the compose file's flags,
migrations apply against real MySQL, `/up` returns 200, php-fpm runs as the user owning
`storage/`, and the database is neither on the shared proxy network nor publishing a port.
It tears itself down, including on failure.

It exists because three bugs reached a deployment while all 299 unit tests passed:

- a global middleware calling `$request->user()` on the session-less `/up` route — health
  check permanently red, every real page fine;
- php-fpm running as `www-data` against storage owned by `app` — signature uploads failing
  silently;
- `--default-authentication-plugin` in the compose file, **removed in MySQL 8.4**, so mysqld
  aborted at boot and the first deployment failed. The earlier smoke script ran
  `docker run mysql:8.4` by hand and could not see a compose-file bug at all. Test the
  artifact you ship.

---

## The host scripts are NOT deployed by a deploy

Two scripts run on the HOST, outside any container, and are installed by hand — **ONE
binary each, shared by every customer instance**, taking the instance slug as `$1`:

    /usr/local/bin/endorsement-backup-sync     <- docker/backup-offhost-sync.sh
    /usr/local/bin/endorsement-uptime-check    <- docker/uptime-check.sh

**A `git push` and a Coolify deploy do not touch them.** On 2026-07-28 the host copy of the
sync script was found to be 48 lines against the repository's 86 — it predated the backup
heartbeat entirely, so a heartbeat URL placed on that server would have been pinged by
nothing, while the repository said the feature existed. The repo was right about the code
and wrong about reality, which is the worse way round.

### Per-instance config, `/etc/endorsement/<slug>.conf`, `0600 root:root`

Both scripts refuse to run without a slug, and read everything instance-specific out of this
file. Root-only: `HEARTBEAT_FILE` names a secret and `DEST` names the off-host copy of the
clinical record.

```sh
# /etc/endorsement/qch.conf — one per customer instance.
PROJECT_UUID=oo7d7si62yhyi7fx10hrck6q
RCLONE_CONF=/etc/endorsement/rclone.conf
DEST=oci-qch:endorsement-backups-qch
PUBLIC_URL=https://endorse.towardpcc.com/up
HEARTBEAT_FILE=/etc/endorsement/qch-heartbeat.url
```

**A separate bucket per customer, not a shared bucket with prefixes** (owner decision
2026-08-08). Three reasons, all already true of this system: a dedicated bucket keeps one
customer's health data from sitting alongside another's; the outstanding object-lock/retention
rule applies per bucket, so a shared bucket makes one customer's retention policy another's;
and the freshness check that treats an empty destination as a failure is only meaningful when
the destination belongs to one customer — otherwise customer B's fresh upload would satisfy
customer A's assertion, and A's backups could stop permanently while the heartbeat keeps
firing.

Cron, per instance:

```cron
5 2 * * *  /usr/local/bin/endorsement-backup-sync qch
*/5 * * * * /usr/local/bin/endorsement-uptime-check qch
```

### Installing a change to either script

```bash
scp -i ~/.ssh/oci_server docker/backup-offhost-sync.sh ubuntu@145.241.105.239:/tmp/s.sh
ssh -i ~/.ssh/oci_server ubuntu@145.241.105.239 '
  sudo cp /usr/local/bin/endorsement-backup-sync /root/endorsement-backup-sync.$(date +%F).bak
  bash -n /tmp/s.sh && sudo install -m 0755 -o root -g root /tmp/s.sh /usr/local/bin/endorsement-backup-sync
  sudo /usr/local/bin/endorsement-backup-sync qch; echo "exit=$?"'
```

Back up first, syntax-check before installing, run it once **with the slug**, and roll back
on a non-zero exit. These are the scripts that protect the only off-site copy of the clinical
record, so "it looked fine" is not a verification.

**Installing the new (slugged) binary breaks any old crontab entry that passes no slug** —
the script now exits 2 immediately instead of running. Update the crontab in the SAME
session you install the binary, and confirm both scripts run by hand (with the slug) before
leaving the host. A script that exits 2 every night is safer than one that guesses which
customer it is protecting, but only if someone notices — and the first night after an install
is exactly when nobody is watching for it.

## Database operations (yours to run)

Open a shell from **Coolify → the app → Terminal**, or over SSH:

```bash
eval "$(sudo bash docker/instance-env.sh oo7d7si62yhyi7fx10hrck6q)" && sudo docker exec -it "$APP" sh
```

**Read the stderr line `instance-env.sh` prints and confirm the database name is the
customer you meant** before typing anything else — `instance-env.sh` refuses to guess when
zero or more than one stack matches, but a UUID typo that happens to match a *different*
real stack will not refuse, and this line is the only thing that catches it.

### Migrations need a privilege the app does not have

`php artisan migrate --force` **fails on its own**, and that is deliberate:

```
SQLSTATE[42000]: 1142 ALTER command denied to user 'endorse'@'...'
```

The application's database user holds `SELECT, INSERT, UPDATE, DELETE` and nothing else —
no `ALTER`, no `DROP`, no `CREATE`, no `REFERENCES`. A compromised application therefore
cannot reshape or drop the clinical schema, which is worth keeping. It does mean a schema
change is a deliberate, privileged act rather than something the app can do to itself.

Setting `-e DB_USERNAME=root` on the exec does **not** work either: the config is cached at
boot, so `env()` is not consulted at runtime. (`docs/sql/least-privilege.sql` used to suggest
this as an alternative — it does not work for the same reason, and no longer suggests it.)

So: grant, migrate, revoke. Run from the HOST (not inside the app container), so the root
credential is read from the database container's own environment and never typed or logged.

**The grant is `ALTER, CREATE, REFERENCES` — not `ALTER` alone.** Verified empirically against
real MySQL 8.4 (`sql_mode` including `STRICT_TRANS_TABLES`): with `ALTER` only, the migration
chain gets through every migration that merely alters an existing table, then dies on the
first one that **creates** a table with a foreign key —
`2026_08_09_120001_create_unit_field_definitions_table` in the current chain, but the same
failure recurs at the next `Schema::create` with a `constrained()` column, so this is not a
one-time exception to special-case:

```
SQLSTATE[42000]: 1142 CREATE command denied to user 'endorse'@'...' for table 'unit_field_definitions'
```

Granting `CREATE` too gets further, then dies one statement later — MySQL compiles a foreign
key on a `Schema::create()` table as a **separate** `ALTER TABLE ... ADD CONSTRAINT ...
FOREIGN KEY ... REFERENCES` statement, not part of the `CREATE TABLE` itself, and that
statement needs `REFERENCES` on the table it points at:

```
SQLSTATE[42000]: 1142 REFERENCES command denied to user 'endorse'@'...' for table 'units'
```

**This is the dangerous part.** MySQL has no transactional DDL: the `CREATE TABLE` statement
already committed before the `ADD CONSTRAINT` statement failed. The migration as a whole
still threw, so `unit_field_definitions` was never recorded in the `migrations` table — but
the table itself now exists, partially built (columns, no foreign key, no indexes). Retrying
`php artisan migrate --force` immediately fails again, on the **same** migration, with a
**different, more confusing** error that gives no hint a privilege was ever the problem:

```
SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'unit_field_definitions' already exists
```

`INDEX` is **not** needed — verified across the full chain, including tables with `unique()`
and `index()` columns.

```bash
eval "$(sudo bash docker/instance-env.sh oo7d7si62yhyi7fx10hrck6q)" && \
PW=$(sudo docker exec "$DB" printenv MYSQL_ROOT_PASSWORD) && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "GRANT ALTER, CREATE, REFERENCES ON \`$DBNAME\`.* TO '$DBUSER'@'%'; FLUSH PRIVILEGES;" && {
  sudo docker exec -u app "$APP" php artisan migrate --force; rc=$?
  sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "REVOKE ALTER, CREATE, REFERENCES ON \`$DBNAME\`.* FROM '$DBUSER'@'%'; FLUSH PRIVILEGES;"
  echo "migrate exit=$rc"
}
```

**Never select the database container by matching the shared MySQL image name** — with two
customer stacks on one host that picks an arbitrary one, and the grant / `migrate` / revoke
sequence above then lands coherently on the **wrong customer's clinical database**.
`docker/instance-env.sh` resolves the one stack matching a Coolify app UUID, or refuses.

Two different jobs are chained here, and they are meant to fail differently. Up to and
including the `GRANT`, `&&` is load-bearing: `instance-env.sh` prints `false` on refusal, so
nothing downstream runs at all — that refusal must abort everything. Past the `GRANT`, the
brace group runs the `REVOKE` **unconditionally**, whatever `migrate` did: a failed migration
is exactly the moment the elevated privileges must not linger on the runtime credential while
you stop to debug it. `rc` captures the migration's real exit code and `migrate exit=$rc`
prints it back — anything but `0` means stop and investigate the migration before doing
anything else, but the schema privilege is already gone either way. **Read the stderr line
`instance-env.sh` prints and confirm the database name is the customer you meant** before
typing anything else.

`MYSQL_PWD` rather than `-p"$PW"` keeps the credential out of the container's process list.
`-u app` keeps the artisan process unprivileged inside the container. **Verify the revoke
landed** — `SHOW GRANTS FOR '$DBUSER'@'%';` should show none of `ALTER, CREATE, REFERENCES`.

#### If `migrate exit=` is non-zero: recovery before you retry

**Do not just re-run `migrate --force`.** MySQL's error will not name a migration — only a
table, column, or constraint — and by the time you see the error, the failed migration may
have already left a partially-built object behind (see the worked example above: a `CREATE
TABLE` with a foreign key can commit the table and then fail adding the constraint, in two
separate statements). Retrying against that residue produces a **different** error (`1050
Table already exists`, or the column/index/constraint equivalents) that reads like a new
problem and is not.

1. `php artisan migrate:status` — the first row still showing "Pending" is the migration that
   failed (everything above it committed and is correctly recorded; nothing below it ran).
2. Open that migration file and read its `up()`. Every table, column, index, and foreign key
   it creates is a candidate for having been partially applied.
3. Check the database directly for each: `SHOW TABLES LIKE '<name>';`,
   `SHOW COLUMNS FROM <table> LIKE '<name>';`, `SHOW CREATE TABLE <table>;` (to see whether a
   constraint landed). Whatever exists that should not yet exist is residue from the failed
   attempt.
4. If nothing exists, the failure was clean (nothing partially applied, e.g. a straightforward
   missing-privilege refusal on the very first statement) — fix the underlying cause (usually:
   the grant above didn't take, or was typed against the wrong database) and retry directly.
5. If something exists, it must be dropped by hand before retrying — `DROP TABLE
   <name>;` or `ALTER TABLE <table> DROP COLUMN <name>;` as appropriate, run as root (this is
   exactly the `DROP` privilege the routine grant above deliberately does not include — see
   below). Only then re-run `php artisan migrate --force`.

#### `migrate:rollback` needs `DROP` too — grant it only for that

`ALTER, CREATE, REFERENCES` is enough for `migrate`, but several `down()` methods drop a
whole table (`dropIfExists`), which needs `DROP` and is refused the same way as everything
above:

```
SQLSTATE[42000]: 1142 DROP command denied to user 'endorse'@'...' for table 'holidays'
```

Do **not** fold `DROP` into the routine deploy grant — `migrate:rollback` is an exceptional,
manual operation (see "Rollback" below), not something every deploy needs, and the runtime
credential should hold the smallest privilege set that gets the operation in front of you
done. Grant it in the same shape, for the one rollback, then revoke it immediately after:

```bash
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "GRANT ALTER, CREATE, REFERENCES, DROP ON \`$DBNAME\`.* TO '$DBUSER'@'%'; FLUSH PRIVILEGES;" && {
  sudo docker exec -u app "$APP" php artisan migrate:rollback --step=1 --force; rc=$?
  sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "REVOKE ALTER, CREATE, REFERENCES, DROP ON \`$DBNAME\`.* FROM '$DBUSER'@'%'; FLUSH PRIVILEGES;"
  echo "rollback exit=$rc"
}
```

```bash
php artisan db:seed --force
```

`db:seed` runs exactly two seeders — `ReferenceSeeder` (the four units + the institution)
and `AccessControlSeeder` (the capability catalogue and role grants). It does **not** touch
`DemoSeeder` or `E2eSeeder`: those create fictional logins whose password is published in
the repo docs, are not wired into `DatabaseSeeder`, and throw if invoked with
`APP_ENV=production`. Never call them by name here.

Create the first administrator. **This is the only way in** — registration produces a
*pending Resident*, and approving one requires an administrator who does not exist yet:

```bash
php artisan user:create-admin
```

It prompts for everything, never echoes the password, applies the same password policy as
the registration form, and refuses a username that already exists so a careless re-run
cannot reset a real administrator's password. Your first sign-in redirects you to your
profile to enrol a second factor — the admin screens are unreachable without one, by design.

Nothing is imported: the system starts clean, by your decision of 2026-07-25.
`docs/RUNBOOK-IMPORT.md` keeps the importer available if the unit ever changes its mind.

---

## Verify

```bash
curl -sI https://endorse.towardpcc.com/up
```

Expect `200`, plus `strict-transport-security`, `content-security-policy`,
`x-frame-options: DENY` and `referrer-policy: no-referrer`. Then, signed in:

- **Admin → Settings** — set SMTP, send the test email, generate the VAPID pair.
- Register a throwaway account and confirm the verification email arrives.
- Open a unit, add a patient row, type in a rich-text field, **reload** — the text must
  still be there and still coloured. That is the legacy production bug this system fixes.
- Print a signed day; confirm names and signatures render.
- `php artisan schedule:list` — **eight** entries: `endorsement:remind` at 07:40 and 15:40
  (the handover times 07:30/15:30 plus `remind_delay_minutes`, default 10) **and** every
  fifteen minutes as an idempotent safety net; `audit:verify` hourly; `audit:anomalies`
  hourly; `backup:run` 01:30; `data:retention --force` 02:30; `users:dormant` Monday 08:00.
  (This said "six jobs … reminders at 07:30 and 15:30" until 2026-08-12. The six is
  `docker/smoke.sh`'s figure, which greps `schedule:list` for four command names only and so
  cannot see `audit:anomalies` or `users:dormant`; the times were the handover times, not the
  reminder times. Enumerated from `routes/console.php`.)
- `php artisan audit:verify` — the hash chain is intact.

---

## Backups

The nightly encrypted dump is scheduled inside the container and lands in the
`endorsement-backups` volume. **A backup that only exists on the machine it backs up is not
a backup** — pull it off-host on a schedule, per `docs/RUNBOOK-BACKUP.md`, which also covers
the local copy and the restore recipe. Do the restore drill once before you trust it.

---

## Rollback

**Coolify → Deployments → the last good one → Redeploy.** That reverts code only. It does
**not** revert migrations: if the release migrated the schema, restore the database backup
taken before it, then redeploy the matching commit.

---

## Deploying without Coolify (fallback)

The compose file runs by hand, but then nothing generates the Traefik router — add these
labels to the `app` service and supply an `--env-file`:

```yaml
labels:
  - traefik.enable=true
  - traefik.docker.network=coolify
  - traefik.http.routers.endorsement-http.rule=Host(`endorse.towardpcc.com`)
  - traefik.http.routers.endorsement-http.entrypoints=http
  - traefik.http.routers.endorsement-http.middlewares=endorsement-https
  - traefik.http.middlewares.endorsement-https.redirectscheme.scheme=https
  - traefik.http.middlewares.endorsement-https.redirectscheme.permanent=true
  - traefik.http.routers.endorsement.rule=Host(`endorse.towardpcc.com`)
  - traefik.http.routers.endorsement.entrypoints=https
  - traefik.http.routers.endorsement.tls=true
  - traefik.http.routers.endorsement.tls.certresolver=letsencrypt
  - traefik.http.services.endorsement.loadbalancer.server.port=8080
```

```bash
docker compose -f docker-compose.production.yml --env-file .env.production up -d --build
```

---

## Outage 2026-07-27 — 504 on every request, container healthy

**Symptom.** The site returned 504 from both Cloudflare and the origin directly, with a
consistent 30-second wait. The app container was `healthy` and answering 200s to its own
healthcheck the whole time. Nothing in the deployed change was related.

**Cause.** The app container is on THREE docker networks — `coolify`, Coolify's per-app
network, and `internal` for the database — and `coolify-proxy` is not on `internal`. No
`traefik.docker.network` label was set, so Traefik chose which of the container's networks
to dial on its own. Go randomises map iteration, so that choice can differ on every deploy.
Pick `internal` and every request hangs until Traefik's 30s backend timeout.

**This had been a coin flip on every deploy since the application was created.** It simply
never lost before. That is the shape worth remembering: a latent fault that stays invisible
for weeks, then presents as a total outage immediately after an unrelated change.

**Fix.** One label on the `app` service in `docker-compose.production.yml`:

```yaml
labels:
  - traefik.docker.network=coolify
```

It is a hint, not a router — routers still come from Coolify's Domains field, so it does
not create a second router competing for the host. Coolify preserves it into its generated
compose; verified on the running container after deploy.

### Diagnosing this class of fault

Two false trails cost time and are worth naming:

- **Probing the container by IP returns `400 Bad Request`.** That is `trustHosts` working
  correctly — the Host header is the container IP, which is not allowlisted. It is not the
  bug. Probe with `--header "Host: endorse.towardpcc.com"` instead.
- **Grepping the main JS bundle for a page's assets finds nothing.** Inertia code-splits by
  page; look in `assets/<PageName>-<hash>.js`, found via `/build/manifest.json`. Comparing
  that hash against the local build also proves the deployed artifact is the tested one.

`scripts/verify-live.sh` catches this outage — it was green after the previous deploy,
which is precisely when the coin had landed the other way. **Run it after every deploy**;
it is the only check that exercises the real path from the edge to the container.

Since 2026-07-27 this no longer depends on somebody remembering. The host runs
`/usr/local/bin/endorsement-uptime-check` every five minutes against the PUBLIC url and
logs to `/var/log/endorsement-uptime.log` on every state change. That is deliberately
outside the container: the container's own HEALTHCHECK proves the app is alive, which is a
different question from whether a clinician can reach it — and during this outage the first
was green the entire time the second was false.

```bash
sudo tail /var/log/endorsement-uptime.log
```

It logs transitions only, plus one daily heartbeat at 07:00 so a silent log can be told
apart from a stopped cron. **It has no notification channel yet** — see the owner checklist.

---

## Verifying the 2026-08-08 unit-configuration migration

After running `php artisan migrate` for `2026_08_08_120001_add_configuration_to_units`,
confirm the backfill landed. Expect exactly four rows, none with a NULL `bar_class`:

    SELECT code, display_order, active, extra_row_fields, bed_label, consultant_pair,
           consultant_by_label, bar_class, print_plan_label, print_narrative_label
    FROM units ORDER BY display_order;

    -- code  display_order  active  extra_row_fields       bed_label  consultant_pair  consultant_by_label  bar_class         print_plan_label  print_narrative_label
    -- PICU  1              1       []                     Bed        1                Consultant covering  channel-bar-picu  Plan Of Care      New events
    -- NICU  2              1       ["dob"]                Bed        1                Consultant covering  channel-bar-nicu  Plan Of Care      To be followed
    -- SCBU  3              1       ["dob"]                Bed        1                Consultant covering  channel-bar-scbu  Plan Of Care      To be followed
    -- WARD  4              1       ["age", "ward_unit"]   Room       0                Consultant Oncall    channel-bar-ward  Management        To be followed

MySQL 8.4 re-serializes a `JSON` column on `SELECT`, inserting a space after each comma — a
multi-element `extra_row_fields` like WARD's above will read back as `["age", "ward_unit"]`
even though it was written as `["age","ward_unit"]`. That is expected, not a corrupted row.

Read columns by POSITION against the header above, not by eye against a neighbouring row —
`consultant_pair` and `display_order`/`active` are all small integers next to each other, and
PICU's is 1 in every one of those columns while WARD's is 0 only in `consultant_pair`. It is
easy to align on the wrong column and still believe you verified.

Then run the counter-check, which is the query that actually catches a missed row —
`display_order` gives no visual signal on its own, since a row the backfill skipped just sorts
to the end rather than looking wrong:

```sql
-- Must return 0. A non-zero count means the backfill missed a row.
SELECT COUNT(*) FROM units WHERE bar_class IS NULL OR display_order = 1000;
```

A NULL `bar_class` or a `display_order` of 1000 (the column's unconfigured-department default)
means the row's `code` did not match the migration's constant — fix the data, do not edit the
migration after it has run.

---

## Verifying the 2026-08-10 identity migrations

### 2026_08_10_120001_create_people_and_link_users

Every account must have gained exactly one person. Run all three; all three must return 0.

    SELECT COUNT(*) FROM users WHERE person_id IS NULL;                    -- unlinked accounts
    SELECT COUNT(*) FROM people p LEFT JOIN users u ON u.person_id = p.id
      WHERE u.id IS NULL;                                                  -- orphan people
    SELECT COUNT(*) FROM (SELECT person_id FROM users WHERE person_id IS NOT NULL
      GROUP BY person_id HAVING COUNT(*) > 1) d;                           -- shared people

And the counts must match, including soft-deleted accounts:

    SELECT (SELECT COUNT(*) FROM users) AS accounts, (SELECT COUNT(*) FROM people) AS people;

A non-zero first query means the backfill did not run — do NOT edit the migration after it has
run. Re-link by hand, or roll back with `php artisan migrate:rollback --step=1` and re-run.

**The "orphan people" query and the accounts-vs-people equality query are time-bounded** — run
them immediately after this migration, before any roster entry (a legacy import, an invitation to
a not-yet-existing address, an admin adding someone to the roster) creates a `people` row with no
`users` row of its own. That is the normal steady state from then on: a department roster always
holds people who have never claimed a login, so a later re-run of either query will report a
false failure once real roster-only rows exist. The "unlinked accounts" query (every account has
a person) and the 120003/120004 checks below stay valid at any time — they are not about counts
matching, they are about a specific `users` row resolving correctly.

### 2026_08_10_120003_move_name_and_position_off_users — TAKE A DUMP FIRST

This migration DROPS `users.full_name` and `users.position`. `down()` restores them by copying
back from `people`, so it is reversible — but only while `people` exists.

Before `php artisan migrate`:

    mysqldump --single-transaction --routines <db> > pre-p0c-$(date +%F).sql

To roll back, roll back in THIS order and no other:

    php artisan migrate:rollback --step=1   # 120005 invitations.person_id
    php artisan migrate:rollback --step=1   # 120004 handover_signoffs person ids
    php artisan migrate:rollback --step=1   # 120003 restores users.full_name / users.position
    php artisan migrate:rollback --step=1   # 120002 levels
    php artisan migrate:rollback --step=1   # 120001 drops `people` — nothing to copy from after this

Rolling 120001 back before 120003 loses every name and role. Restore from the dump instead.

120002 and 120001 each `dropIfExists()` a table, so the runtime credential needs `DROP` for
this sequence — see "`migrate:rollback` needs `DROP` too" above; grant it before step 1,
revoke it after step 5. Verified against real MySQL 8.4: this exact 5-step order, with `DROP`
granted, runs clean end to end — `people` is gone, `users.person_id` is gone, and
`users.full_name` is intact, restored by 120003's own `down()`. (120001's `down()` used to
order `dropUnique` before `dropConstrainedForeignId`, which fails on MySQL/InnoDB — see the
migration file's own comment — but that ordering bug is fixed and this is no longer a
concern for the sequence above.)

After migrating, confirm the copy is complete:

    SELECT COUNT(*) FROM people WHERE full_name IS NULL OR full_name = '';   -- must be 0

### 2026_08_10_120004_add_person_ids_to_handover_signoffs

Every historical named role must have resolved. Expect 0 from each:

    SELECT COUNT(*) FROM handover_signoffs WHERE endorsed_by_user_id IS NOT NULL AND endorsed_by_person_id IS NULL;
    SELECT COUNT(*) FROM handover_signoffs WHERE endorsed_to_user_id IS NOT NULL AND endorsed_to_person_id IS NULL;
    SELECT COUNT(*) FROM handover_signoffs WHERE consultant_by_user_id IS NOT NULL AND consultant_by_person_id IS NULL;
    SELECT COUNT(*) FROM handover_signoffs WHERE consultant_to_user_id IS NOT NULL AND consultant_to_person_id IS NULL;

A non-zero count means a signoff pointed at a `users` row that has no person — check
`SELECT id FROM users WHERE person_id IS NULL` first (that is the 120001 verification).

Spot-check that the names still agree with the frozen snapshots — this is the check that would
catch a copy-instead-of-join:

    SELECT s.id, s.endorsed_by_name, p.full_name
    FROM handover_signoffs s JOIN people p ON p.id = s.endorsed_by_person_id
    WHERE s.endorsed_by_name IS NOT NULL AND s.endorsed_by_name <> p.full_name;

Rows here are people who were renamed after signing (legitimate) OR a mis-joined backfill
(not). Read them; do not assume.

---

## Verifying the 2026-08-11 institution backfill

### 2026_08_11_120001_backfill_institution_on_identity_rows

`institution_id` is grouping/provenance (D11), never a filter — see
`App\Models\Institution::current()` and `InstitutionProvenanceTest::
test_no_query_filters_on_institution_id`. This migration only fills nulls on `people` and
`users` when exactly one active institution exists; it makes no change and is silent about
it otherwise.

```sql
-- Both must return 0 on an instance that has been seeded.
SELECT COUNT(*) FROM users  WHERE institution_id IS NULL;
SELECT COUNT(*) FROM people WHERE institution_id IS NULL;

-- And there must be exactly ONE institution. More than one means the backfill made no change
-- and every count above is expected to be non-zero.
SELECT id, code, name, active FROM institutions;
```

A non-zero `users` count is not automatically a failed backfill: an invitation issued
*before* this migration ran can carry a NULL `institution_id`, and the accept path
(`InvitationAcceptController`) copies it forward verbatim — so a registration completed
*after* the upgrade, from an invite issued *before* it, still yields a NULL user. Check
`invitations.institution_id` on the offending row before treating this as a bug.

---

## Verifying the 2026-08-12 calendar migrations (P1a)

Three additive migrations: `2026_08_12_120001_add_calendar_settings_to_institutions`,
`2026_08_12_120002_create_periods_table`, `2026_08_12_120003_create_holidays_table`. None
retypes or drops anything; `periods` and `holidays` are new, empty tables until P1b's settings
screen (or a seeder) populates them.

```sql
-- Expect one row: hijri_enabled=1, hijri_offset_days=0 (or -1 once the owner action below is
-- done), period_type='week_blocks'.
SELECT hijri_enabled, hijri_offset_days, period_type FROM institutions;

-- Both 0 immediately after this migration — the tables exist but nothing has been generated
-- into them yet. Non-zero later is normal, once P1b's settings screen is used.
SELECT COUNT(*) FROM periods;
SELECT COUNT(*) FROM holidays;
```

A `hijri_offset_days` other than what you expect almost always means `HIJRI_OFFSET_DAYS` was
not set (or not passed through the compose file — see the D11/P0d Task 9 pattern this repeats)
before `db:seed --force` ran. Fix the Coolify variable and re-seed; that is enough.

> **CORRECTED 2026-08-12.** This paragraph used to say `db:seed --force` "will NOT overwrite an
> existing non-null value — `ReferenceSeeder` deliberately never reverts a live department's
> calibration — so a wrong value must be corrected by hand". **In this deployment it always
> overwrites**, and the hand `UPDATE` below is a fallback, not the remedy.
> `ReferenceSeeder` writes this column whenever `config('endorsement.hijri_offset_days')` is
> neither `null` nor `''`, and `docker-compose.production.yml` passes
> `HIJRI_OFFSET_DAYS: ${HIJRI_OFFSET_DAYS:-0}` — so the variable is **always present** in the
> container, defaulting to the string `"0"`. The seeder's own docblock claim ("NEVER reverted to
> 0 by a re-seed") holds only when the variable is *absent*, which this compose file guarantees
> it is not.
>
> The consequence that matters going forward: `hijri_offset_days` is also editable at
> **Admin → Structure → Calendar**. An administrator who recalibrates there and does not also
> change the Coolify variable will have it **silently reverted on the next deploy**. Treat the
> Coolify value as the source of truth and change both together.

```sql
-- Fallback only — prefer setting HIJRI_OFFSET_DAYS in Coolify and re-seeding, or the
-- Admin → Structure → Calendar screen (which is audited; this is not).
UPDATE institutions SET hijri_offset_days = -1 WHERE code = 'QCH';
```

---

## Verifying the 2026-08-13 structure migrations (P1b)

Two additive migrations: `2026_08_13_120001_add_munawib_configuration_to_units` (backfills the
four seeded units), `2026_08_13_120002_add_external_to_levels` (schema only — `levels` was
empty until `db:seed --force` runs `ReferenceSeeder`'s ladder seed). Neither retypes or drops
anything. `2026_08_15_120002_correct_ward_clinic_owner` (P1 defect fix) additionally
corrects `clinic_owner` for any database where this backfill ran before that fix landed —
see that migration's docblock; `db:seed --force` alone cannot fix a `units` row that already
exists, since unit profile columns are written on CREATE only.

```sql
-- Expect four rows: all training_rotation=1, call_target=1, name2=NULL — and clinic_owner=1
-- for WARD ONLY (Owner Decision B, 2026-08-09: WARD is the sole clinic owner), 0 for the
-- other three. A previous version of this runbook said "clinic_owner=0" for all four, which
-- was true only for a cold start seeded BEFORE Owner Decision B existed and is a FALSE
-- FAILURE on any current install.
SELECT code, training_rotation, call_target, clinic_owner, name2 FROM units ORDER BY display_order;

-- Expect five rows: R1 R2 R3 R4 EXT at display_order 10/20/30/40/90, EXT.external=1, the rest
-- external=0. There is no `terminal` column to check — Owner Decision A (P1b, 2026-08-09)
-- removed it before it shipped; `SELECT * FROM levels` will not show one.
SELECT code, name, display_order, external, active FROM levels ORDER BY display_order;
```

Post-deploy checklist addition:

- **`structure.manage` lands on the Administrator role automatically.** The
  `applied_role_defaults` marker is per (role, capability) pair and a brand-new key has never
  been marked, so `AccessControlSeeder`'s idempotent apply grants it the first time this
  migration set runs — no owner action needed, but worth confirming on the Access Control page
  after deploy if a non-Administrator role is expected to hold it too.
- **Admin → Structure → Calendar is now the place to verify `HIJRI_OFFSET_DAYS` reached the
  container**, not just the SQL query above: the screen shows today's Gregorian and Hijri
  labels side by side, computed the same way every clinical screen computes them, so the
  calibration check in the OWNER ACTION section below can be done from the app.

---

## Verifying the 2026-08-14 people migrations (P1c-1)

Two additive migrations: `2026_08_14_120001_add_contact_visibility_to_institutions` (one
defaulted string column), `2026_08_14_120002_add_provenance_to_person_levels` (three nullable
columns — `promotion_batch_id`, `reason`, `created_by`). Neither retypes nor drops anything, and
`person_levels` had never had a production write before this landed — the only point at which
adding provenance columns to it is additive rather than a backfill of facts nobody recorded.

```sql
-- Expect one row: contact_visibility='admins' (the default — nobody has opted the department
-- into showing phone numbers to every account holder yet).
SELECT contact_visibility FROM institutions;

-- Expect zero until the first promotion or roster import runs. App\Support\LevelAssignment is
-- the table's only writer (PersonLevelsHaveOneWriterTest), and nothing wrote to person_levels
-- before this migration.
SELECT COUNT(*) FROM person_levels
  WHERE promotion_batch_id IS NOT NULL OR reason IS NOT NULL OR created_by IS NOT NULL;
```

Post-deploy checklist addition:

- **`people.manage` lands on the Administrator role automatically**, the same
  `applied_role_defaults` mechanism `structure.manage` used above — no owner action needed, but
  worth confirming on the Access Control page after deploy if a non-Administrator role is
  expected to hold it too.
- **The roster import (Admin → People → Import) is CSV/TSV only.** There is no xlsx adapter —
  the department exports from Excel with File → Save As → CSV UTF-8 first, and the import screen
  states this plainly. `docs/OPEN-DECISIONS.md` (item F) records the cost of adding xlsx support
  as a live, costed owner decision, not a limitation discovered later.
- **The first real roster import is the owner's to run, deliberately.** Nothing in P1c-1 seeds
  or imports real staff automatically — the importer ships tested only against synthetic
  fixtures (`tests/fixtures/roster/`), and no real staff list belongs in this repository at any
  time. Run the dry-run preview first; it is the deliverable, not a formality.

---

## OWNER ACTION — set `HIJRI_OFFSET_DAYS=-1` for QCH (P1a)

QCH's Hijri calibration was established once, against the department's own published calendar
across a month boundary (`hijri_offset_days = -1`) — but it is configuration, not a constant,
and it defaults to `0` (uncalibrated) for any deployment that has not set it. Until this is
done, QCH's screens render every Hijri date **one day late**.

1. Set `HIJRI_OFFSET_DAYS=-1` in Coolify's Environment Variables screen for the `endorsement`
   app.
2. Deploy. The compose file passes it through as `HIJRI_OFFSET_DAYS: ${HIJRI_OFFSET_DAYS:-0}`
   (asserted by `DeploymentInvariantsTest::test_hijri_offset_reaches_the_container`, the same
   discipline `INSTANCE_SLUG` and `INSTITUTION_CODE` needed per P0d Task 9 — a value set only
   in Coolify's UI and not threaded through this file's `environment:` block has **zero**
   effect on the running container).
3. Confirm it landed:

   ```bash
   eval "$(sudo bash docker/instance-env.sh oo7d7si62yhyi7fx10hrck6q)" && \
   sudo docker exec -u app "$APP" php artisan tinker --execute="echo App\Models\Institution::current()->hijri_offset_days;"
   ```

   Expect `-1`.
4. Open two consecutive days on screen that cross a Hijri month boundary and compare the
   displayed Hijri date against the department's own published calendar — that comparison is
   the actual calibration check, not just reading the number back from the database.
   **P1b, 2026-08-09:** Admin → Structure → Calendar shows today's Gregorian and Hijri labels
   side by side and is now the easiest place to do this — no SQL prompt or tinker session
   needed. If the calibration ever needs correcting after go-live, that screen is also where an
   administrator changes `hijri_offset_days` (bounded to `[-2, 2]`, audited by key), not a
   direct database edit.
5. Update the identifiers table row above once confirmed.

---

## OWNER ACTION — confirm `INSTANCE_SLUG=qch` is actually set, before the next deploy

The identifiers table above says "not yet confirmed set in Coolify" because of two things,
layered:

1. **P0d Task 1** added `INSTANCE_SLUG` to `config/endorsement.php` and asked the owner to set
   `INSTANCE_SLUG=qch` in Coolify's Environment Variables screen for the `endorsement` app,
   before the deploy that carries that commit — so the first slug-named archive is already
   named `qch`, not a fallback derived from `APP_NAME`.
2. **P0d Task 9's dress rehearsal found that step alone would not have worked.**
   `docker-compose.production.yml`'s `app` service never passed `INSTANCE_SLUG` (or
   `INSTITUTION_CODE`/`INSTITUTION_NAME`) through to the container — Coolify's Environment
   Variables screen makes a value available for `${...}` interpolation *within* the compose
   file, not automatically present in the container's process environment, and this file's
   `environment:` block never referenced any of the three. Setting the variable in Coolify,
   by itself, had no effect. Fixed in the same commit that found it (compose file +
   `DeploymentInvariantsTest::test_instance_and_institution_variables_reach_the_container`);
   full account in `docs/RUNBOOK-PROVISION.md`'s rehearsal appendix.

So, once a deploy carrying that fix ships:

1. Confirm `INSTANCE_SLUG=qch` is set in Coolify's Environment Variables screen for the
   `endorsement` app (set it now if it was never done, per Task 1 Step 8).
2. Deploy.
3. Confirm it actually reached the container:

   ```bash
   eval "$(sudo bash docker/instance-env.sh oo7d7si62yhyi7fx10hrck6q)" && \
   sudo docker exec "$APP" printenv INSTANCE_SLUG
   ```

   Expect `qch`. An empty result (the variable prints nothing, exit 1) means the deploy does
   not yet carry the compose fix, or the Coolify variable was never set — do not proceed to
   the next backup window believing this is done until this command prints `qch`.
4. Or, once `php artisan instance:show` exists on the deployed image, prefer it — it reports
   the slug alongside the institution, timezone and every owner-managed secret's configured
   state in one command, with no secret value printed.
5. Do this **before** the next `01:30` backup — the archive name is derived at the moment
   `backup:run` executes, not retroactively, so a backup taken before this is confirmed still
   gets the un-derived fallback name.
6. Update the identifiers table row above once confirmed: replace "not yet confirmed set in
   Coolify" with the confirmation date.

---

## OWNER ACTION — schedule `2026_08_15_120001_widen_rich_text_handover_columns`; it write-locks `handovers`

2026-08-09 ops rehearsal, measured against real MySQL 8.4: this migration (`disease`,
`details`, `plan`, `nevent`: `TEXT` → `MEDIUMTEXT`) is NOT an instant metadata-only change on
MySQL, even though widening a text-family column sounds like one. MySQL 8.4 refuses both
`ALGORITHM=INSTANT` and `ALGORITHM=INPLACE` for a `TEXT`→`MEDIUMTEXT` change specifically —
the on-disk length-prefix width itself changes (2 bytes → 3), which neither fast path can
apply in place — so it silently falls back to `ALGORITHM=COPY`: MySQL rebuilds the entire
`handovers` table row by row into a new one and swaps it in.

**Reads continue throughout** (COPY keeps the old table serving `SELECT`s until the swap),
but **writes to `handovers` block for the full duration** — any endorsement save queues
behind the migration rather than failing, so this reads as "the app got slow," not an error,
unless someone is watching for it.

**Measured throughput: ~26 MB/s.** 20,000 rows / 157 MB took ≈ 6 seconds. That scales
linearly with table size, not row count alone — a department with a small `handovers` table
(most of this system's clinical fields are `TEXT`, and PHI columns are already off in their
own encrypted/widened set) sees this finish in low single-digit seconds; a long-lived
instance with years of retained clinical text should expect proportionally longer and should
measure its own `handovers` table size before treating "a few seconds" as a given:

```bash
eval "$(sudo bash docker/instance-env.sh <uuid>)" && \
PW=$(sudo docker exec "$DB" printenv MYSQL_ROOT_PASSWORD) && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e \
  "SELECT ROUND((data_length + index_length) / 1024 / 1024, 1) AS mb
     FROM information_schema.TABLES
    WHERE table_schema = DATABASE() AND table_name = 'handovers';"
```

**Schedule it**, the same way `2026_08_10_120003_move_name_and_position_off_users` (below)
is flagged "TAKE A DUMP FIRST" — outside a shift-change window, with charge nurses aware that
saves may pause briefly. This is not a data-loss risk (COPY is atomic: either the whole
rebuild lands or the original table is untouched) and does not need `least-privilege.sql`'s
temporary grant dance to be reverted any differently — it is an ordinary migration, run the
ordinary way (`docs/sql/least-privilege.sql`'s temporary `ALTER, CREATE, REFERENCES` grant,
`php artisan migrate --force`, revoke). The only thing that is unusual is the WAIT, and that
is what needs scheduling, not the privilege.

---

## Verifying the 2026-08-15 rota migrations (P1d-1)

Two additive migrations, both brand-new tables: `2026_08_15_120003_create_master_rota_assignments_table`,
`2026_08_15_120004_create_vacations_table`. Neither retypes nor drops anything, and neither is
touched by the widening migration above — this section documents `120003`/`120004` specifically,
not the unrelated `120001`/`120002` hotfix pair from the MySQL-defects branch that occupies the
rest of that day's date stamp (P1d-1 finding 4: the P1 plan's original "P1d `2026_08_15_*`"
allocation assumed both slots were free; they were not, so P1d-1 continues the sequence at
`120003`).

```sql
-- Expect zero rows on a fresh deploy — nobody has planned a rota yet. Every row this table
-- ever holds is a real, date-bounded span: starts_on/ends_on are NOT NULL on every row, both
-- bounds inclusive (P1d Decision B — there is no nullable "means the whole period" shape).
SHOW CREATE TABLE master_rota_assignments;
SELECT COUNT(*) FROM master_rota_assignments;

-- Expect zero rows on a fresh deploy. No period_id column to check for (P1d Decision C —
-- deliberate: a vacation crosses period boundaries and must survive a department switching
-- period systems).
SHOW CREATE TABLE vacations;
SELECT COUNT(*) FROM vacations;
```

Post-deploy checklist addition:

- **`rota.view` lands on EVERY seeded role automatically, `rota.manage` on Administrator only**
  — the same `applied_role_defaults` idempotent-apply mechanism `structure.manage` and
  `people.manage` used above, no owner action needed. `rota.view` reaching every position is
  deliberate (MR-05: a resident needs to read which unit they rotate through next) — worth a
  glance at the Access Control page after deploy if a department wants it narrower than the
  default.
- **`rota.manage` was reversed to Administrator-only on 2026-08-10, and an instance that already
  has the old grant KEEPS it — that is by design.** P1d-1 seeded `rota.manage` to Chief Resident
  (position 5); P1d-2 removed it from the defaults. `AccessControlSeeder` applies each
  (position, capability) default **once**, records it in `applied_role_defaults`, and never
  re-asserts it, so removing the entry does **not** revoke the capability on an instance that
  already seeded it. That is deliberate: a capability an administrator may since have kept
  deliberately is theirs, and this seeder does not re-impose decisions over an administrator's.
  To remove it: **Admin → Access Control → Chief Resident → un-tick "Create and edit master rota
  assignments and vacations" → Save.** There is no migration and there must not be one. A fresh
  deployment is unaffected — Chief Resident never receives the grant in the first place.
- **`/admin/rota` shows a teaching empty state, not an error, until an academic year of periods
  exists.** The rota's columns are periods (P1b's Structure → Periods screen); a fresh deployment
  or a new academic year has nothing to plan against until that screen generates one.
- **Neither new table soft-deletes.** A cleared assignment or a cancelled vacation is a real
  `DELETE`; the hash-chained `audit_log` (`rota_assign`, `rota_split`, `rota_clear`,
  `vacation_book`, `vacation_cancel` — ids, field names and counts only, never a person's name) is
  the only history. There is no UI undo for a mistaken clear in P1d-1.
- **Deleting an academic year's periods (Structure → Periods) is now refused while any
  `master_rota_assignments` row references that year.** The unlock is "clear the rota for that
  year first (Master Rota), then delete the periods" — the screen's own error message says so.

---

## Operating the master rota's bulk moves, export and import (P1d-2, 2026-08-10)

**No migration.** P1d-2 added not one, in either of its two branches — every table it needs already
existed. There is nothing to run after this deploy; this section is operator procedure, not a
verification step.

**Everything below is behind `cap:rota.manage`** (Administrator-only by default — see the un-tick
note above if this instance carries P1d-1's Chief Resident grant). The resident-facing `/rota` read
view is `cap:rota.view` and is GET-only: there is **no publish gate**, so the rota a resident sees is
always the current one, and there is no "publish" step to remember after editing.

### Bulk fills (Master Rota → "Fill…" on any cell)

Four actions, three shapes: fill this level group, fill this whole column, fill across (this person,
forwards through the rest of the year — never backwards), and copy one period onto another.

- **Always preview, then confirm.** The preview lists every target cell with its outcome and the
  reason. The confirm re-derives the plan server-side inside its own transaction; it never applies
  what the browser sent.
- **A target cell that already carries a split is SKIPPED unless you tick that cell.** That default
  is the point: a blanket fill over deliberate split work is silent data loss. A "confirm all splits"
  control exists and it ticks the individual boxes rather than replacing them, so what you are
  agreeing to is always visible in the table in front of you.
- **Ticking a box does not re-run the preview**, deliberately, so the outcomes on screen are the ones
  the preview ran with. The screen says so and offers "Preview again" beside "Apply this fill".
- **If the rota changed under you between the preview and the confirm, the fill is REFUSED** — the
  whole operation, with nothing written — and you are asked to preview again. That is the digest pin
  working, not an error.
- **A refusal refuses the whole operation.** There is no partial apply, ever.
- Each fill writes exactly **one** `rota_fill` audit row (ids and counts, never a name), and
  `rota_fill` is on the anomaly watch list — so a single confirmation that rewrote several hundred
  cells produces one alert for a human to read. Expect that mail; it is not a fault.

### Export — two files, and what is deliberately not in them

**Master Rota → Export**, two buttons on two URLs: `rota-<year>.csv` (one row per assignment span)
and `vacations-<year>.csv` (one row per leave row). Two files, not one — a single file mixing two row
shapes is how an importer misreads one.

- **A person is identified by `short_name` plus `full_name`. There is no email, no phone and no
  database id in either file.** Ids are instance-local and meaningless in another deployment; contact
  detail has no business in a schedule extract that gets mailed around.
- **`short_name` is nullable, and a person without one exports with a blank handle and cannot be
  re-imported.** The export screen tells you how many such people appear in the year **before** you
  download, and links to fix them. Fix them first if you plan a round trip.
- A person who has left the department but still holds spans **is** in the file — those spans are
  exactly what blocks deleting the year's periods, so an export made to find out why must show them.
- Cells beginning `=`, `+`, `-`, `@`, TAB or CR are neutralised with a leading apostrophe on the way
  out and un-neutralised on the way back in. Do not strip them by hand in a spreadsheet; the pair is
  what makes export → re-import lossless.

### Import — dry run first, and it invents nobody

**Master Rota → Import a file…**. Choose which kind of file it is (assignments or vacations), upload,
read the preview, then commit.

- **The importer creates no people, no units and no periods.** An unknown `short_name`, an unknown or
  retired `unit_code`, or an `(academic_year, period_position)` pair with no row is reported as a
  named skip against that row, never invented. A handle that resolves to somebody no longer on the
  active roster is also a skip — the same rule the editor's own pickers apply.
- **The unit of outcome is the (person, period) cell, not the line.** Two lines describing two halves
  of one split period are one outcome. A cell whose rows do not all resolve is skipped **whole**,
  because applying half of it would delete the other half.
- **A file-level problem — a missing required header, for instance — refuses the WHOLE import.**
  Never "7 of 8 imported".
- **The commit is pinned to the exact bytes the preview ran against.** Re-pick the file if you change
  it; the screen drops its analysis and re-previews rather than committing something you did not see.
- **Re-importing a file this system exported changes nothing** — every assignment comes back
  `unchanged`, and every vacation `skip_duplicate`. That is the safe way to check a file before
  trusting it.
- **Leave marked `week` granularity is snapped to whole weeks on import, exactly as the booking
  screen snaps it**, and the preview shows you the adjusted dates before you commit.
- The commit writes one `rota_import` audit row (counts only) after its transaction; an export writes
  one `rota_export` row. Neither is on the anomaly watch list — they are routine administrative acts,
  and putting them there would train you to ignore the channel that exists for `rota_fill`.

---

## The 2026-08-10 account-lifecycle release (P1c-2) — NO MIGRATION

**Nothing to verify in the schema, and that is the point of saying so.** P1c-2 added **no migration
at all** — every column it needed already existed (`invitations.person_id`, `users.person_id`,
`user_capabilities.effect`, `app_settings.key`/`value`, `handover_signoffs.signed_off_by_name`). The
`2026_08_14_1201*` slot the P1c-1 plan reserved for it is **released, unclaimed**; P1d's
`2026_08_15_*` was the last migration in the tree *when this section was written* — P1e's
`2026_08_16_120001`/`2026_08_16_120002` are now last (see the P1e section below). A runbook that is
silent about a release reads
as an oversight, so: there is genuinely nothing to run, and `php artisan migrate --pretend` should
report no pending migrations after this deploy.

### One post-deploy check, per instance

```bash
# From the app container. Expect NO ROW, or the value 7.
php artisan tinker --execute="dump(\App\Support\AppSettings::get('invitation_lifetime_days'), \App\Models\Invitation::lifetimeDays());"
```

**An absent row is the intended state** — `Invitation::LIFETIME_DAYS = 7` is the default, and
`lifetimeDays()` falls back to it. A surprising value means somebody set it deliberately on the
Settings screen; find out who before changing it back (the write is audited by key name under
`settings_update`). Anything outside **[1, 30]** is ignored by the model's own clamp and the fallback
applies — that clamp exists because `app_settings` is a plain key/value table reachable from a
database console, and such a write passes no validator.

### What changed operationally

- **Admin → Settings now carries "Link lifetime (days)"**, behind `settings.manage`. It is a
  credential-exposure parameter — how long a bearer link to a child's clinical records stays live —
  which is why it sits beside SMTP and VAPID rather than on the account console. Shorter is safer.
- **Resend rotates the token.** Resending an invitation mints a **new** link and kills the old one;
  the superseded row is kept and marked revoked, never deleted. If somebody reports that an old link
  stopped working after a resend, that is correct behaviour, not a fault.
- **Bulk resend (People → select → Resend invitations) refuses outright unless SMTP is configured**,
  is capped at **50** people per request, and is throttled to six requests a minute. It previews
  before it confirms, and the confirm is pinned to the state you previewed — if somebody claims their
  account in between, it refuses with *"Something changed since you previewed this resend"* rather
  than mailing a stale set. **Mail is sent only after the database work commits**, so the failure mode
  is "rows exist, some mail did not go" — visible, reported per person on screen, and fixable by
  resending. Never the reverse.
- **Unbinding an account (Users → Unbind) is irreversible from the UI.** It clears the person link,
  deactivates the account and keeps it as history. **The row then disappears from the Users screen
  entirely**, because that list inner-joins `people` — expected, and the flash message says so. The
  account cannot be reactivated; a colleague who returns gets a **new** invitation and an
  administrator **re-grants their roles**, which are not restored automatically. Before clearing the
  link it snapshots the signer's name onto every handover that account signed which does not already
  carry one, so old signed sheets keep saying who signed them; the count lands in the
  `account_unbound` audit row.
- **Roles can now be granted from Admin → People as well as Admin → Access Control.** Both write the
  same rows through the same code, and both require **`access.manage`** — `people.manage` alone does
  not grant roles, deliberately.

### Closed on 2026-08-11: the system now refuses to leave `access.manage` unheld

Six operations could previously strip the last holder of `access.manage`, after which the Access
Control console is unreachable and recovery needs a database console: denying it on either override
screen, deactivating an account, unbinding one, demoting somebody off Administrator from either the
account or the roster console, and a bulk "set inactive" selection. All six now refuse with a message
naming the remedy ("Grant it to another active account first"), enforced in one place
(`App\Support\AccessManageGuard`). Nothing is written when it refuses, and no audit row claims
otherwise.

**Operationally, still keep more than one account holding `access.manage`.** The guard stops you
removing the last one, but it cannot help if that one account's password is lost or its holder
leaves — and while `access.manage` is unheld (an instance that somehow reached that state), the
guard also refuses deactivations and demotions until a holder exists again. Reactivating an account
and promoting somebody to Administrator both stay available, which is the recovery path.

---

## Verifying the 2026-08-16 clinics and demo migrations (P1e)

**Two additive migrations, both brand-new tables, in the slot the P1 plan reserved.** Nothing is
retyped, nothing is dropped, and no clinical table's shape is touched. The slot was checked free
before either was written — unlike P1d-1, which found its reserved `2026_08_15_1200*` slots already
taken and had to renumber.

- `2026_08_16_120001_create_clinics_and_attendees_tables` (P1e-1)
- `2026_08_16_120002_create_demo_rows_table` (P1e-2)

```sql
-- Expect zero rows on a fresh deploy. `weekday` is ISO-8601 (Monday = 1 … Sunday = 7) — NOT
-- Carbon's dayOfWeek, where Sunday is 0. `unit_id` is a foreign key: a clinic's owning unit is a
-- units row, never a code string. `institution_id` is D11 provenance and no index touches it.
SHOW CREATE TABLE clinics;
SELECT COUNT(*) FROM clinics;

-- Expect zero rows. Each row names exactly ONE of level_id / person_id, and never duplicates
-- within a clinic — a rule the schema cannot express over nullable columns on either engine
-- (NULLs compare distinct), so it lives in App\Support\Clinics\ClinicWriter, the only writer.
-- Carries no institution_id at all: a pure child table does not repeat its parent's provenance.
SHOW CREATE TABLE clinic_attendees;
SELECT COUNT(*) FROM clinic_attendees;

-- Expect zero rows on a fresh deploy, and zero rows on any instance where nobody has pressed
-- "Create the demo department". A non-zero count means a demo department is currently present;
-- SELECT DISTINCT batch_id FROM demo_rows names it. This table has no institution_id column.
SHOW CREATE TABLE demo_rows;
SELECT COUNT(*) FROM demo_rows;

-- WARD is the sole clinic owner (Owner Decision B, 2026-08-09) and the clinics screen offers
-- exactly the units where both flags are true. If this returns no rows, Admin → Structure →
-- Clinics will show an empty state rather than an error — the fix is a checkbox on
-- Admin → Structure → Units, not a migration.
SELECT code, clinic_owner, active FROM units WHERE clinic_owner = 1 AND active = 1;
```

Post-deploy checklist addition:

- **`clinics.view` lands on EVERY seeded role automatically, ONCE** — the same
  `applied_role_defaults` idempotent-apply mechanism `structure.manage`, `people.manage` and
  `rota.view` used above, so there is no owner action. Reaching every position is deliberate (CL-05:
  a resident needs to know when their unit's clinic runs), and it is exactly why the map ships no
  contact field and no person-shaped value of any kind. **An administrator revocation is never
  re-imposed:** `AccessControlSeeder` records each (position, capability) default in
  `applied_role_defaults` and never re-asserts it, so un-ticking this on Admin → Access Control
  survives every later `db:seed --force`, and there is deliberately no migration to force it back.
  **Defining** a clinic stays on `structure.manage` — one new key, not two.
- **The clinic map is `auth` + `cap:clinics.view` and is NOT link-public**, despite Munawib §5's
  footnote naming it among three surfaces exposed without a login. D7 overrides that (design §1.2).
  A consultant who wants to check clinic times signs in.
- **There is no way to delete a clinic, by design.** A clinic that stopped running is deactivated
  (Admin → Structure → Clinics → Deactivate) and can be restarted later — the same shape Units,
  Levels and Holidays already take. DELETE against a clinic URI is a plain 405.
- **`/admin/setup` is the new front door for configuring a department** — a derived checklist over
  the screens that already exist, storing nothing anywhere. An already-configured department (QCH)
  reads as complete on the first load with no backfill and no migration. It is **not** `/setup`,
  which is the per-user first-login two-factor flow and is untouched; an administrator who has not
  finished their own second factor is redirected there first, which is intended.
- **Admin → Structure → Department now edits the institution's display name**, audited
  (`institution_profile_update`), and the rename survives `db:seed --force`. **The institution CODE
  is deliberately not editable from any screen** — it is the key `ReferenceSeeder` finds the row by,
  so re-coding a live institution makes the next deploy create a SECOND institutions row and
  `Institution::current()` return null, blanking every configuration-reading screen. A re-code is a
  provisioning operation; see `docs/RUNBOOK-PROVISION.md`.

### Operating the demo department (ST-05)

**This one is safe to run on the live instance, unlike `db:seed --class=DemoSeeder`, which throws in
production and must keep doing so.** The difference is not policy: `DemoSeeder` and `E2eSeeder` mark
no row they write and have no removal path, so their rows are permanent and indistinguishable from
real ones. The demo department records every row it creates and **creates no account at all**.

| | |
|---|---|
| **Where** | Admin → Set up this department → Somewhere to practise (`/admin/structure/demo`), `structure.manage`. No permanent navigation entry, deliberately. |
| **From a terminal** | `php artisan demo:seed` and `php artisan demo:remove` (both prompt; `--force` skips). `demo:remove` prints the pre-flight before it asks. |
| **What it creates** | One extra unit coded `DEMO`, five staff records, an academic year of periods **only if the department has none**, master-rota spans (one deliberately short, so the coverage summary has a real gap in it), one week of leave, and one weekly clinic. Roughly fifteen rows. |
| **How it is labelled** | Every name begins `Demo ` and every address is on `demo.invalid` — reserved by RFC 2606 and guaranteed never to resolve, so an invitation issued to a demo person by mistake cannot reach a real inbox. |
| **Removing it** | Same screen. Type `DEMO` to confirm. It is a hard delete with no undo. |

**If removal is refused, that is the feature working.** It refuses **whole** — nothing is
half-deleted — the moment a row that is not part of the demo points at one that is: a real handover
written on the demo unit during a training session, an account claimed against a demo person, a real
clinic naming a demo resident, or a sign-off naming one, which is medico-legal evidence and must
never be reachable from a cleanup button. The screen names the tables and the row counts holding it;
the remedy is to deal with those rows first (delete or re-point them) and try again. Both the
refusal and the removal are audited (`demo_department_remove_refused` / `demo_department_remove`) —
ids and counts only, never a name.

**Two operational notes:**

- **The demo generates periods only when the department has none.** On a configured department it
  uses the existing academic year instead — deliberately, since a second year sitting beside the
  real one is confusing on the rota's year picker — and the screen says which branch it took. Read
  that line; it is how you know the button did what you expected.
- **A `DEMO` unit that is NOT part of a demo department blocks creation**, by name, rather than
  colliding on the unique index. Rename or retire it first.
