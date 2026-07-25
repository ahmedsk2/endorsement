# Legacy import runbook (owner-run)

> **NOT PART OF GO-LIVE (owner decision, 2026-07-25).** The system starts CLEAN — no
> legacy data is being migrated. This runbook is kept because the importer is built,
> tested and idempotent, so historical data can still be brought across later if the
> unit ever wants it. Nothing here runs during deployment.


The `legacy:import` command moves the four legacy endorsement table pairs into this
system. It is **one-way** (never writes the legacy DB), **idempotent** (re-runs upsert on
provenance, never duplicate), and **lossless** (every legacy row keeps its
`legacy_source_table` + `legacy_id`). **You run it — never the assistant, never CI.**

## What it imports

| section | legacy source | → destination | notes |
| --- | --- | --- | --- |
| users | `members` | `users` | bcrypt hashes copied **verbatim** (never rehashed) |
| endorsement | `patintsendorcement` | `handovers` (PICU) | rich text sanitised on write |
| endorsement | `nicu_patintsendorcement` | `handovers` (NICU) | + `dob` |
| endorsement | `scbu_patintsendorcement` | `handovers` (SCBU) | + `dob` |
| endorsement | `ward_patintsendorcement` | `handovers` (WARD) | `age`, `unit` → `ward_unit` (verbatim free text) |
| endorsement | `endorsement` / `nicu_` / `scbu_` | `handover_signoffs` | endorser ids resolved via `members`; consultants free-text snapshots |
| endorsement | `ward_endorsement` | `handover_signoffs` | `consultantoncall` → `consultant_by_name` (ruling 5) |

Data rules applied (rows are cleaned, **never dropped**): `0000-00-00` / `1970-01-01`
dates → NULL; zero-date dobs → NULL; missing-value tokens (`''`, `-`, `nill`, `nan`…) →
NULL; imported day headers are marked **signed** (historically final ⇒ locked) with a
NULL signer, which is what distinguishes an imported attestation from one signed here.
Day headers with no parseable date are skipped and counted. Expected divergences appear
in the reconciliation as `dates_nulled` / `skipped_no_date` rows.

## Before you run it

1. **Create a SELECT-only MySQL grant** on the legacy schema for the importer:
   ```sql
   CREATE USER 'endorse_ro'@'%' IDENTIFIED BY '<your-password>';
   GRANT SELECT ON qch_legacy.* TO 'endorse_ro'@'%';
   ```
2. Set the `LEGACY_DB_*` env vars on the app host (`.env`, never committed):
   `LEGACY_DB_HOST`, `LEGACY_DB_DATABASE`, `LEGACY_DB_USERNAME`, `LEGACY_DB_PASSWORD`.
3. The app DB must be migrated and seeded first:
   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```
   (`ReferenceSeeder` creates the four units + institution the import attaches to.)
4. Take a backup of the APP database (the legacy DB is untouched by design).

## Run

```bash
php artisan legacy:import                      # users + endorsement, in order
php artisan legacy:import --only=users         # accounts alone
php artisan legacy:import --only=endorsement   # handovers + sign-offs alone
```

Each section runs in its own transaction: on error it rolls back, is reported, and the
command exits non-zero. Console output and the generated `docs/RECONCILIATION.md` carry
**counts only — no patient names, MRNs, staff names, or hashes**.

## Verify — the cutover gate

```bash
php artisan legacy:reconcile
```

Read-only against both databases; recomputes every per-unit count live and **exits 0
only when everything matches** (with the modelled expected divergences subtracted).
Do not cut over on a non-zero exit.

Expected production magnitudes (from the legacy dump): ward ≈ 9.8k, nicu ≈ 5.6k,
picu ≈ 4.5k, scbu ≈ 2.3k handover rows; ≈ 780/510/500/550 day headers; ~80 members.

## Re-running / rollback

- Re-running is safe at any time: provenance-keyed upserts refresh imported rows and
  never touch rows created in this application (their provenance columns are NULL).
- Rollback of a bad import = restore the APP database backup. The legacy database is
  never written, so it needs no rollback.
