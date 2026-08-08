# Backup & restore runbook (owner-run)

The command is written and verified locally; these are the steps for the **Oracle server**
and for **your own computer**. Do them once each, after go-live.

## 0. Key custody — before anything else

Losing a key destroys data more reliably than any attacker. Two secrets matter, and they
are **different**:

| Secret | Protects | If lost |
|---|---|---|
| `APP_KEY` | the encrypted PHI **columns** (MRN, name, DOB, clinical text), TOTP secrets, SMTP/VAPID secrets | those values are unrecoverable, even from a good backup |
| `BACKUP_PASSPHRASE` | the backup **archive** | that archive cannot be opened |

For each: keep the authoritative copy in your password manager, **and** a printed copy in a
sealed envelope in a safe (ideally a second physical location). Name one break-glass
colleague who can reach the safe. Never commit either to git, never paste into chat, and
never rotate `APP_KEY` without re-encrypting first.

## 1. On the Oracle server

```bash
# Long random passphrase, generated on the server, stored per section 0. ALPHANUMERIC ONLY —
# not `openssl rand -base64`, which emits `+` and `/`. Coolify feeds this value through
# `docker compose`, which performs `$`-interpolation on env values, so `+`/`/` are harmless but
# a password containing `$` would be silently truncated into something weaker if this generator
# were ever copied elsewhere. Consistent with every other generated secret in this system
# (`scripts/new-instance.sh`'s `rnd()`, `docker/smoke.sh`'s throwaway credentials) — do not
# "tidy" this back to `openssl rand -base64`.
LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 48; echo
```

Put it in the app's `.env` as `BACKUP_PASSPHRASE=...` (and nowhere else), then:

```bash
php artisan backup:run
```

It dumps the database, gzips it, encrypts with `openssl enc -aes-256-cbc -pbkdf2`,
**verifies the archive decrypts and decompresses**, shreds the plaintext dump, and prunes
to the last 14 archives sharing this instance's slug (`App\Support\Instance::slug()` —
`docker-compose.production.yml`'s `INSTANCE_SLUG`). It is already scheduled nightly at 01:30 —
confirm the scheduler is actually running, **not** by looking for a `schedule:run` cron entry:
this container runs `schedule:work` continuously under `supervisord`
(`docker/supervisord.conf`), which is what actually fires the nightly job. A `* * * * *
php artisan schedule:run` cron **must not exist** on this host — installing one (the classic
Laravel recipe, and tempting when provisioning a second instance from this page) runs the
schedule a second time from outside the container, which cannot reach `php artisan` inside it
without its own duplicate setup, and either drifts silently from the supervised copy or does
nothing while looking configured. Confirm the real scheduler instead:

```bash
eval "$(sudo bash docker/instance-env.sh <uuid>)" && \
sudo docker exec "$APP" ps aux | grep '[s]chedule:work'
```

**Keep backups in Saudi Arabia.** Copying them to a region outside the Kingdom is a PDPL
Art. 29 cross-border transfer, and health data attracts the strictest treatment. Oracle
Object Storage in the Saudi region (or another in-Kingdom target) is fine; a US bucket is not.

## 2. On your local computer

Same command, pointed at a directory you sync/keep offline:

```bash
php artisan backup:run --path=D:\endorsement-backups --keep=30
```

Or simply pull the server's archives down on a schedule (`scp`/`rsync`). Either way the
rule is the **3-2-1** one: three copies, two media, one off-site — and the off-site copy
still in-Kingdom.

## 3. Restore — practise this before you need it

