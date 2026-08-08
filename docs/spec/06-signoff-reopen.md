# 6. Sign-off and reopen

- Per-day header row in `handover_signoffs`, keyed (unit_id, handover_date).
- **Identity is two tables (P0c, D3 reversed 2026-08-08)**: pickers name a `people` row
  (`*_person_id`), not a `users` row — see `docs/spec/04-data-model.md`.
- **Endorsed By / Endorsed To pickers list active Residents AND Chief Residents (positions 4, 5)
  who hold a claimed, live account** (D9) — their signature is the evidence, so a roster-only
  person cannot be offered. **Consultant pickers list any active Consultant (position 3), account
  or not** — the on-call consultant is a name of record and frequently never logs in. Offer and
  write-side validation are generated from **one predicate per field**,
  `App\Support\SignoffPickers`, so they cannot drift (the 2026-07-26 audit invariant, now
  per-field). Names are snapshotted into `*_name` at write time; a later rename never rewrites a
  signed sheet. A stored id that has since stopped being offered (account deactivated, person
  deactivated) still appears, flagged `retired` and disabled, so a save can never silently clear
  it.
- WARD shows a single consultant picker labelled "Consultant Oncall" stored in `consultant_by_*`; the receiving-consultant field is hidden for WARD **[RULING]**.
- Time: quick-picks `7:30 Am` and `13:30` kept character-for-character (all four units share them); free text accepted only if parseable, normalised to HH:MM for display and 0–1439 for `endorsement_time_minutes`; unparseable input is a validation error.
- **Signing** requires endorsed_by; stamps `signed_off_at` + `signed_off_by_user_id` (the
  authenticated user — an *actor*, not a name of record, so this one stays on `users`); a signed
  day is **locked** — row writes and sign-off edits return validation errors (422).
- **Reopen** requires the `endorsement.reopen` capability (checked in-controller so the 403 can name the actual active holders), a mandatory reason (3–500 chars), clears `signed_off_at`, sets reopened_at/by/reason, preserves all snapshots. The **reason text is never written to the audit log** (it could name a patient); audit rows carry unit, date, and prior-signature metadata only. Denied attempts are audited too.

Audit actions: `endorsement_new_day`, `endorsement_row_create/_update/_delete`, `endorsement_signoff`, `endorsement_signoff_reopen`, `endorsement_signoff_reopen_denied`, `access_denied`.

---
