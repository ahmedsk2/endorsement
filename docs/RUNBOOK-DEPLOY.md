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

Identifiers (project/app/DNS-record/deploy-key UUIDs) are recorded in
`ORACLE MCP/infra/state.env` as `ENDORSE_*`, alongside the other apps on this host.

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
no `ALTER`, no `DROP`, no `CREATE`. A compromised application therefore cannot reshape or
drop the clinical schema, which is worth keeping. It does mean a schema change is a
deliberate, privileged act rather than something the app can do to itself.

Setting `-e DB_USERNAME=root` on the exec does **not** work either: the config is cached at
boot, so `env()` is not consulted at runtime.

So: grant, migrate, revoke. Run from the HOST (not inside the app container), so the root
credential is read from the database container's own environment and never typed or logged.

**Never select the database container by matching the shared MySQL image name** — with two
customer stacks on one host that picks an arbitrary one, and the `GRANT ALTER` / `migrate` /
`REVOKE` sequence below then lands coherently on the **wrong customer's clinical database**.
`docker/instance-env.sh` resolves the one stack matching a Coolify app UUID, or refuses:

```bash
eval "$(sudo bash docker/instance-env.sh oo7d7si62yhyi7fx10hrck6q)" && \
PW=$(sudo docker exec "$DB" printenv MYSQL_ROOT_PASSWORD) && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "GRANT ALTER ON \`$DBNAME\`.* TO '$DBUSER'@'%'; FLUSH PRIVILEGES;" && \
sudo docker exec -u app "$APP" php artisan migrate --force && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "REVOKE ALTER ON \`$DBNAME\`.* FROM '$DBUSER'@'%'; FLUSH PRIVILEGES;"
```

The `&&` chaining is load-bearing: `instance-env.sh` prints `false` on refusal, so nothing
downstream runs. **Read the stderr line it prints and confirm the database name is the
customer you meant** before typing anything else.

`MYSQL_PWD` rather than `-p"$PW"` keeps the credential out of the container's process list.
`-u app` keeps the artisan process unprivileged inside the container. **Verify the revoke
landed** — `SHOW GRANTS FOR '$DBUSER'@'%';` should show no ALTER.

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
- `php artisan schedule:list` — six jobs: handover reminders at 07:30 and 15:30,
  `audit:verify` hourly, `backup:run` 01:30, `data:retention` 02:30.
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
