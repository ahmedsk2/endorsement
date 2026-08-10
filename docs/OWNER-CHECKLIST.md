# Owner checklist — endorse.towardpcc.com

> ## ⚠ Deploying the 2026-07-26 work? Run the migration.
>
> ```bash
> php artisan migrate --force
> ```
>
> One additive, nullable column (`audit_log.hash_version`). The audit chain moved from an
> unkeyed hash to a keyed HMAC, because the old one could be recomputed by anyone with
> database write access — so the trail could be rewritten and still report "chain intact".
> Rows record which algorithm wrote them, so existing history keeps verifying.
>
> Without the migration the application will error on its first audited action. Deploy,
> migrate, then run `bash scripts/verify-live.sh`.

Everything the system needs from you, in the order it has to happen. The site is live and
healthy; nothing below is a bug fix, it is the handover of the things only you can hold.

**This checklist describes the first deployment (`qch`) and predates a second customer
existing.** Provisioning a new customer instance from scratch is `docs/RUNBOOK-PROVISION.md`,
not this file — several steps below (the Coolify project, the terminal-access command, the
database operations) are specific to `qch`'s own Coolify app UUID and would need their own
per-instance values for another customer.

Terminal access, used by several steps — either **Coolify → clinical → endorsement →
Terminal**, or:

```bash
ssh ubuntu@145.241.105.239
```

```bash
sudo docker exec -it $(sudo docker ps -qf name=app-oo7d7si62yhyi7fx10hrck6q) sh
```

---

## 1. Rotate the two Coolify tokens — do this first

`COOLIFY_API_TOKEN` and `COOLIFY_DEPLOY_TOKEN` were exposed in a session transcript on this
machine (they are Sanctum-format `N|<48 chars>`; a shell split them on the `|` and printed
the secret half). Assume both are public.

1. Coolify dashboard → **Keys & Tokens → API tokens**.
2. Revoke both existing tokens.
3. Create two replacements. The deploy one needs the **`deploy`** permission — the
   read/configure token deliberately does not have it, which is why there are two.
4. Update `C:\Users\ahmed\Documents\ORACLE MCP\infra\secrets.env`.

Revoking does not touch the running application. `CF_TOKEN` (Cloudflare) was **not**
exposed and does not need rotating.

---

## 2. Create the first administrator — nothing works until this is done

There are zero accounts. Registration only ever creates a *pending Resident*, and approving
one requires an administrator, so this command is the only way in.

```bash
php artisan user:create-admin
```

It prompts for username, full name, email and password (twice, never echoed). The password
must be 8+ characters with upper case, lower case, a number and a symbol — the same policy
the registration form enforces.

Then sign in at **https://endorse.towardpcc.com/login**.

### ⚠ Choose TOTP, not email, for your second factor

Your first sign-in redirects you to your profile to enrol a second factor; admin screens are
unreachable without one. **The second factor is challenged at login, not just on admin
pages.** SMTP is not configured yet, so if you enrol **email one-time codes**, the next
login will ask for a code that can never arrive — and with only one administrator, there is
nobody to unlock you.

Enrol **TOTP** (Google Authenticator, Microsoft Authenticator, 1Password, Aegis…). Save the
recovery codes it shows you — they are the way back in if you lose the phone. You can switch
to email codes later, once step 4 is done and you have sent yourself a test email.

---

## 3. Put the two keys in your password manager

Both are in `infra/secrets.env` as `ENDORSE_*`. Copy them into your password manager now,
because the server is not a backup of its own keys.

| Key | What it does | If you lose it |
| --- | --- | --- |
| `ENDORSE_APP_KEY` | Encrypts patient name, MRN, DOB and all four rich-text fields **inside the database** | Every clinical record becomes unrecoverable ciphertext. Restoring a backup does not help — the backup is encrypted with it too. |
| `ENDORSE_BACKUP_PASSPHRASE` | Encrypts the nightly archive | You cannot open any backup. |

**Store them in two different places.** A backup and the passphrase that opens it sitting in
the same vault is a single point of failure. `APP_KEY` is additionally needed to *read* a
restored database, so a full recovery needs both — keep that fact written down somewhere you
will find it under pressure.

