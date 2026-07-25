# Deployment runbook — endorse.towardpcc.com (owner-run)

Target: the existing Coolify host in **OCI me-riyadh-1** (in-Kingdom, which is what keeps
the PDPL data-residency position simple). Coolify 4.1.2, Traefik already owns :80/:443 on
the `coolify` docker network.

| | |
| --- | --- |
| Host public IP | `145.241.105.239` |
| Domain | `endorse.towardpcc.com` |
| Stack | `docker-compose.production.yml` (app + dedicated MySQL 8.4) |
| Repo | `github.com/ahmedsk2/endorsement`, branch `main` |

**You run every step here.** Migrations and live-DB changes are yours by project rule, and
no secret in this document has a value written down — you supply them in the Coolify UI.

---

## 1. DNS (Cloudflare) — you add one record

| Type | Name | Content | Proxy | TTL |
| --- | --- | --- | --- | --- |
| A | `endorse` | `145.241.105.239` | **DNS only (grey cloud)** | Auto |

Grey cloud **for the first deploy only**. Let's Encrypt validates over HTTP-01, which
Traefik answers directly on port 80; with the orange cloud on before the certificate
exists, Cloudflare terminates TLS itself and the challenge never reaches Traefik.

Once the certificate is issued (step 5 shows a real Let's Encrypt cert), you may switch
the record to **Proxied (orange)**. If you do, set Cloudflare SSL/TLS mode to
**Full (strict)** — anything less lets Cloudflare talk plain HTTP to the origin, which
would put clinical traffic in the clear on the last hop.

Verify before continuing:

```bash
nslookup endorse.towardpcc.com
```

---

## 2. Push the code

Already committed on `main`. From the project directory:

```bash
git push origin main
```

The repo is private; Coolify authenticates with the GitHub App or a deploy key you
configure once under **Sources**.

---

## 3. Create the application in Coolify

**+ New → Resource → Docker Compose** (not "Dockerfile" — the compose file also brings up
the dedicated database).

| Field | Value |
| --- | --- |
| Source | GitHub → `ahmedsk2/endorsement` |
| Branch | `main` |
| Build pack | Docker Compose |
| Compose file | `docker-compose.production.yml` |
| Base directory | `/` |
| Domain (service `app`) | `https://endorse.towardpcc.com:8080` |

The `:8080` suffix is Coolify's syntax for "route to container port 8080" — the app's
nginx listens there, and the container runs unprivileged so it cannot bind 80. Coolify
generates the Traefik router, the HTTP→HTTPS redirect and the Let's Encrypt certificate
from that one field; the compose file deliberately carries **no** `traefik.*` labels so
there is only ever one router per host.

Leave the `db` service with **no domain** — it must not be reachable from outside.

---

## 4. Environment variables

**Settings → Environment Variables.** Mark every one **Build Variable: off**, and the
secrets as locked/hidden. Generate values yourself — never paste them into a chat.

| Key | Value | Notes |
| --- | --- | --- |
| `APP_NAME` | `Paediatric Endorsement` | shown in the UI and on printed sheets |
| `APP_URL` | `https://endorse.towardpcc.com` | must match exactly, or signed URLs (email verification) break |
| `APP_KEY` | generate: `php artisan key:generate --show` | **the encryption key for all PHI** — see below |
| `MYSQL_DATABASE` | `endorsement` | |
| `MYSQL_USER` | `endorse` | |
| `MYSQL_PASSWORD` | 32+ random chars | app's DB login |
| `MYSQL_ROOT_PASSWORD` | 32+ random chars, **different** | never used by the app |
| `BACKUP_PASSPHRASE` | 32+ random chars, **different again** | encrypts the nightly dump |

> **APP_KEY is not a routine secret.** Patient name, MRN, DOB and all four rich-text
> fields are encrypted with it in the database. Lose it and the clinical record is
> unrecoverable ciphertext — a restored backup will not help. Rotating it is a data
> migration, not a config change. Store it in your password manager *before* the first
> deploy, separately from `BACKUP_PASSPHRASE` (a backup and its key must never sit in the
> same place). Full custody rules: `docs/RUNBOOK-BACKUP.md`.

SMTP and web-push VAPID keys are **not** set here — configure them in-app at
**Admin → Settings** after login, where they are stored encrypted.

---

## 5. Deploy and issue the certificate

Press **Deploy**. First build takes roughly 4–6 minutes on this ARM host (npm + composer);
later builds reuse cached layers.

The app boots but **does not migrate** — `docker/entrypoint.sh` deliberately never touches
the schema, so a restart can never alter your data. Expect the health check to be green
and the site to error until you run step 6.

Watch for: `Certificate obtained successfully` in the Coolify proxy logs, then

```bash
curl -sI https://endorse.towardpcc.com/up | head -3
```

---

## 6. Initialise the database (once)

From **Coolify → the app → Terminal**, or over SSH with
`docker exec -it <app-container> sh`:

```bash
php artisan migrate --force
```

```bash
php artisan db:seed --force
```

That runs exactly two seeders: `ReferenceSeeder` (the four units + the institution) and
`AccessControlSeeder` (the capability catalogue and role grants). It does **not** touch
`DemoSeeder` or `E2eSeeder` — those create fictional logins whose password is published in
the repo docs, are not wired into `DatabaseSeeder`, and throw if invoked with
`APP_ENV=production`. Never call them by name here.

Create the first administrator. This is the only way in: registration produces a *pending
Resident*, and approving one requires an administrator who does not exist yet. The command
prompts for everything and never echoes the password:

```bash
php artisan user:create-admin
```

It refuses a username that already exists (so a careless re-run cannot reset a real
administrator's password) and applies the same password policy as the registration form.
Your first sign-in will redirect you to your profile to enrol a second factor — admin
screens are unreachable without one, by design.

Then register the real clinicians through the normal registration page and promote them
from **Admin → Access Control**. Nothing is imported: the system starts clean, by your
decision of 2026-07-25 (`docs/RUNBOOK-IMPORT.md` keeps the importer available if the unit
ever changes its mind).

---

## 7. Verify the live deployment

```bash
curl -sI https://endorse.towardpcc.com/up
```

Expect `200`, plus `strict-transport-security`, `content-security-policy`,
`x-frame-options: DENY` and `referrer-policy: no-referrer`.

Then, signed in:

- **Admin → Settings** — set SMTP, send the test email, generate the VAPID pair.
- Register a throwaway account and confirm the verification email arrives.
- Open a unit, add a patient row, type in a rich-text field, **reload** — the text must
  still be there and still coloured (that is the legacy production bug this system fixes).
- Print a signed day and confirm names + signatures render.
- Confirm the scheduler is alive:

  ```bash
  php artisan schedule:list
  ```

  Six jobs: two handover reminders (07:30 / 15:30), `audit:verify` hourly, `backup:run`
  01:30, `data:retention` 02:30.

- Confirm the audit chain is intact:

  ```bash
  php artisan audit:verify
  ```

---

## 8. Backups

The nightly encrypted dump is already scheduled inside the container and lands in the
`endorsement-backups` volume. **A backup that only exists on the machine it backs up is
not a backup** — pull it off-host on a schedule, per `docs/RUNBOOK-BACKUP.md`, which also
covers the local-copy step and the restore drill. Do the restore drill once before you
trust it.

---

## Rollback

Coolify keeps previous deployments: **Deployments → the last good one → Redeploy**. That
reverts code only. It does **not** revert migrations — if a release migrated the schema,
roll back by restoring the database backup taken before it, then redeploying the matching
image.

---

## Deploying without Coolify (fallback)

If Coolify is ever unavailable, the same compose file runs by hand — but then nothing
generates the Traefik router, so add these labels to the `app` service and supply an
`--env-file`:

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

## Verifying a change before it reaches the ward

`docker/smoke.sh` boots the built image against a throwaway MySQL and asserts what unit
tests structurally cannot: that the image boots, migrations apply, `/up` returns 200, the
security headers survive, php-fpm runs as the storage owner, and the scheduler is
registered. Run it on the host after any Dockerfile or middleware change:

```bash
docker build -t endorsement-app:test . && bash docker/smoke.sh
```

It tears its own containers down, including on failure. It caught two bugs that all 293
unit tests passed straight through: a global middleware calling `$request->user()` on the
session-less `/up` route (health check permanently red, every page fine), and php-fpm
running as `www-data` against storage owned by `app` (signature uploads silently failing).
