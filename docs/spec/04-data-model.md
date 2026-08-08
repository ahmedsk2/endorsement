# 4. Data model

### Identity: two tables (P0c, D3 reversed 2026-08-08)

`people` is the roster and the name/role of record: `full_name`, `short_name` (unique), `position`
tinyint, `email`/`phone`/`joined_at`, level history (`person_levels`, effective-dated),
`notes`/`constraints` (both plaintext), `external`, `active` (governs whether a person may be
**named**), soft deletes.
`users` is purely the account: `member_name` login, `active` (governs whether it may **log in**),
`pass_exp_date`, encrypted TOTP columns, soft deletes — and `person_id` (nullable, UNIQUE),
linking at most one account per person. A person with no `users` row cannot authenticate by
construction — see `CLAUDE.md`'s identity paragraph and
`tests/Feature/Auth/RosterOnlyCannotAuthenticateTest.php`. `people.id` and `users.id` are
independent sequences; never compare or copy them positionally.

### Cloned foundation tables (verbatim from the reference)

`positions` (0 Administrator, 1 Nurse [RETIRED], 2 Charge Nurse, 3 Consultant, 4 Resident, 5 Chief
Resident), `institutions` (kept; nullable FKs) **[RULING]**, `pending_registrations` (frozen — no
live writer; superseded by `invitations`, below), `capabilities` / `role_capabilities` /
`user_capabilities` / `applied_role_defaults`, `audit_log` (append-only, hash-chained: `prev_hash`,
`hash`), `sessions`, `cache`, `password_reset_tokens`.

### `invitations`

Replaces self-registration (closed 2026-07-27) — the only way an account is now created; see
`docs/spec/08-foundation.md`.

- id; institution_id FK nullable; **person_id** FK nullable, `nullOnDelete` (P0c/Task 8 — the
  roster row this invitation is issued to, matched-or-created at issue time; null only on
  pre-P0c rows, for which redemption still creates the person)
- member_email string, position tinyint — the FROZEN terms of the invitation, set by the
  inviter; the invitee never supplies either
- **token_hash** string(64) unique — sha256 of the bearer token; the plaintext token is never
  persisted, logged or audited, and is shown to the inviter exactly once
- invited_by_user_id FK nullable
- expires_at timestamp — 7-day lifetime (`Invitation::LIFETIME_DAYS`)
- accepted_at, accepted_user_id nullable; revoked_at, revoked_by_user_id nullable — redemption
  and revocation are recorded, never deleted
- timestamps (no soft deletes)
- index (member_email, accepted_at); index (person_id)

### `handovers`

Reference schema with two changes: **no `draft` column** **[RULING]**, **plus lossless-import provenance**.

- id; institution_id FK nullable; unit_id FK; handover_date date
- bed, mrn, patient_name (strings, nullable)
- dob datetime nullable (NICU/SCBU); age string nullable, ward_unit string nullable (WARD) — kept for all units, surfaced per UnitProfile
- disease, details, plan, nevent — TEXT, rich HTML, sanitised on write (§7)
- extra_fields TEXT nullable (P0b) — one `{key: value}` map of per-unit custom field values,
  `App\Casts\EncryptedJson`; shape driven by `unit_field_definitions`, see
  `docs/spec/03-unit-model.md`
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