Rotating `APP_KEY` later is a data migration, not a settings change. Decide now and leave it
alone.

---

## 4. Configure SMTP — **Admin → Settings**

Until this works, three things are broken: registration email verification (so **no member
of staff can complete registration**), password reset, and email one-time codes.

Enter host, port, encryption, username, password, from-address, from-name, then use the
**send test email** button and confirm it arrives. The password is stored encrypted; it is
not an environment variable and does not require a redeploy.

### 4b. Set "Operational alerts to" — same screen, right below SMTP

The nightly backup and the hourly audit-chain check know when they have failed. Until this
address is set they report it to a log file on the server that nobody reads — so the system
can know it produced no recoverable copy of the clinical record and tell no one.

Put a mailbox you actually watch. Alerts carry a job name and a timestamp and nothing else:
they travel through an external relay into a mailbox without this system's access controls
around it, so they deliberately contain no patient information.

## 5. Generate the VAPID pair — same screen

Enables browser push for the 07:30 and 15:30 handover reminders. One click; the private key
is stored encrypted. Without it the reminders simply do not send — nothing else breaks.

---

## 6. ~~Get backups off the server~~ — DONE 2026-07-26

Closed. `/usr/local/bin/endorsement-backup-sync` runs at 02:05 (after `backup:run` at
01:30) and copies the encrypted archives to a **dedicated** OCI Object Storage bucket,
`endorsement-backups` — deliberately not the shared `coolify-backups`, so children's health
data does not sit alongside unrelated projects. First run verified: last night's real
archive is in the bucket.

`copy`, not `sync`: `sync` would delete remote objects as the local 14-archive retention
prunes them, which makes the off-host copy no better than the on-host one against
ransomware that simply waits. Credentials live in `/etc/endorsement/rclone.conf` (0600,
root-only) and are NOT in the app container — the web tier has no object-storage access,
so a compromise there cannot reach the backups.

Nothing in that bucket is readable without `BACKUP_PASSPHRASE`, which is not on the host
and not in Object Storage. **That separation is the whole design: losing the bucket leaks
nothing, losing the passphrase loses the backups.**

Two things still worth doing, both in the OCI console:

- **A retention/object-lock rule on the bucket.** Without it, credentials that can write
  can also delete — which is what ransomware does after it finds the backup.
- **A third copy.** You already pull other projects to `C:\Backups\oracle`; add this bucket.

<details>
<summary>Original item, for reference</summary>

## ~~6-old. Get backups off the server — the largest operational gap~~

The nightly encrypted archive lands in a docker volume **on the machine it is backing up**.
That is not a backup: it does not survive the failure it exists for.

You already have the pieces — `BACKUP_BUCKET` and `COOLIFY_S3_STORAGE_UUID` in
`infra/state.env`, and an `infra/local-backup-sync.sh` pattern from your other apps. Point
one of them at this volume. To pull the newest archive by hand:

```bash
sudo docker cp $(sudo docker ps -qf name=app-oo7d7si62yhyi7fx10hrck6q):/var/www/html/storage/backups/. ./endorsement-backups/
```

Keep the off-site copy **in-Kingdom** (PDPL Art. 29). Aim for 3-2-1: three copies, two
media, one off-site.

The first restore drill is already done (2026-07-25, before any patient data existed): the
archive decrypted to 207 KB of SQL and restored into a scratch MySQL 8.4 with all 24 tables.
Repeat it quarterly — `docs/RUNBOOK-BACKUP.md` has the recipe.

---

</details>

## 6b. Run the least-privilege grants

```bash
eval "$(sudo bash docker/instance-env.sh oo7d7si62yhyi7fx10hrck6q)" && \
sed -e "s/{{DATABASE}}/$DBNAME/g" -e "s/{{USER}}/$DBUSER/g" docs/sql/least-privilege.sql | \
sudo docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot '"$DBNAME"
```

**Read the stderr line `instance-env.sh` prints and confirm the database name before piping
anything into it** — never select the database container by matching the shared MySQL image
name; with two customer stacks on one host that picks an arbitrary one.

