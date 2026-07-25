# 4. Data model

### Cloned foundation tables (verbatim from the reference)

`users` (member_name login, position tinyint, active flag, pass_exp_date, encrypted TOTP columns, soft deletes), `positions` (0 Administrator, 1 Nurse, 2 Charge Nurse, 3 Consultant, 4 Resident), `institutions` (kept; nullable FKs) **[RULING]**, `pending_registrations`, `capabilities` / `role_capabilities` / `user_capabilities` / `applied_role_defaults`, `audit_log` (append-only, hash-chained: `prev_hash`, `hash`), `sessions`, `cache`, `password_reset_tokens`.

### `handovers`

Reference schema with two changes: **no `draft` column** **[RULING]**, **plus lossless-import provenance**.

- id; institution_id FK nullable; unit_id FK; handover_date date
- bed, mrn, patient_name (strings, nullable)
- dob datetime nullable (NICU/SCBU); age string nullable, ward_unit string nullable (WARD) — kept for all units, surfaced per UnitProfile
- disease, details, plan, nevent — TEXT, rich HTML, sanitised on write (§7)
- author_user_id FK nullable (last author)
- **legacy_source_table** string nullable, **legacy_id** unsigned bigint nullable **[RULING]** — unique together when present
- timestamps, softDeletes; index (unit_id, handover_date)

### `handover_signoffs`

Reference schema verbatim, plus the same provenance pair:

- id; institution_id, unit_id FKs; handover_date date; **UNIQUE (unit_id, handover_date)** (fixes legacy's missing uniqueness)
- Four staff pairs, each `*_user_id` FK nullOnDelete + `*_name` **string snapshot frozen at write time**: endorsed_by, endorsed_to, consultant_by, consultant_to
- endorsement_time string (verbatim display label) + endorsement_time_minutes unsigned small int nullable (0–1439)
- signed_off_at timestamp nullable, signed_off_by_user_id FK
- reopened_at, reopened_by_user_id, reopen_reason TEXT
- legacy_source_table, legacy_id (nullable)
- timestamps, softDeletes

### New tables (compliance addendum)

- `push_subscriptions`: user_id FK, endpoint (unique), p256dh, auth, timestamps
- `reminder_preferences`: user_id FK, unit_id FK, unique (user_id, unit_id)

### Schema governance

Additive, nullable migrations; soft deletes everywhere clinical; no destructive change to a column holding real data; clinical rows never hard-deleted; accounts deactivated, never deleted.

---
