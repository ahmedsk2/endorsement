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

```bash
eval "$(sudo bash docker/instance-env.sh <this-instance's-uuid>)" && \
PW=$(sudo docker exec "$DB" printenv MYSQL_ROOT_PASSWORD) && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "GRANT ALTER ON \`$DBNAME\`.* TO '$DBUSER'@'%'; FLUSH PRIVILEGES;" && \
sudo docker exec -u app "$APP" php artisan migrate --force && \
sudo docker exec -e MYSQL_PWD="$PW" "$DB" mysql -uroot -e "REVOKE ALTER ON \`$DBNAME\`.* FROM '$DBUSER'@'%'; FLUSH PRIVILEGES;"
```

**Read the stderr line `instance-env.sh` prints and confirm the database name is the customer
you meant** before typing anything else.

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