The mysql image auto-granted the application's user `ALL PRIVILEGES` — including `DROP`,
and including `UPDATE`/`DELETE` on `audit_log`. So two things this system states as facts
are currently only conventions in PHP: that the audit log is append-only, and that clinical
rows are never hard-deleted. The script strips DDL from the runtime credential and makes
`audit_log` append-only with database triggers, which hold no matter which credential is
used.

**One consequence, deliberate:** `php artisan migrate` will no longer work with the app's
credential. Run migrations with the root one instead — the command is in the script's
header. That keeps schema change an explicit privileged act rather than something the web
tier could do.

Verify it took by confirming both of these FAIL:

```bash
eval "$(sudo bash docker/instance-env.sh oo7d7si62yhyi7fx10hrck6q)" && \
sudo docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot '"$DBNAME"' -e "UPDATE audit_log SET action=\"x\" WHERE id=1"'
```

## 7. Restrict `/register`

It is currently open to the internet. Anyone who finds the domain can submit a registration.
They cannot get in — an administrator must approve, and the email must be verified — but it
is an unauthenticated write endpoint on a system holding children's health data, and it is
the largest remaining exposure. Options, cheapest first:

- Put Cloudflare Access in front of `/register` only (you already use it elsewhere).
- Restrict by IP to the hospital network.
- Replace self-registration with admin-issued invitations.

---

## 8. Create the real accounts

Order matters: Residents and Chief Residents **register themselves** at `/register`, then an
administrator (or a Chief Resident, for Residents only) activates them from **Admin → Access
Control**. Chief Residents register as Residents and are promoted by you. Consultants and
Charge Nurses are created the same way.

This needs step 4 done first — registration cannot complete without a verification email.

---

## 9. Cloudflare proxy (optional)

The DNS record is grey-cloud (DNS-only) because Let's Encrypt validates over HTTP-01. The
certificate now exists, so you may switch it to **Proxied (orange)** for DDoS protection and
WAF. If you do, set SSL/TLS mode to **Full (strict)** — anything lower lets Cloudflare talk
plain HTTP to the origin on the last hop.

---

## 10. PDPL governance — paperwork, not code

Health data about children is the highest-risk category SDAIA recognises. None of this is
something the application can do for you:

- Appoint and publish a **Data Protection Officer**; register with SDAIA if your processing
  volume requires it.
- **Records of processing (ROPA)**, a **privacy notice** for staff and patients, and a
  **DPIA** for this system.
- A **retention schedule** per table (handovers per the MOH medical-records schedule; audit
  log ≥ 12 months hot; pending registrations 30 days). The disposal mechanism already exists
  as `data:retention`.
- A **breach procedure**: SDAIA notification inside 72 hours — who decides, who writes it.
- A **data-subject-rights** procedure (access, correction, erasure where lawful).

---

## 11. GitHub Actions has not run a single job since 2026-08-08 — it is a billing block

**Nothing in CI has executed for two days of work.** Every run since `2026-08-08T07:45Z` ends in
2–4 seconds with the same annotation on every job:

> The job was not started because recent account payments have failed or your spending limit needs
> to be increased. Please check the 'Billing & plans' section in your settings

The last run that actually started was `2026-08-07T17:21Z`. Measured with `gh run list` /
`gh run view`, not inferred from a red badge — a billing block and a genuine test failure both show
as a red X on the commit list, which is exactly why this went unnoticed.

**Fix it in GitHub → Settings → Billing & plans** (payment method, then the Actions spending limit).
Dependabot's own update runs still succeed, so a green tick in that list is not evidence CI is
working.

Why it matters more than the usual "CI is red":

- **P0a through P1d-2 have had zero CI coverage.** Every one of those slices was verified locally
  (`php artisan test`, `npm test`, `npm run test:e2e`, `npm run build`) and committed on that
  evidence. That is the only reason the tree is trustworthy — but "the suite is green" has meant
  "green on one Windows machine" since 2026-08-08, with no second opinion on Linux, no matrix, and
  no check on a pull request.
- **The `docker-build` job has never executed once.** It was added on 2026-08-09 by the ops-rehearsal
  work for one specific reason: the production image's `composer:2` vendor stage had been failing its
  `ext-intl` platform check since the extension was first required, and every push stayed green
  because nothing in CI had ever built the image — a Coolify deploy was the first thing to try it,
  and it failed. The job exists so that a broken production build shows up on a push instead of on a
  deploy. It has been blocked by billing since the day it was written, so **the image is currently
  verified only by `docker build` run by hand on this machine**, which is what was done. Until
  billing is fixed, treat a green commit list as saying nothing at all about whether the image
  builds.

