# 5. Day lifecycle

### Day index (per unit)

Last 30 days newest-first with date-range filter (reference parity), each day showing census count and sign-off badge. **Gap markers**: missing dates between existing sheets render inline as "no endorsement — {date} · create" with one-tap backfill (§10.4).

### New day (carry census forward)

- Target date = requested date or `Calendar::todayYmd()` (P1a; the INSTANCE timezone —
  `APP_TIMEZONE`, Asia/Riyadh for QCH today, not a hardcoded system constant — see
  `docs/spec/04-data-model.md`'s calendar-config addendum). **Idempotent**: if the unit already has rows for the target date, nothing is copied and the user is sent to the existing sheet.
- Carry copies, per source row: institution_id, bed, mrn, patient_name, dob, age, ward_unit, disease, details, plan **and nevent** — nevent carries forward verbatim, per legacy **[RULING]**.
- **Carry dialog** **[RULING]**: when the most recent prior sheet is exactly the day before the target, carry happens silently (the normal flow). When it is **older than yesterday**, a dialog shows "Last endorsement was {date}" and offers **carry that census forward** or **start blank**. Starting blank creates the day with one empty row.
- If nothing exists to carry, the day is created with one blank row so it exists.
- Runs in a transaction; audited as `endorsement_new_day` with unit, date, and carried-row count only.

### Rows

- Add: inserts a blank row for the sheet date. Delete: **soft delete**, audited. Both POST/DELETE + CSRF behind `cap:endorsement.edit`.
- Ordering: natural case-insensitive bed sort (`strnatcasecmp`) with blank beds **last** — legacy's intent, fixing the reference's string-sort regression.
- Editing: **per-field save-on-blur.** Each PATCH carries exactly one field. Status per cell: `saving → saved` (auto-clears after 2.5 s) or persistent `error` ("Not saved", `role=alert`) until the next attempt. Never fire-and-forget: the UI state reflects the server response, and e2e tests assert on **persistence after reload**, never on the indicator alone.

---
