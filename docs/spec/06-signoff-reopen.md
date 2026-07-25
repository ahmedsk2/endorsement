# 6. Sign-off and reopen

- Per-day header row in `handover_signoffs`, keyed (unit_id, handover_date).
- **Endorsed By / Endorsed To pickers list active Residents only (position 4)**, per legacy **[RULING]**. **Consultant pickers list active Consultants only (position 3)** (reference behaviour; legacy free text is superseded). Names are snapshotted into `*_name` at write time; a later rename never rewrites a signed sheet.
- WARD shows a single consultant picker labelled "Consultant Oncall" stored in `consultant_by_*`; the receiving-consultant field is hidden for WARD **[RULING]**.
- Time: quick-picks `7:30 Am` and `13:30` kept character-for-character (all four units share them); free text accepted only if parseable, normalised to HH:MM for display and 0–1439 for `endorsement_time_minutes`; unparseable input is a validation error.
- **Signing** requires endorsed_by; stamps `signed_off_at` + `signed_off_by_user_id`; a signed day is **locked** — row writes and sign-off edits return validation errors (422).
- **Reopen** requires the `endorsement.reopen` capability (checked in-controller so the 403 can name the actual active holders), a mandatory reason (3–500 chars), clears `signed_off_at`, sets reopened_at/by/reason, preserves all snapshots. The **reason text is never written to the audit log** (it could name a patient); audit rows carry unit, date, and prior-signature metadata only. Denied attempts are audited too.

Audit actions: `endorsement_new_day`, `endorsement_row_create/_update/_delete`, `endorsement_signoff`, `endorsement_signoff_reopen`, `endorsement_signoff_reopen_denied`, `access_denied`.

---
