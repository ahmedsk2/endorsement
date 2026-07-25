# Owner checklist — endorse.towardpcc.com

Everything the system needs from you, in the order it has to happen. The site is live and
healthy; nothing below is a bug fix, it is the handover of the things only you can hold.

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

## 5. Generate the VAPID pair — same screen

Enables browser push for the 07:30 and 15:30 handover reminders. One click; the private key
is stored encrypted. Without it the reminders simply do not send — nothing else breaks.

---

## 6. Get backups off the server — the largest operational gap

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

## Ongoing

| When | Do |
| --- | --- |
| Before pushing anything that touches the image or compose file | `bash docker/smoke.sh` on the host |
| After any deploy (wait ~1 min for the container swap) | `bash scripts/verify-live.sh` |
| Quarterly | Restore drill (`docs/RUNBOOK-BACKUP.md`) |
| If a release adds migrations | Run `php artisan migrate --force` yourself after the deploy — the container never migrates at boot, by design |

`audit:verify` runs hourly, `backup:run` at 01:30, `data:retention` at 02:30, handover
reminders at 07:30 and 15:30 — all inside the container, all logging a critical line on
failure.