## 12. Un-tick `rota.manage` for Chief Resident on this instance

**Only if this instance already deployed P1d-1** (the 2026-08-10 master-rota release). P1d-1 seeded
`rota.manage` to Administrator **and** Chief Resident; the owner reversed that the same day and
P1d-2 removed Chief Resident from the defaults.

**The reversal does not reach an instance that already has the grant, by design.**
`AccessControlSeeder` applies each (position, capability) default exactly once — it records the pair
in `applied_role_defaults` and never re-asserts it — because whatever `role_capabilities` says about
an already-applied pair is the administrator's decision, revocations included. Removing the seed
entry therefore changes fresh deployments only. **There is no migration that revokes it, and there
must not be one**: silently taking a capability back from an administrator who may have kept it on
purpose is precisely what that mechanism exists to prevent.

So this one is yours, on the running system:

**Admin → Access Control → Chief Resident → un-tick "Create and edit master rota assignments and
vacations" → Save.**

Skip it deliberately if you *want* Chief Residents editing the rota — that is a supported
configuration and the reason the capability is grantable per role at all (Munawib's Scheduler
persona maps to no role here, and Chief Resident is the nearest fit). `rota.view` is untouched and
stays on every seeded position: reading the rota is not editing it.

---

## 13. Keep a SECOND account holding `access.manage` — and know what the invitation knob does

Two things arrived with the 2026-08-10 account-lifecycle release (P1c-2). Neither needs a command;
both are yours to decide.

**A. More than one `access.manage` holder, always.** The self-lockout guard on Admin → Access
Control protects the Administrator **role's** default set — it stops position 0 giving up
`access.manage` on the role matrix. It does **not** run on the per-account grant/deny path, on either
screen. So an administrator can deny `access.manage` to the last account holding it, and the security
console then answers to nobody: no screen can grant it back, and recovery means a database console.
This is pre-existing, measured rather than guessed, and deliberately not patched in a release whose
job was to extract one writer without changing behaviour (design §14 open item 20; a test already
pins both screens in agreement so a future fix is proved to reach each of them). **Until it is fixed,
the mitigation is entirely procedural: keep at least two active accounts holding `access.manage`, and
do not experiment with denying it to yourself.**

**B. "Link lifetime (days)" on Admin → Settings.** How long an invitation link stays usable, default
**7**, maximum **30**. Munawib's own figure is 14; 7 is the deliberate choice here, because redeeming
an invitation creates an account that reaches children's clinical records, so a link that was
forwarded, printed or left in a shared inbox is live for half as long. **Leave it alone unless you
have a reason.** Raising it is a real, if small, security decision; the field is on the Settings
screen rather than the account console precisely so it is reviewed alongside SMTP and VAPID rather
than adjusted for convenience by whoever is issuing invitations that day. An unset field means the
default is in force — that is the normal, intended state.

Also worth knowing, because both look like faults and are not: **resending an invitation kills the
old link** (a new one is minted; the old row is kept and marked revoked), and **unbinding an account
removes it from the Users list** (it is kept as history, cannot log in, and cannot be reactivated — a
colleague who returns gets a fresh invitation and needs their roles granted again).

---

## Ongoing

| When | Do |
| --- | --- |
| Before pushing anything that touches the image or compose file | `bash docker/smoke.sh` on the host |
| After any deploy (wait ~1 min for the container swap) | `bash scripts/verify-live.sh` |
| Quarterly | Restore drill (`docs/RUNBOOK-BACKUP.md`) |
| If a release adds migrations | Run `php artisan migrate --force` yourself after the deploy — the container never migrates at boot, by design |
| Whenever you change who holds `access.manage` | Confirm at least two active accounts still hold it (item 13) |

`audit:verify` runs hourly, `backup:run` at 01:30, `data:retention` at 02:30, handover
reminders at 07:30 and 15:30 — all inside the container, all logging a critical line on
failure.