The archive is deliberately openssl-standard, so recovery needs **only openssl and the
passphrase**, not this application. Archives are named
`endorsement-<instance-slug>-YYYY-MM-DD_HHMMSS.sql.gz.enc` (P0d) — the slug is
`App\Support\Instance::slug()` / `INSTANCE_SLUG`, e.g. `endorsement-qch-2026-08-11_013000.sql.gz.enc`
for the live deployment. Each `.meta.json` sidecar beside an archive names its slug and the
`APP_KEY` fingerprint that opens it, if you are holding a bucket full of these and need to
confirm which is which before choosing a key:

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -in endorsement-<slug>-YYYY-MM-DD_HHMMSS.sql.gz.enc | gunzip > restore.sql
```

**One-time cleanup, only on the deployment that predates P0d's slug rename.** Archives written
before `INSTANCE_SLUG` was set are named without a slug
(`endorsement-YYYY-MM-DD_HHMMSS.sql.gz.enc`, no instance token) and `backup:run` warns about
their count on every run rather than pruning them — a slug-scoped glob cannot match them, and
widening the glob to catch them would reopen the cross-customer deletion this rename exists to
close. Once a slugged archive has been restored successfully at least once (proving the rename
did not break anything), remove or rename the pre-slug archives by hand; do not leave
`backup:run` warning forever.

```bash
mysql --ssl-verify-server-cert=0 -h db -u <user> -p <database> < restore.sql
```

`--ssl-verify-server-cert=0` is needed **inside the app container**, whose `mysql-client`
package is MariaDB's client: MariaDB 11 verifies the server certificate by default and
MySQL 8.4 generates a self-signed one, so without it the client refuses to connect
(`error 2026`). Drop the flag if you restore with Oracle's client — it does not accept that
spelling; use `--ssl-mode=PREFERRED` there instead. This same mismatch silently broke the
nightly dump once; `backup:run` now selects the right flag by detecting the client.

### The signature archive

`backup:run` writes a **second** file whenever any signatures exist:
`endorsement-signatures-<instance-slug>-<stamp>.tar.gz.enc`. Copy it off-host with the
database archive.

`handover_signoffs` stores the PATH of the signature frozen onto a sheet, not the bytes, so
a database-only restore produces sheets that still say they are signed while every
signature renders as nothing — the attestation is the point of the document, and losing its
image silently weakens every historical one. Restore it into the app's private disk:

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -in endorsement-signatures-<slug>-YYYY-MM-DD_HHMMSS.tar.gz.enc | tar xzf - -C storage/app/private/signatures
```

Files are content-addressed, so re-extracting an older archive over a newer one is safe:
identical content has an identical name and nothing is overwritten with something else.

Then set `APP_KEY` on the restoring machine to the **same value** as the system that wrote
the backup, or every encrypted column reads back as ciphertext.

> Since 2026-07-26 a wrong `APP_KEY` no longer silently destroys data: an undecryptable
> value renders as `[unreadable — encrypted with a different key]` and **any write over it
> is refused**, so the row survives until the correct key is restored. Before that change,
> the ciphertext was shown as clinical text and the next save re-encrypted it under the new
> key, permanently.

**Test a restore quarterly** into a scratch database and confirm: row counts look right, a
handover sheet opens, and `php artisan audit:verify` exits 0. A backup nobody has restored
is a hypothesis, not a backup.

**Running `audit:verify` against the scratch database, not the live one — this is not
obvious and P0d Task 9's rehearsal got it wrong on the first attempt.** Running the command
inside the already-booted app container with `docker exec -e DB_DATABASE=<scratch-db> ...
php artisan audit:verify` does **not** work: `config:cache` bakes `DB_DATABASE` in at
container boot, so the environment override on `docker exec` is silently ignored and the
command verifies the **live** database instead — it still reports success, which looks like a
completed drill but proves nothing about the restored copy. Two things are needed:

```bash
# 1. The app's least-privilege database user can only SELECT its own schema (docs/sql/least-privilege.sql)
#    — grant it SELECT on the scratch database too, or the next step gets "Access denied".
sudo docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" "$DB" mysql -uroot \
    -e "GRANT SELECT ON <scratch-db>.* TO '<app-db-user>'@'%'; FLUSH PRIVILEGES;"

# 2. Force the ALREADY-RUNNING process to reconnect to the scratch database at runtime,
#    since the cached config cannot be overridden by environment alone:
sudo docker exec -u app "$APP" php artisan tinker --execute="
config(['database.connections.mysql.database' => '<scratch-db>']);
Illuminate\Support\Facades\DB::purge('mysql');
echo Illuminate\Support\Facades\Artisan::call('audit:verify');
echo Illuminate\Support\Facades\Artisan::output();
"
```

**First drill done 2026-07-25**, on the live server before any patient data existed: the
nightly archive decrypted, decompressed to 207 KB of SQL, and restored into a scratch MySQL
8.4 database with all 24 tables and matching row counts. That also settled the open question
about the client mismatch — a dump produced by MariaDB's `mariadb-dump` restores cleanly
into MySQL 8.4.

One expected discrepancy: `audit_log` will always have one row *more* in the live database
than in the archive, because `backup:run` records its own `backup_created` entry after the
dump is taken. That is ordering, not loss.

**The per-instance drill register.** At N customers, "quarterly" is N obligations nobody is
tracking unless the date is written down somewhere. Record, per instance: slug, date of last
successful restore drill, who ran it, and the `APP_KEY` fingerprint confirmed against the
archive's `.meta.json` sidecar.

| Instance slug | Last drill | Run by | `APP_KEY` fingerprint confirmed |
|---|---|---|---|
| `qch` | 2026-07-25 | (record here) | (record here) |

## 4. What is deliberately NOT automated

`data:retention` prunes only expired operational rows (abandoned registration requests,
dead one-time codes, idle sessions). It never touches handovers, sign-offs or the audit
log — clinical retention follows the hospital's medical-records schedule and is your
decision with the medical records department, not a cron job's.
