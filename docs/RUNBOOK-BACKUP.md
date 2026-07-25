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
# Long random passphrase, generated on the server, stored per section 0.
openssl rand -base64 48
```

Put it in the app's `.env` as `BACKUP_PASSPHRASE=...` (and nowhere else), then:

```bash
php artisan backup:run
```

It dumps the database, gzips it, encrypts with `openssl enc -aes-256-cbc -pbkdf2`,
**verifies the archive decrypts and decompresses**, shreds the plaintext dump, and prunes
to the last 14 archives. It is already scheduled nightly at 01:30 — confirm the scheduler
cron exists:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
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
passphrase**, not this application:

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -in endorsement-YYYY-MM-DD_HHMMSS.sql.gz.enc | gunzip > restore.sql
```

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
`endorsement-signatures-<stamp>.tar.gz.enc`. Copy it off-host with the database archive.

`handover_signoffs` stores the PATH of the signature frozen onto a sheet, not the bytes, so
a database-only restore produces sheets that still say they are signed while every
signature renders as nothing — the attestation is the point of the document, and losing its
image silently weakens every historical one. Restore it into the app's private disk:

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -in endorsement-signatures-YYYY-MM-DD_HHMMSS.tar.gz.enc | tar xzf - -C storage/app/private/signatures
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

**First drill done 2026-07-25**, on the live server before any patient data existed: the
nightly archive decrypted, decompressed to 207 KB of SQL, and restored into a scratch MySQL
8.4 database with all 24 tables and matching row counts. That also settled the open question
about the client mismatch — a dump produced by MariaDB's `mariadb-dump` restores cleanly
into MySQL 8.4.

One expected discrepancy: `audit_log` will always have one row *more* in the live database
than in the archive, because `backup:run` records its own `backup_created` entry after the
dump is taken. That is ordering, not loss.

## 4. What is deliberately NOT automated

`data:retention` prunes only expired operational rows (abandoned registration requests,
dead one-time codes, idle sessions). It never touches handovers, sign-offs or the audit
log — clinical retention follows the hospital's medical-records schedule and is your
decision with the medical records department, not a cron job's.
