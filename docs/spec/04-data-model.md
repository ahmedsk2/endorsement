# 4. Data model

### Identity: two tables (P0c, D3 reversed 2026-08-08)

`people` is the roster and the name/role of record: `full_name`, `short_name` (unique), `position`
tinyint, `email`/`phone`, level history (`person_levels`, effective-dated), `notes`/`constraints`
(both plaintext), `external`, `active` (governs whether a person may be **named**), soft deletes.
`users` is purely the account: `member_name` login, `active` (governs whether it may **log in**),
`pass_exp_date`, encrypted TOTP columns, soft deletes — and `person_id` (nullable, UNIQUE),
linking at most one account per person. A person with no `users` row cannot authenticate by
construction — see `CLAUDE.md`'s identity paragraph and
`tests/Feature/Auth/RosterOnlyCannotAuthenticateTest.php`. `people.id` and `users.id` are
independent sequences; never compare or copy them positionally.

### Cloned foundation tables (verbatim from the reference)

`positions` (0 Administrator, 1 Nurse [RETIRED], 2 Charge Nurse, 3 Consultant, 4 Resident, 5 Chief
Resident), `institutions` (kept; nullable FKs) **[RULING]**, `pending_registrations` (frozen — no
live writer; superseded by `invitations`, see §6 below), `capabilities` / `role_capabilities` /
`user_capabilities` / `applied_role_defaults`, `audit_log` (append-only, hash-chained: `prev_hash`,
`hash`), `sessions`, `cache`, `password_reset_tokens`.

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
- Four staff pairs, each `*_person_id` FK nullOnDelete (P0c — the live column; wire contract is `endorsed_by_person_id` etc., not `*_user_id`) + `*_name` **string snapshot frozen at write time**: endorsed_by, endorsed_to, consultant_by, consultant_to. `endorsed_by`/`endorsed_to` require a claimed account (D9); `consultant_by`/`consultant_to` accept any active roster person, account or not — one predicate per field in `App\Support\SignoffPickers`. The legacy `*_user_id` columns survive alongside them, `nullOnDelete`, populated only on historical rows backfilled through `users.person_id`; new writes leave them NULL
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
